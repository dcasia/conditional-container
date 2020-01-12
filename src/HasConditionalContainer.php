<?php

namespace DigitalCreative\ConditionalContainer;

use Benjacho\BelongsToManyField\BelongsToManyField;
use Closure;
use Illuminate\Http\Resources\MergeValue;
use Illuminate\Support\Collection;
use Laravel\Nova\Contracts\RelatableField;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\FieldCollection;
use Laravel\Nova\Http\Controllers\ActionController;
use Laravel\Nova\Http\Controllers\AssociatableController;
use Laravel\Nova\Http\Controllers\AttachableController;
use Laravel\Nova\Http\Controllers\CreationFieldController;
use Laravel\Nova\Http\Controllers\FieldController;
use Laravel\Nova\Http\Controllers\MorphableController;
use Laravel\Nova\Http\Controllers\ResourceAttachController;
use Laravel\Nova\Http\Controllers\ResourceIndexController;
use Laravel\Nova\Http\Controllers\ResourceStoreController;
use Laravel\Nova\Http\Controllers\ResourceUpdateController;
use Laravel\Nova\Http\Controllers\UpdateFieldController;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use NovaAttachMany\AttachMany;
use Whitecube\NovaFlexibleContent\Flexible;
use Whitecube\NovaFlexibleContent\Layouts\Collection as LayoutCollection;
use Whitecube\NovaFlexibleContent\Layouts\Layout;

trait HasConditionalContainer
{

    /**
     * Get the panels that are available for the given detail request.
     *
     * @param NovaRequest $request
     *
     * @return array
     */
    public function availablePanelsForDetail($request)
    {
        $panels = parent::availablePanelsForDetail($request);
        $fields = parent::availableFields($request);

        return $this->mergePanels($panels, $this->findAllActiveContainers($fields, $this));
    }

    /**
     * Get the panels that are available for the given create request.
     *
     * @param NovaRequest $request
     *
     * @return array
     */
    public function availablePanelsForCreate($request)
    {
        $panels = parent::availablePanelsForCreate($request);
        $fields = parent::availableFields($request);

        return $this->mergePanels($panels, $this->findAllContainers($fields));
    }

    /**
     * Get the panels that are available for the given update request.
     *
     * @param NovaRequest $request
     *
     * @return array
     */
    public function availablePanelsForUpdate($request)
    {
        $panels = parent::availablePanelsForUpdate($request);
        $fields = parent::availableFields($request);

        return $this->mergePanels($panels, $this->findAllContainers($fields));
    }

    private function mergePanels(array $panels, Collection $containers): array
    {
        return $containers
            ->flatMap(static function ($container) {
                return $container->fields->whereInstanceOf(Panel::class);
            })
            ->prepend($panels)
            ->flatten()
            ->toArray();
    }

    public function availableFields(NovaRequest $request)
    {

        $controller = $request->route()->controller;

        /**
         * Exclude all instance of conditional container from index views
         */
        if ($controller instanceof ResourceIndexController) {

            return parent::availableFields($request)->filter(static function ($field) {

                return !($field instanceof ConditionalContainer);

            });

        }

        if ($controller instanceof CreationFieldController ||
            $controller instanceof UpdateFieldController) {

            $fields = parent::availableFields($request);
            $containers = $this->findAllContainers($fields);

            $this->findAllFlexibleContentFields($fields);

            $expressionsMap = $containers->flatMap->expressions;

            $cleanUpMethodName = $controller instanceof UpdateFieldController ?
                'removeNonUpdateFields' :
                'removeNonCreationFields';

            /**
             * @var ConditionalContainer $container
             */
            foreach ($containers as $container) {

                $container->fields = $this->$cleanUpMethodName(
                    $request, new FieldCollection($this->filter($container->fields->toArray()))
                )->values();

                /**
                 * Inject all the expressions from all the fields within all the containers, so
                 * each container has an overall knowledge of what fields it should build up the listeners
                 */
                $container->withMeta([ 'expressionsMap' => $expressionsMap ]);

            }

            return $this->preloadRelationships($expressionsMap, $fields);

        }

        /**
         * Whats is this controller for? seems to be executed when there is a BelongsToMany Field
         */
        if ($controller instanceof ActionController) {

            return parent::availableFields($request);

        }

        $allFields = $this->fields($request);
        $containers = $this->findAllContainers($allFields);
        $expressionsMap = $containers->flatMap->expressions;
        $flexibleContent = $this->findAllFlexibleContentFields($allFields);

        if ($flexibleContent->isNotEmpty()) {

            $this->registerFlexibleMacros($request, $flexibleContent);

        }

        $fields = $this->flattenDependencies(
            $request, $this->preloadRelationships($expressionsMap, $allFields)
        );

        return new FieldCollection(array_values($this->filter($fields->toArray())));

    }

