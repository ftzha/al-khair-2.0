export type MembershipStatus = 'ACTIVE' | 'LAPSED' | 'CLOSED'
export type RenewalState = 'PAID' | 'PENDING_VERIFICATION' | 'CLOSED' | 'CANCELLED'
  | 'CUTOFF_PASSED' | 'NOT_AVAILABLE' | 'PAYMENT_REJECTED' | 'PAYMENT_DUE' | 'AVAILABLE' | 'NOT_OPEN'

export interface MembershipOverview {
  id: number
  member_number: string
  status: MembershipStatus
  activated_on: string
  waiting_until: string
  waiting_days_remaining: number
  eligibility: { eligible: boolean; reason: string; as_of: string }
  current_period: { billing_year: number; starts_on: string; ends_on: string; effective_at: string } | null
  renewal: {
    billing_year: number
    cutoff_date: string
    state: RenewalState
    can_generate_invoice: boolean
    invoice: { id: number; invoice_number: string; status: 'UNPAID' | 'PAID' | 'CANCELLED'; total: string } | null
  }
}

export const membershipStatusLabels: Record<MembershipStatus, string> = {
  ACTIVE: 'Active', LAPSED: 'Lapsed', CLOSED: 'Closed',
}
export const eligibilityExplanations: Record<string, string> = {
  ELIGIBLE: 'Your waiting period is complete and your membership fees cover the current year.',
  WAITING_PERIOD: 'Your membership is active. Eligibility begins after the initial waiting period, provided the current year is paid.',
  NO_PAID_PERIOD: 'The current year does not have a verified paid membership period.',
  NOT_ACTIVATED: 'Your membership has not activated yet.',
  LAPSED: 'Your membership has lapsed. A timely submitted payment can still be independently verified.',
  CLOSED: 'This membership is closed. Contact the committee if you need assistance.',
}
export const renewalExplanations: Record<RenewalState, string> = {
  PAID: 'Your membership fees for this year have been verified.',
  PENDING_VERIFICATION: 'Your payment is awaiting independent verification. Eligibility resumes from verification once the waiting period is complete.',
  CLOSED: 'Renewal is unavailable for a closed membership.',
  CANCELLED: 'Your renewal invoice was cancelled. Contact the committee for assistance.',
  CUTOFF_PASSED: 'The renewal submission deadline has passed. New or replacement payments cannot be submitted for this year.',
  NOT_AVAILABLE: 'Online renewal is unavailable. Contact the committee to check your membership history.',
  PAYMENT_REJECTED: 'Your payment was rejected. Open the invoice to read the reason and submit a replacement before the deadline.',
  PAYMENT_DUE: 'Open your invoice to submit a bank transfer, or contact the committee to record cash payment.',
  AVAILABLE: 'Create your annual renewal invoice, then arrange payment before the submission deadline.',
  NOT_OPEN: 'The annual renewal window is January 1–30.',
}
