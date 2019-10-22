<?php

namespace DigitalCreative\ConditionalContainer;

use Illuminate\Http\Resources\MergeValue;
use Illuminate\Support\Collection;
use Laravel\Nova\Fields\FieldCollection;
use Laravel\Nova\Http\Controllers\ActionController;
use Laravel\Nova\Http\Controllers\AssociatableController;
use Laravel\Nova\Http\Controllers\CreationFieldController;
use Laravel\Nova\Http\Controllers\FieldController;
use Laravel\Nova\Http\Controllers\MorphableController;
use Laravel\Nova\Http\Controllers\ResourceAttachController;
use Laravel\Nova\Http\Controllers\ResourceStoreController;
use Laravel\Nova\Http\Controllers\ResourceUpdateController;
use Laravel\Nova\Http\Controllers\UpdateFieldController;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

trait HasConditionalContainer
{

    /**
     * Get the panels that are available for the given detail request.
     *
     * @param \Laravel\Nova\Http\Requests\NovaRequest $request
     * @return \Illuminate\Support\Collection
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
     * @param \Laravel\Nova\Http\Requests\NovaRequest $request
     * @return \Illuminate\Support\Collection
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
     * @param \Laravel\Nova\Http\Requests\NovaRequest $request
     * @return \Illuminate\Support\Collection
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

        if ($controller instanceof CreationFieldController ||
            $controller instanceof UpdateFieldController) {

            $fields = parent::availableFields($request);
            $containers = $this->findAllContainers($fields);

            $cleanUpMethodName = $controller instanceof UpdateFieldController ?
                'removeNonUpdateFields' :
                'removeNonCreationFields';

            $expressionsMap = $containers->flatMap->expressions;

            /**
             * @var ConditionalContainer $container
             */
            foreach ($containers as $container) {

                $container->fields = $this->$cleanUpMethodName(
                    $request, collect($this->filter($container->fields->toArray()))
                )->values();

                /**
                 * Inject all the expressions from all the fields within all the containers, so
                 * each container has an overall knowledge of what fields it should build up the listeners
                 */
                $container->withMeta([ 'expressionsMap' => $expressionsMap ]);

            }

            return $fields;

        }

        /**
         * Whats is this controller for? seems to be executed when there is a BelongsToMany Field
         */
        if ($controller instanceof ActionController) {

            return parent::availableFields($request);

        }

        $fields = $this->flattenDependencies($request, $this->fields($request));

        return new FieldCollection(array_values($this->filter($fields->toArray())));

    }

    private function flattenDependencies(NovaRequest $request, array $fields)
    {

        $controller = $request->route()->controller;
        $fields = collect($fields);

        if ($fields->whereInstanceOf(ConditionalContainer::class)->isEmpty() &&
            $fields->whereInstanceOf(MergeValue::class)->isEmpty()) {

            return $fields;

        }

        return $fields->flatMap(function ($field) use ($fields, $request, $controller) {

            if ($field instanceof ConditionalContainer) {

                $field->fields->each(function ($container) use ($field) {
                    $container->panel = $field->panel;
                });

                /*
                 * If instance of any associative type flatten out all the fields
                 */
                if ($controller instanceof AssociatableController ||
//                    $controller instanceof AttachableController ||
                    $controller instanceof MorphableController ||
                    $controller instanceof ResourceAttachController ||
                    $controller instanceof FieldController) {

                    return $this->flattenDependencies($request, $field->fields->toArray());

                }

                if ($controller instanceof ResourceUpdateController ||
                    $controller instanceof ResourceStoreController) {

                    return $this->flattenDependencies($request, $field->resolveDependencyFieldUsingRequest($this, $request));

                }

                return $this->flattenDependencies($request, $field->resolveDependencyFieldUsingResource($this));

            }

            if ($field instanceof MergeValue) {

                return $this->flattenDependencies($request, $field->data);

            }

            return [ $field ];

        });

    }

    private function findAllActiveContainers(Collection $fields, $resource): Collection
    {
        return $this->findAllContainers($fields)
                    ->filter(function ($container) use ($resource) {
                        return $container->runConditions(collect($resource->toArray()));
                    })
                    ->values();
    }

    private function findAllContainers(Collection $fields): Collection
    {
        return $fields->flatMap(function ($field) {

            if ($field instanceof ConditionalContainer) {

                return $this->findAllContainers($field->fields)->concat([ $field ]);

            }

            if ($field instanceof MergeValue) {

                return $this->findAllContainers(collect($field->data));

            }

        })->filter();
    }

}
