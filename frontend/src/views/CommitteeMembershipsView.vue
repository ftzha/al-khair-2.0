<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import { committeeMembershipsApi } from '@/services/committeeMemberships'
import { membershipError } from '@/services/membership'
import { membershipStatusLabels } from '@/types/membership'
import { formatMalaysiaDate } from '@/services/dateFormatting'
import type { CommitteeMembershipPage } from '@/types/committeeMembership'

const result = ref<CommitteeMembershipPage | null>(null)
const search = ref('')
const submittedSearch = ref('')
const loading = ref(true)
const errorMessage = ref('')
let version = 0

async function load(page = 1) {
  const current = ++version
  loading.value = true
  errorMessage.value = ''
  result.value = null
  try {
    const data = await committeeMembershipsApi.list(submittedSearch.value, page)
    if (current === version) result.value = data
  } catch (error) {
    if (current === version) errorMessage.value = membershipError(error)
  } finally {
    if (current === version) loading.value = false
  }
}
function searchMembers() {
  submittedSearch.value = search.value.trim()
  void load()
}
onMounted(() => load())
onUnmounted(() => { version++ })
</script>

<template>
  <main>
    <header><div><p class="eyebrow">al Khair · Committee</p><h1>Memberships</h1><p>Find a member, check eligibility and review membership history.</p></div><button type="button" :disabled="loading" @click="load()"><AppIcon name="refresh" /> Refresh memberships</button></header>
    <form class="search-form" @submit.prevent="searchMembers">
      <label for="member-search">Member name or number</label>
      <div class="search-row"><input id="member-search" v-model="search" type="search" maxlength="100" placeholder="e.g. member name or AKM-..." /><button type="submit" :disabled="loading">Search</button></div>
    </form>
    <p v-if="loading" role="status">Loading memberships...</p>
    <p v-if="errorMessage" class="notice error" role="alert">{{ errorMessage }}</p>
    <template v-if="result && !loading">
      <p class="muted">{{ result.total }} {{ result.total === 1 ? 'membership' : 'memberships' }} found.</p>
      <p v-if="!result.data.length" class="notice">No memberships match this search.</p>
      <article v-for="member in result.data" :key="member.id">
        <div class="member-heading"><h2>{{ member.full_name }}</h2><span class="badge" :class="member.status.toLowerCase()">{{ membershipStatusLabels[member.status] }}</span></div>
        <p class="member-number">{{ member.member_number }}</p>
        <p class="muted">Activated {{ formatMalaysiaDate(member.activated_on) }}</p>
        <RouterLink class="button-link" :to="{ name: 'committee-membership-detail', params: { id: member.id } }"><AppIcon name="members" /> View membership</RouterLink>
      </article>
      <nav v-if="result.last_page > 1" aria-label="Membership pages"><button type="button" :disabled="result.current_page <= 1" @click="load(result.current_page - 1)">Previous</button><span>Page {{ result.current_page }} of {{ result.last_page }}</span><button type="button" :disabled="result.current_page >= result.last_page" @click="load(result.current_page + 1)">Next</button></nav>
    </template>
  </main>
</template>

<style scoped>
main { max-width: 64rem; margin: 2rem auto; padding: 0 1rem 2rem; color: #1c3029; }
header, .member-heading, nav, .search-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; }
h1 { margin: 0.3rem 0; } h2 { margin: 0; font-size: 1.15rem; overflow-wrap: anywhere; } p { line-height: 1.6; }
.eyebrow, .muted { color: #4c6055; } .eyebrow { font-size: 0.85rem; margin: 0; }
.search-form, article { margin-top: 1rem; padding: 1.25rem; border: 1px solid #d8e3de; border-radius: 12px; background: #fff; }
label { display: block; font-weight: 600; margin-bottom: 0.5rem; }
input { flex: 1 1 15rem; min-width: 0; min-height: 44px; padding: 0.65rem; border: 1px solid #82968b; border-radius: 6px; font: inherit; }
button, .button-link { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; min-height: 44px; box-sizing: border-box; padding: 0.65rem 1rem; border: 1px solid #185940; border-radius: 6px; color: #185940; background: #fff; font: inherit; cursor: pointer; text-decoration: none; }
button[type='submit'] { background: #185940; color: #fff; } button:disabled { opacity: 0.6; cursor: not-allowed; }
.member-number { font-family: ui-monospace, monospace; overflow-wrap: anywhere; } .badge { padding: 0.3rem 0.7rem; border-radius: 999px; background: #eef1f0; font-size: 0.85rem; }
.active { background: #e6f5ec; color: #185940; } .lapsed { background: #fff4d8; color: #78520b; }
.notice { padding: 1rem; border-radius: 8px; background: #f5f8f7; } .error { background: #fff0ed; color: #b42318; }
nav { justify-content: center; margin-top: 1.25rem; }
a:focus-visible, button:focus-visible, input:focus-visible { outline: 3px solid #a9d8c5; outline-offset: 3px; }
</style>