    private function registerFlexibleMacros(NovaRequest $request, Collection $flexibleContent)
    {

        /** @var NovaRequest $fakeRequest */
        $fakeRequest = $request;

        foreach ($flexibleContent as $field) {

            $field::macro('generateFieldName', static function (array $fields) {

                return collect($fields)->pluck('attribute')->join('.');

            });

            $field::macro('resolveFlexibleGroups', function ($flattenDependencies) use ($fakeRequest) {

                /**
                 * Clone groups using a filtered version of the available fields
                 */
                return $this->groups->filter()->map(function (Layout $layout) use ($flattenDependencies, $fakeRequest) {

                    $fields = $flattenDependencies(
                        $fakeRequest, $layout->fields(), $layout->attributesToArray()
                    )->map(static function ($field) {
                        return clone $field;
                    });

                    $name = $this->generateFieldName($fields->toArray());

                    return new Layout(
                        $layout->title(),
                        $name,
                        $fields,
                        $layout->key(),
                        $layout->getAttributes()
                    );

                });

            });

            $field::macro('resolveForValidation', function ($flattenDependencies, Flexible $field) use ($fakeRequest) {

                $this->groups = collect();

                $this->syncAndFillGroups($fakeRequest, $this->attribute);

                $this->groups = $this->resolveFlexibleGroups($flattenDependencies);

                $bag = collect($fakeRequest->input($this->attribute))->keyBy('key');

                foreach ($this->groups as $group) {

                    $key = $group->key();
                    $currentValue = $bag->get($key);
                    $bag->offsetSet($group->key(), array_merge($currentValue, [ 'layout' => $group->name() ]));

                }

                $fakeRequest->merge([ $this->attribute => $bag->values()->toArray() ]);

                $this->layouts = LayoutCollection::make($this->groups);

            });

            $field::macro('resolveConditionalContainer', function ($flattenDependencies) use ($fakeRequest) {

                /**
                 * Clone groups using a filtered version of the available fields
                 */
                $groups = $this->resolveFlexibleGroups($flattenDependencies);

                /**
                 * Rename the layouts instances by composing the names of all the available fields
                 */
                $layouts = LayoutCollection::make($groups)
                                           ->map(function (Layout $layout) {

                                               $name = $this->generateFieldName($layout->fields());
                                               $layout->setAttribute('__conditional_name__', $name);

                                               return new Layout(
                                                   $layout->title(),
                                                   $name,
                                                   $layout->fields(),
                                                   $layout->key(),
                                                   $layout->getAttributes()
                                               );

                                           })
                                           ->unique('__conditional_name__');

                $value = $this->resolveGroups($groups)->map(function (array $layout) {

                    $layout[ 'layout' ] = $this->generateFieldName($layout[ 'attributes' ]);

                    return $layout;

                });

                $this->withMeta([ 'value' => $value->values() ]);
                $this->withMeta([ 'layouts' => $layouts->values() ]);

            });

        }
    }

    private function preloadRelationships(Collection $expressionsMap, $fields)
    {

        $relations = collect();

        foreach ($fields as $field) {

            if ($field instanceof RelatableField ||
                $field instanceof AttachMany ||
                $field instanceof BelongsToManyField) {

                $relations->push($field->attribute);

            }

        }

        $expressionsMap = $expressionsMap->map(static function (string $expression) {
            return ConditionalContainer::splitLiteral($expression)[ 0 ];
        });

        /**
         * Only load the relations that are necessary
         */
        $relations = $relations->filter(static function ($relation) use ($expressionsMap) {
            return $expressionsMap->contains($relation);
        });

        if ($relations->isNotEmpty()) {

            $this->loadMissing($relations);

        }

        return $fields;

    }

