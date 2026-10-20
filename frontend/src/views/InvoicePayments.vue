<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue'
import { computed, ref, watch } from 'vue'
import { paymentsApi, paymentError } from '@/services/payments'
import { formatRinggit, type InvoiceStatus } from '@/types/invoice'
import { paymentMethodLabels, paymentStatusLabels, type PaymentRecord } from '@/types/payment'
import { formatMalaysiaDateTime } from '@/services/dateFormatting'

const props = defineProps<{ invoiceId: number; total: string; status: InvoiceStatus }>()
const emit = defineEmits<{ verified: [] }>()
const records = ref<PaymentRecord[]>([])
const loading = ref(true)
const submitting = ref(false)
const downloadedId = ref<number | null>(null)
const historyReady = ref(false)
const reference = ref('')
const receipt = ref<File | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const errorMessage = ref('')
const successMessage = ref('')
const fields = ref<Record<string, string[]>>({})
const confirming = ref(false)
const canSubmit = computed(() => historyReady.value && props.status === 'UNPAID' && !records.value.some((p) => p.status === 'PENDING' || p.status === 'VERIFIED'))
let version = 0

async function loadHistory() {
  const current = ++version
  loading.value = true
  historyReady.value = false
  confirming.value = false
  errorMessage.value = ''
  try {
    const data = await paymentsApi.history(props.invoiceId)
    if (current !== version) return
    records.value = data
    historyReady.value = true
    if (data.some((payment) => payment.status === 'VERIFIED') && props.status !== 'PAID') emit('verified')
  } catch (error) {
    if (current === version) errorMessage.value = paymentError(error).message
  } finally {
    if (current === version) loading.value = false
  }
}
watch(() => props.invoiceId, () => {
  reference.value = ''
  receipt.value = null
  records.value = []
  successMessage.value = ''
  fields.value = {}
  void loadHistory()
}, { immediate: true })
watch([reference, receipt], () => { confirming.value = false })

function selectReceipt(event: Event) {
  receipt.value = (event.target as HTMLInputElement).files?.[0] ?? null
}
function prepareTransfer() {
  if (!canSubmit.value || submitting.value) return
  fields.value = {}
  errorMessage.value = ''
  if (!reference.value.trim()) fields.value.reference = ['Enter your bank transfer reference.']
  if (!receipt.value) fields.value.receipt = ['Select a PDF, JPG or PNG receipt.']
  else if (receipt.value.size > 2097152 || !['application/pdf', 'image/jpeg', 'image/png'].includes(receipt.value.type)) {
    fields.value.receipt = ['Upload a PDF, JPG or PNG receipt up to 2 MB.']
  }
  if (Object.keys(fields.value).length) return
  confirming.value = true
}
async function submitTransfer() {
  if (!canSubmit.value || submitting.value || !confirming.value || !receipt.value) return
  submitting.value = true
  errorMessage.value = ''
  fields.value = {}
  const current = version
  try {
    const payment = await paymentsApi.submitTransfer(props.invoiceId, props.total, reference.value.trim(), receipt.value)
    if (current !== version) return
    // Add the accepted payment immediately so a failed refresh cannot allow a duplicate.
    records.value.push(payment)
    confirming.value = false
    reference.value = ''
    receipt.value = null
    if (fileInput.value) fileInput.value.value = ''
    successMessage.value = 'Payment submitted. Please wait for independent verification.'
  } catch (error) {
    if (current !== version) return
    const failure = paymentError(error)
    const originalMessage = failure.message
    await loadHistory()
    if (current + 1 !== version) return
    errorMessage.value = historyReady.value ? originalMessage : `${originalMessage} Reload payment history before trying again.`
    fields.value = failure.fields
  } finally {
    submitting.value = false
  }
}
async function download(id: number) {
  if (downloadedId.value !== null) return
  downloadedId.value = id
  try { await paymentsApi.downloadReceipt(id) }
  catch (error) { errorMessage.value = paymentError(error).message }
  finally { downloadedId.value = null }
}
</script>

