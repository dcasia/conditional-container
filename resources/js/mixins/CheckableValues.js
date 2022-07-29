export default {

    mounted() {

        if (this.field) {

            Nova.$on(`${this.field.attribute}-get-value`, () => {

                Nova.$emit(`${this.field.attribute}-value`, this.checkableValuesGetValue())

            })

            if (this.hasFormUniqueId === true) {

                Nova.$on(`${this.formUniqueId}-${this.field.attribute}-get-value`, () => {

                    Nova.$emit(`${this.field.attribute}-value`, this.checkableValuesGetValue())

                })

            }

        }

    },

    methods: {

        checkableValuesGetValue() {

            switch (this.field.component) {
                case 'belongs-to-field':
                    return this.selectedResourceId
                case 'morph-to-field':
                    return this.selectedResourceId
                case 'file-field':
                    return this.fileName
                case 'key-value-field':
                    return this.finalPayload
                case 'dynamic-select':
                    return this.value.value
                case 'nova-attach-many':
                    return JSON.parse(value || '[]')
                case 'BelongsToManyField':
                    return (value || []).map(({id}) => id)
                default:
                    return this.value
            }

        }

    },

    watch: {

        file() {

            this.emitFieldValueChange(this.field.attribute, this.fileName)

        }

    }
}
