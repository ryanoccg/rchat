import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

// Simple example component test
describe('Component Tests', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
    })

    describe('Example Component', () => {
        it('should mount a simple component', () => {
            const TestComponent = {
                template: '<div class="test">{{ message }}</div>',
                data() {
                    return { message: 'Hello World' }
                }
            }

            const wrapper = mount(TestComponent)

            expect(wrapper.text()).toContain('Hello World')
            expect(wrapper.find('.test').exists()).toBe(true)
        })

        it('should handle click events', async () => {
            const onClick = vi.fn()
            const TestComponent = {
                template: '<button @click="handleClick">Click me</button>',
                methods: {
                    handleClick: onClick
                }
            }

            const wrapper = mount(TestComponent)
            await wrapper.find('button').trigger('click')

            expect(onClick).toHaveBeenCalled()
        })

        it('should handle props', () => {
            const TestComponent = {
                template: '<span>{{ title }}</span>',
                props: ['title']
            }

            const wrapper = mount(TestComponent, {
                props: { title: 'Test Title' }
            })

            expect(wrapper.text()).toBe('Test Title')
        })

        it('should handle v-model', async () => {
            const TestComponent = {
                template: '<input v-model="text" />',
                data() {
                    return { text: '' }
                }
            }

            const wrapper = mount(TestComponent)
            await wrapper.find('input').setValue('Hello')

            expect(wrapper.vm.text).toBe('Hello')
        })

        it('should handle emitted events', async () => {
            const TestComponent = {
                template: '<button @click="$emit(\'custom\', \'payload\')">Emit</button>',
                emits: ['custom']
            }

            const wrapper = mount(TestComponent)
            await wrapper.find('button').trigger('click')

            expect(wrapper.emitted()).toHaveProperty('custom')
            expect(wrapper.emitted().custom[0]).toEqual(['payload'])
        })
    })
})