<template>
  <section class="payment-panel" aria-labelledby="payments-heading">
    <header class="panel-header"><h2 id="payments-heading">Payments</h2><button type="button" :disabled="loading || submitting" @click="loadHistory"><AppIcon name="refresh" /> Refresh payments</button></header>
    <p v-if="loading" role="status">Loading payment history...</p>
    <p v-if="errorMessage" class="notice error" role="alert">{{ errorMessage }}</p>
    <ul v-if="Object.keys(fields).length" class="notice error" role="alert"><template v-for="(messages, field) in fields" :key="field"><li v-for="message in messages" :key="message">{{ message }}</li></template></ul>
    <p v-if="successMessage" class="notice success" role="status">{{ successMessage }}</p>
    <template v-if="historyReady && !loading">
      <p v-if="records.length === 0" class="muted">No payments have been recorded for this invoice.</p>
      <article v-for="payment in records.slice().reverse()" :key="payment.id" class="payment-card">
        <div class="panel-header"><strong>{{ paymentMethodLabels[payment.method] }} · {{ formatRinggit(payment.amount) }}</strong><span class="badge" :class="payment.status.toLowerCase()">{{ paymentStatusLabels[payment.status] }}</span></div>
        <p class="muted">Submitted: {{ formatMalaysiaDateTime(payment.submitted_at) }}</p>
        <p v-if="payment.reference">Reference: {{ payment.reference }}</p>
        <p v-if="payment.reviewed_at">Reviewed: {{ formatMalaysiaDateTime(payment.reviewed_at) }}</p>
        <p v-if="payment.rejection_reason" class="notice warning">{{ payment.rejection_reason }}</p>
        <button v-if="payment.has_receipt" type="button" :disabled="downloadedId !== null" @click="download(payment.id)"><AppIcon name="download" /> {{ downloadedId === payment.id ? 'Downloading...' : 'Download receipt' }}</button>
      </article>
      <p v-if="records.some((p) => p.status === 'PENDING')" class="notice warning">A payment is awaiting verification. Another payment cannot be submitted for this invoice while review is pending.</p>
      <form v-if="canSubmit" @submit.prevent="prepareTransfer">
        <h3>Submit bank transfer</h3>
        <p>Submit proof of your completed bank transfer for the full invoice total.</p>
        <p><strong>Amount: {{ formatRinggit(total) }}</strong></p>
        <p class="muted"><span class="required-marker" aria-hidden="true">*</span> Required field</p>
        <fieldset :disabled="submitting">
          <legend class="sr-only">Bank transfer details</legend>
          <label for="transfer-reference">Transfer reference <span class="required-marker" aria-hidden="true">*</span></label>
          <input id="transfer-reference" v-model="reference" maxlength="100" required placeholder="Reference shown on your bank transfer receipt" :aria-invalid="!!fields.reference" />
          <label for="transfer-receipt">Receipt <span class="required-marker" aria-hidden="true">*</span></label>
          <input id="transfer-receipt" ref="fileInput" type="file" accept="application/pdf,image/jpeg,image/png" required aria-describedby="receipt-help" :aria-invalid="!!fields.receipt" @change="selectReceipt" />
          <small id="receipt-help">PDF, JPG or PNG, up to 2 MB. Receipts are accessible only to you and authorized committee users.</small>
          <button v-if="!confirming" type="submit">Review payment submission</button>
          <div v-else class="notice warning">
            <p>Submit this {{ formatRinggit(total) }} payment for verification? Your invoice remains unpaid until verification is complete.</p>
            <button type="button" @click="submitTransfer">{{ submitting ? 'Submitting...' : 'Confirm payment submission' }}</button>
            <button type="button" @click="confirming = false">Cancel</button>
          </div>
        </fieldset>
      </form>
    </template>
  </section>
</template>

<style scoped>
.payment-panel { margin-top: 1.5rem; padding: clamp(1rem, 3vw, 1.5rem); border: 1px solid #d8e3de; border-radius: 12px; color: #1c3029; }
h2 { margin: 0; font-size: 1.2rem; }
.panel-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; }
p, small { line-height: 1.6; }
.muted, small { color: #4c6055; }
.payment-card { margin-top: 1rem; padding: 1rem; border: 1px solid #d8e3de; border-radius: 8px; overflow-wrap: anywhere; }
form { margin-top: 1.5rem; border-top: 1px solid #d8e3de; padding-top: 1rem; }
fieldset { border: 0; min-width: 0; padding: 0; margin: 0; }
label, input, small { display: block; }
label { margin-top: 1rem; font-weight: 600; }
input { box-sizing: border-box; width: 100%; min-height: 44px; padding: 0.75rem; margin: 0.5rem 0; border: 1px solid #82968b; border-radius: 6px; font: inherit; }
button { min-height: 44px; padding: 0.65rem 1rem; margin: 0.5rem 0.5rem 0 0; border: 1px solid #185940; border-radius: 6px; background: #fff; color: #185940; font: inherit; cursor: pointer; }
button[type='submit'] { background: #185940; color: #fff; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
button:focus-visible, input:focus-visible { outline: 3px solid #a9d8c5; outline-offset: 3px; }
.badge { padding: 0.3rem 0.7rem; border-radius: 999px; font-size: 0.8rem; font-weight: 600; }
.pending { background: #edf4fc; color: #234f7a; }
.verified, .success { background: #edf8f1; color: #185940; }
.rejected, .error { background: #fff0ed; color: #b42318; }
.notice { padding: 1rem; border-radius: 8px; }
.warning { background: #fff7e3; color: #704a0b; }
.required-marker { color: #b42318; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
</style>
