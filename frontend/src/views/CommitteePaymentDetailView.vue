<script setup lang="ts">
import { ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { paymentsApi, paymentError } from '@/services/payments'
import { formatRinggit } from '@/types/invoice'
import { paymentMethodLabels, paymentStatusLabels, type PaymentDetail } from '@/types/payment'
import { formatMalaysiaDate, formatMalaysiaDateTime } from '@/services/dateFormatting'

const route = useRoute()
const payment = ref<PaymentDetail | null>(null)
const loading = ref(true)
const reviewing = ref(false)
const downloading = ref(false)
const decision = ref<'VERIFY' | 'REJECT'>('VERIFY')
const reason = ref('')
const confirming = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const fields = ref<Record<string, string[]>>({})
let version = 0
async function loadPayment() {
  const current = ++version
  payment.value = null
  loading.value = true
  confirming.value = false
  errorMessage.value = ''
  successMessage.value = ''
  fields.value = {}
  reason.value = ''
  decision.value = 'VERIFY'
  try {
    const id = Number(route.params.id)
    if (!Number.isSafeInteger(id) || id < 1) { errorMessage.value = 'This payment could not be found.'; return }
    const data = await paymentsApi.detail(id)
    if (current === version) payment.value = data
  } catch (error) { if (current === version) errorMessage.value = paymentError(error).message }
  finally { if (current === version) loading.value = false }
}
watch(() => route.params.id, loadPayment, { immediate: true })
watch([decision, reason], () => { confirming.value = false })
function prepareReview() {
  if (!payment.value?.can_review || reviewing.value) return
  fields.value = {}
  if (decision.value === 'REJECT' && !reason.value.trim()) { fields.value.reason = ['A rejection reason is required.']; return }
  confirming.value = true
}
async function confirmReview() {
  if (!payment.value?.can_review || reviewing.value || !confirming.value) return
  const current = version
  const id = payment.value.id
  const chosen = decision.value
  reviewing.value = true
  errorMessage.value = ''
  fields.value = {}
  try {
    const result = await paymentsApi.review(id, chosen, reason.value)
    if (current !== version || !payment.value) return
    Object.assign(payment.value, result, { can_review: false })
    if (chosen === 'VERIFY') payment.value.invoice.status = 'PAID'
    successMessage.value = chosen === 'VERIFY' ? 'Payment verified. The invoice is now paid.' : 'Payment rejected. The invoice remains unpaid.'
    confirming.value = false
  } catch (error) {
    if (current !== version) return
    const failure = paymentError(error)
    errorMessage.value = failure.message
    fields.value = failure.fields
    confirming.value = false
    // Refresh uncertain write results rather than automatically retrying a financial action.
    try {
      const updated = await paymentsApi.detail(id)
      if (current === version) payment.value = updated
    } catch {
      if (current === version) { payment.value = null; errorMessage.value += ' Reload this payment before trying again.' }
    }
  } finally { reviewing.value = false }
}
async function download() {
  if (!payment.value || downloading.value) return
  downloading.value = true
  try { await paymentsApi.downloadReceipt(payment.value.id) }
  catch (error) { errorMessage.value = paymentError(error).message }
  finally { downloading.value = false }
}
</script>
<template>
  <main>
    <RouterLink class="back-link" :to="{ name: 'committee-payments' }">Back to payment queue</RouterLink>
    <header><div><p class="eyebrow">al Khair · Committee</p><h1>Review Payment</h1></div><button type="button" :disabled="loading || reviewing" @click="loadPayment">Reload payment</button></header>
    <p v-if="loading" role="status">Loading payment...</p><p v-if="errorMessage" class="notice error" role="alert">{{ errorMessage }}</p><p v-if="successMessage" class="notice success" role="status">{{ successMessage }}</p>
    <ul v-if="Object.keys(fields).length" class="notice error" role="alert"><template v-for="(messages, field) in fields" :key="field"><li v-for="message in messages" :key="message">{{ message }}</li></template></ul>
    <template v-if="payment && !loading">
      <section class="card"><div class="row"><h2>{{ payment.invoice.applicant_name }}</h2><span class="badge" :class="payment.status.toLowerCase()">{{ paymentStatusLabels[payment.status] }}</span></div>
        <dl><div><dt>Invoice</dt><dd>{{ payment.invoice.invoice_number }}</dd></div><div><dt>Payment amount</dt><dd>{{ formatRinggit(payment.amount) }}</dd></div><div><dt>Method</dt><dd>{{ paymentMethodLabels[payment.method] }}</dd></div><div><dt>Reference</dt><dd>{{ payment.reference || 'Not provided' }}</dd></div><div><dt>Submitted by</dt><dd>{{ payment.submitted_by_name }}</dd></div><div><dt>Submitted</dt><dd>{{ formatMalaysiaDateTime(payment.submitted_at) }}</dd></div><div v-if="payment.reviewed_at"><dt>Reviewed</dt><dd>{{ formatMalaysiaDateTime(payment.reviewed_at) }} · {{ payment.reviewed_by_name || 'Recorded reviewer' }}</dd></div></dl>
        <button v-if="payment.has_receipt" type="button" :disabled="downloading" @click="download">{{ downloading ? 'Downloading...' : 'Download receipt' }}</button><p v-else class="muted">No receipt attached. Cash records require independent confirmation.</p>
        <p v-if="payment.rejection_reason" class="notice warning reason">{{ payment.rejection_reason }}</p>
      </section>
      <section class="card"><h2>Invoice breakdown</h2><p>{{ payment.invoice.status }} · Due {{ formatMalaysiaDate(payment.invoice.due_date) }}</p><div v-for="(item, index) in payment.invoice.items" :key="index" class="row"><p>{{ item.description }}</p><strong>{{ formatRinggit(item.amount) }}</strong></div><div class="row"><strong>Total</strong><strong>{{ formatRinggit(payment.invoice.total) }}</strong></div></section>
      <form v-if="payment.can_review" class="card" @submit.prevent="prepareReview"><h2>Independent verification</h2><p class="notice warning">Check the receipt or cash record before verifying. Verification marks this invoice paid.</p><fieldset :disabled="reviewing"><legend>Payment decision</legend><label for="payment-decision">Decision <span class="required-marker" aria-hidden="true">*</span></label><select id="payment-decision" v-model="decision"><option value="VERIFY">Verify payment</option><option value="REJECT">Reject payment</option></select><label for="payment-reason">Reason <span v-if="decision === 'REJECT'" class="required-marker" aria-hidden="true">*</span><span v-else> (optional)</span></label><textarea id="payment-reason" v-model="reason" maxlength="2000" :required="decision === 'REJECT'" placeholder="Explain why the payment cannot be verified, if rejecting." /><small>Rejection requires a reason. You cannot review a payment you submitted or a payment for your own invoice.</small><button v-if="!confirming" type="submit">Review decision</button><div v-else class="notice warning"><p>Confirm {{ decision === 'VERIFY' ? 'verification' : 'rejection' }} of {{ formatRinggit(payment.amount) }}?</p><button type="button" @click="confirmReview">{{ reviewing ? 'Recording...' : 'Confirm decision' }}</button><button type="button" @click="confirming = false">Cancel</button></div></fieldset></form>
      <p v-else class="notice warning">Review actions are unavailable. A different authorized user must review payments you submitted or payments for your own invoice.</p>
      <p class="muted">Membership activation is handled separately after the required conditions are met.</p>
    </template>
  </main>
</template>
<style scoped>
main { max-width: 60rem; margin: 2rem auto; padding: 0 1rem 2rem; color: #1c3029; }
a { color: #185940; }
.back-link { display: inline-block; margin-bottom: 1.5rem; }
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
