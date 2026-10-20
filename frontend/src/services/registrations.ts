import axios from 'axios'
import { api } from '@/services/api'
import type { DraftResult, RegistrationDetail, RegistrationDraft, RegistrationSummary, SubmissionResult } from '@/types/registration'

// Unwrap Laravel's response envelope here so pages deal only with domain data.
export const registrationsApi = {
  async list(): Promise<RegistrationSummary[]> {
    return (await api.get<{ data: RegistrationSummary[] }>('/registrations')).data.data
  },
  async create(): Promise<DraftResult> {
    return (await api.post<{ data: DraftResult }>('/registrations')).data.data
  },
  async detail(id: number): Promise<RegistrationDetail> {
    return (await api.get<{ data: RegistrationDetail }>(`/registrations/${id}`)).data.data
  },
  async submit(id: number): Promise<SubmissionResult> {
    return (await api.post<{ data: SubmissionResult }>(`/registrations/${id}/submit`)).data.data
  },
  async update(id: number, draft: RegistrationDraft): Promise<DraftResult> {
    // Explicitly whitelist editable fields; identity and status stay server-controlled.
    return (await api.patch<{ data: DraftResult }>(`/registrations/${id}/draft`, {
      phone_number: draft.phone_number,
      address: draft.address,
    })).data.data
  },
}

export function registrationError(error: unknown): {
  message: string
  fields: Record<string, string[]>
  status?: number
} {
  if (!axios.isAxiosError(error)) {
    return { message: 'Something went wrong. Please try again.', fields: {} }
  }
  const status = error.response?.status
  const messages: Record<number, string> = {
    401: 'Your session has expired. Please sign in again.',
    403: 'You do not have permission to access this application.',
    404: 'This application could not be found.',
    409: 'This application has changed. Reload it before continuing.',
  }
  return {
    status,
    message: (status && messages[status]) || error.response?.data?.message || 'Could not reach the server. Please try again.',
    fields: status === 422 ? error.response?.data?.errors ?? {} : {},
  }
}
