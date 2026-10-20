export type RegistrationStatus =
  | 'DRAFT'
  | 'PENDING_REVIEW'
  | 'CHANGES_REQUESTED'
  | 'REJECTED'
  | 'APPROVED'

export interface RegistrationDraft {
  phone_number: string | null
  address: string | null
}

export interface RegistrationSummary {
  id: number
  type: string
  status: RegistrationStatus
  created_at: string
  updated_at: string
}

export interface RegistrationDetail {
  id: number
  type: string
  status: RegistrationStatus
  draft_data: RegistrationDraft | null
  // Identity is provided by the owner-only detail endpoint and is never editable.
  applicant: { full_name: string; date_of_birth: string | null }
  submissions: RegistrationSubmission[]
  created_at: string
}

export interface DraftResult {
  id: number
  status: RegistrationStatus
  draft_data: RegistrationDraft | null
}

export const registrationStatusLabels: Record<RegistrationStatus, string> = {
  DRAFT: 'Draft',
  PENDING_REVIEW: 'Pending review',
  CHANGES_REQUESTED: 'Changes requested',
  REJECTED: 'Rejected',
  APPROVED: 'Approved',
}

export function canEditRegistration(status: RegistrationStatus): boolean {
  return status === 'DRAFT' || status === 'CHANGES_REQUESTED'
}

export type ReviewDecision = 'APPROVE' | 'REJECT' | 'REQUEST_CHANGES'

export interface RegistrationSubmission {
  id: number
  registration_id: number
  revision: number
  submitted_at: string
  snapshot: RegistrationDraft & { full_name: string; date_of_birth: string | null }
  reviews: { decision: ReviewDecision; reason: string | null; reviewed_at: string }[]
}

export interface SubmissionResult {
  registration_id: number
  revision: number
  submitted_at: string
}

export const reviewDecisionLabels: Record<ReviewDecision, string> = {
  APPROVE: 'Approved',
  REJECT: 'Rejected',
  REQUEST_CHANGES: 'Changes requested',
}
