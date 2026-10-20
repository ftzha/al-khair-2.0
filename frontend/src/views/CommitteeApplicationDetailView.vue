<script setup lang="ts">
import { formatMalaysiaDate, formatMalaysiaDateTime } from '@/services/dateFormatting'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { committeeRegistrationsApi } from '@/services/committeeRegistrations'
import { registrationError } from '@/services/registrations'
import type { CommitteeApplicationDetail } from '@/types/committee'
import { registrationStatusLabels, reviewDecisionLabels, type ReviewDecision, type RegistrationStatus } from '@/types/registration'

const route = useRoute()
// Full identity stays in component memory only and is cleared on hide/navigation.
const revealedIdentity = ref<string | null>(null)
const revealingIdentity = ref(false)
const identityError = ref('')
let identityVersion = 0
let hideTimer: ReturnType<typeof setTimeout> | null = null

function hideIdentity() {
  identityVersion++
  revealedIdentity.value = null
  revealingIdentity.value = false
  if (hideTimer) clearTimeout(hideTimer)
  hideTimer = null
}

async function revealIdentity() {
  if (!application.value || revealingIdentity.value) return
  const version = ++identityVersion
  const id = application.value.id
  revealingIdentity.value = true
  identityError.value = ''
  try {
    const identity = await committeeRegistrationsApi.revealIdentity(id)
    if (version !== identityVersion) return
    revealedIdentity.value = identity
    hideTimer = setTimeout(hideIdentity, 60000)
  } catch (error) {
    if (version === identityVersion) identityError.value = registrationError(error).message
  } finally {
    if (version === identityVersion) revealingIdentity.value = false
  }
}

function handleVisibilityChange() {
  if (document.hidden) hideIdentity()
}
onMounted(() => document.addEventListener('visibilitychange', handleVisibilityChange))
onUnmounted(() => {
  hideIdentity()
  document.removeEventListener('visibilitychange', handleVisibilityChange)
})
const application = ref<CommitteeApplicationDetail | null>(null)
const loading = ref(true)
const reviewing = ref(false)
const reviewAllowed = ref(true)
const confirming = ref(false)
const decision = ref<ReviewDecision>('APPROVE')
const reason = ref('')
const errorMessage = ref('')
const successMessage = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const canReview = computed(() => reviewAllowed.value && application.value?.status === 'PENDING_REVIEW' && application.value.submissions.length > 0)
const needsReason = computed(() => decision.value !== 'APPROVE')
let requestVersion = 0

async function loadApplication() {
  hideIdentity()
  identityError.value = ''
  const version = ++requestVersion
  loading.value = true
  application.value = null
  confirming.value = false
  reviewAllowed.value = true
  errorMessage.value = ''
  successMessage.value = ''
  fieldErrors.value = {}
  decision.value = 'APPROVE'
  reason.value = ''
  try {
    const id = Number(route.params.id)
    if (!Number.isSafeInteger(id) || id < 1) {
      errorMessage.value = 'This application could not be found.'
      return
    }
    const data = await committeeRegistrationsApi.detail(id)
    if (version === requestVersion) application.value = data
  } catch (error) {
    if (version === requestVersion) errorMessage.value = registrationError(error).message
  } finally {
    if (version === requestVersion) loading.value = false
  }
}

function prepareReview() {
  if (!canReview.value || reviewing.value) return
  errorMessage.value = ''
  fieldErrors.value = {}
  if (needsReason.value && !reason.value.trim()) {
    fieldErrors.value = { reason: ['A reason is required for this decision.'] }
    return
  }
  confirming.value = true
}

async function confirmReview() {
  if (!application.value || !canReview.value || reviewing.value || !confirming.value) return
  const version = requestVersion
  const id = application.value.id
  hideIdentity()
  const chosenDecision = decision.value
  reviewing.value = true
  errorMessage.value = ''
  successMessage.value = ''
  fieldErrors.value = {}
  try {
    await committeeRegistrationsApi.review(id, {
      decision: chosenDecision,
      reason: reason.value.trim() || null,
    })
    if (version !== requestVersion || !application.value) return
    // Lock immediately; a failed history refresh must never re-enable the decision.
    const statuses: Record<ReviewDecision, RegistrationStatus> = {
      APPROVE: 'APPROVED', REJECT: 'REJECTED', REQUEST_CHANGES: 'CHANGES_REQUESTED',
    }
    application.value.status = statuses[chosenDecision]
    reviewAllowed.value = false
    confirming.value = false
    successMessage.value = `Decision recorded: ${reviewDecisionLabels[chosenDecision]}.`
    try {
      const updated = await committeeRegistrationsApi.detail(id)
      if (version === requestVersion) application.value = updated
    } catch {
      if (version === requestVersion) errorMessage.value = 'The decision was recorded, but history could not be refreshed. Reload the application.'
    }
  } catch (error) {
    if (version !== requestVersion) return
    const failure = registrationError(error)
    errorMessage.value = failure.message
    fieldErrors.value = failure.fields
    confirming.value = false
    // Laravel enforces self-review and role checks. A forbidden response disables review.
    if (failure.status === 401 || failure.status === 403) reviewAllowed.value = false
    // Refresh after errors because another reviewer or an uncertain connection may
    // have completed the decision. Do not automatically retry a write request.
    try {
      const latest = await committeeRegistrationsApi.detail(id)
      if (version === requestVersion) application.value = latest
    } catch {
      if (version === requestVersion) {
        application.value = null
        errorMessage.value += ' Reload the application before trying again.'
      }
    }
  } finally {
    reviewing.value = false
  }
}

