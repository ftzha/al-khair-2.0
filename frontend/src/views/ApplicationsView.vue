<script setup lang="ts">
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
  <main>
    <RouterLink to="/dashboard">Back to dashboard</RouterLink>
    <h1>My Applications</h1>
    <p v-if="loading" role="status">Loading applications...</p>
    <p v-if="errorMessage" role="alert">{{ errorMessage }}</p>
    <button v-if="!loading" type="button" @click="loadApplications" :disabled="creating">Refresh applications</button>
    <template v-if="!loading && !errorMessage">
      <p v-if="applications.length === 0">You have no membership applications yet.</p>
      <ul v-else>
        <li v-for="application in applications" :key="application.id">
          <RouterLink :to="{ name: 'application-detail', params: { id: application.id } }">
            Application #{{ application.id }}
          </RouterLink>
          — {{ registrationStatusLabels[application.status] }}
          <p>Created: {{ new Date(application.created_at).toLocaleString() }}</p>
        </li>
      </ul>
      <button type="button" :disabled="creating || !canCreate" @click="createDraft">
        {{ creating ? 'Creating...' : 'Create application draft' }}
      </button>
      <p v-if="!canCreate">You already have an application in progress or approved. Open it above to view its details.</p>
    </template>
  </main>
</template>
