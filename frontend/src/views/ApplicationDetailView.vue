<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { registrationsApi, registrationError } from '@/services/registrations'
import { canEditRegistration, registrationStatusLabels, reviewDecisionLabels, type RegistrationDetail } from '@/types/registration'

const route = useRoute()
const auth = useAuthStore()
const application = ref<RegistrationDetail | null>(null)
const loading = ref(true)
const saving = ref(false)
const submitting = ref(false)
const verifying = ref(false)
const confirmingSubmission = ref(false)
const verificationMessage = ref('')
const busy = computed(() => saving.value || submitting.value || verifying.value)
const phoneNumber = ref('')
const address = ref('')
const errorMessage = ref('')
const savedMessage = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const editable = computed(() => application.value !== null && canEditRegistration(application.value.status))
// Ignore stale responses if the user navigates to a different application mid-request.
let requestVersion = 0

async function loadApplication() {
  const version = ++requestVersion
  application.value = null
  loading.value = true
  errorMessage.value = ''
  savedMessage.value = ''
  fieldErrors.value = {}
  confirmingSubmission.value = false
  const id = Number(route.params.id)
  try {
    if (!Number.isSafeInteger(id) || id < 1) {
      errorMessage.value = 'This application could not be found.'
      return
    }
    const data = await registrationsApi.detail(id)
    if (version !== requestVersion) return
    application.value = data
    phoneNumber.value = data.draft_data?.phone_number ?? ''
    address.value = data.draft_data?.address ?? ''
  } catch (error) {
    if (version === requestVersion) errorMessage.value = registrationError(error).message
  } finally {
    if (version === requestVersion) loading.value = false
  }
}

async function saveDraft() {
  if (!application.value || !editable.value || busy.value) return
  const version = requestVersion
  const id = application.value.id
  saving.value = true
  errorMessage.value = ''
  savedMessage.value = ''
  fieldErrors.value = {}
  try {
    const result = await registrationsApi.update(id, {
      phone_number: phoneNumber.value,
      address: address.value,
    })
    if (version !== requestVersion || !application.value) return
    application.value.status = result.status
    application.value.draft_data = result.draft_data
    savedMessage.value = 'Application draft saved.'
  } catch (error) {
    if (version !== requestVersion) return
    const failure = registrationError(error)
    fieldErrors.value = failure.fields
    errorMessage.value = failure.message
    // A reviewer may have changed the status since this page loaded.
    // Laravel's rejection is authoritative; the user can reload to see the latest state.
  } finally {
    saving.value = false
  }
}

async function refreshVerification() {
  if (busy.value) return
  verifying.value = true
  verificationMessage.value = ''
  try {
    await auth.refreshUser()
    verificationMessage.value = auth.user?.email_verified
      ? 'Your email address is verified.'
      : 'Your email address has not been verified yet.'
  } catch (error) {
    errorMessage.value = registrationError(error).message
  } finally {
    verifying.value = false
  }
}

async function resendVerification() {
  if (busy.value) return
  verifying.value = true
  verificationMessage.value = ''
  try {
    verificationMessage.value = await auth.resendVerificationEmail()
  } catch (error) {
    errorMessage.value = registrationError(error).message
  } finally {
    verifying.value = false
  }
}

async function submitApplication() {
  if (!application.value || !editable.value || busy.value || !confirmingSubmission.value) return
  const version = requestVersion
  const id = application.value.id
  submitting.value = true
  errorMessage.value = ''
  savedMessage.value = ''
  fieldErrors.value = {}
  try {
    // Refresh from Laravel so verification in another tab is reflected here.
    await auth.refreshUser()
    if (version !== requestVersion) return
    if (!auth.user?.email_verified) {
      errorMessage.value = 'Please verify your email before submitting.'
      confirmingSubmission.value = false
      return
    }
    // Save first so the immutable submission snapshot contains the visible form values.
    await registrationsApi.update(id, {
      phone_number: phoneNumber.value,
      address: address.value,
    })
    if (version !== requestVersion) return
    const submission = await registrationsApi.submit(id)
    if (version !== requestVersion || !application.value) return
    // Lock immediately even if loading the updated history subsequently fails.
    application.value.status = 'PENDING_REVIEW'
    confirmingSubmission.value = false
    savedMessage.value = `Revision ${submission.revision} submitted for review.`
    try {
      const updated = await registrationsApi.detail(id)
      if (version === requestVersion) application.value = updated
    } catch {
      if (version === requestVersion) {
        errorMessage.value = 'Your submission succeeded, but history could not be refreshed. Reload the application.'
      }
    }
  } catch (error) {
    if (version !== requestVersion) return
    const failure = registrationError(error)
    errorMessage.value = failure.message
    fieldErrors.value = failure.fields
    // A connection failure may occur after the server accepted a submission.
    // Require a fresh detail response before allowing another attempt.
    if (!failure.status || failure.status === 409 || failure.fields.registration) {
      try {
        const latest = await registrationsApi.detail(id)
        if (version === requestVersion) application.value = latest
      } catch {
        if (version !== requestVersion) return
        application.value = null
        errorMessage.value += ' Reload the application before trying again.'
      }
    }
    confirmingSubmission.value = false
  } finally {
    submitting.value = false
  }
}

