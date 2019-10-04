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
    import flatten from 'lodash.flatten'

    export default {
        mixins: [FormField, HandlesValidationErrors],

        props: ['resourceName', 'resourceId', 'field'],

        data() {
            return {
                values: {},
                conditionSatisfied: false
            }
        },

        created() {

            console.log('works')

            this.registerDependencyWatchers(this.$parent.$children)

        },

        computed: {
            // watchableAttributes() {
            //
            //     const conditionals = this.field.fields
            //         .filter(field => field.attribute.startsWith('conditional_container'))
            //         .map(field => flatten(field.conditions))
            //
            //     return flatten(conditionals).map(({attribute}) => attribute)
            //
            // },
            watchableAttributes() {

                return flatten(this.field.conditions).map(({attribute}) => attribute)

            }
        },

        methods: {

            registerDependencyWatchers(components) {

                components.forEach(component => {

                    const attribute = component.field.attribute

                    if (this.watchableAttributes.includes(attribute)) {

                        const watchableAttribute = this.findWatchableComponentAttribute(component)

                        component.$watch(watchableAttribute, value => {

                            this.values[attribute] = value

                            this.conditionSatisfied = this.valueMatchCondition()

                        }, {immediate: true})

                    }

                })

            },

            executeCondition(condition, value) {

                if (typeof condition.value === 'number') {

                    /**
                     * @todo handle float
                     */
                    value = parseInt(value)

                }

                switch (condition.operator) {

                    case '==':
                        return value == condition.value
                    case '===':
                        return value === condition.value
                    case '!=':
                        return value != condition.value
                    case '!==':
                        return value !== condition.value
                    case '>':
                        return value > condition.value
                    case '<':
                        return value < condition.value
                    case '>=':
                        return value >= condition.value
                    case '<=':
                        return value <= condition.value
                    default :
                        return false

                }

            },

            valueMatchCondition() {

                return this.field.conditions.some(condition => {

                    if (!Array.isArray(condition)) condition = [condition]

                    return condition.every(item =>
                        this.executeCondition(
                            item, this.values[item.attribute]
                        )
                    )

                })

            },

            findWatchableComponentAttribute(component) {

                switch (component.field.component) {

                    case 'belongs-to-field':
                        return 'selectedResource.value'

                    case 'morph-to-field':
                        return 'resourceType'

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
