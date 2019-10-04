<?php

namespace DigitalCreative\ConditionalContainer;

use App\Nova\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo as MorphToRelation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\MorphTo as MorphToField;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;

class ConditionalContainer extends Field
{
    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'conditional-container';

    /**
     * @var Collection
     */
    public $fields;

    /**
     * @var Collection
     */
    public $conditions;

    public function __construct(array $fields)
    {

        parent::__construct('conditional_container_' . Str::random(10));

        $this->fields = collect($fields);
        $this->conditions = collect();

        $this->withMeta([
            'conditions' => $this->conditions,
            'fields' => $this->fields
        ]);

    }

    private function parseIf(string $attribute, $operator, $value = null)
    {

        if (func_num_args() === 2) {

            $value = $operator;
            $operator = '===';

        };

        /**
         * If its a subclass of Resource assume user is trying to match against a morphTo relationship
         */
        if (is_string($value) && class_exists($value) && is_subclass_of($value, Resource::class)) {

            $value = $value::uriKey();

        }

        if (is_callable($value)) {

            $value = call_user_func($value);

        }

        return [
            'attribute' => $attribute,
            'operator' => $operator,
            'value' => $value
        ];

    }

    public function if($attribute, $operator, $value = null)
    {

        if (is_array($attribute)) {

            $conditions = collect();

            foreach (func_get_args() as $condition) {

                $conditions->push($this->parseIf(...$condition));

            }

            $this->conditions->push($conditions);

        } else {

            $this->conditions->push($this->parseIf(...func_get_args()));

        }

        return $this;

    }

    /**
     * Resolve the field's value.
     *
     * @param mixed $resource
     * @param string|null $attribute
     *
     * @return void
     */
    public function resolve($resource, $attribute = null)
    {

        /**
         * @var Field $field
         */
        foreach ($this->fields as $field) {

            $field->resolve($resource, $field->attribute);

        }

    }

    public function fill(NovaRequest $request, $model)
    {

        /**
         * @var Field $field
         */
        foreach ($this->fields as $field) {

            $field->fill($request, $model);

        }

    }

    private function getConditionalAttributes(): Collection
    {

        return $this->conditions
            ->flatMap(function ($field) {
                return is_array($field) ? [ $field ] : $field->toArray();
            })
            ->pluck('attribute')
            ->unique();

    }

    private function wrapValuesIntoArrays(array $values, Resource $resource, Collection $fields, NovaRequest $request): Collection
    {

        return collect($values)->map(function ($value, $attribute) use ($resource, $fields, $request) {

            /**
             * @var Field $field
             */
            $field = $fields->firstWhere('attribute', $attribute);

            if ($field instanceof MorphToField) {

                /**
                 * If it's a morphTo relation match either against its ID or MorphMap type
                 *
                 * @var MorphToRelation $relation
                 */
                if (($relation = $resource->$attribute()) instanceof MorphToRelation) {

                    /**
                     * On update the value will be the actual relation stance, so cast it back to primitive values
                     */
                    if ($value instanceof Model) {

                        return [ $value->id, Nova::resourceForModel($value)::uriKey() ];

                    }

                    return [ $value, $request->get($relation->getMorphType()) ];

                }

            }

            return [ $value ];

        });

    }

    private function runConditions(Collection $values): bool
    {

        return $this->conditions->some(function ($value) use ($values) {

            if (is_array($value)) {

                $value = collect([ $value ]);

            }

            /**
             * Skip if values doesnt include data for the given condition
             */
            if ($value->pluck('attribute')->intersect($values->keys())->isEmpty()) {

                return false;

            }

            return $value->every(function ($condition) use ($values) {

                return collect($values[ $condition[ 'attribute' ] ])->some(function ($value) use ($condition) {

                    return $this->executeCondition($condition, $value);

                });

            });

        });

    }

    private function executeCondition(array $condition, $value)
    {

        if (is_numeric($condition[ 'value' ])) $value = (int) $value;
        if (is_bool($condition[ 'value' ])) $value = (bool) $value;

        switch ($condition[ 'operator' ]) {

            case '==':
                return $value == $condition[ 'value' ];
            case '===':
                return $value === $condition[ 'value' ];
            case '!=':
                return $value != $condition[ 'value' ];
            case '!==':
                return $value !== $condition[ 'value' ];
            case '>':
                return $value > $condition[ 'value' ];
            case '<':
                return $value < $condition[ 'value' ];
            case '>=':
                return $value >= $condition[ 'value' ];
            case '<=':
                return $value <= $condition[ 'value' ];
            default :
                return false;

        }

    }

    public function resolveDependencyFieldUsingRequest(Resource $resource, Collection $fields, NovaRequest $request): array
    {

        $values = $request->only($this->getConditionalAttributes()->toArray());

        $matched = $this->runConditions(
            $this->wrapValuesIntoArrays($values, $resource, $fields, $request)
        );

        return $matched ? $this->fields->toArray() : [];

    }

    /**
     * @param Resource $resource
     * @param Collection $fields
     * @param NovaRequest $request
     *
     * @return array
     */
    public function resolveDependencyFieldUsingResource(Resource $resource, Collection $fields, NovaRequest $request): array
    {

        $values = $resource->only($this->getConditionalAttributes()->toArray());

        $matched = $this->runConditions(
            $this->wrapValuesIntoArrays($values, $resource, $fields, $request)
        );

        return $matched ? $this->fields->toArray() : [];

    }

}
