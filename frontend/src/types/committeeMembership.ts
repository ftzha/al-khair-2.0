import type { MembershipOverview, MembershipStatus } from '@/types/membership'

export interface CommitteeMembershipRow {
  id: number
  member_number: string
  full_name: string
  status: MembershipStatus
  activated_on: string
}
export interface CommitteeMembershipPage {
  data: CommitteeMembershipRow[]
  current_page: number
  last_page: number
  total: number
}
export interface CommitteeMembershipDetail extends MembershipOverview {
  full_name: string
  email: string | null
  pending_payment_count: number
  can_close: boolean
  periods: { id: number; billing_year: number; starts_on: string; ends_on: string; effective_at: string; payment_id: number }[]
  events: { id: number; type: string; effective_at: string; recorded_at: string; actor_name: string; reason: string | null }[]
}
