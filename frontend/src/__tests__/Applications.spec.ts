import type { Router } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ApplicationsView from '@/views/ApplicationsView.vue'
import ApplicationDetailView from '@/views/ApplicationDetailView.vue'
import { registrationsApi } from '@/services/registrations'
import { canEditRegistration } from '@/types/registration'

const { push, route } = vi.hoisted(() => ({ push: vi.fn<Router['push']>(), route: { params: { id: '7' } } }))
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
  useRoute: () => route,
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { full_name: 'Test Applicant' } }) }))
vi.mock('@/services/registrations', () => ({
  registrationsApi: { submit: vi.fn<typeof registrationsApi.submit>(), list: vi.fn<typeof registrationsApi.list>(), create: vi.fn<typeof registrationsApi.create>(), detail: vi.fn<typeof registrationsApi.detail>(), update: vi.fn<typeof registrationsApi.update>() },
  registrationError: () => ({ message: 'Request failed', fields: {} }),
}))

function detail(status: 'DRAFT' | 'PENDING_REVIEW' | 'CHANGES_REQUESTED' = 'DRAFT') {
  return {
    id: 7, type: 'NEW', status,
    draft_data: { phone_number: '0123456789', address: 'Existing address' },
    applicant: { full_name: 'Test Applicant', date_of_birth: '1990-01-01' },
    submissions: [], created_at: '2026-10-20T00:00:00Z',
  }
}

beforeEach(() => { vi.resetAllMocks() })

describe('member applications', () => {
  it('shows an empty state and opens a newly created draft', async () => {
    vi.mocked(registrationsApi.list).mockResolvedValue([])
    vi.mocked(registrationsApi.create).mockResolvedValue({ id: 7, status: 'DRAFT', draft_data: null })
    const wrapper = mount(ApplicationsView)
    await flushPromises()
    expect(wrapper.text()).toContain('You have no membership applications yet.')
    await wrapper.findAll('button').find((button) => button.text() === 'Create application draft')!.trigger('click')
    await flushPromises()
    expect(registrationsApi.create).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledWith({ name: 'application-detail', params: { id: 7 } })
  })

  it('does not offer another draft when an approved application exists', async () => {
    vi.mocked(registrationsApi.list).mockResolvedValue([{ ...detail(), status: 'APPROVED', updated_at: '2026-10-20T00:00:00Z' }])
    const wrapper = mount(ApplicationsView)
    await flushPromises()
    expect(wrapper.text()).toContain('Approved')
    const create = wrapper.findAll('button').find((button) => button.text() === 'Create application draft')!
    expect(create.attributes('disabled')).toBeDefined()
  })

  it('reports a failed list request with a retry action', async () => {
    vi.mocked(registrationsApi.list).mockRejectedValue(new Error('offline'))
    const wrapper = mount(ApplicationsView)
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('Request failed')
    expect(wrapper.text()).toContain('Refresh applications')
  })

  it('saves only editable contact fields and leaves identity read-only', async () => {
    vi.mocked(registrationsApi.detail).mockResolvedValue(detail())
    vi.mocked(registrationsApi.update).mockResolvedValue({ id: 7, status: 'DRAFT', draft_data: detail().draft_data })
    const wrapper = mount(ApplicationDetailView)
    await flushPromises()
    expect(wrapper.text()).toContain('1990-01-01')
    await wrapper.get('#address').setValue('Updated address')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(registrationsApi.update).toHaveBeenCalledWith(7, { phone_number: '0123456789', address: 'Updated address' })
    expect(wrapper.text()).toContain('Application draft saved.')
    expect(wrapper.findAll('input')).toHaveLength(1)
  })

  it('blocks edits to pending applications', async () => {
    vi.mocked(registrationsApi.detail).mockResolvedValue(detail('PENDING_REVIEW'))
    const wrapper = mount(ApplicationDetailView)
    await flushPromises()
    expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined()
    await wrapper.get('form').trigger('submit')
    expect(registrationsApi.update).not.toHaveBeenCalled()
  })

  it('allows contact corrections after changes are requested', () => {
    expect(canEditRegistration('CHANGES_REQUESTED')).toBe(true)
    expect(canEditRegistration('DRAFT')).toBe(true)
    for (const status of ['PENDING_REVIEW', 'APPROVED', 'REJECTED'] as const) {
      expect(canEditRegistration(status)).toBe(false)
    }
  })
})
