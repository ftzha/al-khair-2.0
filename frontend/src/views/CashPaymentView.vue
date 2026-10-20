<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue'
import { onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { paymentsApi, paymentError } from '@/services/payments'
import { formatRinggit } from '@/types/invoice'
import type { CashInvoice, CashInvoicePage } from '@/types/payment'
import { formatMalaysiaDate } from '@/services/dateFormatting'

const search = ref('')
const results = ref<CashInvoicePage | null>(null)
const selected = ref<CashInvoice | null>(null)
const reference = ref('')
const loading = ref(true)
const recording = ref(false)
const confirming = ref(false)
const errorMessage = ref('')
const successId = ref<number | null>(null)
const fields = ref<Record<string, string[]>>({})
let version = 0
async function loadInvoices(page = 1) {
  const current = ++version
  loading.value = true
  selected.value = null
  confirming.value = false
  errorMessage.value = ''
  try {
    const data = await paymentsApi.cashInvoices(search.value.trim(), page)
    if (current !== version) return
    if (data.current_page > data.last_page) { await loadInvoices(data.last_page); return }
    results.value = data
  } catch (error) { if (current === version) { results.value = null; errorMessage.value = paymentError(error).message } }
  finally { if (current === version) loading.value = false }
}
function selectInvoice(invoice: CashInvoice) {
  if (recording.value) return
  selected.value = invoice
  reference.value = ''
  confirming.value = false
  fields.value = {}
}
async function recordCash() {
  if (!selected.value || recording.value || !confirming.value) return
  const invoice = selected.value
  recording.value = true
  fields.value = {}
  errorMessage.value = ''
  try {
    const payment = await paymentsApi.recordCash(invoice.id, invoice.total, reference.value)
    successId.value = payment.id
    await loadInvoices(results.value?.current_page ?? 1)
  } catch (error) {
    const failure = paymentError(error)
    await loadInvoices(results.value?.current_page ?? 1)
    errorMessage.value = failure.message
    fields.value = failure.fields
  } finally { recording.value = false }
}
watch(reference, () => { confirming.value = false })
onMounted(() => loadInvoices())
</script>
<template>
  <main>
    <RouterLink class="back-link" :to="{ name: 'committee-payments' }"><AppIcon name="back" /> Payment queue</RouterLink><p class="eyebrow">al Khair · Committee</p><h1>Record Cash Payment</h1><p class="muted">Choose an unpaid invoice and record the full cash amount received. Another authorized user must verify it.</p>
    <div v-if="successId" class="notice success" role="status">Cash payment recorded and awaiting independent verification. <RouterLink :to="{ name: 'committee-payment-detail', params: { id: successId } }">View payment</RouterLink></div>
    <p v-if="errorMessage" class="notice error" role="alert">{{ errorMessage }}</p><ul v-if="Object.keys(fields).length" class="notice error" role="alert"><template v-for="(messages, field) in fields" :key="field"><li v-for="message in messages" :key="message">{{ message }}</li></template></ul>
    <form class="card" @submit.prevent="loadInvoices()"><label for="invoice-search">Find invoice</label><input id="invoice-search" v-model="search" maxlength="100" placeholder="Enter all or part of the invoice number" :disabled="recording" /><button type="submit" :disabled="loading || recording">Search invoices</button></form>
    <p v-if="loading" role="status">Loading unpaid invoices...</p>
    <template v-if="results && !loading">
      <p v-if="!results.data.length" class="notice warning">No eligible unpaid invoices found. Invoices with a pending or verified payment cannot receive another payment.</p>
      <ul class="list"><li v-for="invoice in results.data" :key="invoice.id" class="card"><div class="row"><div><strong>{{ invoice.applicant_name }}</strong><p>{{ invoice.invoice_number }}</p><p class="muted">Due {{ formatMalaysiaDate(invoice.due_date) }}</p></div><div><strong>{{ formatRinggit(invoice.total) }}</strong><button type="button" :disabled="recording" @click="selectInvoice(invoice)">{{ selected?.id === invoice.id ? 'Selected' : 'Select invoice' }}</button></div></div></li></ul>
      <nav v-if="results.last_page > 1" aria-label="Cash invoice pages"><button type="button" :disabled="recording || results.current_page <= 1" @click="loadInvoices(results.current_page - 1)">Previous page</button><span>Page {{ results.current_page }} of {{ results.last_page }}</span><button type="button" :disabled="recording || results.current_page >= results.last_page" @click="loadInvoices(results.current_page + 1)">Next page</button></nav>
    </template>
    <form v-if="selected" class="card" @submit.prevent="confirming = true"><h2>Cash record</h2><p>{{ selected.invoice_number }} · {{ selected.applicant_name }}</p><p><strong>Full amount received: {{ formatRinggit(selected.total) }}</strong></p><fieldset :disabled="recording"><legend>Recording details</legend><label for="cash-reference">Receipt or reference number (optional)</label><input id="cash-reference" v-model="reference" maxlength="100" placeholder="e.g. cash receipt number" /><button v-if="!confirming" type="submit">Review cash record</button><div v-else class="notice warning"><p>Confirm you received {{ formatRinggit(selected.total) }} in cash for this invoice? This remains pending until independently verified.</p><button type="button" @click="recordCash">{{ recording ? 'Recording...' : 'Confirm cash payment' }}</button><button type="button" @click="confirming = false">Cancel</button></div></fieldset></form>
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
