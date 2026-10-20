import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/services/api'
import { paymentsApi, receiptDownloadFilename } from '@/services/payments'

vi.mock('@/services/api', () => ({ api: { get: vi.fn<typeof api.get>(), post: vi.fn<typeof api.post>() } }))
beforeEach(() => vi.resetAllMocks())

describe('payment API', () => {
  it('uses the server filename and falls back for older responses', () => {
    expect(receiptDownloadFilename('attachment; filename="AK-TEST_receipt_20261020_153000_payment-9.pdf"', 'receipt.pdf'))
      .toBe('AK-TEST_receipt_20261020_153000_payment-9.pdf')
    expect(receiptDownloadFilename(undefined, 'payment-9-receipt.pdf')).toBe('payment-9-receipt.pdf')
  })

  it('downloads through the authenticated API client rather than a public receipt URL', async () => {
    vi.mocked(api.get).mockRejectedValue(new Error('Forbidden'))
    await expect(paymentsApi.downloadReceipt(9)).rejects.toThrow('Forbidden')
    expect(api.get).toHaveBeenCalledWith('/payments/9/receipt', { responseType: 'blob' })
  })

  it('sends bank transfer data as multipart with the receipt', async () => {
    vi.mocked(api.post).mockResolvedValue({ data: { data: { id: 9, status: 'PENDING' } } })
    const file = new File(['receipt'], 'receipt.pdf', { type: 'application/pdf' })
    expect(await paymentsApi.submitTransfer(7, '70.00', 'TRANSFER-TEST', file)).toEqual({ id: 9, status: 'PENDING' })
    expect(api.post).toHaveBeenCalledWith('/invoices/7/payments', expect.any(FormData))
    const form = vi.mocked(api.post).mock.calls[0]![1] as FormData
    expect(form.get('amount')).toBe('70.00')
    expect(form.get('reference')).toBe('TRANSFER-TEST')
    expect((form.get('receipt') as File).name).toBe(file.name)
    expect((form.get('receipt') as File).size).toBe(file.size)
  })
  it('requests payment queues with status and pagination', async () => {
    const page = { data: [], current_page: 2, last_page: 2, total: 16 }
    vi.mocked(api.get).mockResolvedValue({ data: page })
    expect(await paymentsApi.queue('REJECTED', 2)).toEqual(page)
    expect(api.get).toHaveBeenCalledWith('/committee/payments', { params: { status: 'REJECTED', page: 2 } })
  })
  it('records cash without sending a verification status or submitter identity', async () => {
    vi.mocked(api.post).mockResolvedValue({ data: { data: { id: 9 } } })
    await paymentsApi.recordCash(7, '70.00', ' CASH-TEST ')
    expect(api.post).toHaveBeenCalledWith('/committee/payment-invoices/7/cash', { amount: '70.00', reference: 'CASH-TEST' })
  })
  it('submits only the decision and reason when reviewing', async () => {
    vi.mocked(api.post).mockResolvedValue({ data: { data: { id: 9 } } })
    await paymentsApi.review(9, 'REJECT', ' Not received. ')
    expect(api.post).toHaveBeenCalledWith('/committee/payments/9/review', { decision: 'REJECT', reason: 'Not received.' })
  })
})