// Editing a proposed decision requires a fresh confirmation.
watch([decision, reason], () => { confirming.value = false })
watch(() => route.params.id, loadApplication, { immediate: true })
</script>

<template>
  <main>
    <RouterLink class="back-link" :to="{ name: 'committee-applications' }">Back to review queue</RouterLink>
    <p class="eyebrow">al Khair · Committee</p>
    <h1>Review Application</h1>
    <p class="intro">Check the latest submitted details and earlier feedback before recording your decision.</p>
    <p v-if="loading" role="status">Loading application...</p>
    <p v-if="errorMessage" class="notice error" role="alert">{{ errorMessage }}</p>
    <ul v-if="Object.keys(fieldErrors).length" role="alert">
      <template v-for="(messages, field) in fieldErrors" :key="field">
        <li v-for="message in messages" :key="message">{{ message }}</li>
      </template>
    </ul>
    <p v-if="successMessage" class="notice success" role="status">{{ successMessage }}</p>
    <button v-if="!loading" type="button" :disabled="reviewing" @click="loadApplication">Reload application</button>
    <template v-if="application && !loading">
      <h2>Application #{{ application.id }} — {{ application.applicant_name }}</h2>
      <p class="status-row">Status: <span class="badge" :class="application.status.toLowerCase()">{{ registrationStatusLabels[application.status] }}</span></p>
      <section aria-labelledby="identity-heading">
        <h2 id="identity-heading">Identity number</h2>
        <p class="identity-value">{{ revealedIdentity ?? application.masked_identity_number }}</p>
        <p v-if="identityError" role="alert">{{ identityError }}</p>
        <button v-if="revealedIdentity" type="button" @click="hideIdentity">Hide identity number</button>
        <button v-else type="button" :disabled="revealingIdentity || reviewing" @click="revealIdentity">
          {{ revealingIdentity ? 'Loading identity...' : 'Reveal identity number' }}
        </button>
        <p class="privacy-note">Reveal only when needed for identity verification. Access is recorded. The number hides after 60 seconds or when this tab is hidden.</p>
      </section>
      <section aria-labelledby="history-heading">
        <h2 id="history-heading">Submitted revisions</h2>
        <p class="muted">Latest revision first. Each revision preserves the details submitted at that time.</p>
        <p v-if="application.submissions.length === 0">No submitted revisions are available.</p>
        <article v-for="submission in application.submissions.slice().reverse()" :key="submission.id">
          <h3>Revision {{ submission.revision }} <span v-if="submission.id === application.submissions[application.submissions.length - 1]?.id" class="badge latest">Latest submission</span></h3>
          <p>Submitted: {{ formatMalaysiaDateTime(submission.submitted_at) }}</p>
          <dl>
            <dt>Name</dt><dd>{{ submission.snapshot.full_name }}</dd>
            <dt>Date of birth</dt><dd>{{ formatMalaysiaDate(submission.snapshot.date_of_birth) }}</dd>
            <dt>Phone number</dt><dd>{{ submission.snapshot.phone_number ?? 'Not provided' }}</dd>
            <dt>Address</dt><dd>{{ submission.snapshot.address ?? 'Not provided' }}</dd>
          </dl>
          <p v-if="submission.reviews.length === 0">No review decision recorded.</p>
          <div v-for="(review, index) in submission.reviews" :key="index" class="review-feedback">
            <p>{{ reviewDecisionLabels[review.decision] }} — {{ formatMalaysiaDateTime(review.reviewed_at) }}</p>
            <p v-if="review.reason" class="review-reason">{{ review.reason }}</p>
          </div>
        </article>
      </section>
      <form v-if="canReview" @submit.prevent="prepareReview">
        <h2>Review latest submission</h2>
        <p class="muted">Review the latest submitted revision. You cannot review your own submission.</p>
        <fieldset :disabled="reviewing">
          <legend>Committee decision</legend>
          <p><span class="required-marker" aria-hidden="true">*</span> Required field</p>
          <label for="review-decision">Decision <span class="required-marker" aria-hidden="true">*</span></label>
          <select id="review-decision" v-model="decision">
            <option value="APPROVE">Approve</option>
            <option value="REJECT">Reject</option>
            <option value="REQUEST_CHANGES">Request changes</option>
          </select>
          <label for="review-reason">Reason <span v-if="needsReason" class="required-marker" aria-hidden="true">*</span><span v-else> (optional)</span></label>
          <textarea id="review-reason" v-model="reason" maxlength="2000" rows="5" placeholder="Explain the decision and, if changes are needed, tell the applicant what to update." aria-describedby="review-help" :required="needsReason" :aria-invalid="!!fieldErrors.reason" />
          <small id="review-help" class="muted">A reason is required for rejection or a change request. Maximum 2,000 characters.</small>
          <button v-if="!confirming" type="submit">Review decision</button>
          <div v-else class="confirmation">
            <p>Confirm this decision: {{ reviewDecisionLabels[decision] }}. This records a decision against the latest submitted revision.</p>
            <button type="button" @click="confirmReview">{{ reviewing ? 'Recording...' : 'Confirm decision' }}</button>
            <button type="button" @click="confirming = false">Cancel</button>
          </div>
        </fieldset>
      </form>
      <p v-else>Review actions are unavailable for this application.</p>
      <p class="footer-note">Application approval does not activate membership.</p>
    </template>
  </main>
