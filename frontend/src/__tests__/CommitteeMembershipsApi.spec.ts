import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/services/api'
import { committeeMembershipsApi } from '@/services/committeeMemberships'

vi.mock('@/services/api', () => ({ api: { get: vi.fn<typeof api.get>(), post: vi.fn<typeof api.post>() } }))
beforeEach(() => vi.resetAllMocks())
describe('committee membership API', () => {
  it('passes search and page and unwraps detail responses', async () => {
    vi.mocked(api.get).mockResolvedValueOnce({ data: { data: [], total: 0 } }).mockResolvedValueOnce({ data: { data: { id: 7 } } })
    await committeeMembershipsApi.list('AKM-TEST', 2)
    expect(api.get).toHaveBeenCalledWith('/committee/memberships', { params: { search: 'AKM-TEST', page: 2 } })
    expect(await committeeMembershipsApi.detail(7)).toEqual({ id: 7 })
  })
  it('sends only a trimmed closure reason, never an effective date or actor', async () => {
    vi.mocked(api.post).mockResolvedValue({ data: { data: { id: 7, status: 'CLOSED' } } })
    await committeeMembershipsApi.close(7, ' Member request. ')
    expect(api.post).toHaveBeenCalledWith('/committee/memberships/7/close', { reason: 'Member request.' })
  })
})
