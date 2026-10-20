import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { Router } from 'vue-router'
import RegisterView from '@/views/RegisterView.vue'
import { api } from '@/services/api'

const { replace, show } = vi.hoisted(() => ({
  replace: vi.fn<Router['replace']>(),
  show: vi.fn<(type: string, message: string) => void>(),
}))
vi.mock('vue-router', () => ({ useRouter: () => ({ replace }) }))
vi.mock('@/stores/flash', () => ({ useFlashStore: () => ({ show }) }))
vi.mock('@/services/api', () => ({ api: { post: vi.fn<typeof api.post>() } }))

beforeEach(() => { vi.resetAllMocks() })
const options = { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } }

describe('registration date of birth', () => {
  it('requires an editable DOB and autofills from an NRIC', async () => {
    const wrapper = mount(RegisterView, options)
    const dob = wrapper.get('#date_of_birth')
    expect(dob.attributes('type')).toBe('date')
    expect(dob.attributes('required')).toBeDefined()
    expect(dob.attributes('readonly')).toBeUndefined()
    await wrapper.get('#identity_number').setValue('900101-10-1234')
    expect((dob.element as HTMLInputElement).value).toBe('1990-01-01')
  })

  it('preserves a manual century correction when identity changes', async () => {
    const wrapper = mount(RegisterView, options)
    await wrapper.get('#identity_number').setValue('200101-10-1234')
    await wrapper.get('#date_of_birth').setValue('1920-01-01')
    await wrapper.get('#identity_number').setValue('200101-10-5678')
    expect((wrapper.get('#date_of_birth').element as HTMLInputElement).value).toBe('1920-01-01')
  })

  it('clears an automatic suggestion when the NRIC is replaced with another document', async () => {
    const wrapper = mount(RegisterView, options)
    await wrapper.get('#identity_number').setValue('900101-10-1234')
    await wrapper.get('#identity_number').setValue('PASSPORT-TEST')
    expect((wrapper.get('#date_of_birth').element as HTMLInputElement).value).toBe('')
  })

  it('sends the confirmed DOB during account creation', async () => {
    vi.mocked(api.post).mockResolvedValue({ data: {} })
    const wrapper = mount(RegisterView, options)
    await wrapper.get('#full_name').setValue('Test Member')
    await wrapper.get('#identity_number').setValue('200101-10-1234')
    await wrapper.get('#date_of_birth').setValue('1920-01-01')
    await wrapper.get('#email').setValue('member@alkhair.test')
    await wrapper.get('#password').setValue('Password123!')
    await wrapper.get('#password_confirmation').setValue('Password123!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(api.post).toHaveBeenCalledWith('/register', {
      full_name: 'Test Member', identity_number: '200101-10-1234', date_of_birth: '1920-01-01',
      email: 'member@alkhair.test', password: 'Password123!', password_confirmation: 'Password123!',
    })
    expect(replace).toHaveBeenCalledWith({ name: 'login' })
  })
})
