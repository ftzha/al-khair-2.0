import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { Router } from 'vue-router'
import MembershipSummary from '@/components/MembershipSummary.vue'
import { membershipApi } from '@/services/membership'
import type { MembershipOverview } from '@/types/membership'

const { push } = vi.hoisted(() => ({ push: vi.fn<Router['push']>() }))
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
}))
vi.mock('@/services/membership', () => ({
  membershipApi: { overview: vi.fn<typeof membershipApi.overview>(), createRenewal: vi.fn<typeof membershipApi.createRenewal>() },
  membershipError: () => 'Membership request failed.',
}))

function overview(): MembershipOverview {
  return {
    id: 3, member_number: 'AKM-TEST', status: 'ACTIVE',
    activated_on: '2026-01-01', waiting_until: '2026-04-01', waiting_days_remaining: 90,
    eligibility: { eligible: false, reason: 'WAITING_PERIOD', as_of: '2026-01-01T04:00:00Z' },
    current_period: { billing_year: 2026, starts_on: '2026-01-01', ends_on: '2026-12-31', effective_at: '2026-01-01T04:00:00Z' },
    renewal: { billing_year: 2026, cutoff_date: '2026-01-30', state: 'PAID', can_generate_invoice: false,
      invoice: { id: 2, invoice_number: 'AK-INITIAL', status: 'PAID', total: '70.00' } },
  }
}

beforeEach(() => {
  vi.resetAllMocks()
  vi.mocked(membershipApi.overview).mockResolvedValue(overview())
})

describe('membership dashboard summary', () => {
  it('shows Malaysian dates, member number and the server waiting-period explanation', async () => {
    const wrapper = mount(MembershipSummary)
    await flushPromises()
    expect(wrapper.text()).toContain('AKM-TEST')
    expect(wrapper.text()).toContain('01/04/2026')
    expect(wrapper.text()).toContain('90 days remaining')
    expect(wrapper.text()).toContain('Currently not eligible')
    expect(wrapper.text()).toContain('Your membership fees for this year have been verified.')
    expect(wrapper.text()).not.toContain('Create renewal invoice')
  })

  it('shows an empty membership state with application and invoice links', async () => {
    vi.mocked(membershipApi.overview).mockResolvedValue(null)
    const wrapper = mount(MembershipSummary)
    await flushPromises()
    expect(wrapper.text()).toContain('No membership record yet')
    expect(wrapper.text()).toContain('My Applications')
    expect(wrapper.text()).toContain('My Invoices')
  })

  it('shows a failed request and can refresh without claiming there is no membership', async () => {
    vi.mocked(membershipApi.overview).mockRejectedValueOnce(new Error('offline'))
    const wrapper = mount(MembershipSummary)
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('Membership request failed.')
    expect(wrapper.text()).not.toContain('No membership record yet')
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('AKM-TEST')
  })

  it('uses server eligibility instead of inferring it from active status', async () => {
    const data = overview()
    data.eligibility = { ...data.eligibility, eligible: true, reason: 'ELIGIBLE' }
    data.waiting_days_remaining = 0
    vi.mocked(membershipApi.overview).mockResolvedValue(data)
    const wrapper = mount(MembershipSummary)
    await flushPromises()
    expect(wrapper.text()).toContain('Currently eligible')
    expect(wrapper.text()).not.toContain('days remaining')
  })

  it('prevents duplicate renewal requests and opens the generated invoice', async () => {
    const data = overview()
    data.renewal = { ...data.renewal, state: 'AVAILABLE', invoice: null, can_generate_invoice: true }
    vi.mocked(membershipApi.overview).mockResolvedValue(data)
    let finish!: (value: { id: number; invoice_number: string }) => void
    vi.mocked(membershipApi.createRenewal).mockReturnValue(new Promise((resolve) => { finish = resolve }))
    const wrapper = mount(MembershipSummary)
    await flushPromises()
    const button = wrapper.findAll('button').find((item) => item.text().includes('Create renewal invoice'))!
    await button.trigger('click')
    await button.trigger('click')
    expect(membershipApi.createRenewal).toHaveBeenCalledTimes(1)
    finish({ id: 8, invoice_number: 'AK-RENEWAL' })
    await flushPromises()
    expect(push).toHaveBeenCalledWith({ name: 'invoice-detail', params: { id: 8 } })
  })

  it('refreshes after an uncertain creation failure and shows the existing invoice', async () => {
    const data = overview()
    data.renewal = { ...data.renewal, state: 'AVAILABLE', invoice: null, can_generate_invoice: true }
    vi.mocked(membershipApi.overview).mockResolvedValueOnce(data).mockResolvedValue(overview())
    vi.mocked(membershipApi.createRenewal).mockRejectedValue(new Error('offline'))
    const wrapper = mount(MembershipSummary)
    await flushPromises()
    await wrapper.findAll('button').find((item) => item.text().includes('Create renewal invoice'))!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Membership request failed.')
    expect(wrapper.text()).toContain('View invoice')
    expect(membershipApi.createRenewal).toHaveBeenCalledTimes(1)
    expect(push).not.toHaveBeenCalled()
  })

  it('explains timely pending verification after cutoff without offering another invoice', async () => {
    const data = overview()
    data.status = 'LAPSED'
    data.renewal.state = 'PENDING_VERIFICATION'
    vi.mocked(membershipApi.overview).mockResolvedValue(data)
    const wrapper = mount(MembershipSummary)
    await flushPromises()
    expect(wrapper.text()).toContain('Lapsed')
    expect(wrapper.text()).toContain('awaiting independent verification')
    expect(wrapper.text()).not.toContain('Create renewal invoice')
  })
})
