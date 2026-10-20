import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import InvoicesView from '@/views/InvoicesView.vue'
import InvoiceDetailView from '@/views/InvoiceDetailView.vue'
import { invoicesApi } from '@/services/invoices'
import type { InvoiceDetail } from '@/types/invoice'

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '7' } }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('@/services/invoices', () => ({
  invoicesApi: { list: vi.fn<typeof invoicesApi.list>(), detail: vi.fn<typeof invoicesApi.detail>() },
  invoiceError: () => 'Could not load invoice.',
}))

const invoice: InvoiceDetail = {
  id: 7, invoice_number: 'AK-TEST', registration_id: 3, type: 'INITIAL', status: 'UNPAID',
  billing_year: 2026, currency: 'MYR', total: '70.00',
  issued_at: '2026-10-20T01:00:00Z', due_date: '2026-11-19',
  items: [
    { id: 1, fee_code: 'REGISTRATION', description: 'Registration fee', amount: '20.00' },
    { id: 2, fee_code: 'ANNUAL', description: 'Annual membership fee', amount: '50.00' },
  ],
}
beforeEach(() => vi.resetAllMocks())

describe('member invoice screens', () => {
  it('shows an empty state before invoices are issued', async () => {
    vi.mocked(invoicesApi.list).mockResolvedValue({ data: [], current_page: 1, last_page: 1, total: 0 })
    const wrapper = mount(InvoicesView)
    await flushPromises()
    expect(wrapper.text()).toContain('No invoices yet')
    expect(wrapper.text()).toContain('when your membership application is approved')
  })

  it('shows invoice totals and requests the next page', async () => {
    vi.mocked(invoicesApi.list).mockResolvedValue({ data: [invoice], current_page: 1, last_page: 2, total: 16 })
    const wrapper = mount(InvoicesView)
    await flushPromises()
    expect(wrapper.text()).toContain('AK-TEST')
    expect(wrapper.text()).toMatch(/RM\s*70\.00/)
    expect(wrapper.text()).toContain('19/11/2026')
    const next = wrapper.findAll('button').find((button) => button.text() === 'Next page')!
    await next.trigger('click')
    await flushPromises()
    expect(invoicesApi.list).toHaveBeenLastCalledWith(2)
  })

  it('displays the two historical items and total in invoice details', async () => {
    vi.mocked(invoicesApi.detail).mockResolvedValue(invoice)
    const wrapper = mount(InvoiceDetailView, { global: { stubs: { InvoicePayments: true } } })
    await flushPromises()
    expect(wrapper.text()).toContain('Registration fee')
    expect(wrapper.text()).toContain('Annual membership fee')
    expect(wrapper.text()).toMatch(/RM\s*20\.00/)
    expect(wrapper.text()).toMatch(/RM\s*50\.00/)
    expect(wrapper.text()).toMatch(/RM\s*70\.00/)
    expect(wrapper.text()).toContain('19/11/2026')
    expect(wrapper.text()).toContain('20/10/2026, 09:00 MYT')
    expect(wrapper.text()).toContain('View related application')
    expect(wrapper.text()).toContain('separate from the annual renewal deadline')
  })

  it('shows a retry action without invoice data when details fail', async () => {
    vi.mocked(invoicesApi.detail).mockRejectedValue(new Error('forbidden'))
    const wrapper = mount(InvoiceDetailView, { global: { stubs: { InvoicePayments: true } } })
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('Could not load invoice.')
    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.text()).toContain('Reload invoice')
  })

  it('displays cancelled invoices without an unpaid reminder', async () => {
    vi.mocked(invoicesApi.detail).mockResolvedValue({ ...invoice, status: 'CANCELLED' })
    const wrapper = mount(InvoiceDetailView, { global: { stubs: { InvoicePayments: true } } })
    await flushPromises()
    expect(wrapper.text()).toContain('This invoice has been cancelled.')
    expect(wrapper.text()).not.toContain('This invoice is unpaid.')
  })
})
