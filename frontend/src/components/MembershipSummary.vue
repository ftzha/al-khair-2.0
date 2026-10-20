<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import { membershipApi, membershipError } from '@/services/membership'
import { formatMalaysiaDate, formatMalaysiaDateTime } from '@/services/dateFormatting'
import { formatRinggit } from '@/types/invoice'
import { eligibilityExplanations, membershipStatusLabels, renewalExplanations, type MembershipOverview } from '@/types/membership'

const router = useRouter()
const membership = ref<MembershipOverview | null>(null)
const loading = ref(true)
const loaded = ref(false)
const creating = ref(false)
const errorMessage = ref('')
const actionError = ref('')
let version = 0

async function load() {
  const current = ++version
  loading.value = true
  loaded.value = false
  membership.value = null
  errorMessage.value = ''
  try {
    const data = await membershipApi.overview()
    if (current === version) {
      membership.value = data
      loaded.value = true
    }
  } catch (error) {
    if (current === version) errorMessage.value = membershipError(error)
  } finally {
    if (current === version) loading.value = false
  }
}

async function createRenewal() {
  if (creating.value || loading.value || !membership.value?.renewal.can_generate_invoice) return
  creating.value = true
  actionError.value = ''
  const current = version
  try {
    const invoice = await membershipApi.createRenewal()
    if (current !== version) return
    await router.push({ name: 'invoice-detail', params: { id: invoice.id } })
  } catch (error) {
    // The server may have created the invoice before the connection failed.
    // Refresh the authoritative state; the endpoint also prevents duplicates.
    if (current !== version) return
    const message = membershipError(error)
    await load()
    actionError.value = message
  } finally {
    creating.value = false
  }
}
onMounted(load)
onUnmounted(() => { version++ })
</script>

<template>
  <section class="membership-card" aria-labelledby="membership-heading" :aria-busy="loading">
    <header>
      <div><p class="eyebrow">Membership &amp; eligibility</p><h2 id="membership-heading">Your membership</h2></div>
      <button type="button" class="secondary" :disabled="loading || creating" @click="load"><AppIcon name="refresh" /> Refresh membership</button>
    </header>
    <p v-if="loading" role="status">Loading membership...</p>
    <p v-if="errorMessage" class="notice error" role="alert">{{ errorMessage }}</p>
    <p v-if="actionError" class="notice error" role="alert">{{ actionError }}</p>
    <template v-if="loaded && membership">
      <div class="membership-grid">
        <div>
          <div class="status-row"><strong class="member-number">{{ membership.member_number }}</strong><span class="badge" :class="membership.status.toLowerCase()">{{ membershipStatusLabels[membership.status] }}</span></div>
          <dl>
            <div><dt>Activated</dt><dd>{{ formatMalaysiaDate(membership.activated_on) }}</dd></div>
            <div><dt>Waiting period ends</dt><dd>{{ formatMalaysiaDate(membership.waiting_until) }}</dd></div>
            <div v-if="membership.current_period"><dt>Paid membership period</dt><dd>{{ formatMalaysiaDate(membership.current_period.starts_on) }} – {{ formatMalaysiaDate(membership.current_period.ends_on) }}</dd></div>
          </dl>
          <div class="notice" :class="membership.eligibility.eligible ? 'positive' : 'pending'">
            <strong><AppIcon :name="membership.eligibility.eligible ? 'check-circle' : 'warning'" /> {{ membership.eligibility.eligible ? 'Currently eligible' : 'Currently not eligible' }}</strong>
            <p>{{ eligibilityExplanations[membership.eligibility.reason] ?? 'Contact the committee to check your eligibility.' }}</p>
            <p v-if="membership.waiting_days_remaining > 0">{{ membership.waiting_days_remaining }} {{ membership.waiting_days_remaining === 1 ? 'day' : 'days' }} remaining in your initial waiting period. The period ends at 00:00 Malaysia time on the date shown.</p>
          </div>
        </div>
        <div class="renewal-panel">
          <h3>{{ membership.renewal.billing_year }} membership fees</h3>
          <p>{{ renewalExplanations[membership.renewal.state] }}</p>
          <p v-if="membership.renewal.state !== 'PAID'">Submission deadline: <strong>{{ formatMalaysiaDate(membership.renewal.cutoff_date) }}</strong> (Malaysia time).</p>
          <p v-if="membership.renewal.invoice">Invoice: {{ membership.renewal.invoice.invoice_number }} · {{ formatRinggit(membership.renewal.invoice.total) }}</p>
          <RouterLink v-if="membership.renewal.invoice" class="button-link" :to="{ name: 'invoice-detail', params: { id: membership.renewal.invoice.id } }"><AppIcon name="invoice" /> View invoice</RouterLink>
          <button v-if="membership.renewal.can_generate_invoice" type="button" :disabled="creating" @click="createRenewal"><AppIcon name="invoice" /> {{ creating ? 'Creating invoice...' : 'Create renewal invoice' }}</button>
        </div>
      </div>
      <p class="checked-at">Checked {{ formatMalaysiaDateTime(membership.eligibility.as_of) }}. Refresh to check for recent payment verification.</p>
    </template>
    <div v-else-if="loaded" class="empty-state">
      <h3>No membership record yet</h3>
      <p>Membership activates after application approval and verification of the initial payment. If your initial payment is already verified, contact the committee to check activation.</p>
      <div class="actions"><RouterLink class="button-link" :to="{ name: 'applications' }"><AppIcon name="application" /> My Applications</RouterLink><RouterLink class="button-link" :to="{ name: 'invoices' }"><AppIcon name="invoice" /> My Invoices</RouterLink></div>
    </div>
  </section>