    private function flattenDependencies(NovaRequest $request, array $fields, array $resource = null)
    {

        $controller = $request->route()->controller;
        $fields = collect($fields);
        $resource = is_array($resource) ? collect($resource) : $this;

        if ($fields->whereInstanceOf(ConditionalContainer::class)->isEmpty() &&
            $fields->whereInstanceOf(MergeValue::class)->isEmpty() &&
            $fields->whereInstanceOf(Flexible::class)->isEmpty()) {

            return $fields;

        }

        $fakeRequest = $request->duplicate();

        return $fields->flatMap(function ($field) use ($fields, $fakeRequest, $controller, $resource) {

            if ($field instanceof Field) {

                $this->parseThirdPartyPackageFieldValue($field, $fakeRequest);

            }

            if ($field instanceof Flexible) {

                if ($controller instanceof ResourceUpdateController) {

//                    $field->resolveForValidation(
//                        Closure::fromCallable([ $this, 'flattenDependencies' ]), $field
//                    );

                } else {

                    $field->resolve($this);

                    $field->resolveConditionalContainer(
                        Closure::fromCallable([ $this, 'flattenDependencies' ])
                    );

                }

                return [ $field ];

            }

            if ($field instanceof ConditionalContainer) {

                $field->fields->each(static function ($container) use ($field) {
                    $container->panel = $field->panel;
                });

                /*
                 * If instance of any associative type flatten out all the fields
                 */
                if ($controller instanceof AssociatableController ||
                    $controller instanceof AttachableController ||
                    $controller instanceof MorphableController ||
                    $controller instanceof ResourceAttachController ||
                    $controller instanceof FieldController) {

                    return $this->flattenDependencies($fakeRequest, $field->fields->toArray());

                }

                if ($controller instanceof ResourceUpdateController ||
                    $controller instanceof ResourceStoreController) {

                    return $this->flattenDependencies(
                        $fakeRequest, $field->resolveDependencyFieldUsingRequest($this, $fakeRequest)
                    );

                }

                return $this->flattenDependencies($fakeRequest, $field->resolveDependencyFieldUsingResource($resource));

            }

            if ($field instanceof MergeValue) {

                return $this->flattenDependencies($fakeRequest, $field->data);

            }

            return [ $field ];

        });

    }

    /**
     * Intended to minimize the incompatibility with third part package
     *
     * @param Field $field
     * @param NovaRequest $request
     */
    private function parseThirdPartyPackageFieldValue(Field $field, NovaRequest $request)
    {

        $value = $request->get($field->attribute);

        if ($field instanceof BelongsToManyField) {

            $request->offsetSet(
                $field->attribute, collect(json_decode($value, true))->map->id
            );

        }

        if ($field instanceof AttachMany) {

            $request->offsetSet(
                $field->attribute, collect(json_decode($value, true))
            );

        }

    }

    private function findAllActiveContainers(Collection $fields, $resource): Collection
    {
        return $this->findAllContainers($fields)
                    ->filter(static function ($container) use ($resource) {
                        return $container->runConditions(collect($resource->toArray()));
                    })
                    ->values();
    }

    private function findAllFlexibleContentFields($fields): Collection
    {
        return collect($fields)
            ->flatMap(function ($field) {

                if ($field instanceof Flexible) {

                    return $this->findAllFlexibleContentFields($field->meta[ 'layouts' ]->flatMap->fields())->concat([ $field ]);

                }

                if ($field instanceof MergeValue) {

                    return $this->findAllFlexibleContentFields($field->data);

                }

                if ($field instanceof ConditionalContainer) {

                    return $this->findAllFlexibleContentFields($field->fields);

                }

            })
            ->filter()
            ->each(static function (Flexible $flexible) {

                collect($flexible->meta[ 'layouts' ]->flatMap->fields())->each(static function ($field) {


                    if ($field instanceof ConditionalContainer) {

                        $field->withMeta([ '__uses_flexible_field__' => true ]);

                        $field->fields->each(static function ($field) {

                            if (method_exists($field, 'withMeta')) {

                                $field->withMeta([ '__has_flexible_field__' => true ]);

                            }

                        });

                    }

                });

            });
    }

    private function findAllContainers($fields): Collection
    {
        return collect($fields)
            ->flatMap(function ($field) {

                if ($field instanceof ConditionalContainer) {

                    return $this->findAllContainers($field->fields)->concat([ $field ]);

                }

                if ($field instanceof MergeValue) {

                    return $this->findAllContainers($field->data);

                }

                if ($field instanceof Flexible) {

                    return $this->findAllContainers(
                        $field->meta[ 'layouts' ]->flatMap->fields()
                    );

                }

            })
            ->filter()
            /**
             * Pass all meta to it's $fields
             */
            ->each(static function (ConditionalContainer $conditionalContainer) {

                $conditionalContainer->fields->each(static function ($field) use ($conditionalContainer) {

                    if (method_exists($field, 'withMeta')) {

                        $field->withMeta($conditionalContainer->meta());

                    }

                });

            });
    }

}
