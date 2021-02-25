<?php

namespace DigitalCreative\ConditionalContainer;

use DigitalCreative\JsonWrapper\JsonWrapper;
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
use Laravel\Nova\Resource;

trait HasConditionalContainer
{

    public function hasRelatableField(NovaRequest $request, $attribute)
    {
        return $this->availableFields($request)->whereInstanceOf(RelatableField::class);
    }

    /**
     * Get the panels that are available for the given detail request.
     *
     * @param NovaRequest $request
     * @return array
     */
    public function availablePanelsForDetail(NovaRequest $request, Resource $resource)
    {
        $panels = parent::availablePanelsForDetail($request, $resource);
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
    public function availablePanelsForUpdate(NovaRequest $request, Resource $resource = null)
    {
        $panels = parent::availablePanelsForUpdate($request, $resource);
        $fields = parent::availableFields($request);

        return $this->mergePanels($panels, $this->findAllContainers($fields));
    }

    private function mergePanels(array $panels, Collection $containers): array
    {
        return $containers
            ->flatMap(function ($container) {
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

            return parent::availableFields($request)->filter(function ($field) {

                return !($field instanceof ConditionalContainer);

            });

        }

        if ($controller instanceof CreationFieldController ||
            $controller instanceof UpdateFieldController) {

            $fields = parent::availableFields($request);
            $containers = $this->findAllContainers($fields);
            $expressionsMap = $containers->flatMap->expressions->map(function ($expression) {
                return is_callable($expression) ? $expression() : $expression;
            });

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

        $method = $this->fieldsMethod($request);
        $allFields = $this->{$method}($request);
        $containers = $this->findAllContainers($allFields);
        $expressionsMap = $containers->flatMap->expressions->map(function ($expression) {
            return is_callable($expression) ? $expression() : $expression;
        });

        $fields = $this->flattenDependencies(
            $request, $this->preloadRelationships($expressionsMap, $allFields)
        );

        return new FieldCollection(array_values($this->filter($fields->toArray())));

    }

    private function preloadRelationships(Collection $expressionsMap, $fields)
    {

        $relations = collect();

        foreach ($fields as $field) {

            if ($field instanceof RelatableField ||
                $field instanceof \NovaAttachMany\AttachMany ||
                $field instanceof \Benjacho\BelongsToManyField\BelongsToManyField) {

                $relations->push($field->attribute);

            }

        }

        $expressionsMap = $expressionsMap->map(function (string $expression) {
            return ConditionalContainer::splitLiteral($expression)[ 0 ];
        });

        /**
         * Only load the relations that are necessary
         */
        $relations = $relations->filter(function ($relation) use ($expressionsMap) {
            return $expressionsMap->contains($relation);
        });

        if ($relations->isNotEmpty()) {

            $this->loadMissing($relations);

        }

        return $fields;

    }

    private function flattenDependencies(NovaRequest $request, array $fields)
    {

        $controller = $request->route()->controller;
        $fields = collect($fields);

        if ($fields->whereInstanceOf(ConditionalContainer::class)->isEmpty() &&
            $fields->whereInstanceOf(MergeValue::class)->isEmpty()) {

            return $fields;

        }

        $fakeRequest = $request->duplicate();

        return $fields->flatMap(function ($field) use ($fields, $fakeRequest, $controller) {

            if ($field instanceof Field) {

                $this->parseThirdPartyPackageFieldValue($field, $fakeRequest);

            }

            if ($field instanceof ConditionalContainer) {

                $field->fields->each(function ($container) use ($field) {
                    $container->panel = $field->panel;
                });

                /*
                 * If instance of any associative type flatten out all the fields
                 */
                if (
                    $controller instanceof AssociatableController ||
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

                return $this->flattenDependencies($fakeRequest, $field->resolveDependencyFieldUsingResource($this));

            }

            if ($field instanceof MergeValue) {

                return $this->flattenDependencies($fakeRequest, $field->data);

            }

            if ($field instanceof JsonWrapper) {

                return $this->flattenDependencies($fakeRequest, $field->fields->toArray());

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

        if ($field instanceof \Benjacho\BelongsToManyField\BelongsToManyField) {

            $request->offsetSet(
                $field->attribute, collect(json_decode($value, true))->map->id
            );

        }

        if ($field instanceof \NovaAttachMany\AttachMany) {

            $request->offsetSet(
                $field->attribute, collect(json_decode($value, true))
            );

        }

    }

    private function findAllActiveContainers(Collection $fields, $resource): Collection
    {
        return $this->findAllContainers($fields)
                    ->filter(function ($container) use ($resource) {
                        return $container->runConditions(collect($resource->toArray()));
                    })
                    ->values();
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

                if ($field instanceof JsonWrapper) {

                    return $this->findAllContainers($field->fields);

                }

            })
            ->filter()
            /**
             * Pass all meta to it's $fields
             */
            ->each(function (ConditionalContainer $conditionalContainer) {

                $conditionalContainer->fields->each(function ($field) use ($conditionalContainer) {

                    if (method_exists($field, 'withMeta')) {

                        $field->withMeta($conditionalContainer->meta());

                    }

                });

            });
    }

}
