export type InvoiceStatus = 'UNPAID' | 'PAID' | 'CANCELLED'

export interface InvoiceSummary {
  id: number
  invoice_number: string
  registration_id: number | null
  type: string
  status: InvoiceStatus
  billing_year: number
  currency: 'MYR'
  total: string
  issued_at: string
  due_date: string
}

export interface InvoicePage {
  data: InvoiceSummary[]
  current_page: number
  last_page: number
  total: number
}

export interface InvoiceDetail extends InvoiceSummary {
  items: { id: number; fee_code: string; description: string; amount: string }[]
}

export const invoiceStatusLabels: Record<InvoiceStatus, string> = {
  UNPAID: 'Unpaid', PAID: 'Paid', CANCELLED: 'Cancelled',
}

// API amounts remain decimal strings; convert only for display, never billing arithmetic.
export function formatRinggit(amount: string): string {
  return new Intl.NumberFormat('en-MY', { style: 'currency', currency: 'MYR' }).format(Number(amount))
}
