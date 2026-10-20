<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { invoicesApi, invoiceError } from '@/services/invoices'
import { formatMalaysiaDate, formatMalaysiaDateTime } from '@/services/dateFormatting'
import { formatRinggit, invoiceStatusLabels, type InvoicePage } from '@/types/invoice'

const results = ref<InvoicePage | null>(null)
const loading = ref(true)
const errorMessage = ref('')
let requestVersion = 0

async function loadInvoices(page = 1) {
  const version = ++requestVersion
  loading.value = true
  errorMessage.value = ''
  try {
    const data = await invoicesApi.list(page)
    if (version !== requestVersion) return
    if (data.current_page > data.last_page) {
      await loadInvoices(data.last_page)
      return
    }
    results.value = data
  } catch (error) {
    if (version === requestVersion) {
      results.value = null
      errorMessage.value = invoiceError(error)
    }
  } finally {
    if (version === requestVersion) loading.value = false
  }
}
onMounted(() => loadInvoices())
</script>

<template>
  <main>
    <header class="page-header">
      <div>
        <p class="eyebrow">al Khair · Billing</p>
        <h1>My Invoices</h1>
        <p class="muted">View your fees, invoice status and payment due dates.</p>
      </div>
      <button type="button" :disabled="loading" @click="loadInvoices(results?.current_page ?? 1)">Refresh invoices</button>
    </header>
    <p v-if="loading" class="notice" role="status">Loading invoices...</p>
    <p v-if="errorMessage" class="notice error" role="alert">{{ errorMessage }}</p>
    <template v-if="results && !loading">
      <section v-if="results.data.length === 0" class="empty-state">
        <h2>No invoices yet</h2>
        <p>An initial invoice is generated when your membership application is approved.</p>
        <RouterLink to="/applications">View my applications</RouterLink>
      </section>
      <ul v-else class="invoice-list">
        <li v-for="invoice in results.data" :key="invoice.id" class="invoice-card">
          <div class="invoice-header">
            <RouterLink class="invoice-title" :to="{ name: 'invoice-detail', params: { id: invoice.id } }">{{ invoice.invoice_number }}</RouterLink>
            <span class="badge" :class="invoice.status.toLowerCase()">{{ invoiceStatusLabels[invoice.status] }}</span>
          </div>
          <p class="amount">{{ formatRinggit(invoice.total) }}</p>
          <dl>
            <div><dt>Issued</dt><dd>{{ formatMalaysiaDateTime(invoice.issued_at) }}</dd></div>
            <div><dt>Payment due</dt><dd>{{ formatMalaysiaDate(invoice.due_date) }}</dd></div>
            <div><dt>Billing year</dt><dd>{{ invoice.billing_year }}</dd></div>
          </dl>
          <RouterLink class="details-link" :to="{ name: 'invoice-detail', params: { id: invoice.id } }">View invoice <span aria-hidden="true">→</span></RouterLink>
        </li>
      </ul>
      <nav v-if="results.last_page > 1" aria-label="Invoice pages">
        <button type="button" :disabled="results.current_page <= 1" @click="loadInvoices(results.current_page - 1)">Previous page</button>
        <span>Page {{ results.current_page }} of {{ results.last_page }}</span>
        <button type="button" :disabled="results.current_page >= results.last_page" @click="loadInvoices(results.current_page + 1)">Next page</button>
      </nav>
    </template>
  </main>
</template>

<style scoped>
main { max-width: 60rem; margin: 2rem auto; padding: 0 1rem 2rem; color: #1c3029; }
a { color: #185940; }
.page-header, .invoice-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
h1 { margin: 0.3rem 0 0.5rem; }
h2 { font-size: 1.2rem; }
p { line-height: 1.6; }
.eyebrow { color: #185940; font-size: 0.85rem; font-weight: 600; margin: 0; }
.muted, dt { color: #4c6055; }
.invoice-list { display: grid; gap: 1rem; list-style: none; padding: 0; }
.invoice-card, .empty-state { padding: clamp(1rem, 3vw, 1.5rem); border: 1px solid #d8e3de; border-radius: 12px; background: #fff; }
.empty-state { margin-top: 1.5rem; text-align: center; }
.invoice-title { font-weight: 600; overflow-wrap: anywhere; }
.amount { font-size: 1.75rem; font-weight: 700; margin: 1rem 0; }
.badge { padding: 0.3rem 0.75rem; border-radius: 999px; font-size: 0.85rem; font-weight: 600; }
.unpaid { background: #fff4d8; color: #78520b; }
.paid { background: #e6f5ec; color: #185940; }
.cancelled { background: #eef1f0; color: #41564b; }
dl { display: flex; flex-wrap: wrap; gap: 1rem 2rem; }
dd { margin: 0.3rem 0 0; }
.details-link { display: inline-block; margin-top: 0.5rem; font-weight: 600; }
button { min-height: 44px; padding: 0.65rem 1rem; border: 1px solid #185940; border-radius: 6px; background: #fff; color: #185940; font: inherit; cursor: pointer; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
a:focus-visible, button:focus-visible { outline: 3px solid #a9d8c5; outline-offset: 3px; }
nav { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem; margin-top: 1.5rem; }
.notice { padding: 1rem; background: #f1f6f3; border-radius: 8px; }
.error { color: #b42318; background: #fff0ed; }
</style>
