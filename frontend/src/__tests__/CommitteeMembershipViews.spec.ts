import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import CommitteeMembershipsView from '@/views/CommitteeMembershipsView.vue'
import CommitteeMembershipDetailView from '@/views/CommitteeMembershipDetailView.vue'
import { committeeMembershipsApi } from '@/services/committeeMemberships'
import type { CommitteeMembershipDetail } from '@/types/committeeMembership'

const { auth, route } = vi.hoisted(() => ({
  auth: { user: { role: 'ADMIN' } },
  route: { params: { id: '7' } },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('vue-router', () => ({
  useRoute: () => route,
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
}))
vi.mock('@/services/committeeMemberships', () => ({
  committeeMembershipsApi: {
    list: vi.fn<typeof committeeMembershipsApi.list>(),
    detail: vi.fn<typeof committeeMembershipsApi.detail>(),
    close: vi.fn<typeof committeeMembershipsApi.close>(),
  },
}))
vi.mock('@/services/membership', () => ({ membershipError: () => 'Membership request failed.' }))

function detail(): CommitteeMembershipDetail {
  return {
    id: 7, full_name: 'Test Member', email: 'member@alkhair.test', member_number: 'AKM-TEST',
    status: 'ACTIVE', activated_on: '2026-01-01', waiting_until: '2026-04-01', waiting_days_remaining: 0,
    eligibility: { eligible: true, reason: 'ELIGIBLE', as_of: '2026-06-01T00:00:00Z' },
    current_period: { billing_year: 2026, starts_on: '2026-01-01', ends_on: '2026-12-31', effective_at: '2026-01-01T00:00:00Z' },
    renewal: { billing_year: 2026, cutoff_date: '2026-01-30', state: 'PAID', can_generate_invoice: false, invoice: null },
    pending_payment_count: 0, can_close: true,
    periods: [{ id: 1, billing_year: 2026, starts_on: '2026-01-01', ends_on: '2026-12-31', effective_at: '2026-01-01T00:00:00Z', payment_id: 9 }],
    events: [{ id: 1, type: 'ACTIVATED', effective_at: '2026-01-01T00:00:00Z', recorded_at: '2026-01-01T00:00:00Z', actor_name: 'Test Admin', reason: null }],
  }
}
function closed(): CommitteeMembershipDetail {
  const data = detail()
  return { ...data, status: 'CLOSED', can_close: false, eligibility: { ...data.eligibility, eligible: false, reason: 'CLOSED' } }
}
beforeEach(() => {
  vi.resetAllMocks()
  auth.user.role = 'ADMIN'
  vi.mocked(committeeMembershipsApi.detail).mockResolvedValue(detail())
  vi.mocked(committeeMembershipsApi.close).mockResolvedValue(closed())
  vi.mocked(committeeMembershipsApi.list).mockResolvedValue({ data: [], current_page: 1, last_page: 1, total: 0 })
})

describe('committee membership screens', () => {
  it('searches by member name or number and handles empty results', async () => {
    const wrapper = mount(CommitteeMembershipsView)
    await flushPromises()
    expect(wrapper.text()).toContain('No memberships match this search.')
    await wrapper.get('#member-search').setValue(' AKM-TEST ')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(committeeMembershipsApi.list).toHaveBeenLastCalledWith('AKM-TEST', 1)
  })

  it('gives committee users read-only access even if a response offers closure', async () => {
    auth.user.role = 'COMMITTEE'
    const wrapper = mount(CommitteeMembershipDetailView)
    await flushPromises()
    expect(wrapper.text()).toContain('Membership history')
    expect(wrapper.text()).toContain('ADMIN users only')
    expect(wrapper.find('form').exists()).toBe(false)
    expect(committeeMembershipsApi.close).not.toHaveBeenCalled()
  })

  it('requires a reason and confirmation before closing', async () => {
    const wrapper = mount(CommitteeMembershipDetailView)
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    expect(wrapper.text()).toContain('Enter a closure reason.')
    expect(committeeMembershipsApi.close).not.toHaveBeenCalled()
    await wrapper.get('#closure-reason').setValue('Member request.')
    await wrapper.get('form').trigger('submit')
    expect(wrapper.text()).toContain('permanently?')
    expect(committeeMembershipsApi.close).not.toHaveBeenCalled()
    await wrapper.findAll('button').find((button) => button.text() === 'Confirm permanent closure')!.trigger('click')
    await flushPromises()
    expect(committeeMembershipsApi.close).toHaveBeenCalledExactlyOnceWith(7, 'Member request.')
    expect(wrapper.text()).toContain('permanently closed')
    expect(wrapper.find('textarea').exists()).toBe(false)
  })

  it('blocks the form while payments are pending', async () => {
    vi.mocked(committeeMembershipsApi.detail).mockResolvedValue({ ...detail(), can_close: false, pending_payment_count: 1 })
    const wrapper = mount(CommitteeMembershipDetailView)
    await flushPromises()
    expect(wrapper.text()).toContain('Resolve 1 pending payment')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('prevents repeated confirmation requests', async () => {
    let finish!: (value: CommitteeMembershipDetail) => void
    vi.mocked(committeeMembershipsApi.close).mockReturnValue(new Promise<CommitteeMembershipDetail>((resolve) => { finish = resolve }))
    const wrapper = mount(CommitteeMembershipDetailView)
    await flushPromises()
    await wrapper.get('#closure-reason').setValue('Member request.')
    await wrapper.get('form').trigger('submit')
    const confirm = wrapper.findAll('button').find((button) => button.text() === 'Confirm permanent closure')!
    await confirm.trigger('click')
    await confirm.trigger('click')
    expect(committeeMembershipsApi.close).toHaveBeenCalledTimes(1)
    finish(closed())
    await flushPromises()
    expect(wrapper.text()).toContain('permanently closed')
  })

  it('refreshes authoritative state after uncertain closure failure without retrying', async () => {
    vi.mocked(committeeMembershipsApi.detail).mockResolvedValueOnce(detail()).mockResolvedValue(closed())
    vi.mocked(committeeMembershipsApi.close).mockRejectedValue(new Error('offline'))
    const wrapper = mount(CommitteeMembershipDetailView)
    await flushPromises()
    await wrapper.get('#closure-reason').setValue('Member request.')
    await wrapper.get('form').trigger('submit')
    await wrapper.findAll('button').find((button) => button.text() === 'Confirm permanent closure')!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Membership request failed.')
    expect(wrapper.text()).toContain('permanently closed')
    expect(committeeMembershipsApi.close).toHaveBeenCalledTimes(1)
  })

  it('renders closure reasons as text rather than executable markup', async () => {
    const data = closed()
    data.events.push({ id: 2, type: 'CLOSED', effective_at: '2026-06-01T00:00:00Z', recorded_at: '2026-06-01T00:00:00Z', actor_name: 'Admin', reason: '<img src=x onerror=alert(1)>' })
    vi.mocked(committeeMembershipsApi.detail).mockResolvedValue(data)
    const wrapper = mount(CommitteeMembershipDetailView)
    await flushPromises()
    expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>')
    expect(wrapper.find('img').exists()).toBe(false)
  })
})
