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
                ]
            }
        },

        mounted() {

            this.deepSearch(this.$root.$children)

            this.$root.$on('update-conditional-container', this.checkResolver)
            this.$once('hook:beforeDestroy', () => {
                this.$root.$off('update-conditional-container', this.checkResolver)
            })

            this.checkResolver()

        },

        computed: {
            watchableAttributes() {

                return this.field.expressionsMap.join()

            }
            // watchableAttributes() {
            //
            //     const attributes = []
            //
            //     this.parser.walk(node => {
            //
            //         if (node.token.type === 'LITERAL') {
            //
            //             const [attribute] = this.splitLiteral(node.token.literal)
            //
            //             attributes.push(attribute)
            //
            //         }
            //
            //     })
            //
            //     return attributes
            //
            // }
        },

        methods: {

            deepSearch(children) {

                if (children) {

                    for (const child of children) {

                        if (child.field && child.field.component !== 'conditional-container') {

                            this.registerDependencyWatchers(child)

                        }

                        this.deepSearch(child.$children)

                    }

                }

            },

            checkResolver() {

                this.conditionSatisfied = this.resolvers[this.field.operation](resolver => resolver(valueBag))

            },

            registerItSelf(index) {

                this.registerDependencyWatchers(this.$refs.fields[index])

            },

            relationalOperatorLeafResolver(values, literal) {

                const [attribute, operator, value] = this.splitLiteral(literal)

                if (values.hasOwnProperty(attribute)) {

                    return this.executeCondition(values[attribute], operator, value)

                }

                return false

            },

            registerDependencyWatchers(component) {

                const attribute = component.field.attribute

                /**
                 * @todo walk the tokens tree in order to determine if a value should be watchable or not!!!
                 */
                if (this.watchableAttributes.includes(attribute) && !valueBag.hasOwnProperty(attribute)) {

                    const watchableAttribute = this.findWatchableComponentAttribute(component)

                    /**
                     * Initialize bag with initial value
                     */
                    this.setBagValue(component, attribute, component[watchableAttribute])

                    component.$once('hook:beforeDestroy', () => this.deleteBagAttribute(attribute))
                    component.$watch(watchableAttribute, value => this.setBagValue(component, attribute, value))

                }

            },

            setBagValue(component, attribute, value) {

                valueBag[attribute] = this.parseComponentValue(component, value)

                this.$root.$emit('update-conditional-container')

            },

            deleteBagAttribute(attribute) {

                delete valueBag[attribute]

                this.$root.$emit('update-conditional-container')

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

            parseComponentValue(component, value) {

                switch (component.field.component) {

                    case 'nova-attach-many':
                        return JSON.parse(value || '[]')

                    case 'BelongsToManyField':
                        return (value || []).map(({id}) => id)

                }

                return value

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
