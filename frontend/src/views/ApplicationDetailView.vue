<script setup lang="ts">
import { formatMalaysiaDate, formatMalaysiaDateTime } from '@/services/dateFormatting'
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
    <p class="intro">Check your identity information and provide your current contact details before submitting for review.</p>
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
        <p>Identity number: {{ application.applicant.masked_identity_number }}</p>
        <p>Date of birth: {{ formatMalaysiaDate(application.applicant?.date_of_birth) }}</p>
        <p class="field-hint">These details come from your account and cannot be edited here.</p>
      </section>
      <form @submit.prevent="saveDraft">
        <h2>Contact details</h2>
        <p v-if="!editable">Contact details cannot be edited while this application is {{ registrationStatusLabels[application.status].toLowerCase() }}.</p>
        <fieldset :disabled="!editable || busy">
          <legend>Application contact details</legend>
          <label for="phone-number">Phone number <span class="optional">(optional)</span></label>
          <input id="phone-number" v-model="phoneNumber" type="tel" maxlength="30" autocomplete="tel" placeholder="e.g. 012-345 6789" aria-describedby="phone-help" :aria-invalid="!!fieldErrors.phone_number" />
          <small id="phone-help" class="field-hint">Enter a contact number where you can be reached.</small>
          <label for="address">Address <span class="optional">(optional)</span></label>
          <textarea id="address" v-model="address" maxlength="1000" autocomplete="street-address" rows="4" placeholder="House/unit number, street, area, postcode, town and state" aria-describedby="address-help" :aria-invalid="!!fieldErrors.address" />
          <small id="address-help" class="field-hint">Include your postcode, town and state. You can save a draft and return later.</small>
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
      <section aria-labelledby="history-heading" class="history-section">
        <header class="history-header">
          <div>
            <h2 id="history-heading">Submission history</h2>
            <p class="history-description">Latest revision first. Each record preserves the details you submitted at that time.</p>
          </div>
          <span v-if="application.submissions.length" class="revision-count">{{ application.submissions.length }} {{ application.submissions.length === 1 ? 'revision' : 'revisions' }}</span>
        </header>
        <div v-if="application.submissions.length === 0" class="history-empty">
          <p>No submissions yet.</p>
          <p>Your submitted details and committee feedback will appear here after you submit your application.</p>
        </div>
        <ol v-else class="revision-list" aria-label="Application submission revisions">
          <li v-for="submission in application.submissions.slice().reverse()" :key="submission.id">
            <article class="revision-card" :aria-labelledby="'revision-' + submission.id">
              <header class="revision-header">
                <div class="revision-title">
                  <h3 :id="'revision-' + submission.id">Revision {{ submission.revision }}</h3>
                  <span v-if="submission.id === application.submissions[application.submissions.length - 1]?.id" class="history-badge latest">Latest submission</span>
                </div>
                <p class="submitted-at">Submitted: {{ formatMalaysiaDateTime(submission.submitted_at) }}</p>
              </header>
              <div v-if="submission.reviews.length === 0" class="review-status awaiting">
                <strong>Awaiting committee review.</strong>
                <p>A decision has not been recorded for this revision yet.</p>
              </div>
              <div v-for="(review, index) in submission.reviews" :key="index" class="review-status" :class="review.decision.toLowerCase()">
                <div class="decision-header">
                  <strong>{{ reviewDecisionLabels[review.decision] }}</strong>
                  <span class="reviewed-at">{{ formatMalaysiaDateTime(review.reviewed_at) }}</span>
                </div>
                <p v-if="review.reason" class="review-reason">{{ review.reason }}</p>
                <p v-else>No additional comments.</p>
              </div>
              <details class="submitted-details" :open="submission.id === application.submissions[application.submissions.length - 1]?.id">
                <summary>Submitted details <span class="summary-hint">View the information in this revision</span></summary>
                <dl class="snapshot-grid">
                  <div><dt>Name</dt><dd>{{ submission.snapshot.full_name }}</dd></div>
                  <div><dt>Date of birth</dt><dd>{{ formatMalaysiaDate(submission.snapshot.date_of_birth) }}</dd></div>
                  <div><dt>Phone number</dt><dd>{{ submission.snapshot.phone_number || 'Not provided' }}</dd></div>
                  <div class="address-detail"><dt>Address</dt><dd>{{ submission.snapshot.address || 'Not provided' }}</dd></div>
                </dl>
              </details>
            </article>
          </li>
        </ol>
      </section>
    </template>
  </main>
