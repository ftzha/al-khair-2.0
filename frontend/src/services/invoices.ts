import axios from 'axios'
import { api } from '@/services/api'
import type { InvoiceDetail, InvoicePage } from '@/types/invoice'

export const invoicesApi = {
  async list(page = 1): Promise<InvoicePage> {
    return (await api.get<InvoicePage>('/invoices', { params: { page } })).data
  },
  async detail(id: number): Promise<InvoiceDetail> {
    return (await api.get<{ data: InvoiceDetail }>(`/invoices/${id}`)).data.data
  },
}

export function invoiceError(error: unknown): string {
  if (!axios.isAxiosError(error)) return 'Could not load invoices. Please try again.'
  const messages: Record<number, string> = {
    401: 'Your session has expired. Please sign in again.',
    403: 'You do not have permission to view this invoice.',
    404: 'This invoice could not be found.',
    429: 'Too many requests. Please wait before trying again.',
  }
  return messages[error.response?.status ?? 0] ?? 'Could not load invoices. Please try again.'
}
