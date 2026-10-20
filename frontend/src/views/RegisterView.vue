
<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { AxiosError } from 'axios'

import { api } from '@/services/api'
import { malaysiaToday, suggestNricDateOfBirth } from '@/services/identityDateOfBirth'
import type {
  RegisterPayload,
  ValidationErrorResponse,
} from '@/types/auth'

import { useFlashStore } from '@/stores/flash'

// Reuse the shared store for cross-page notifications.
const flash = useFlashStore()

const router = useRouter()

// Keep related form fields together in one reactive object.
// This also matches the payload expected by Laravel.
const form = reactive<RegisterPayload>({
  full_name: '',
  identity_number: '',
  date_of_birth: '',
  email: '',
  password: '',
  password_confirmation: '',
})

// Autofill is a convenience, not identity verification. Preserve any manual DOB
// correction when the identity number changes (including a century correction).
const dobEdited = ref(false)
watch(() => form.identity_number, (identity) => {
  if (!dobEdited.value) form.date_of_birth = suggestNricDateOfBirth(identity) ?? ''
})
// Native date inputs accept ISO values while browsers localize their presentation.
const latestBirthDate = () => {
  const today = malaysiaToday()
  return new Date(Date.parse(`${today}T00:00:00Z`) - 86400000).toISOString().slice(0, 10)
}
// Laravel validation errors are grouped by field name.
const fieldErrors = ref<Record<string, string[]>>({})
const errorMessage = ref('')
const isSubmitting = ref(false)

