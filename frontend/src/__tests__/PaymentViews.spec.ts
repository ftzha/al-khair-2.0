import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import InvoicePayments from '@/views/InvoicePayments.vue'
import CommitteePaymentsView from '@/views/CommitteePaymentsView.vue'
import CommitteePaymentDetailView from '@/views/CommitteePaymentDetailView.vue'
import CashPaymentView from '@/views/CashPaymentView.vue'
import { paymentsApi } from '@/services/payments'
import type { PaymentDetail, PaymentRecord } from '@/types/payment'

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '9' } }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('@/services/payments', () => ({
  paymentsApi: {
    history: vi.fn<typeof paymentsApi.history>(), submitTransfer: vi.fn<typeof paymentsApi.submitTransfer>(),
    queue: vi.fn<typeof paymentsApi.queue>(), detail: vi.fn<typeof paymentsApi.detail>(),
    cashInvoices: vi.fn<typeof paymentsApi.cashInvoices>(), recordCash: vi.fn<typeof paymentsApi.recordCash>(),
    review: vi.fn<typeof paymentsApi.review>(), downloadReceipt: vi.fn<typeof paymentsApi.downloadReceipt>(),
  },
  paymentError: () => ({ message: 'Payment request failed.', fields: {} }),
}))
const pending: PaymentRecord = {
  id: 9, invoice_id: 7, method: 'BANK_TRANSFER', status: 'PENDING', amount: '70.00',
  reference: 'TRANSFER-TEST', has_receipt: true, submitted_at: '2026-10-20T01:00:00Z',
  reviewed_at: null, verified_at: null, rejection_reason: null,
}
const detail: PaymentDetail = {
  ...pending, submitted_by_name: 'Test Member', reviewed_by_name: null, can_review: true,
  invoice: { id: 7, invoice_number: 'AK-TEST', applicant_name: 'Test Member', total: '70.00', status: 'UNPAID', due_date: '2026-11-19', items: [{ description: 'Registration fee', amount: '20.00' }, { description: 'Annual membership fee', amount: '50.00' }] },
}
const props = { invoiceId: 7, total: '70.00', status: 'UNPAID' as const }
function buttonWithText(wrapper: ReturnType<typeof mount>, text: string) {
  const button = wrapper.findAll('button').find((item) => item.text() === text)
  if (!button) throw new Error(`Button not found: ${text}`)
  return button
}
async function fillTransfer(wrapper: ReturnType<typeof mount>, size = 7, type = 'application/pdf') {
  await wrapper.get('#transfer-reference').setValue('TRANSFER-TEST')
  const file = new File([new Uint8Array(size)], 'receipt.pdf', { type })
  Object.defineProperty(wrapper.get('#transfer-receipt').element, 'files', { value: [file], configurable: true })
  await wrapper.get('#transfer-receipt').trigger('change')
  return file
}
beforeEach(() => {
  vi.resetAllMocks()
  vi.mocked(paymentsApi.history).mockResolvedValue([])
  vi.mocked(paymentsApi.submitTransfer).mockResolvedValue({ ...pending })
  vi.mocked(paymentsApi.detail).mockResolvedValue(structuredClone(detail))
  vi.mocked(paymentsApi.review).mockResolvedValue({ ...pending, status: 'VERIFIED', verified_at: '2026-10-20T02:00:00Z', reviewed_at: '2026-10-20T02:00:00Z' })
  vi.mocked(paymentsApi.queue).mockResolvedValue({ data: [{ ...pending, invoice_number: 'AK-TEST', applicant_name: 'Test Member' }], current_page: 1, last_page: 1, total: 1 })
  vi.mocked(paymentsApi.cashInvoices).mockResolvedValue({ data: [{ id: 7, invoice_number: 'AK-TEST', applicant_name: 'Test Member', total: '70.00', due_date: '2026-11-19' }], current_page: 1, last_page: 1, total: 1 })
  vi.mocked(paymentsApi.recordCash).mockResolvedValue({ ...pending, method: 'CASH', has_receipt: false })
})

