import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ApplicationDetailView from '@/views/ApplicationDetailView.vue'
import { registrationsApi } from '@/services/registrations'
import type { RegistrationDetail } from '@/types/registration'

const { auth } = vi.hoisted(() => ({
  auth: {
    user: { full_name: 'Test Applicant', email_verified: true },
    refreshUser: vi.fn<() => Promise<void>>(),
    resendVerificationEmail: vi.fn<() => Promise<string>>(),
  },
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '7' } }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('@/services/registrations', () => ({
  registrationsApi: {
    detail: vi.fn<typeof registrationsApi.detail>(),
    update: vi.fn<typeof registrationsApi.update>(),
    submit: vi.fn<typeof registrationsApi.submit>(),
  },
  registrationError: () => ({ message: 'Submission failed', fields: {}, status: 422 }),
}))

function application(status: RegistrationDetail['status'] = 'DRAFT'): RegistrationDetail {
  return {
    id: 7, type: 'NEW', status,
    draft_data: { phone_number: '0123456789', address: 'Existing address' },
    applicant: { full_name: 'Test Applicant', date_of_birth: '1990-01-01' },
    submissions: [], created_at: '2026-10-20T00:00:00Z',
  }
}

function buttonWithText(wrapper: ReturnType<typeof mount>, text: string) {
  const button = wrapper.findAll('button').find((item) => item.text() === text)
  if (!button) throw new Error(`Button not found: ${text}`)
  return button
}

beforeEach(() => {
  vi.resetAllMocks()
  auth.user.email_verified = true
  auth.refreshUser.mockResolvedValue()
  auth.resendVerificationEmail.mockResolvedValue('Verification email sent.')
  vi.mocked(registrationsApi.detail).mockResolvedValue(application())
  vi.mocked(registrationsApi.update).mockResolvedValue({ id: 7, status: 'DRAFT', draft_data: application().draft_data })
  vi.mocked(registrationsApi.submit).mockResolvedValue({ registration_id: 7, revision: 1, submitted_at: '2026-10-20T00:00:00Z' })
})

describe('application submission', () => {
  it('keeps submission disabled for an unverified account and can resend verification', async () => {
    auth.user.email_verified = false
    const wrapper = mount(ApplicationDetailView)
    await flushPromises()
    expect(buttonWithText(wrapper, 'Save and submit for review').attributes('disabled')).toBeDefined()
    await buttonWithText(wrapper, 'Resend verification email').trigger('click')
    await flushPromises()
    expect(auth.resendVerificationEmail).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).toContain('Verification email sent.')
    expect(registrationsApi.submit).not.toHaveBeenCalled()
  })

  it('confirms, saves the visible edits, then submits and locks the form', async () => {
    vi.mocked(registrationsApi.detail)
      .mockResolvedValueOnce(application())
      .mockResolvedValueOnce(application('PENDING_REVIEW'))
    const wrapper = mount(ApplicationDetailView)
    await flushPromises()
    await wrapper.get('#address').setValue('Latest address')
    await buttonWithText(wrapper, 'Save and submit for review').trigger('click')
    expect(registrationsApi.submit).not.toHaveBeenCalled()
    await buttonWithText(wrapper, 'Confirm submission').trigger('click')
    await flushPromises()
    expect(auth.refreshUser).toHaveBeenCalledTimes(1)
    expect(registrationsApi.update).toHaveBeenCalledWith(7, { phone_number: '0123456789', address: 'Latest address' })
    expect(vi.mocked(registrationsApi.update).mock.invocationCallOrder[0]!).toBeLessThan(vi.mocked(registrationsApi.submit).mock.invocationCallOrder[0]!)
    expect(registrationsApi.submit).toHaveBeenCalledWith(7)
    expect(wrapper.text()).toContain('Revision 1 submitted for review.')
    expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined()
  })

  it('rechecks verification before saving or submitting', async () => {
    auth.refreshUser.mockImplementation(async () => { auth.user.email_verified = false })
    const wrapper = mount(ApplicationDetailView)
    await flushPromises()
    await buttonWithText(wrapper, 'Save and submit for review').trigger('click')
    await buttonWithText(wrapper, 'Confirm submission').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Please verify your email before submitting.')
    expect(registrationsApi.update).not.toHaveBeenCalled()
    expect(registrationsApi.submit).not.toHaveBeenCalled()
  })

  it('shows a submission failure without reporting success', async () => {
    vi.mocked(registrationsApi.submit).mockRejectedValue(new Error('Validation failed'))
    const wrapper = mount(ApplicationDetailView)
    await flushPromises()
    await buttonWithText(wrapper, 'Save and submit for review').trigger('click')
    await buttonWithText(wrapper, 'Confirm submission').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Submission failed')
    expect(wrapper.text()).not.toContain('Revision 1 submitted for review.')
  })
  it('does not submit when saving the draft fails', async () => {
    vi.mocked(registrationsApi.update).mockRejectedValue(new Error('Invalid draft'))
    const wrapper = mount(ApplicationDetailView)
    await flushPromises()
    await buttonWithText(wrapper, 'Save and submit for review').trigger('click')
    await buttonWithText(wrapper, 'Confirm submission').trigger('click')
    await flushPromises()
    expect(registrationsApi.submit).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Submission failed')
  })

  it('prevents repeated clicks while submission is pending', async () => {
    let finishVerification!: () => void
    auth.refreshUser.mockReturnValue(new Promise<void>((resolve) => { finishVerification = resolve }))
    const wrapper = mount(ApplicationDetailView)
    await flushPromises()
    await buttonWithText(wrapper, 'Save and submit for review').trigger('click')
    const confirm = buttonWithText(wrapper, 'Confirm submission')
    await confirm.trigger('click')
    await confirm.trigger('click')
    expect(auth.refreshUser).toHaveBeenCalledTimes(1)
    finishVerification()
    await flushPromises()
    expect(registrationsApi.submit).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('shows committee feedback and preserves previous snapshot details during resubmission', async () => {
    const data = application('CHANGES_REQUESTED')
    data.submissions = [{
      id: 11, registration_id: 7, revision: 1, submitted_at: '2026-10-20T00:00:00Z',
      snapshot: { full_name: 'Test Applicant', date_of_birth: '1990-01-01', phone_number: null, address: 'Original address' },
      reviews: [{ decision: 'REQUEST_CHANGES', reason: 'Please provide your full address.', reviewed_at: '2026-10-20T01:00:00Z' }],
    }]
    vi.mocked(registrationsApi.detail).mockResolvedValue(data)
    const wrapper = mount(ApplicationDetailView)
    await flushPromises()
    expect(wrapper.text()).toContain('Please provide your full address.')
    expect(wrapper.text()).toContain('Original address')
    await wrapper.get('#address').setValue('Corrected address')
    expect(wrapper.text()).toContain('Original address')
    await buttonWithText(wrapper, 'Save and resubmit for review').trigger('click')
    await buttonWithText(wrapper, 'Confirm submission').trigger('click')
    await flushPromises()
    expect(registrationsApi.update).toHaveBeenCalledWith(7, { phone_number: '0123456789', address: 'Corrected address' })
    expect(registrationsApi.submit).toHaveBeenCalledTimes(1)
  })

  it('keeps the form locked when submission succeeds but history reload fails', async () => {
    vi.mocked(registrationsApi.detail).mockResolvedValueOnce(application()).mockRejectedValueOnce(new Error('offline'))
    const wrapper = mount(ApplicationDetailView)
    await flushPromises()
    await buttonWithText(wrapper, 'Save and submit for review').trigger('click')
    await buttonWithText(wrapper, 'Confirm submission').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Revision 1 submitted for review.')
    expect(wrapper.text()).toContain('history could not be refreshed')
    expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined()
  })
})
