import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import CommitteeApplicationsView from '@/views/CommitteeApplicationsView.vue'
import CommitteeApplicationDetailView from '@/views/CommitteeApplicationDetailView.vue'
import { committeeRegistrationsApi } from '@/services/committeeRegistrations'
import type { CommitteeApplicationDetail } from '@/types/committee'

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '7' } }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('@/services/committeeRegistrations', () => ({
  committeeRegistrationsApi: {
    list: vi.fn<typeof committeeRegistrationsApi.list>(),
    detail: vi.fn<typeof committeeRegistrationsApi.detail>(),
    review: vi.fn<typeof committeeRegistrationsApi.review>(),
  },
}))

function detail(status: CommitteeApplicationDetail['status'] = 'PENDING_REVIEW'): CommitteeApplicationDetail {
  return {
    id: 7, status, applicant_name: 'Test Applicant',
    submissions: [{
      id: 11, revision: 1, submitted_at: '2026-10-20T00:00:00Z',
      snapshot: { full_name: 'Test Applicant', date_of_birth: '1990-01-01', phone_number: null, address: 'Submitted address' },
      reviews: [],
    }],
  }
}

function buttonWithText(wrapper: ReturnType<typeof mount>, text: string) {
  const button = wrapper.findAll('button').find((item) => item.text() === text)
  if (!button) throw new Error(`Button not found: ${text}`)
  return button
}

beforeEach(() => {
  vi.resetAllMocks()
  vi.mocked(committeeRegistrationsApi.detail).mockResolvedValue(detail())
  vi.mocked(committeeRegistrationsApi.review).mockResolvedValue({ id: 9, decision: 'APPROVE', reason: null, reviewed_at: '2026-10-20T01:00:00Z' })
})

describe('committee review screens', () => {
  it('shows an empty queue', async () => {
    vi.mocked(committeeRegistrationsApi.list).mockResolvedValue({ data: [], current_page: 1, last_page: 1, total: 0 })
    const wrapper = mount(CommitteeApplicationsView)
    await flushPromises()
    expect(wrapper.text()).toContain('No applications are awaiting review.')
  })

  it('loads the next page of pending applicants', async () => {
    vi.mocked(committeeRegistrationsApi.list).mockResolvedValue({
      data: [{ id: 7, status: 'PENDING_REVIEW', type: 'NEW', applicant_name: 'Test Applicant', created_at: '2026-10-20T00:00:00Z' }],
      current_page: 1, last_page: 2, total: 16,
    })
    const wrapper = mount(CommitteeApplicationsView)
    await flushPromises()
    expect(wrapper.text()).toContain('Test Applicant')
    await buttonWithText(wrapper, 'Next page').trigger('click')
    await flushPromises()
    expect(committeeRegistrationsApi.list).toHaveBeenLastCalledWith(2)
  })

  it.each(['REJECT', 'REQUEST_CHANGES'] as const)('requires a reason for %s', async (decision) => {
    const wrapper = mount(CommitteeApplicationDetailView)
    await flushPromises()
    await wrapper.get('#review-decision').setValue(decision)
    await wrapper.get('#review-reason').setValue('   ')
    await wrapper.get('form').trigger('submit')
    expect(wrapper.text()).toContain('A reason is required for this decision.')
    expect(committeeRegistrationsApi.review).not.toHaveBeenCalled()
  })

  it.each(['APPROVE', 'REJECT', 'REQUEST_CHANGES'] as const)('confirms and records %s once', async (decision) => {
    vi.mocked(committeeRegistrationsApi.detail).mockResolvedValueOnce(detail()).mockResolvedValueOnce(detail('APPROVED'))
    const wrapper = mount(CommitteeApplicationDetailView)
    await flushPromises()
    expect(wrapper.text()).toContain('Submitted address')
    await wrapper.get('#review-decision').setValue(decision)
    if (decision !== 'APPROVE') await wrapper.get('#review-reason').setValue(' Please update the address. ')
    await wrapper.get('form').trigger('submit')
    expect(committeeRegistrationsApi.review).not.toHaveBeenCalled()
    const confirm = buttonWithText(wrapper, 'Confirm decision')
    await confirm.trigger('click')
    await confirm.trigger('click')
    await flushPromises()
    expect(committeeRegistrationsApi.review).toHaveBeenCalledExactlyOnceWith(7, {
      decision, reason: decision === 'APPROVE' ? null : 'Please update the address.',
    })
    expect(wrapper.text()).toContain('Decision recorded:')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('shows earlier decisions and reasons', async () => {
    const data = detail('CHANGES_REQUESTED')
    data.submissions[0]!.reviews = [{ decision: 'REQUEST_CHANGES', reason: 'Please complete your address.', reviewed_at: '2026-10-20T01:00:00Z' }]
    vi.mocked(committeeRegistrationsApi.detail).mockResolvedValue(data)
    const wrapper = mount(CommitteeApplicationDetailView)
    await flushPromises()
    expect(wrapper.text()).toContain('Please complete your address.')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('disables review after a server-side self-review refusal', async () => {
    vi.mocked(committeeRegistrationsApi.review).mockRejectedValue({ isAxiosError: true, response: { status: 403 } })
    const wrapper = mount(CommitteeApplicationDetailView)
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    await buttonWithText(wrapper, 'Confirm decision').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('You do not have permission')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('keeps a successful decision locked when history refresh fails', async () => {
    vi.mocked(committeeRegistrationsApi.detail).mockResolvedValueOnce(detail()).mockRejectedValueOnce(new Error('offline'))
    const wrapper = mount(CommitteeApplicationDetailView)
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    await buttonWithText(wrapper, 'Confirm decision').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Decision recorded: Approved.')
    expect(wrapper.text()).toContain('history could not be refreshed')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('reloads server state after a failed review instead of retrying', async () => {
    vi.mocked(committeeRegistrationsApi.review).mockRejectedValue(new Error('Connection lost'))
    vi.mocked(committeeRegistrationsApi.detail).mockResolvedValueOnce(detail()).mockResolvedValueOnce(detail('REJECTED'))
    const wrapper = mount(CommitteeApplicationDetailView)
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    await buttonWithText(wrapper, 'Confirm decision').trigger('click')
    await flushPromises()
    expect(committeeRegistrationsApi.review).toHaveBeenCalledTimes(1)
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.text()).toContain('Status: Rejected')
  })
})
