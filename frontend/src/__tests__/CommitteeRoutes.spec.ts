import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AuthUser, UserRole } from '@/types/auth'

const { auth } = vi.hoisted(() => ({
  auth: {
    user: null as AuthUser | null,
    get isAuthenticated() { return this.user !== null },
    restoreSession: vi.fn<() => Promise<void>>(),
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
import router from '@/router'

beforeEach(async () => {
  vi.clearAllMocks()
  auth.user = null
  auth.restoreSession.mockResolvedValue()
  await router.push('/login')
})

function signIn(role: UserRole) {
  auth.user = { id: 1, full_name: 'Test User', email: 'test@example.com', role, email_verified: true }
}

describe('committee route protection', () => {
  it.each(['/committee/applications', '/committee/applications/7', '/committee/payments', '/committee/payments/7', '/committee/payments/cash'])('redirects guests from %s to login', async (path) => {
    await router.push(path)
    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe(path)
    expect(auth.restoreSession).toHaveBeenCalled()
  })

  it.each(['/committee/applications', '/committee/applications/7', '/committee/payments', '/committee/payments/7', '/committee/payments/cash'])('prevents member access to %s', async (path) => {
    signIn('MEMBER')
    await router.push(path)
    expect(router.currentRoute.value.name).toBe('dashboard')
  })

  it.each(['COMMITTEE', 'ADMIN'] as const)('allows %s to access the queue and details', async (role) => {
    signIn(role)
    await router.push('/committee/applications')
    expect(router.currentRoute.value.name).toBe('committee-applications')
    await router.push('/committee/applications/7')
    expect(router.currentRoute.value.name).toBe('committee-application-detail')
    await router.push('/committee/payments')
    expect(router.currentRoute.value.name).toBe('committee-payments')
    await router.push('/committee/payments/7')
    expect(router.currentRoute.value.name).toBe('committee-payment-detail')
    await router.push('/committee/payments/cash')
    expect(router.currentRoute.value.name).toBe('committee-cash-payment')
  })
})
