<script setup lang="ts">
import { formatMalaysiaDateTime } from '@/services/dateFormatting'
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { committeeRegistrationsApi } from '@/services/committeeRegistrations'
import { registrationError } from '@/services/registrations'
import type { CommitteeApplicationPage } from '@/types/committee'

const results = ref<CommitteeApplicationPage | null>(null)
const loading = ref(true)
const errorMessage = ref('')
let requestVersion = 0

async function loadQueue(page = 1) {
  const version = ++requestVersion
  loading.value = true
  errorMessage.value = ''
  try {
    const data = await committeeRegistrationsApi.list(page)
    if (version !== requestVersion) return
    // A decision in another session can shrink the queue between page requests.
    if (data.current_page > data.last_page) {
      await loadQueue(data.last_page)
      return
    }
    results.value = data
  } catch (error) {
    if (version === requestVersion) {
      results.value = null
      errorMessage.value = registrationError(error).message
    }
  } finally {
    if (version === requestVersion) loading.value = false
  }
}

onMounted(() => loadQueue())
</script>

<template>
  <main>
    <header class="page-header">
      <div>
        <p class="eyebrow">al Khair · Committee</p>
        <h1>Application Review Queue</h1>
        <p class="muted">Review submitted details and help applicants complete their membership applications.</p>
      </div>
      <button type="button" :disabled="loading" @click="loadQueue(results?.current_page ?? 1)">Refresh queue</button>
    </header>
    <p v-if="loading" class="notice" role="status">Loading applications...</p>
    <p v-if="errorMessage" class="notice error" role="alert">{{ errorMessage }}</p>
    <template v-if="results && !loading">
      <div class="queue-summary">
        <span class="count">{{ results.total }}</span>
        <div><strong>Awaiting review</strong><p class="muted">Applications pending a committee decision</p></div>
      </div>
      <section v-if="results.data.length === 0" class="empty-state">
        <h2>Your review queue is clear</h2>
        <p>No applications are awaiting review.</p>
        <p class="muted">New submissions and resubmissions will appear here when they are ready.</p>
      </section>
      <ul v-else class="queue-list">
        <li v-for="application in results.data" :key="application.id" class="application-card">
          <div class="application-header">
            <div><p class="eyebrow">Application #{{ application.id }}</p><h2>{{ application.applicant_name }}</h2></div>
            <span class="badge pending">Pending review</span>
          </div>
          <dl><div><dt>Application type</dt><dd>{{ application.type }}</dd></div><div><dt>Created</dt><dd>{{ formatMalaysiaDateTime(application.created_at) }}</dd></div></dl>
          <RouterLink class="review-link" :to="{ name: 'committee-application-detail', params: { id: application.id } }">
            Review application <span aria-hidden="true">→</span>
          </RouterLink>
        </li>
      </ul>
      <nav v-if="results.last_page > 1" aria-label="Review queue pages">
        <button type="button" :disabled="results.current_page <= 1" @click="loadQueue(results.current_page - 1)">Previous page</button>
        <span>Page {{ results.current_page }} of {{ results.last_page }}</span>
        <button type="button" :disabled="results.current_page >= results.last_page" @click="loadQueue(results.current_page + 1)">Next page</button>
      </nav>
    </template>
  </main>
</template>

<style scoped>
main { max-width: 60rem; margin: 2rem auto; padding: 0 1rem 2rem; color: #1c3029; }
.page-header, .application-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
.page-header { margin-bottom: 1.5rem; }
h1 { margin: 0.3rem 0 0.5rem; }
h2 { margin: 0.4rem 0; font-size: 1.2rem; overflow-wrap: anywhere; }
p { line-height: 1.6; }
.eyebrow { margin: 0; color: #185940; font-size: 0.85rem; font-weight: 600; }
.muted { color: #4c6055; }
.queue-summary { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.5rem; background: #f3faf5; border: 1px solid #d8e3de; border-radius: 12px; }
.queue-summary p { margin: 0.3rem 0 0; }
.count { color: #185940; font-size: 2.25rem; font-weight: 700; }
.queue-list { list-style: none; padding: 0; display: grid; gap: 1rem; }
.application-card, .empty-state { padding: clamp(1rem, 3vw, 1.5rem); border: 1px solid #d8e3de; border-radius: 12px; background: #fff; }
.empty-state { text-align: center; margin-top: 1rem; padding: 2.5rem 1.5rem; }
.badge { display: inline-block; border-radius: 999px; padding: 0.3rem 0.75rem; font-size: 0.85rem; font-weight: 600; white-space: nowrap; }
.pending { color: #234f7a; background: #edf4fc; }
dl { display: flex; flex-wrap: wrap; gap: 1rem 3rem; margin: 1rem 0 1.25rem; }
dt { color: #4c6055; font-size: 0.85rem; }
dd { margin: 0.3rem 0 0; }
.review-link { display: inline-flex; gap: 1rem; padding: 0.7rem 1rem; border-radius: 6px; background: #185940; color: #fff; text-decoration: none; font-weight: 600; }
button { min-height: 44px; padding: 0.65rem 1rem; border: 1px solid #185940; border-radius: 6px; background: #fff; color: #185940; font: inherit; cursor: pointer; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
a:focus-visible, button:focus-visible { outline: 3px solid #a9d8c5; outline-offset: 3px; }
nav { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between; margin-top: 1.5rem; }
.notice { padding: 1rem; background: #f1f6f3; border-radius: 8px; }
.error { color: #b42318; background: #fff0ed; }
@media (max-width: 640px) { .page-header { flex-direction: column; align-items: stretch; } .application-header { align-items: flex-start; flex-wrap: wrap; } }
</style>
