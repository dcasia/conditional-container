import CheckableValues from './mixins/CheckableValues'
import ConditionalField from './components/ConditionalField'

Nova.booting((Vue, router, store) => {

    Vue.component('form-conditional-container', ConditionalField)

})

Nova.booted((Vue, router, store) => {

    addMixinToFields()

})

function addMixinToFields() {

    const components = Nova.app._context.components

    if (components.FormSelectField) {

        Object.keys(components).forEach((componentName) => {

            if (componentName.startsWith('Form') || componentName.startsWith('form')) {

                const component = components[ componentName ]

                if (component.mixins) {

                    component.mixins.push(CheckableValues)

                } else {

                    component.mixins = [ CheckableValues ]

                }

            }

        })

    } else {

        setTimeout(addMixinToFields, 1)

    }

}

