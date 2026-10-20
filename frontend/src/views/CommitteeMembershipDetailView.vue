<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import { useAuthStore } from '@/stores/auth'
import { committeeMembershipsApi } from '@/services/committeeMemberships'
import { membershipError } from '@/services/membership'
import { formatMalaysiaDate, formatMalaysiaDateTime } from '@/services/dateFormatting'
import { eligibilityExplanations, membershipStatusLabels } from '@/types/membership'
import type { CommitteeMembershipDetail } from '@/types/committeeMembership'

const route = useRoute()
const auth = useAuthStore()
const member = ref<CommitteeMembershipDetail | null>(null)
const loading = ref(true)
const closing = ref(false)
const confirming = ref(false)
const reason = ref('')
const errorMessage = ref('')
const actionError = ref('')
const success = ref('')
const events = computed(() => [...(member.value?.events ?? [])].reverse())
const eventLabels: Record<string, string> = { ACTIVATED: 'Activated', RENEWED: 'Renewed', LAPSED: 'Lapsed', CLOSED: 'Closed' }
let version = 0

async function load() {
  const current = ++version
  loading.value = true
  errorMessage.value = ''
  confirming.value = false
  member.value = null
  try {
    const id = Number(route.params.id)
    if (!Number.isSafeInteger(id) || id < 1) throw new Error('Invalid membership')
    const data = await committeeMembershipsApi.detail(id)
    if (current === version) member.value = data
  } catch (error) {
    if (current === version) errorMessage.value = membershipError(error)
  } finally {
    if (current === version) loading.value = false
  }
}
function confirmClose() {
  actionError.value = ''
  if (!reason.value.trim()) {
    actionError.value = 'Enter a closure reason.'
    return
  }
  if (member.value?.can_close && auth.user?.role === 'ADMIN') confirming.value = true
}
async function closeMembership() {
  if (closing.value || !confirming.value || !member.value?.can_close || auth.user?.role !== 'ADMIN') return
  const current = version
  const id = member.value.id
  closing.value = true
  actionError.value = ''
  try {
    const data = await committeeMembershipsApi.close(id, reason.value)
    if (current !== version) return
    member.value = data
    confirming.value = false
    reason.value = ''
    success.value = 'Membership closed. The login account and historical records are preserved.'
  } catch (error) {
    if (current !== version) return
    const message = membershipError(error)
    await load()
    actionError.value = message
  } finally {
    closing.value = false
  }
}
watch(() => route.params.id, () => {
  reason.value = ''
  actionError.value = ''
  success.value = ''
  void load()
}, { immediate: true })
onUnmounted(() => { version++ })
</script>

