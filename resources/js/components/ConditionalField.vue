<template>

    <div v-if="conditionSatisfied" class="conditional-container">

        <div v-for="(childField, index) in field.fields" :key="index">

            <component
                ref="fields"
                @hook:mounted="registerItSelf(index)"
                :is="'form-' + childField.component"
                :resource-name="resourceName"
                :resource-id="resourceId"
                :field="childField"
                :errors="errors"
                :related-resource-name="relatedResourceName"
                :related-resource-id="relatedResourceId"
                :via-resource="viaResource"
                :via-resource-id="viaResourceId"
                :via-relationship="viaRelationship"
                :show-help-text="childField.helpText != null"
            />

        </div>

    </div>

</template>

<script>

    import {FormField, HandlesValidationErrors} from 'laravel-nova'
    import logipar from 'logipar'

    const valueBag = {}

    export default {

        name: 'ConditionalContainer',

        mixins: [FormField, HandlesValidationErrors],

        props: [
            'field',
            'resourceId',
            'viaResource',
            'resourceName',
            'viaResourceId',
            'viaRelationship',
            'relatedResourceId',
            'relatedResourceName'
        ],

        data() {

            return {
                resolvers: this.field.expressions.map(expression => {
                    const parser = new logipar.Logipar()

                    parser.parse(expression)

                    return parser.filterFunction(this.relationalOperatorLeafResolver)
                }),
                conditionSatisfied: false,
                operators: [

                    '>=', '<=', '<', '>',
                    '!==', '!=',
                    '===', '==', '=',
                    'includes', 'contains',
                    'ends with', 'starts with', 'startsWith', 'endsWith',
                    'boolean ', 'truthy'
                ],
                fieldNames: []
            }

        },

        mounted() {

            const fieldNames = [];

            for(const expression of this.field.expressions) {

                const parser = new logipar.Logipar()

                parser.parse(expression)

                parser.walk((step) => {
                    if (step.token.literal) {
                        const [attribute, operator, value] = this.splitLiteral(step.token.literal)

                        if (!fieldNames.includes(attribute)) {
                            fieldNames.push(attribute)
                        }
                    }
                })

            }

            const onChange = (fieldName, value) => {

                valueBag[fieldName] = value;

                this.checkResolver()

            }

            this.fieldNames = fieldNames;

            for (const fieldName of fieldNames) {

                Nova.$on(`${fieldName}-change`, (value) => onChange(fieldName, value))
                Nova.$on(`${fieldName}-value`, (value) => onChange(fieldName, value))

                Nova.$emit(`${fieldName}-get-value`)

            }

        },

        beforeUnmount() {

            for (const fieldName of this.fieldNames) {

                Nova.$off(`${fieldName}-change`, (value) => onChange(fieldName, value))

            }

        },

        methods: {

            checkResolver() {

                this.conditionSatisfied = this.resolvers[this.field.operation](resolver => resolver(valueBag))

            },

            relationalOperatorLeafResolver(values, literal) {

                const [attribute, operator, value] = this.splitLiteral(literal)

                if (values.hasOwnProperty(attribute)) {

                    return this.executeCondition(values[attribute], operator, value)

                }

                return false

            },

            splitLiteral(literal) {

                const operator = this.operators.find(operator => literal.includes(` ${operator} `))

                if (!operator) {

                    throw 'Invalid operator! ' + literal

                }

                const chunks = literal.split(operator)

                return [
                    chunks.shift().trim(),
                    operator,
                    chunks.join(operator).trim()
                ]

            },

            executeCondition(attributeValue, operator, conditionValue) {

                conditionValue = conditionValue.replace(/^["'](.+)["']$/, '$1')

                if (['<', '>', '<=', '>='].includes(operator) && conditionValue) {

                    attributeValue = parseInt(attributeValue)
                    conditionValue = parseInt(conditionValue)

                    if (!isNaN(attributeValue)) {

                        attributeValue = parseInt(attributeValue)

                    }

                }

                if (!isNaN(conditionValue)) {

                    conditionValue = parseInt(conditionValue)

                }

                if (['true', 'false'].includes(conditionValue)) {

                    conditionValue = conditionValue === 'true'

                }

                switch (operator) {

                    case '=':
                    case '==':
                        return attributeValue == conditionValue
                    case '===':
                        return attributeValue === conditionValue
                    case '!=':
                        return attributeValue != conditionValue
                    case '!==':
                        return attributeValue !== conditionValue
                    case '>':
                        return attributeValue > conditionValue
                    case '<':
                        return attributeValue < conditionValue
                    case '>=':
                        return attributeValue >= conditionValue
                    case '<=':
                        return attributeValue <= conditionValue
                    case 'boolean':
                    case 'truthy':
                        return conditionValue ? !!attributeValue : !attributeValue
                    case 'includes':
                    case 'contains':
                        return attributeValue.includes(conditionValue)
                    case 'startsWith':
                    case 'starts with':
                        return attributeValue.startsWith(conditionValue)
                    case 'endsWith':
                    case 'ends with':
                        return attributeValue.endsWith(conditionValue)
                    default:
                        return false

                }

            },

        }
    }
</script>
