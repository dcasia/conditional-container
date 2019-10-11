<template>

    <div v-if="conditionSatisfied">

        <div v-for="childField in field.fields">

            <component
                :is="'form-' + childField.component"
                :errors="errors"
                :resource-id="resourceId"
                :resource-name="resourceName"
                :field="childField"/>

        </div>

    </div>

</template>

<script>
    import {FormField, HandlesValidationErrors} from 'laravel-nova'
    import logipar from 'logipar'

    export default {
        mixins: [FormField, HandlesValidationErrors],

        props: ['resourceName', 'resourceId', 'field'],

        data() {

            return {
                values: {},
                resolvers: this.field.expressions.map(expression => {

                    const parser = new logipar.Logipar()

                    parser.parse(expression)

                    return parser.filterFunction(this.relationalOperatorLeafResolver)

                }),
                conditionSatisfied: false,
                operators: [
                    '===', '==', '=',
                    '!==', '!=',
                    '>=', '<=', '<', '>',
                    'includes', 'contains',
                    'ends with', 'starts with', 'startsWith', 'endsWith',
                    'boolean ', 'truthy'
                ]
            }
        },

        created() {

            this.registerDependencyWatchers(this.$parent.$children)

        },

        computed: {
            watchableAttributes() {

                const attributes = []

                this.parser.walk(node => {

                    if (node.token.type === 'LITERAL') {

                        const [attribute] = this.splitLiteral(node.token.literal)

                        attributes.push(attribute)

                    }

                })

                return attributes

            }
        },

        methods: {

            relationalOperatorLeafResolver(values, literal) {

                const [attribute, operator, value] = this.splitLiteral(literal)

                if (values.hasOwnProperty(attribute)) {

                    return this.executeCondition(values[attribute], operator, value)

                }

                return false

            },

            registerDependencyWatchers(components) {

                components.forEach(component => {

                    const attribute = component.field.attribute

                    /**
                     * @todo walk the tokens tree in order to determine if a value should be watchable or not!!!
                     */
                    // if (this.watchableAttributes.includes(attribute)) {
                    if (this.field.expressions.join(',').includes(attribute)) {

                        const watchableAttribute = this.findWatchableComponentAttribute(component)

                        component.$watch(watchableAttribute, value => {

                            this.values[attribute] = value
                            this.conditionSatisfied = this.resolvers[this.field.operation](resolver => resolver(this.values))

                        }, {immediate: true})

                    }

                })

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

            findWatchableComponentAttribute(component) {

                switch (component.field.component) {

                    case 'belongs-to-field':
                        return 'selectedResource.value'

                    case 'morph-to-field':
                        return 'resourceType'

                    case 'file-field':
                        return 'file'

                    case 'key-value-field':
                        return 'finalPayload'

                    default:
                        return 'value'

                }

            },

            /*
             * Set the initial, internal value for the field.
             */
            setInitialValue() {
                this.value = null
            },

            /**
             * Fill the given FormData object with the field's internal value.
             */
            fill(formData) {

                if (this.conditionSatisfied) {

                    for (const field of this.field.fields) {

                        field.fill(formData)

                    }

                }

            },

            /**
             * Update the field's internal value.
             */
            handleChange(value) {
                this.value = value
            }
        }
    }
</script>