describe('member payments', () => {
  it('confirms one full transfer and prevents a second submission', async () => {
    const wrapper = mount(InvoicePayments, { props })
    await flushPromises()
    const file = await fillTransfer(wrapper)
    await wrapper.get('form').trigger('submit')
    expect(paymentsApi.submitTransfer).not.toHaveBeenCalled()
    const confirm = buttonWithText(wrapper, 'Confirm payment submission')
    await confirm.trigger('click')
    await confirm.trigger('click')
    await flushPromises()
    expect(paymentsApi.submitTransfer).toHaveBeenCalledExactlyOnceWith(7, '70.00', 'TRANSFER-TEST', file)
    expect(wrapper.text()).toContain('Payment submitted.')
    expect(wrapper.text()).toContain('Pending verification')
    expect(wrapper.find('form').exists()).toBe(false)
  })
  it('does not offer another payment while one is pending', async () => {
    vi.mocked(paymentsApi.history).mockResolvedValue([{ ...pending }])
    const wrapper = mount(InvoicePayments, { props })
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.text()).toContain('awaiting verification')
  })
  it('rejects an oversized receipt before sending it', async () => {
    const wrapper = mount(InvoicePayments, { props })
    await flushPromises()
    await fillTransfer(wrapper, 2097153)
    await wrapper.get('form').trigger('submit')
    expect(wrapper.text()).toContain('up to 2 MB')
    expect(paymentsApi.submitTransfer).not.toHaveBeenCalled()
  })
  it('blocks submission if history cannot be checked', async () => {
    vi.mocked(paymentsApi.history).mockRejectedValue(new Error('offline'))
    const wrapper = mount(InvoicePayments, { props })
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.text()).toContain('Payment request failed.')
  })
  it('refreshes an uncertain submission instead of automatically retrying', async () => {
    vi.mocked(paymentsApi.submitTransfer).mockRejectedValue(new Error('offline'))
    vi.mocked(paymentsApi.history).mockResolvedValueOnce([]).mockResolvedValueOnce([{ ...pending }])
    const wrapper = mount(InvoicePayments, { props })
    await flushPromises()
    await fillTransfer(wrapper)
    await wrapper.get('form').trigger('submit')
    await buttonWithText(wrapper, 'Confirm payment submission').trigger('click')
    await flushPromises()
    expect(paymentsApi.submitTransfer).toHaveBeenCalledTimes(1)
    expect(wrapper.find('form').exists()).toBe(false)
  })
  it('requests an invoice status refresh once a verified payment is seen', async () => {
    vi.mocked(paymentsApi.history).mockResolvedValue([{ ...pending, status: 'VERIFIED' }])
    const wrapper = mount(InvoicePayments, { props })
    await flushPromises()
    expect(wrapper.emitted('verified')).toHaveLength(1)
    expect(wrapper.find('form').exists()).toBe(false)
  })
  it('shows rejection reasons and permits a replacement for an unpaid initial invoice', async () => {
    vi.mocked(paymentsApi.history).mockResolvedValue([{ ...pending, status: 'REJECTED', rejection_reason: 'Transfer not received.' }])
    const wrapper = mount(InvoicePayments, { props })
    await flushPromises()
    expect(wrapper.text()).toContain('Transfer not received.')
    expect(wrapper.find('form').exists()).toBe(true)
  })
})

describe('committee payment screens', () => {
  it('filters the verification queue by status', async () => {
    const wrapper = mount(CommitteePaymentsView)
    await flushPromises()
    expect(wrapper.text()).toContain('Test Member')
    await wrapper.get('#payment-status').setValue('REJECTED')
    await flushPromises()
    expect(paymentsApi.queue).toHaveBeenLastCalledWith('REJECTED', 1)
  })
  it('confirms verification once and locks the review action', async () => {
    const wrapper = mount(CommitteePaymentDetailView)
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    const confirm = buttonWithText(wrapper, 'Confirm decision')
    await confirm.trigger('click')
    await confirm.trigger('click')
    await flushPromises()
    expect(paymentsApi.review).toHaveBeenCalledExactlyOnceWith(9, 'VERIFY', '')
    expect(wrapper.text()).toContain('The invoice is now paid.')
    expect(wrapper.find('form').exists()).toBe(false)
  })
  it('requires a reason for payment rejection', async () => {
    const wrapper = mount(CommitteePaymentDetailView)
    await flushPromises()
    await wrapper.get('#payment-decision').setValue('REJECT')
    await wrapper.get('form').trigger('submit')
    expect(wrapper.text()).toContain('A rejection reason is required.')
    expect(paymentsApi.review).not.toHaveBeenCalled()
  })
  it('does not offer a review for the submitter or invoice owner', async () => {
    vi.mocked(paymentsApi.detail).mockResolvedValue({ ...structuredClone(detail), can_review: false })
    const wrapper = mount(CommitteePaymentDetailView)
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.text()).toContain('A different authorized user')
  })
  it('confirms a cash record and leaves it awaiting independent verification', async () => {
    const wrapper = mount(CashPaymentView)
    await flushPromises()
    await buttonWithText(wrapper, 'Select invoice').trigger('click')
    await wrapper.get('#cash-reference').setValue('CASH-TEST')
    await wrapper.findAll('form')[1]!.trigger('submit')
    const confirm = buttonWithText(wrapper, 'Confirm cash payment')
    await confirm.trigger('click')
    await confirm.trigger('click')
    await flushPromises()
    expect(paymentsApi.recordCash).toHaveBeenCalledExactlyOnceWith(7, '70.00', 'CASH-TEST')
    expect(wrapper.text()).toContain('awaiting independent verification')
    expect(wrapper.find('#cash-reference').exists()).toBe(false)
  })
})
