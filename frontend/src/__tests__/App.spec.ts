import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import App from '../App.vue'

describe('App', () => {
  it('renders the active route through the router outlet', () => {
    const wrapper = mount(App, {
      global: { stubs: { AppNavigation: true, RouterView: { template: '<main>Active route</main>' } } },
    })
    expect(wrapper.text()).toContain('Active route')
  })
})
