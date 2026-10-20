<script setup lang="ts">
import { formatMalaysiaDateTime } from '@/services/dateFormatting'
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { registrationsApi, registrationError } from '@/services/registrations'
import { registrationStatusLabels, type RegistrationSummary } from '@/types/registration'

const router = useRouter()
const applications = ref<RegistrationSummary[]>([])
const loading = ref(true)
const creating = ref(false)
const errorMessage = ref('')
// Laravel also checks this restriction; this prevents offering an invalid action.
const canCreate = computed(() => !applications.value.some((item) => item.status !== 'REJECTED'))

async function loadApplications() {
  loading.value = true
  errorMessage.value = ''
  try {
    applications.value = await registrationsApi.list()
  } catch (error) {
    errorMessage.value = registrationError(error).message
  } finally {
    loading.value = false
  }
}

async function createDraft() {
  if (creating.value || loading.value || !canCreate.value) return
  creating.value = true
  errorMessage.value = ''
  try {
    const draft = await registrationsApi.create()
    // Refresh the list even if subsequent navigation fails, avoiding duplicate creation.
    await loadApplications()
    await router.push({ name: 'application-detail', params: { id: draft.id } })
  } catch (error) {
    const failure = registrationError(error)
    errorMessage.value = Object.values(failure.fields).flat().join(' ') || failure.message
  } finally {
    creating.value = false
  }
}

onMounted(loadApplications)
</script>

<template>
  <main class="applications-page">
    <header class="page-header">
      <div>
        <p class="eyebrow">al Khair · Membership</p>
        <h1>My Applications</h1>
        <p class="muted">Manage your membership applications and follow their progress.</p>
      </div>
      <button v-if="!loading" class="secondary" type="button" @click="loadApplications" :disabled="creating">Refresh applications</button>
    </header>
    <p v-if="loading" class="notice" role="status">Loading applications...</p>
    <p v-if="errorMessage" class="notice error" role="alert">{{ errorMessage }}</p>
    <template v-if="!loading && !errorMessage">
      <section v-if="applications.length === 0" class="empty-state">
        <button class="empty-symbol" type="button" aria-label="Create application draft" :disabled="creating || !canCreate" @click="createDraft"><span aria-hidden="true">+</span></button>
        <h2>Start your membership application</h2>
        <p>You have no membership applications yet.</p>
        <p class="muted">Create a draft, check your details and submit it for committee review when you are ready.</p>
      </section>
      <ul v-else class="application-list">
        <li v-for="application in applications" :key="application.id" class="application-card">
          <div class="application-header">
            <RouterLink class="application-title" :to="{ name: 'application-detail', params: { id: application.id } }">
              Application #{{ application.id }}
            </RouterLink>
            <span class="status-badge" :class="application.status.toLowerCase()">{{ registrationStatusLabels[application.status] }}</span>
          </div>
          <p class="muted">Created: {{ formatMalaysiaDateTime(application.created_at) }}</p>
          <p v-if="application.status === 'DRAFT'">Your draft is saved. Open it to complete your details and submit for review.</p>
          <p v-else-if="application.status === 'PENDING_REVIEW'">Your application has been submitted and is awaiting committee review.</p>
          <p v-else-if="application.status === 'CHANGES_REQUESTED'">The committee has requested changes. Open your application to view the feedback and resubmit.</p>
          <p v-else-if="application.status === 'APPROVED'">Your application has been approved. Membership activation is a separate step.</p>
          <p v-else-if="application.status === 'REJECTED'">Open your application to view the committee's decision and reason.</p>
          <RouterLink class="details-link" :to="{ name: 'application-detail', params: { id: application.id } }">View application <span aria-hidden="true">→</span></RouterLink>
        </li>
      </ul>
      <section class="create-panel">
        <div>
          <h2>{{ canCreate ? 'New application' : 'Your current application' }}</h2>
          <p v-if="!canCreate" class="muted">You already have an application in progress or approved. Open it above to view its details.</p>
          <p v-else class="muted">You can save your progress before submitting for review.</p>
        </div>
        <button type="button" :disabled="creating || !canCreate" @click="createDraft">
          {{ creating ? 'Creating...' : 'Create application draft' }}
        </button>
      </section>
    </template>
  </main>
</template>

<style scoped>
.applications-page { max-width: 60rem; margin: 2rem auto; padding: 0 1rem 2rem; color: #1c3029; }
.page-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
h1 { margin: 0.3rem 0 0.5rem; }
h2 { margin: 0 0 0.6rem; font-size: 1.15rem; }
p { line-height: 1.6; }
.eyebrow { margin: 0; color: #185940; font-size: 0.85rem; font-weight: 600; }
.muted { color: #4c6055; }
.application-list { list-style: none; padding: 0; display: grid; gap: 1rem; }
.application-card, .empty-state, .create-panel { padding: clamp(1rem, 3vw, 1.5rem); border: 1px solid #d8e3de; border-radius: 12px; background: #fff; }
.application-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; }
.application-title { color: #185940; font-size: 1.1rem; font-weight: 600; text-decoration: none; }
.application-title:hover, .details-link:hover { text-decoration: underline; }
.details-link { display: inline-block; margin-top: 0.4rem; color: #185940; font-weight: 600; }
.status-badge { display: inline-block; border-radius: 999px; padding: 0.3rem 0.75rem; font-size: 0.85rem; font-weight: 600; }
.draft { background: #eef1f0; color: #41564b; }
.pending_review { background: #edf4fc; color: #234f7a; }
.changes_requested { background: #fff4d8; color: #78520b; }
.approved { background: #e6f5ec; color: #185940; }
.rejected { background: #fff0ed; color: #a52b20; }
.empty-state { text-align: center; background: #f5faf7; padding: 2.5rem 1.5rem; }
.empty-symbol { display: inline-grid; place-items: center; width: 3rem; height: 3rem; margin-bottom: 1rem; padding: 0; border: 1px solid #8caf9c; border-radius: 50%; background: #e1efe6; color: #185940; font-size: 1.75rem; }
.empty-symbol:hover:not(:disabled) { background: #cde4d6; border-color: #185940; }
.empty-state p { max-width: 34rem; margin: 0.6rem auto; }
.create-panel { display: flex; justify-content: space-between; align-items: center; gap: 1.5rem; margin-top: 1.5rem; }
.create-panel p { margin-bottom: 0; }
button { min-height: 44px; padding: 0.7rem 1rem; border: 1px solid #185940; border-radius: 6px; background: #185940; color: #fff; font: inherit; cursor: pointer; }
button.secondary { background: #fff; color: #185940; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
button:focus-visible, a:focus-visible { outline: 3px solid #a9d8c5; outline-offset: 3px; }
.notice { padding: 1rem; border-radius: 8px; background: #f1f6f3; }
.error { background: #fff0ed; color: #b42318; }
@media (max-width: 640px) { .page-header, .create-panel { flex-direction: column; align-items: stretch; } }
</style>
