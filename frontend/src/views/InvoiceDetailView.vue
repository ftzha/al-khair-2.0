<script setup lang="ts">
import { ref, watch } from 'vue'
import InvoicePayments from '@/views/InvoicePayments.vue'
import { RouterLink, useRoute } from 'vue-router'
import { invoicesApi, invoiceError } from '@/services/invoices'
import { formatMalaysiaDate, formatMalaysiaDateTime } from '@/services/dateFormatting'
import { formatRinggit, invoiceStatusLabels, type InvoiceDetail } from '@/types/invoice'

const route = useRoute()
const invoice = ref<InvoiceDetail | null>(null)
const loading = ref(true)
const errorMessage = ref('')
let requestVersion = 0

async function loadInvoice() {
  const version = ++requestVersion
  invoice.value = null
  loading.value = true
  errorMessage.value = ''
  try {
    const id = Number(route.params.id)
    if (!Number.isSafeInteger(id) || id < 1) {
      errorMessage.value = 'This invoice could not be found.'
      return
    }
    const data = await invoicesApi.detail(id)
    if (version === requestVersion) invoice.value = data
  } catch (error) {
    if (version === requestVersion) errorMessage.value = invoiceError(error)
  } finally {
    if (version === requestVersion) loading.value = false
  }
}
async function refreshInvoiceStatus() {
  if (!invoice.value) return
  const id = invoice.value.id
  const version = requestVersion
  try {
    const updated = await invoicesApi.detail(id)
    if (version === requestVersion && invoice.value?.id === id) invoice.value = updated
  } catch (error) {
    if (version === requestVersion) errorMessage.value = invoiceError(error)
  }
}
watch(() => route.params.id, loadInvoice, { immediate: true })
</script>

<template>
  <main>
    <RouterLink class="back-link" :to="{ name: 'invoices' }">Back to my invoices</RouterLink>
    <header class="page-header">
      <div><p class="eyebrow">al Khair · Billing</p><h1>Invoice Details</h1></div>
      <button type="button" :disabled="loading" @click="loadInvoice">Reload invoice</button>
    </header>
    <p v-if="loading" class="notice" role="status">Loading invoice...</p>
    <p v-if="errorMessage" class="notice error" role="alert">{{ errorMessage }}</p>
    <template v-if="invoice && !loading">
      <section class="card" aria-labelledby="invoice-heading">
        <div class="invoice-header">
          <h2 id="invoice-heading">{{ invoice.invoice_number }}</h2>
          <span class="badge" :class="invoice.status.toLowerCase()">{{ invoiceStatusLabels[invoice.status] }}</span>
        </div>
        <dl>
          <div><dt>Invoice type</dt><dd>{{ invoice.type === 'INITIAL' ? 'Initial registration' : 'Annual renewal' }}</dd></div>
          <div><dt>Billing year</dt><dd>{{ invoice.billing_year }}</dd></div>
          <div><dt>Issued</dt><dd>{{ formatMalaysiaDateTime(invoice.issued_at) }}</dd></div>
          <div><dt>Payment due</dt><dd>{{ formatMalaysiaDate(invoice.due_date) }}</dd></div>
        </dl>
        <RouterLink v-if="invoice.registration_id" :to="{ name: 'application-detail', params: { id: invoice.registration_id } }">View related application</RouterLink>
      </section>
      <section class="card" aria-labelledby="items-heading">
        <h2 id="items-heading">Fee breakdown</h2>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Invoice fee breakdown">
          <table>
            <caption class="sr-only">Invoice items in Malaysian ringgit</caption>
            <thead><tr><th scope="col">Description</th><th scope="col" class="number">Amount (MYR)</th></tr></thead>
            <tbody>
              <tr v-for="item in invoice.items" :key="item.id"><td>{{ item.description }}</td><td class="number">{{ formatRinggit(item.amount) }}</td></tr>
            </tbody>
            <tfoot><tr><th scope="row">Total</th><td class="number">{{ formatRinggit(invoice.total) }}</td></tr></tfoot>
          </table>
        </div>
        <p class="muted">Amounts reflect the fees applicable when this invoice was issued.</p>
      </section>
      <InvoicePayments :invoice-id="invoice.id" :total="invoice.total" :status="invoice.status" @verified="refreshInvoiceStatus" />
      <p v-if="invoice.status === 'UNPAID'" class="notice">This invoice is unpaid. Membership activation requires a verified initial payment.</p>
      <p v-else-if="invoice.status === 'CANCELLED'" class="notice">This invoice has been cancelled.</p>
      <p v-if="invoice.type === 'INITIAL'" class="muted">The initial invoice due date is separate from the annual renewal deadline.</p>
    </template>
  </main>
</template>

<style scoped>
main { max-width: 60rem; margin: 2rem auto; padding: 0 1rem 2rem; color: #1c3029; }
a { color: #185940; }
.back-link { display: inline-block; margin-bottom: 1.5rem; }
.page-header, .invoice-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem; }
h1 { margin: 0.3rem 0 0.5rem; }
h2 { font-size: 1.2rem; margin-top: 0; overflow-wrap: anywhere; }
.eyebrow { color: #185940; font-size: 0.85rem; font-weight: 600; margin: 0; }
.card { margin-top: 1.5rem; padding: clamp(1rem, 3vw, 1.5rem); border: 1px solid #d8e3de; border-radius: 12px; }
.badge { padding: 0.3rem 0.75rem; border-radius: 999px; font-size: 0.85rem; font-weight: 600; }
.unpaid { background: #fff4d8; color: #78520b; }
.paid { background: #e6f5ec; color: #185940; }
.cancelled { background: #eef1f0; color: #41564b; }
dl { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 1rem; }
dt, .muted { color: #4c6055; }
dd { margin: 0.35rem 0 0; line-height: 1.6; }
.table-scroll { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 1rem 0.5rem; text-align: left; border-bottom: 1px solid #d8e3de; }
thead, tfoot { background: #f5f8f7; }
.number { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
tfoot { font-weight: 700; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
.notice { padding: 1rem; margin-top: 1.5rem; background: #fff7e3; border-radius: 8px; line-height: 1.6; }
.error { color: #b42318; background: #fff0ed; }
button { min-height: 44px; padding: 0.65rem 1rem; border: 1px solid #185940; border-radius: 6px; background: #fff; color: #185940; font: inherit; cursor: pointer; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
a:focus-visible, button:focus-visible, .table-scroll:focus-visible { outline: 3px solid #a9d8c5; outline-offset: 3px; }
</style>