</template>

<style scoped>
.membership-card { margin-bottom: 1rem; padding: clamp(1rem, 3vw, 1.5rem); border: 1px solid #d8e3de; border-radius: 12px; background: #fff; color: #1c3029; }
header, .status-row, .actions { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; }
header { justify-content: space-between; margin-bottom: 1.25rem; }
h2 { margin: 0.3rem 0 0; font-size: 1.2rem; }
h3 { margin-top: 0; font-size: 1.05rem; }
p { line-height: 1.6; }
.eyebrow, .checked-at, dt { color: #4c6055; }
.eyebrow { margin: 0; font-size: 0.85rem; }
.membership-grid { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: 1.25rem; }
.member-number { overflow-wrap: anywhere; }
.badge { padding: 0.25rem 0.65rem; border-radius: 999px; font-size: 0.85rem; font-weight: 600; background: #eef1f0; }
.active, .positive { background: #e6f5ec; color: #185940; }
.lapsed, .pending { background: #fff4d8; color: #78520b; }
.closed { color: #41564b; }
dl { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 1rem; }
dt { font-size: 0.9rem; }
dd { margin: 0.3rem 0 0; line-height: 1.5; }
.notice, .renewal-panel, .empty-state { padding: 1rem; border-radius: 8px; }
.notice p:last-child, .empty-state p:last-child { margin-bottom: 0; }
.renewal-panel, .empty-state { background: #f5f8f7; border: 1px solid #d8e3de; }
.error { background: #fff0ed; color: #b42318; }
button, .button-link { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; box-sizing: border-box; min-height: 44px; padding: 0.65rem 1rem; border: 1px solid #185940; border-radius: 6px; background: #185940; color: #fff; font: inherit; text-decoration: none; cursor: pointer; }
button.secondary { background: #fff; color: #185940; }
button:disabled { opacity: 0.6; cursor: wait; }
a:focus-visible, button:focus-visible { outline: 3px solid #a9d8c5; outline-offset: 3px; }
.checked-at { margin-bottom: 0; font-size: 0.85rem; }
@media (max-width: 640px) { .membership-grid { grid-template-columns: minmax(0, 1fr); } }
</style>
