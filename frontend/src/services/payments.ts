import axios from 'axios'
import { api } from '@/services/api'
import type { CashInvoicePage, PaymentDetail, PaymentPage, PaymentRecord, PaymentStatus } from '@/types/payment'

export const paymentsApi = {
  async history(invoiceId: number): Promise<PaymentRecord[]> {
    return (await api.get<{ data: PaymentRecord[] }>(`/invoices/${invoiceId}/payments`)).data.data
  },
  async submitTransfer(invoiceId: number, amount: string, reference: string, receipt: File): Promise<PaymentRecord> {
    const form = new FormData()
    form.append('amount', amount)
    form.append('reference', reference)
    form.append('receipt', receipt)
    // Let the browser set the multipart boundary; the token stays in the Authorization header.
    return (await api.post<{ data: PaymentRecord }>(`/invoices/${invoiceId}/payments`, form)).data.data
  },
  async queue(status: PaymentStatus = 'PENDING', page = 1): Promise<PaymentPage> {
    return (await api.get<PaymentPage>('/committee/payments', { params: { status, page } })).data
  },
  async detail(id: number): Promise<PaymentDetail> {
    return (await api.get<{ data: PaymentDetail }>(`/committee/payments/${id}`)).data.data
  },
  async cashInvoices(search = '', page = 1): Promise<CashInvoicePage> {
    return (await api.get<CashInvoicePage>('/committee/payment-invoices', { params: { search, page } })).data
  },
  async recordCash(invoiceId: number, amount: string, reference: string): Promise<PaymentRecord> {
    return (await api.post<{ data: PaymentRecord }>(`/committee/payment-invoices/${invoiceId}/cash`, { amount, reference: reference.trim() || null })).data.data
  },
  async review(id: number, decision: 'VERIFY' | 'REJECT', reason: string): Promise<PaymentRecord> {
    return (await api.post<{ data: PaymentRecord }>(`/committee/payments/${id}/review`, { decision, reason: reason.trim() || null })).data.data
  },
  async downloadReceipt(id: number): Promise<void> {
    const response = await api.get<Blob>(`/payments/${id}/receipt`, { responseType: 'blob' })
    const type = response.data.type.split(';')[0] ?? ''
    const extension: Record<string, string> = { 'application/pdf': 'pdf', 'image/jpeg': 'jpg', 'image/png': 'png' }
    if (!extension[type]) throw new Error('Unsupported receipt type')
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = receiptDownloadFilename(response.headers['content-disposition'], `payment-${id}-receipt.${extension[type]}`)
    document.body.appendChild(link)
    link.click()
    link.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  },
}

export function receiptDownloadFilename(disposition: string | undefined, fallback: string): string {
  const filename = disposition?.match(/(?:^|;)\s*filename="?([^";]+)"?/i)?.[1]
  return filename ? filename.replace(/[^A-Za-z0-9._-]/g, '_') : fallback
}

export function paymentError(error: unknown): { message: string; fields: Record<string, string[]> } {
  if (!axios.isAxiosError(error)) return { message: 'The payment request could not be completed. Please try again.', fields: {} }
  const messages: Record<number, string> = {
    401: 'Your session has expired. Please sign in again.',
    403: 'You do not have permission for this payment action. You cannot review your own submitted payment or your own invoice.',
    404: 'The payment or receipt could not be found.',
    413: 'The receipt is too large. Upload a file up to 2 MB.',
    429: 'Too many requests. Please wait before trying again.',
  }
  return {
    message: messages[error.response?.status ?? 0] ?? error.response?.data?.message ?? 'The payment request could not be completed. Reload before trying again.',
    fields: error.response?.status === 422 ? error.response.data.errors ?? {} : {},
  }
}