async function handleRegister() {
  // Prevent duplicate submissions while the request is running.
  if (isSubmitting.value) return

  fieldErrors.value = {}
  errorMessage.value = ''
  isSubmitting.value = true

  try {
    // Laravel creates the account but does not log the user in.
    await api.post('/register', form)

    // Store a one-time success message for the login page.
    // No personal details or messages are added to the URL.
    flash.show(
      'success',
      'Account created successfully. Please sign in and verify your email.',
    )

    // Navigate to the clean login URL.
    await router.replace({ name: 'login' })
  } catch (error) {
    if (error instanceof AxiosError) {
      const response = error.response?.data as
        | ValidationErrorResponse
        | undefined

      // HTTP 422 means Laravel rejected one or more form fields.
      if (error.response?.status === 422) {
        fieldErrors.value = response?.errors ?? {}
        errorMessage.value = response?.message ?? 'Please check your details.'
      } else {
        errorMessage.value =
          response?.message ?? 'Registration failed. Please try again.'
      }
    } else {
      errorMessage.value = 'Something went wrong. Please try again.'
    }
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
    <main class="auth-page">
        <form class="auth-form" @submit.prevent="handleRegister">
            <h1>Create Account</h1>
            <p>Create your account to start a membership application.</p>
            <small><span class="required-marker" aria-hidden="true">*</span> Required field</small>

            <label for="full_name">Full Name <span class="required-marker" aria-hidden="true">*</span></label>
            <input
            id="full_name"
            v-model.trim="form.full_name"
            type="text"
            autocomplete="name"
            required
            />
            <small id="fullname-help">Enter your full name as shown on your identity document.</small>
            <small v-if="fieldErrors.full_name" class="error">
                {{ fieldErrors.full_name[0] }}
            </small>

            <label for="identity_number">Identity Number <span class="required-marker" aria-hidden="true">*</span></label>
            <input
            id="identity_number"
            v-model.trim="form.identity_number"
            type="text"
            autocomplete="off"
            placeholder="e.g. 990101-23-4455"
            aria-describedby="identity-help"
            required
            />
            <small id="identity-help">Enter your Malaysian NRIC or other identity document number.</small>
            <small v-if="fieldErrors.identity_number" class="error">
                {{ fieldErrors.identity_number[0] }}
            </small>

            <label for="date_of_birth">Date of Birth <span class="required-marker" aria-hidden="true">*</span></label>
            <input
            id="date_of_birth"
            v-model="form.date_of_birth"
            type="date"
            :max="latestBirthDate()"
            autocomplete="bday"
            required
            aria-describedby="dob-help"
            :aria-invalid="!!fieldErrors.date_of_birth"
            @input="dobEdited = true"
            />
            <small id="dob-help" class="dob-warning">
                Select your date of birth or enter it using the date field. Malaysian NRIC numbers can suggest a date of birth. Check the full year
                and correct it if needed: the century cannot be determined from NRIC alone.
                For other identity documents, enter your date of birth manually.
                Date of birth cannot be edited after account creation.
            </small>
            <small v-if="fieldErrors.date_of_birth" class="error" role="alert">
                {{ fieldErrors.date_of_birth[0] }}
            </small>

            <label for="email">Email <span class="required-marker" aria-hidden="true">*</span></label>
            <input
            id="email"
            v-model.trim="form.email"
            type="email"
            autocomplete="email"
            placeholder="e.g. name@example.com"
            aria-describedby="email-help"
            required
            />
            <small id="email-help">A verification link will be sent to this email address.</small>
            <small v-if="fieldErrors.email" class="error">
                {{ fieldErrors.email[0] }}
            </small>

            <label for="password">Password <span class="required-marker" aria-hidden="true">*</span></label>
            <input
            id="password"
            v-model="form.password"
            placeholder="Create a password with at least 8 characters"
            aria-describedby="password-help"
            type="password"
            autocomplete="new-password"
            minlength="8"
            required
            />
            <small id="password-help">Use at least 8 characters.</small>
            <small v-if="fieldErrors.password" class="error">
                {{ fieldErrors.password[0] }}
            </small>

            <label for="password_confirmation">Confirm Password <span class="required-marker" aria-hidden="true">*</span></label>
            <input
            id="password_confirmation"
            v-model="form.password_confirmation"
            placeholder="Re-enter your password"
            type="password"
            autocomplete="new-password"
            minlength="8"
            required
            />
            <small v-if="fieldErrors.password_confirmation" class="error">
                {{ fieldErrors.password_confirmation[0] }}
            </small>

            <p v-if="errorMessage" role="alert" class="error">
                {{ errorMessage }}
            </p>

            <button type="submit" :disabled="isSubmitting">
                {{ isSubmitting ? 'Creating account...' : 'Create Account' }}
            </button>

            <p>
                Already have an account?
                <RouterLink to="/login">Sign In</RouterLink>
            </p>
        </form>
    </main>
</template>

<style scoped>
.auth-page {
    display: grid;
    min-height: 100vh;
    place-items: center;
    padding: 1.5rem;
    background: #f5f8f7;
    color: #1c3029;
}
.auth-form {
    display: grid;
    gap: 0.65rem;
    box-sizing: border-box;
    width: min(100%, 520px);
    padding: clamp(1.25rem, 4vw, 2rem);
    background: #fff;
    border: 1px solid #d8e3de;
    border-radius: 12px;
}
h1, p { margin: 0 0 0.5rem; }
.auth-form label { font-weight: 600; margin-top: 0.65rem; }
.auth-form input {
    box-sizing: border-box;
    width: 100%;
    min-height: 44px;
    padding: 0.75rem;
    border: 1px solid #82968b;
    border-radius: 6px;
    font: inherit;
}
.auth-form input::placeholder { color: #617168; opacity: 1; }
.auth-form input:focus-visible, .auth-form button:focus-visible {
    outline: 3px solid #a9d8c5;
    outline-offset: 2px;
}
small { line-height: 1.5; color: #4c6055; }
.auth-form button {
    min-height: 44px;
    margin-top: 1rem;
    padding: 0.75rem;
    border: 0;
    border-radius: 6px;
    background: #185940;
    color: #fff;
    font: inherit;
    font-weight: 600;
    cursor: pointer;
}
.auth-form button:disabled { cursor: wait; opacity: 0.6; }
.error { color: #b42318; }
.required-marker { color: #b42318; }
.required-note { font-size: 0.875rem; }
.dob-warning { padding: 0.85rem 1rem; border: 1px solid #e8c77c; border-left: 4px solid #ad7413; border-radius: 6px; background: #fff7e3; color: #704a0b; line-height: 1.6; }
</style>
