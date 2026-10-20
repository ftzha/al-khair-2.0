import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/services/api'
import { registrationsApi, registrationError } from '@/services/registrations'

vi.mock('@/services/api', () => ({ api: { get: vi.fn<typeof api.get>(), post: vi.fn<typeof api.post>(), patch: vi.fn<typeof api.patch>() } }))
beforeEach(() => vi.resetAllMocks())

describe('registration API', () => {
  it('submits through the existing endpoint and unwraps revision metadata', async () => {
    const submission = { registration_id: 7, revision: 2, submitted_at: '2026-10-20T00:00:00Z' }
    vi.mocked(api.post).mockResolvedValue({ data: { data: submission } })
    expect(await registrationsApi.submit(7)).toEqual(submission)
    expect(api.post).toHaveBeenCalledWith('/registrations/7/submit')
  })

  it('unwraps the member list envelope', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: [{ id: 7, status: 'DRAFT' }] } })
    expect(await registrationsApi.list()).toEqual([{ id: 7, status: 'DRAFT' }])
    expect(api.get).toHaveBeenCalledWith('/registrations')
  })

  it('never sends injected identity or status fields in draft updates', async () => {
    vi.mocked(api.patch).mockResolvedValue({ data: { data: { id: 7, status: 'DRAFT' } } })
    const payload = { phone_number: null, address: 'New address', status: 'APPROVED', full_name: 'Changed name' }
    await registrationsApi.update(7, payload)
    expect(api.patch).toHaveBeenCalledWith('/registrations/7/draft', { phone_number: null, address: 'New address' })
  })

  it('preserves Laravel field errors', () => {
    const result = registrationError({ isAxiosError: true, response: { status: 422, data: {
      message: 'Validation failed', errors: { phone_number: ['Invalid phone number.'] },
    } } })
    expect(result.fields.phone_number).toEqual(['Invalid phone number.'])
  })

  it.each([401, 403, 404, 409])('provides actionable feedback for HTTP %s', (status) => {
    const result = registrationError({ isAxiosError: true, response: { status } })
    expect(result.status).toBe(status)
    expect(result.message.length).toBeGreaterThan(0)
    expect(result.fields).toEqual({})
  })
})
