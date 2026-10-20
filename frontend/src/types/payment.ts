export type PaymentStatus = 'PENDING' | 'VERIFIED' | 'REJECTED'
export interface PaymentRecord {
  id: number
  invoice_id: number
  method: 'BANK_TRANSFER' | 'CASH'
  status: PaymentStatus
  amount: string
  reference: string | null
  has_receipt: boolean
  submitted_at: string
  reviewed_at: string | null
  verified_at: string | null
  rejection_reason: string | null
}
export interface PaymentSummary extends PaymentRecord {
  invoice_number: string
  applicant_name: string
}
export interface PaymentPage {
  data: PaymentSummary[]
  current_page: number
  last_page: number
  total: number
}
export interface PaymentDetail extends PaymentRecord {
  submitted_by_name: string
  reviewed_by_name: string | null
  can_review: boolean
  invoice: {
    id: number; invoice_number: string; applicant_name: string; total: string
    status: 'UNPAID' | 'PAID' | 'CANCELLED'; due_date: string
    items: { description: string; amount: string }[]
  }
}
export interface CashInvoice {
  id: number; invoice_number: string; total: string; applicant_name: string; due_date: string
}
export interface CashInvoicePage { data: CashInvoice[]; current_page: number; last_page: number; total: number }
export const paymentStatusLabels: Record<PaymentStatus, string> = {
  PENDING: 'Pending verification', VERIFIED: 'Verified', REJECTED: 'Rejected',
}
export const paymentMethodLabels = { BANK_TRANSFER: 'Bank transfer', CASH: 'Cash' }
