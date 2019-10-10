<?php

namespace DigitalCreative\ConditionalContainer;

use Illuminate\Support\Collection;
use Laravel\Nova\Fields\FieldCollection;
use Laravel\Nova\Http\Controllers\ActionController;
use Laravel\Nova\Http\Controllers\AssociatableController;
use Laravel\Nova\Http\Controllers\AttachableController;
use Laravel\Nova\Http\Controllers\CreationFieldController;
use Laravel\Nova\Http\Controllers\FieldController;
use Laravel\Nova\Http\Controllers\MorphableController;
use Laravel\Nova\Http\Controllers\ResourceAttachController;
use Laravel\Nova\Http\Controllers\ResourceStoreController;
use Laravel\Nova\Http\Controllers\ResourceUpdateController;
use Laravel\Nova\Http\Controllers\UpdateFieldController;
use Laravel\Nova\Http\Requests\NovaRequest;

trait HasConditionalContainer
{

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

            /**
             * @var ConditionalContainer $container
             */
            foreach ($containers as $container) {

                $container->fields = $this->$cleanUpMethodName($request, collect(
                    $this->filter($container->fields->toArray()))
                );

            }

            return $fields;

        }

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

        if ($fields->whereInstanceOf(ConditionalContainer::class)->isEmpty()) {

            return $fields;

        }

        return $fields->flatMap(function ($field) use ($fields, $request, $controller) {

            if ($field instanceof ConditionalContainer) {

                /*
                 * If instance of any associative type flatten out all the fields
                 */
                if ($controller instanceof AssociatableController ||
                    $controller instanceof AttachableController ||
                    $controller instanceof MorphableController ||
                    $controller instanceof ResourceAttachController ||
                    $controller instanceof FieldController) {

                    return $this->flattenDependencies($request, $field->fields->toArray());

                }

                if ($controller instanceof ResourceUpdateController ||
                    $controller instanceof ResourceStoreController) {

                    return $this->flattenDependencies($request, $field->resolveDependencyFieldUsingRequest($request));

                }

                return $this->flattenDependencies($request, $field->resolveDependencyFieldUsingResource($this));

            }

            return [ $field ];

        });

    }

    private function findAllContainers(Collection $fields): Collection
    {

        return $fields->flatMap(function ($field) {

            if ($field instanceof ConditionalContainer) {

                return $this->findAllContainers($field->fields)->concat([ $field ]);

            }

        })->filter();

    }

}