watch(() => route.params.id, loadApplication, { immediate: true })
</script>

<template>
  <main>
    <RouterLink :to="{ name: 'applications' }">Back to my applications</RouterLink>
    <h1>Membership Application</h1>
    <p v-if="loading" role="status">Loading application...</p>
    <p v-if="errorMessage" role="alert">{{ errorMessage }}</p>
    <ul v-if="Object.keys(fieldErrors).length" role="alert">
      <template v-for="(messages, field) in fieldErrors" :key="field">
        <li v-for="message in messages" :key="message">{{ message }}</li>
      </template>
    </ul>
    <p v-if="savedMessage" role="status">{{ savedMessage }}</p>
    <button v-if="!loading" type="button" :disabled="busy" @click="loadApplication">Reload application</button>
    <template v-if="application && !loading">
      <p>Application #{{ application.id }} — {{ registrationStatusLabels[application.status] }}</p>
      <section aria-labelledby="identity-heading">
        <h2 id="identity-heading">Identity information</h2>
        <p>Name: {{ application.applicant?.full_name ?? auth.user?.full_name }}</p>
        <p>Date of birth: {{ application.applicant?.date_of_birth ?? 'Not available' }}</p>
        <p>Identity information is read-only.</p>
        <p>Identity number: {{ application.applicant.masked_identity_number }}</p>
      </section>
      <form @submit.prevent="saveDraft">
        <h2>Contact details</h2>
        <p v-if="!editable">Contact details cannot be edited while this application is {{ registrationStatusLabels[application.status].toLowerCase() }}.</p>
        <fieldset :disabled="!editable || busy">
          <legend>Application contact details</legend>
          <label for="phone-number">Phone number</label>
          <input id="phone-number" v-model="phoneNumber" type="tel" maxlength="30" autocomplete="tel" :aria-invalid="!!fieldErrors.phone_number" />
          <label for="address">Address</label>
          <textarea id="address" v-model="address" maxlength="1000" autocomplete="street-address" :aria-invalid="!!fieldErrors.address" />
          <button v-if="editable" type="submit">{{ saving ? 'Saving...' : 'Save draft' }}</button>
        </fieldset>
      </form>
      <section v-if="editable" aria-labelledby="submission-heading">
        <h2 id="submission-heading">Submit for review</h2>
        <template v-if="!auth.user?.email_verified">
          <p>Please verify your email before submitting. You can still save your draft.</p>
          <button type="button" :disabled="busy" @click="resendVerification">Resend verification email</button>
          <button type="button" :disabled="busy" @click="refreshVerification">Refresh verification status</button>
        </template>
        <p v-if="verificationMessage" role="status">{{ verificationMessage }}</p>
        <template v-if="confirmingSubmission">
          <p>Your current contact details will be saved and submitted. You cannot edit them while the application is under review.</p>
          <button type="button" :disabled="busy" @click="submitApplication">
            {{ submitting ? 'Submitting...' : 'Confirm submission' }}
          </button>
          <button type="button" :disabled="busy" @click="confirmingSubmission = false">Cancel</button>
        </template>
        <button v-else type="button" :disabled="busy || !auth.user?.email_verified" @click="confirmingSubmission = true">
          {{ application.status === 'CHANGES_REQUESTED' ? 'Save and resubmit for review' : 'Save and submit for review' }}
        </button>
      </section>
      <section aria-labelledby="history-heading">
        <h2 id="history-heading">Submission history</h2>
        <p v-if="application.submissions.length === 0">No submissions yet.</p>
        <article v-for="submission in application.submissions" :key="submission.id">
          <h3>Revision {{ submission.revision }}</h3>
          <p>Submitted: {{ new Date(submission.submitted_at).toLocaleString() }}</p>
          <dl>
            <dt>Name</dt><dd>{{ submission.snapshot.full_name }}</dd>
            <dt>Date of birth</dt><dd>{{ submission.snapshot.date_of_birth ?? 'Not available' }}</dd>
            <dt>Phone number</dt><dd>{{ submission.snapshot.phone_number ?? 'Not provided' }}</dd>
            <dt>Address</dt><dd>{{ submission.snapshot.address ?? 'Not provided' }}</dd>
          </dl>
          <p v-if="submission.reviews.length === 0">Awaiting committee review.</p>
          <div v-for="(review, index) in submission.reviews" :key="index">
            <p>{{ reviewDecisionLabels[review.decision] }} — {{ new Date(review.reviewed_at).toLocaleString() }}</p>
            <p v-if="review.reason" class="review-reason">{{ review.reason }}</p>
          </div>
        </article>
      </section>
    </template>
  </main>
</template>

<style scoped>
main { max-width: 48rem; margin: 2rem auto; padding: 0 1rem; }
label, input, textarea { display: block; }
input, textarea { box-sizing: border-box; width: 100%; margin: .5rem 0 1rem; }
textarea { min-height: 6rem; }
.review-reason, dd { white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
