import { api } from '@/services/api'
import type { CommitteeMembershipDetail, CommitteeMembershipPage } from '@/types/committeeMembership'

export const committeeMembershipsApi = {
  async list(search = '', page = 1): Promise<CommitteeMembershipPage> {
    return (await api.get<CommitteeMembershipPage>('/committee/memberships', { params: { search, page } })).data
  },
  async detail(id: number): Promise<CommitteeMembershipDetail> {
    return (await api.get<{ data: CommitteeMembershipDetail }>(`/committee/memberships/${id}`)).data.data
  },
  async close(id: number, reason: string): Promise<CommitteeMembershipDetail> {
    return (await api.post<{ data: CommitteeMembershipDetail }>(`/committee/memberships/${id}/close`, { reason: reason.trim() })).data.data
  },
}