<template>
  <main>
    <RouterLink class="back-link" :to="{ name: 'committee-memberships' }"><AppIcon name="back" /> Memberships</RouterLink>
    <header><div><p class="eyebrow">al Khair · Committee</p><h1>Membership Details</h1></div><button type="button" :disabled="loading || closing" @click="load"><AppIcon name="refresh" /> Refresh membership</button></header>
    <p v-if="loading" role="status">Loading membership...</p>
    <p v-if="errorMessage" class="notice error" role="alert">{{ errorMessage }}</p>
    <p v-if="actionError" class="notice error" role="alert">{{ actionError }}</p>
    <p v-if="success" class="notice positive" role="status">{{ success }}</p>
    <template v-if="member && !loading">
      <section>
        <div class="heading-row"><h2>{{ member.full_name }}</h2><span class="badge" :class="member.status.toLowerCase()">{{ membershipStatusLabels[member.status] }}</span></div>
        <dl><div><dt>Member number</dt><dd>{{ member.member_number }}</dd></div><div><dt>Email</dt><dd>{{ member.email ?? 'Not available' }}</dd></div><div><dt>Activated</dt><dd>{{ formatMalaysiaDate(member.activated_on) }}</dd></div><div><dt>Waiting period ends</dt><dd>{{ formatMalaysiaDate(member.waiting_until) }}</dd></div></dl>
        <div class="notice" :class="member.eligibility.eligible ? 'positive' : 'pending'"><strong>{{ member.eligibility.eligible ? 'Currently eligible' : 'Currently not eligible' }}</strong><p>{{ eligibilityExplanations[member.eligibility.reason] }}</p><small>Checked {{ formatMalaysiaDateTime(member.eligibility.as_of) }}</small></div>
      </section>
      <section aria-labelledby="periods-heading">
        <h2 id="periods-heading">Paid membership periods</h2>
        <p v-if="!member.periods.length">No paid periods recorded.</p>
        <article v-for="period in member.periods" :key="period.id"><h3>{{ period.billing_year }}</h3><p>{{ formatMalaysiaDate(period.starts_on) }} – {{ formatMalaysiaDate(period.ends_on) }}</p><p>Verified {{ formatMalaysiaDateTime(period.effective_at) }}</p><RouterLink class="button-link" :to="{ name: 'committee-payment-detail', params: { id: period.payment_id } }"><AppIcon name="payment" /> View verified payment</RouterLink></article>
      </section>
      <section aria-labelledby="history-heading">
        <h2 id="history-heading">Membership history</h2>
        <ol class="history"><li v-for="event in events" :key="event.id"><strong>{{ eventLabels[event.type] ?? event.type }}</strong><p>{{ formatMalaysiaDateTime(event.effective_at) }} · {{ event.actor_name }}</p><p v-if="event.reason" class="reason">{{ event.reason }}</p><small>Recorded {{ formatMalaysiaDateTime(event.recorded_at) }}</small></li></ol>
      </section>
      <section v-if="auth.user?.role === 'ADMIN'" class="closure" aria-labelledby="closure-heading">
        <h2 id="closure-heading">Close membership</h2>
        <p v-if="member.status === 'CLOSED'">This membership is permanently closed. Reopening is unavailable in v1.0.</p>
        <template v-else>
          <p>Closure is permanent and takes effect immediately. The member becomes ineligible. Their login account, invoices, payments and history are preserved.</p>
          <p v-if="member.pending_payment_count" class="notice pending">Resolve {{ member.pending_payment_count }} pending {{ member.pending_payment_count === 1 ? 'payment' : 'payments' }} before closing this membership.</p>
          <form v-if="member.can_close" @submit.prevent="confirmClose">
            <label for="closure-reason">Closure reason <span aria-hidden="true">*</span></label>
            <textarea id="closure-reason" v-model="reason" required maxlength="2000" rows="4" :disabled="closing" placeholder="Explain why this membership is being permanently closed." />
            <button v-if="!confirming" class="danger" type="submit">Review closure</button>
            <div v-else class="notice pending" role="group" aria-label="Confirm membership closure">
              <p>Close membership for <strong>{{ member.full_name }} ({{ member.member_number }})</strong> permanently?</p>
              <p class="reason">{{ reason }}</p>
              <button class="danger" type="button" :disabled="closing" @click="closeMembership">{{ closing ? 'Closing...' : 'Confirm permanent closure' }}</button>
              <button type="button" :disabled="closing" @click="confirming = false">Cancel</button>
            </div>
          </form>
        </template>
      </section>
      <p v-else class="muted">Membership closure is available to ADMIN users only.</p>
    </template>
  </main>
</template>

<style scoped>
main { max-width: 64rem; margin: 2rem auto; padding: 0 1rem 2rem; color: #1c3029; }
header, .heading-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; }
h1 { margin: 0.3rem 0; } h2 { margin-top: 0; font-size: 1.2rem; } h3 { font-size: 1.05rem; } p { line-height: 1.6; }
.eyebrow, .muted, dt, small { color: #4c6055; } .eyebrow { margin: 0; font-size: 0.85rem; }
section { margin-top: 1.25rem; padding: clamp(1rem, 3vw, 1.5rem); border: 1px solid #d8e3de; border-radius: 12px; background: #fff; }
dl { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 1rem; } dd { margin: 0.3rem 0 0; overflow-wrap: anywhere; }
.badge { padding: 0.3rem 0.7rem; border-radius: 999px; background: #eef1f0; font-size: 0.85rem; }
.active, .positive { background: #e6f5ec; color: #185940; } .lapsed, .pending { background: #fff4d8; color: #78520b; }
.notice { margin-top: 1rem; padding: 1rem; border-radius: 8px; } .notice p:last-child { margin-bottom: 0; } .error { background: #fff0ed; color: #b42318; }
article { padding: 0.75rem 0; border-bottom: 1px solid #d8e3de; } a { color: #185940; }
.history { list-style: none; padding: 0; } .history li { margin-top: 1rem; padding: 0.75rem 1rem; border-left: 3px solid #8caf9c; background: #f5f8f7; border-radius: 4px; } .history p { margin: 0.4rem 0; }
.reason { white-space: pre-wrap; overflow-wrap: anywhere; }
label { display: block; font-weight: 600; } textarea { box-sizing: border-box; width: 100%; margin: 0.5rem 0; padding: 0.75rem; border: 1px solid #82968b; border-radius: 6px; font: inherit; resize: vertical; }
button { min-height: 44px; margin-right: 0.5rem; padding: 0.65rem 1rem; border: 1px solid #185940; border-radius: 6px; background: #fff; color: #185940; font: inherit; cursor: pointer; }
.button-link { display: inline-flex; align-items: center; gap: 0.5rem; min-height: 44px; box-sizing: border-box; padding: 0.65rem 1rem; border: 1px solid #185940; border-radius: 6px; text-decoration: none; }
button.danger { background: #a52b20; color: #fff; border-color: #a52b20; } button:disabled { opacity: 0.6; cursor: not-allowed; }
a:focus-visible, button:focus-visible, textarea:focus-visible { outline: 3px solid #a9d8c5; outline-offset: 3px; }
</style>