</template>

<style scoped>
main { max-width: 48rem; margin: 2rem auto; padding: 0 1rem; color: #1c3029; }
h1 { margin-bottom: 0.5rem; }
.intro, .field-hint { color: #4c6055; line-height: 1.5; }
section, form { margin-top: 1.5rem; padding: clamp(1rem, 3vw, 1.5rem); border: 1px solid #d8e3de; border-radius: 10px; background: #fff; }
h2 { margin-top: 0; font-size: 1.2rem; }
fieldset { min-width: 0; margin: 0; padding: 0; border: 0; }
legend { margin-bottom: 0.75rem; font-weight: 600; }
label, input, textarea, .field-hint { display: block; }
label { margin-top: 1rem; font-weight: 600; }
.optional { color: #4c6055; font-size: 0.9rem; font-weight: 400; }
input, textarea { box-sizing: border-box; width: 100%; margin: 0.5rem 0 0.4rem; padding: 0.75rem; border: 1px solid #82968b; border-radius: 6px; font: inherit; }
input { min-height: 44px; }
textarea { min-height: 7rem; resize: vertical; }
input::placeholder, textarea::placeholder { color: #617168; opacity: 1; }
input:focus-visible, textarea:focus-visible, button:focus-visible { outline: 3px solid #a9d8c5; outline-offset: 2px; }
button { min-height: 44px; margin: 0.75rem 0.5rem 0 0; padding: 0.6rem 1rem; border: 1px solid #185940; border-radius: 6px; background: #fff; color: #185940; font: inherit; cursor: pointer; }
button[type='submit'] { background: #185940; color: #fff; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
input:disabled, textarea:disabled { background: #f3f6f4; color: #4c6055; }

.review-reason, dd { white-space: pre-wrap; overflow-wrap: anywhere; }
.history-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.25rem; }
.history-header h2 { margin-bottom: 0.5rem; }
.history-description { margin: 0; color: #4c6055; font-size: 0.9rem; line-height: 1.6; }
.revision-count, .history-badge { display: inline-block; padding: 0.3rem 0.65rem; border-radius: 999px; font-size: 0.8rem; font-weight: 600; white-space: nowrap; }
.revision-count { background: #eef3f0; color: #41564b; }
.latest { background: #e6f5ec; color: #185940; }
.history-empty { padding: 1rem; border-radius: 8px; background: #f5f8f7; color: #4c6055; }
.history-empty p { margin: 0.3rem 0; line-height: 1.6; }
.revision-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 1.25rem; }
.revision-card { border: 1px solid #d8e3de; border-radius: 10px; overflow: hidden; }
.revision-header { padding: 1rem; background: #f5f8f7; }
.revision-title { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; }
.revision-title h3 { margin: 0; font-size: 1.05rem; }
.submitted-at { margin: 0.6rem 0 0; color: #4c6055; font-size: 0.85rem; }
.review-status { margin: 1rem; padding: 0.9rem 1rem; border-left: 3px solid #8caf9c; border-radius: 6px; background: #f3faf5; }
.review-status p { margin: 0.5rem 0 0; line-height: 1.6; }
.awaiting { background: #edf4fc; color: #234f7a; border-color: #7099c4; }
.approve { background: #edf8f1; color: #185940; border-color: #5a9873; }
.request_changes { background: #fff7e3; color: #704a0b; border-color: #c99a3d; }
.reject { background: #fff0ed; color: #9f2c23; border-color: #c97870; }
.decision-header { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 0.5rem; }
.reviewed-at { font-size: 0.8rem; }
.submitted-details { border-top: 1px solid #d8e3de; }
summary { padding: 1rem; cursor: pointer; color: #185940; font-weight: 600; line-height: 1.5; }
.summary-hint { display: block; margin: 0.25rem 0 0 1rem; color: #4c6055; font-size: 0.8rem; font-weight: 400; }
summary:focus-visible { outline: 3px solid #a9d8c5; outline-offset: -3px; }
.snapshot-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem 1.5rem; margin: 0; padding: 0 1rem 1rem; }
.snapshot-grid dt { margin-bottom: 0.35rem; color: #4c6055; font-size: 0.85rem; }
.snapshot-grid dd { margin: 0; line-height: 1.6; }
.address-detail { grid-column: 1 / -1; }
@media (max-width: 480px) { .snapshot-grid { grid-template-columns: 1fr; } .history-header { flex-wrap: wrap; } }
</style>