</template>

<style scoped>
main { max-width: 60rem; margin: 2rem auto; padding: 0 1rem 2rem; color: #1c3029; }
.back-link { display: inline-block; margin-bottom: 1.5rem; color: #185940; }
.eyebrow { margin: 0; color: #185940; font-size: 0.85rem; font-weight: 600; }
h1 { margin: 0.3rem 0 0.5rem; }
h2 { font-size: 1.2rem; overflow-wrap: anywhere; }
h3 { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; font-size: 1.05rem; }
p { line-height: 1.6; }
.intro, .muted, .footer-note { color: #4c6055; }
section, form { margin-top: 1.5rem; padding: clamp(1rem, 3vw, 1.5rem); border: 1px solid #d8e3de; border-radius: 12px; background: #fff; }
section > h2, form > h2 { margin-top: 0; }
.badge { display: inline-block; border-radius: 999px; padding: 0.3rem 0.75rem; font-size: 0.8rem; font-weight: 600; background: #eef1f0; color: #41564b; }
.pending_review { background: #edf4fc; color: #234f7a; }
.changes_requested { background: #fff4d8; color: #78520b; }
.approved, .latest { background: #e6f5ec; color: #185940; }
.rejected { background: #fff0ed; color: #a52b20; }
.identity-value { font-family: ui-monospace, monospace; font-size: 1.2rem; letter-spacing: 0.08em; overflow-wrap: anywhere; }
.privacy-note, .confirmation { padding: 1rem; border: 1px solid #e8c77c; border-radius: 8px; background: #fff7e3; color: #704a0b; }
.privacy-note { font-size: 0.9rem; }
article { margin-top: 1.25rem; padding: 1rem; border: 1px solid #d8e3de; border-radius: 8px; }
dl { display: grid; grid-template-columns: 9rem minmax(0, 1fr); gap: 0.75rem 1rem; }
dt { color: #4c6055; }
dd { margin: 0; }
.review-feedback { margin-top: 1rem; padding: 0.75rem 1rem; border-left: 3px solid #8caf9c; background: #f5f8f7; border-radius: 4px; }
.review-feedback p { margin: 0.3rem 0; }
fieldset { min-width: 0; border: 0; margin: 0; padding: 0; }
legend { font-weight: 600; }
label, select, textarea, small { display: block; }
label { margin-top: 1rem; font-weight: 600; }
select, textarea { box-sizing: border-box; width: 100%; padding: 0.75rem; margin: 0.5rem 0; border: 1px solid #82968b; border-radius: 6px; background: #fff; font: inherit; }
textarea { min-height: 8rem; resize: vertical; }
textarea::placeholder { color: #617168; opacity: 1; }
small { line-height: 1.5; }
button { min-height: 44px; margin: 0.75rem 0.5rem 0 0; padding: 0.65rem 1rem; border: 1px solid #185940; border-radius: 6px; background: #fff; color: #185940; font: inherit; cursor: pointer; }
button[type='submit'], .confirmation button:first-of-type { background: #185940; color: #fff; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
a:focus-visible, button:focus-visible, select:focus-visible, textarea:focus-visible { outline: 3px solid #a9d8c5; outline-offset: 3px; }
.confirmation { margin-top: 1rem; }
.notice { padding: 1rem; border-radius: 8px; }
.error { background: #fff0ed; color: #b42318; }
.success { background: #edf8f1; color: #185940; }
.review-reason, dd { white-space: pre-wrap; overflow-wrap: anywhere; }
.required-marker { color: #b42318; }
.footer-note { padding: 0 0.25rem; font-size: 0.9rem; }
@media (max-width: 480px) { dl { grid-template-columns: 1fr; gap: 0.3rem; } dd { margin-bottom: 0.75rem; } }
</style>
