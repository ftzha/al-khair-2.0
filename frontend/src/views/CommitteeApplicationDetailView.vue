<script setup lang="ts">
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
    <RouterLink :to="{ name: 'committee-applications' }">Back to review queue</RouterLink>
    <h1>Review Application</h1>
    <p v-if="loading" role="status">Loading application...</p>
    <p v-if="errorMessage" role="alert">{{ errorMessage }}</p>
    <ul v-if="Object.keys(fieldErrors).length" role="alert">
      <template v-for="(messages, field) in fieldErrors" :key="field">
        <li v-for="message in messages" :key="message">{{ message }}</li>
      </template>
    </ul>
    <p v-if="successMessage" role="status">{{ successMessage }}</p>
    <button v-if="!loading" type="button" :disabled="reviewing" @click="loadApplication">Reload application</button>
    <template v-if="application && !loading">
      <h2>Application #{{ application.id }} — {{ application.applicant_name }}</h2>
      <p>Status: {{ registrationStatusLabels[application.status] }}</p>
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
        <p v-if="application.submissions.length === 0">No submitted revisions are available.</p>
        <article v-for="submission in application.submissions" :key="submission.id">
          <h3>Revision {{ submission.revision }}</h3>
          <p>Submitted: {{ new Date(submission.submitted_at).toLocaleString() }}</p>
          <dl>
            <dt>Name</dt><dd>{{ submission.snapshot.full_name }}</dd>
            <dt>Date of birth</dt><dd>{{ submission.snapshot.date_of_birth ?? 'Not available' }}</dd>
            <dt>Phone number</dt><dd>{{ submission.snapshot.phone_number ?? 'Not provided' }}</dd>
            <dt>Address</dt><dd>{{ submission.snapshot.address ?? 'Not provided' }}</dd>
          </dl>
          <p v-if="submission.reviews.length === 0">No review decision recorded.</p>
          <div v-for="(review, index) in submission.reviews" :key="index">
            <p>{{ reviewDecisionLabels[review.decision] }} — {{ new Date(review.reviewed_at).toLocaleString() }}</p>
            <p v-if="review.reason" class="review-reason">{{ review.reason }}</p>
          </div>
        </article>
      </section>
      <form v-if="canReview" @submit.prevent="prepareReview">
        <h2>Review latest submission</h2>
        <p>You cannot review your own submission. Laravel checks this when recording the decision.</p>
        <fieldset :disabled="reviewing">
          <legend>Committee decision</legend>
          <label for="review-decision">Decision</label>
          <select id="review-decision" v-model="decision">
            <option value="APPROVE">Approve</option>
            <option value="REJECT">Reject</option>
            <option value="REQUEST_CHANGES">Request changes</option>
          </select>
          <label for="review-reason">Reason {{ needsReason ? '(required)' : '(optional)' }}</label>
          <textarea id="review-reason" v-model="reason" maxlength="2000" :required="needsReason" :aria-invalid="!!fieldErrors.reason" />
          <button v-if="!confirming" type="submit">Review decision</button>
          <template v-else>
            <p>Confirm this decision: {{ reviewDecisionLabels[decision] }}. This records a decision against the latest submitted revision.</p>
            <button type="button" @click="confirmReview">{{ reviewing ? 'Recording...' : 'Confirm decision' }}</button>
            <button type="button" @click="confirming = false">Cancel</button>
          </template>
        </fieldset>
      </form>
      <p v-else>Review actions are unavailable for this application.</p>
      <p>Application approval does not activate membership.</p>
    </template>
  </main>
</template>

<style scoped>
main { max-width: 56rem; margin: 2rem auto; padding: 0 1rem; }
article { border-bottom: 1px solid #ccc; padding-bottom: 1rem; }
label, select, textarea { display: block; }
select, textarea { box-sizing: border-box; width: 100%; margin: .5rem 0 1rem; }
textarea { min-height: 6rem; }
.review-reason, dd { white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
