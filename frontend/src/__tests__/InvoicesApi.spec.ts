import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/services/api'
import { invoicesApi, invoiceError } from '@/services/invoices'

vi.mock('@/services/api', () => ({ api: { get: vi.fn<typeof api.get>() } }))
beforeEach(() => vi.resetAllMocks())

describe('invoice API', () => {
  it('preserves pagination metadata', async () => {
    const page = { data: [], current_page: 2, last_page: 2, total: 16 }
    vi.mocked(api.get).mockResolvedValue({ data: page })
    expect(await invoicesApi.list(2)).toEqual(page)
    expect(api.get).toHaveBeenCalledWith('/invoices', { params: { page: 2 } })
  })

  it('unwraps invoice details', async () => {
    const invoice = { id: 7, total: '70.00', items: [] }
    vi.mocked(api.get).mockResolvedValue({ data: { data: invoice } })
    expect(await invoicesApi.detail(7)).toEqual(invoice)
    expect(api.get).toHaveBeenCalledWith('/invoices/7')
  })

  it.each([
    [401, 'Your session has expired. Please sign in again.'],
    [403, 'You do not have permission to view this invoice.'],
    [404, 'This invoice could not be found.'],
  ])('explains HTTP %s failures', (status, message) => {
    expect(invoiceError({ isAxiosError: true, response: { status } })).toBe(message)
  })
})
