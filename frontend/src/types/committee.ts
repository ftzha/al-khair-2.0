import type { RegistrationStatus, RegistrationSubmission, ReviewDecision } from '@/types/registration'

export interface CommitteeApplicationSummary {
  id: number
  type: string
  status: RegistrationStatus
  applicant_name: string
  created_at: string
}

// Laravel returns its paginator directly, rather than a nested data envelope.
export interface CommitteeApplicationPage {
  data: CommitteeApplicationSummary[]
  current_page: number
  last_page: number
  total: number
}

export interface CommitteeApplicationDetail {
  id: number
  status: RegistrationStatus
  applicant_name: string
  submissions: Omit<RegistrationSubmission, 'registration_id'>[]
}

export interface ReviewPayload {
  decision: ReviewDecision
  reason: string | null
}

export interface ReviewResult extends ReviewPayload {
  id: number
  reviewed_at: string
}
