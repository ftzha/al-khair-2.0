import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { Router } from 'vue-router'
import AppNavigation from '@/components/AppNavigation.vue'

const { auth, route, replace } = vi.hoisted(() => ({
  auth: {
    isAuthenticated: true,
    user: { full_name: 'Test Member', role: 'MEMBER' },
    logout: vi.fn<() => Promise<void>>(),
  },
  route: { path: '/applications/7', meta: { requiresAuth: true } },
  replace: vi.fn<Router['replace']>(),
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('vue-router', () => ({
  useRoute: () => route,
  useRouter: () => ({ replace }),
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
}))

beforeEach(() => {
  vi.resetAllMocks()
  auth.isAuthenticated = true
  auth.user.role = 'MEMBER'
  route.path = '/applications/7'
  route.meta.requiresAuth = true
  auth.logout.mockResolvedValue()
})

describe('portal navigation', () => {
  it('provides member destinations and logout on a detail page', () => {
    const wrapper = mount(AppNavigation)
    expect(wrapper.text()).toContain('My Applications')
    expect(wrapper.text()).toContain('My Invoices')
    expect(wrapper.text()).toContain('Logout')
    expect(wrapper.text()).not.toContain('Review Applications')
    expect(wrapper.get('[aria-current="page"]').text()).toBe('My Applications')
  })

  it.each(['COMMITTEE', 'ADMIN'])('shows review destinations for %s', (role) => {
    auth.user.role = role
    route.path = '/committee/payments/9'
    const wrapper = mount(AppNavigation)
    expect(wrapper.text()).toContain('Review Applications')
    expect(wrapper.get('[aria-current="page"]').text()).toBe('Verify Payments')
  })

  it('hides authenticated navigation on guest pages', () => {
    route.meta.requiresAuth = false
    expect(mount(AppNavigation).find('nav').exists()).toBe(false)
  })

  it('prevents duplicate logout requests and redirects to login', async () => {
    let finish!: () => void
    auth.logout.mockReturnValue(new Promise<void>((resolve) => { finish = resolve }))
    const wrapper = mount(AppNavigation)
    await wrapper.get('button').trigger('click')
    await wrapper.get('button').trigger('click')
    expect(auth.logout).toHaveBeenCalledTimes(1)
    finish()
    await flushPromises()
    expect(replace).toHaveBeenCalledWith({ name: 'login' })
  })

  it('keeps logout failure feedback visible after redirecting', async () => {
    auth.logout.mockRejectedValue(new Error('offline'))
    const wrapper = mount(AppNavigation)
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(replace).toHaveBeenCalledWith({ name: 'login' })
    expect(wrapper.get('[role="alert"]').text()).toContain('Your local session was cleared')
  })
})
