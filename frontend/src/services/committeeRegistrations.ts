import { api } from '@/services/api'
import type { CommitteeApplicationDetail, CommitteeApplicationPage, ReviewPayload, ReviewResult } from '@/types/committee'

export const committeeRegistrationsApi = {
  async list(page = 1): Promise<CommitteeApplicationPage> {
    return (await api.get<CommitteeApplicationPage>('/committee/registrations', { params: { page } })).data
  },
  async detail(id: number): Promise<CommitteeApplicationDetail> {
    return (await api.get<{ data: CommitteeApplicationDetail }>(`/committee/registrations/${id}`)).data.data
  },
  async review(id: number, payload: ReviewPayload): Promise<ReviewResult> {
    return (await api.post<{ data: ReviewResult }>(`/committee/registrations/${id}/review`, {
      decision: payload.decision,
      reason: payload.reason,
    })).data.data
  },
}
