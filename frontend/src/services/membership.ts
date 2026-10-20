import axios from 'axios'
import { api } from '@/services/api'
import type { MembershipOverview } from '@/types/membership'

export const membershipApi = {
  async overview(): Promise<MembershipOverview | null> {
    return (await api.get<{ data: MembershipOverview | null }>('/membership')).data.data
  },
  async createRenewal(): Promise<{ id: number; invoice_number: string }> {
    return (await api.post<{ data: { id: number; invoice_number: string } }>('/membership/renewal-invoice')).data.data
  },
}

export function membershipError(error: unknown): string {
  if (!axios.isAxiosError(error)) return 'The membership request could not be completed. Please try again.'
  const messages: Record<number, string> = {
    401: 'Your session has expired. Please sign in again.',
    403: 'You do not have access to this membership action.',
    404: 'No membership record was found for your account.',
    429: 'Too many requests. Please wait before trying again.',
  }
  const fields = error.response?.data?.errors as Record<string, string[]> | undefined
  const validationMessage = Object.values(fields ?? {})[0]?.[0]
  return messages[error.response?.status ?? 0] ?? validationMessage ?? error.response?.data?.message ?? 'The membership request could not be completed. Please try again.'
}
