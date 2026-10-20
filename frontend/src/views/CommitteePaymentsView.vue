<script setup lang="ts">
import { ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { paymentsApi, paymentError } from '@/services/payments'
import { formatRinggit } from '@/types/invoice'
import { paymentMethodLabels, paymentStatusLabels, type PaymentPage, type PaymentStatus } from '@/types/payment'
import { formatMalaysiaDateTime } from '@/services/dateFormatting'

const status = ref<PaymentStatus>('PENDING')
const results = ref<PaymentPage | null>(null)
const loading = ref(true)
const errorMessage = ref('')
let version = 0
async function loadQueue(page = 1) {
  const current = ++version
  loading.value = true
  errorMessage.value = ''
  try {
    const data = await paymentsApi.queue(status.value, page)
    if (current !== version) return
    if (data.current_page > data.last_page) { await loadQueue(data.last_page); return }
    results.value = data
  } catch (error) {
    if (current === version) { results.value = null; errorMessage.value = paymentError(error).message }
  } finally { if (current === version) loading.value = false }
}
watch(status, () => loadQueue(), { immediate: true })
</script>
<template>
  <main>
    <header><div><p class="eyebrow">al Khair · Committee</p><h1>Payment Verification</h1><p class="muted">Check receipts and confirm payments independently.</p></div><RouterLink class="button-link" :to="{ name: 'committee-cash-payment' }">Record cash payment</RouterLink></header>
    <label for="payment-status">Payment status</label><select id="payment-status" v-model="status"><option value="PENDING">Pending verification</option><option value="VERIFIED">Verified</option><option value="REJECTED">Rejected</option></select>
    <button type="button" :disabled="loading" @click="loadQueue(results?.current_page ?? 1)">Refresh payments</button>
    <p v-if="loading" role="status">Loading payments...</p><p v-if="errorMessage" class="notice error" role="alert">{{ errorMessage }}</p>
    <template v-if="results && !loading">
      <p class="muted">{{ results.total }} payment(s) · {{ paymentStatusLabels[status] }}</p>
      <section v-if="!results.data.length" class="card"><h2>No payments in this queue</h2><p>Payments matching this status will appear here.</p></section>
      <ul v-else class="list"><li v-for="payment in results.data" :key="payment.id" class="card">
        <div class="row"><strong>{{ payment.applicant_name }}</strong><span class="badge" :class="payment.status.toLowerCase()">{{ paymentStatusLabels[payment.status] }}</span></div>
        <p>{{ payment.invoice_number }} · {{ paymentMethodLabels[payment.method] }} · {{ formatRinggit(payment.amount) }}</p><p class="muted">Submitted: {{ formatMalaysiaDateTime(payment.submitted_at) }}</p>
        <RouterLink :to="{ name: 'committee-payment-detail', params: { id: payment.id } }">View payment →</RouterLink>
      </li></ul>
      <nav v-if="results.last_page > 1" aria-label="Payment pages"><button type="button" :disabled="results.current_page <= 1" @click="loadQueue(results.current_page - 1)">Previous page</button><span>Page {{ results.current_page }} of {{ results.last_page }}</span><button type="button" :disabled="results.current_page >= results.last_page" @click="loadQueue(results.current_page + 1)">Next page</button></nav>
    </template>
  </main>
</template>
<style scoped>
main { max-width: 60rem; margin: 2rem auto; padding: 0 1rem 2rem; color: #1c3029; }
a { color: #185940; }
header, .row, nav { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
h1 { margin: 0.3rem 0 0.5rem; } h2 { font-size: 1.2rem; } p, small { line-height: 1.6; }
.eyebrow { margin: 0; font-size: 0.85rem; color: #185940; font-weight: 600; }
.muted, small, dt { color: #4c6055; }
.card { margin-top: 1rem; padding: clamp(1rem, 3vw, 1.5rem); border: 1px solid #d8e3de; border-radius: 12px; overflow-wrap: anywhere; }
.list { list-style: none; padding: 0; }
label, input, select, textarea, small { display: block; }
label { margin-top: 1rem; font-weight: 600; }
input, select, textarea { box-sizing: border-box; width: 100%; min-height: 44px; padding: 0.75rem; margin: 0.5rem 0; border: 1px solid #82968b; border-radius: 6px; font: inherit; }
textarea { min-height: 7rem; resize: vertical; }
button, .button-link { display: inline-block; min-height: 44px; box-sizing: border-box; padding: 0.65rem 1rem; margin: 0.5rem 0.5rem 0 0; border: 1px solid #185940; border-radius: 6px; background: #fff; color: #185940; font: inherit; cursor: pointer; text-decoration: none; }
button[type='submit'] { background: #185940; color: #fff; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible { outline: 3px solid #a9d8c5; outline-offset: 3px; }
fieldset { border: 0; padding: 0; margin: 0; min-width: 0; }
.notice { padding: 1rem; border-radius: 8px; margin-top: 1rem; }
.error { background: #fff0ed; color: #b42318; }
.success { background: #edf8f1; color: #185940; }
.warning { background: #fff7e3; color: #704a0b; }
.badge { padding: 0.3rem 0.7rem; border-radius: 999px; background: #edf4fc; color: #234f7a; font-size: 0.85rem; font-weight: 600; }
.badge.verified { background: #edf8f1; color: #185940; } .badge.rejected { background: #fff0ed; color: #b42318; }
dl { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 1rem; } dd { margin: 0.3rem 0 0; overflow-wrap: anywhere; }
.reason { white-space: pre-wrap; }
.required-marker { color: #b42318; }
nav { margin-top: 1rem; }
</style>
