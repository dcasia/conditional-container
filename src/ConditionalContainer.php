<?php

namespace DigitalCreative\ConditionalContainer;

use App\Nova\Resource;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use logipar\Logipar;

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
    public $expressions;

    /**
     * ConditionalContainer constructor.
     *
     * @param array $fields
     */
    public function __construct(array $fields)
    {

        parent::__construct('conditional_container_' . Str::random(10));

        $this->fields = collect($fields);
        $this->expressions = collect();

    }

    public function expression(string $expression): self
    {
        $this->expressions->push($expression);

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

    private function relationalOperatorLeafResolver(Collection $values, string $literal)
    {

        [ $attribute, $operator, $value ] = $this->splitLiteral($literal);

        if ($values->keys()->contains($attribute)) {

            return $this->executeConditionStatement($values->get($attribute), $operator, $value);

        }

        return false;

    }

    private function executeConditionStatement($attributeValue, string $operator, $conditionValue)
    {

        if (in_array($operator, [ '<', '>', '<=', '>=' ]) && $conditionValue) {

            $conditionValue = (int) $conditionValue;
            $attributeValue = (int) $attributeValue;

        }

        if (in_array($conditionValue, [ 'true', 'false' ])) {

            $conditionValue = $conditionValue === 'true';

        }

        switch ($operator) {

            case '=':
            case '==':
            case '===':
                return $attributeValue === $conditionValue;
            case '!=':
                return $attributeValue != $conditionValue;
            case '!==':
                return $attributeValue !== $conditionValue;
            case '>':
                return $attributeValue > $conditionValue;
            case '<':
                return $attributeValue < $conditionValue;
            case '>=':
                return $attributeValue >= $conditionValue;
            case '<=':
                return $attributeValue <= $conditionValue;
            case 'truthy':
                return $conditionValue ? !!$attributeValue : !$attributeValue;
            case 'includes':
            case 'contains':
                return Str::contains($attributeValue, $conditionValue);
            case 'starts with':
            case 'startsWith':
                return Str::startsWith($attributeValue, $conditionValue);
            case 'endsWith':
            case 'ends with':
                return Str::endsWith($attributeValue, $conditionValue);
            default :
                return false;

        }

    }

    private function splitLiteral(string $literal): array
    {

        $operators = collect([
            '===', '==', '=', '!==', '!=',
            '>=', '<=', '<', '>',
            'includes', 'contains', 'truthy',
            'ends with', 'starts with'
        ]);

        $operator = $operators
            ->filter(function ($operator) use ($literal) {
                return strpos($literal, $operator) !== false;
            })
            ->first();

        [ $attribute, $value ] = collect(explode($operator, $literal))->map(function ($value) {
            return trim($value);
        });

        return [
            $attribute,
            $operator,
            $value
        ];

    }

    private function runConditions(Collection $values): bool
    {
        return $this->expressions->some(function ($expression) use ($values) {

            $parser = new Logipar();
            $parser->parse(is_callable($expression) ? $expression($values) : $expression);

            $resolver = $parser->filterFunction(function (...$arguments) {
                return $this->relationalOperatorLeafResolver(...$arguments);
            });

            return $resolver($values);

        });
    }

    /**
     * @param NovaRequest $request
     *
     * @return array
     */
    public function resolveDependencyFieldUsingRequest(NovaRequest $request): array
    {

        $matched = $this->runConditions(collect($request->toArray()));

        return $matched ? $this->fields->toArray() : [];

    }

    /**
     * @param Resource $resource
     *
     * @return array
     */
    public function resolveDependencyFieldUsingResource(Resource $resource): array
    {

        $matched = $this->runConditions(collect($resource->toArray()));

        return $matched ? $this->fields->toArray() : [];

    }

    public function jsonSerialize()
    {
        return array_merge([
            'fields' => $this->fields,
            'expressions' => $this->expressions
        ], parent::jsonSerialize());
    }

}
