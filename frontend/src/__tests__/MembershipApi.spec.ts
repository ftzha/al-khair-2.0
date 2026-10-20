import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/services/api'
import { membershipApi, membershipError } from '@/services/membership'

vi.mock('@/services/api', () => ({ api: { get: vi.fn<typeof api.get>(), post: vi.fn<typeof api.post>() } }))
beforeEach(() => vi.resetAllMocks())

describe('membership API', () => {
  it('loads the signed-in membership without a supplied person or member ID', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: null } })
    expect(await membershipApi.overview()).toBeNull()
    expect(api.get).toHaveBeenCalledWith('/membership')
  })
  it('generates renewal without sending an amount, year or owner', async () => {
    vi.mocked(api.post).mockResolvedValue({ data: { data: { id: 8, invoice_number: 'AK-RENEWAL' } } })
    expect(await membershipApi.createRenewal()).toEqual({ id: 8, invoice_number: 'AK-RENEWAL' })
    expect(api.post).toHaveBeenCalledWith('/membership/renewal-invoice')
  })
  it('preserves the backend explanation for an unavailable renewal', () => {
    expect(membershipError({ isAxiosError: true, response: { status: 422, data: { message: 'Renewal window is closed.' } } }))
      .toBe('Renewal window is closed.')
  })
})
