<script setup lang="ts">
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
    <RouterLink to="/dashboard">Back to dashboard</RouterLink>
    <h1>Application Review Queue</h1>
    <p>Applications awaiting committee review.</p>
    <p v-if="loading" role="status">Loading applications...</p>
    <p v-if="errorMessage" role="alert">{{ errorMessage }}</p>
    <button type="button" :disabled="loading" @click="loadQueue(results?.current_page ?? 1)">Refresh queue</button>
    <template v-if="results && !loading">
      <p v-if="results.data.length === 0">No applications are awaiting review.</p>
      <ul v-else>
        <li v-for="application in results.data" :key="application.id">
          <RouterLink :to="{ name: 'committee-application-detail', params: { id: application.id } }">
            Application #{{ application.id }} — {{ application.applicant_name }}
          </RouterLink>
          <p>Type: {{ application.type }} · Created: {{ new Date(application.created_at).toLocaleString() }}</p>
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
main { max-width: 56rem; margin: 2rem auto; padding: 0 1rem; }
li { margin: 1rem 0; }
nav { display: flex; gap: 1rem; align-items: center; }
</style>
