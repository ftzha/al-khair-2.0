import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/services/api'
import { committeeRegistrationsApi } from '@/services/committeeRegistrations'

vi.mock('@/services/api', () => ({ api: { get: vi.fn<typeof api.get>(), post: vi.fn<typeof api.post>() } }))
beforeEach(() => vi.resetAllMocks())

describe('committee registration API', () => {
  it('requests full identity only through the explicit reveal endpoint', async () => {
    vi.mocked(api.post).mockResolvedValue({ data: { data: { identity_number: '900101101234' } } })
    expect(await committeeRegistrationsApi.revealIdentity(7)).toBe('900101101234')
    expect(api.post).toHaveBeenCalledWith('/committee/registrations/7/identity/reveal')
  })

  it('preserves pagination and sends the selected page', async () => {
    const page = { data: [], current_page: 2, last_page: 3, total: 31 }
    vi.mocked(api.get).mockResolvedValue({ data: page })
    expect(await committeeRegistrationsApi.list(2)).toEqual(page)
    expect(api.get).toHaveBeenCalledWith('/committee/registrations', { params: { page: 2 } })
  })

  it('unwraps committee application details', async () => {
    const detail = { id: 7, status: 'PENDING_REVIEW', submissions: [] }
    vi.mocked(api.get).mockResolvedValue({ data: { data: detail } })
    expect(await committeeRegistrationsApi.detail(7)).toEqual(detail)
    expect(api.get).toHaveBeenCalledWith('/committee/registrations/7')
  })

  it('sends only decision and reason when reviewing', async () => {
    const result = { id: 9, decision: 'REJECT', reason: 'Incomplete address', reviewed_at: '2026-10-20T00:00:00Z' }
    vi.mocked(api.post).mockResolvedValue({ data: { data: result } })
    expect(await committeeRegistrationsApi.review(7, { decision: 'REJECT', reason: 'Incomplete address' })).toEqual(result)
    expect(api.post).toHaveBeenCalledWith('/committee/registrations/7/review', { decision: 'REJECT', reason: 'Incomplete address' })
  })
})
