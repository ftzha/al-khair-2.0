
<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { AxiosError } from 'axios'
import { hasCommitteeAccess } from '@/types/auth'

const auth = useAuthStore()
const router = useRouter()

// Display an error if the logout request fails.
// The store still clears the local session in that case.
const logoutError = ref('')

async function handleLogout() {
    logoutError.value = ''

    try {
        await auth.logout()
    } catch {
        // Network errors can prevent server-side token revocation.
        logoutError.value =
        'Could not confirm server logout. Your local session was cleared.'
    } finally {
        await router.replace('/login')
    }
}

// Track verification actions separately from logout.
const isSendingVerification = ref(false)
const isRefreshingUser = ref(false)
const verificationMessage = ref('')
const verificationError = ref('')

async function handleResendVerification() {
  if (isSendingVerification.value) return

  isSendingVerification.value = true
  verificationMessage.value = ''
  verificationError.value = ''

  try {
    // Request a new signed verification link from Laravel.
    verificationMessage.value = await auth.resendVerificationEmail()
  } catch (error) {
    verificationError.value =
      error instanceof AxiosError
        ? (error.response?.data?.message ?? 'Could not send verification email.')
        : 'Could not send verification email.'
  } finally {
    isSendingVerification.value = false
  }
}

async function handleRefreshVerification() {
  if (isRefreshingUser.value) return

  isRefreshingUser.value = true
  verificationMessage.value = ''
  verificationError.value = ''

  try {
    // Re-read the user's current verification status from Laravel.
    await auth.refreshUser()

    // The template already displays the verified status.
    // Only show additional feedback when verification is still pending.
    verificationMessage.value = auth.user?.email_verified
        ? ''
        : 'Your email has not been verified yet.'
  } catch {
    verificationError.value = 'Could not refresh verification status.'
  } finally {
    isRefreshingUser.value = false
  }
}

</script>

<template>
  <main class="dashboard">
    <header class="page-header">
      <div>
        <p class="eyebrow">al Khair · Member portal</p>
        <h1>Dashboard</h1>
        <p class="muted">Welcome, {{ auth.user?.full_name }}!</p>
      </div>
      <button type="button" class="secondary" @click="handleLogout">Logout</button>
    </header>
    <p v-if="logoutError" class="notice error" role="alert">{{ logoutError }}</p>
    <div class="dashboard-grid">
      <section class="card" aria-labelledby="account-heading">
        <h2 id="account-heading">Your account</h2>
        <dl>
          <dt>Name</dt><dd>{{ auth.user?.full_name }}</dd>
          <dt>Email</dt><dd>{{ auth.user?.email }}</dd>
          <dt>Role</dt><dd><span class="badge">{{ auth.user?.role }}</span></dd>
        </dl>
      </section>
      <section class="card" :class="auth.user?.email_verified ? 'verified' : 'pending'" aria-labelledby="verification-heading">
        <h2 id="verification-heading">Email Verification</h2>
        <template v-if="!auth.user?.email_verified">
          <p>Your email address is not verified yet. Please verify it before submitting a membership application.</p>
          <div class="actions">
            <button type="button" :disabled="isSendingVerification" @click="handleResendVerification">
              {{ isSendingVerification ? 'Sending...' : 'Resend Verification Email' }}
            </button>
            <button type="button" class="secondary" :disabled="isRefreshingUser" @click="handleRefreshVerification">
              {{ isRefreshingUser ? 'Checking...' : 'Refresh Verification Status' }}
            </button>
          </div>
        </template>
        <template v-else>
          <span class="badge">Verified</span>
          <p>Your email address is verified.</p>
          <p class="muted">You can submit your membership application for review.</p>
        </template>
        <p v-if="verificationMessage" class="notice" role="status">{{ verificationMessage }}</p>
        <p v-if="verificationError" class="notice error" role="alert">{{ verificationError }}</p>
      </section>
    </div>
    <section class="card" aria-labelledby="actions-heading">
      <h2 id="actions-heading">Applications &amp; billing</h2>
      <div class="action-grid">
        <RouterLink class="action-card" to="/applications">
          <strong>My Applications <span aria-hidden="true">→</span></strong>
          <span>Create a draft, update your contact details and follow your application status.</span>
        </RouterLink>
        <RouterLink class="action-card" to="/invoices">
          <strong>My Invoices <span aria-hidden="true">→</span></strong>
          <span>View your fee breakdown, invoice status and payment due dates.</span>
        </RouterLink>
        <RouterLink v-if="hasCommitteeAccess(auth.user?.role)" class="action-card" to="/committee/applications">
          <strong>Review Applications <span aria-hidden="true">→</span></strong>
          <span>Review submitted applications and record committee decisions.</span>
        </RouterLink>
        <RouterLink v-if="hasCommitteeAccess(auth.user?.role)" class="action-card" to="/committee/payments">
          <strong>Verify Payments <span aria-hidden="true">→</span></strong>
          <span>Review transfer receipts, record cash payments and verify independently.</span>
        </RouterLink>
      </div>
    </section>
  </main>
</template>

<style scoped>
.dashboard { max-width: 64rem; margin: 2rem auto; padding: 0 1rem 2rem; color: #1c3029; }
.page-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1.5rem; }
h1 { margin: 0.3rem 0 0.5rem; }
h2 { margin-top: 0; font-size: 1.2rem; }
p { line-height: 1.6; }
.eyebrow { margin: 0; color: #185940; font-size: 0.85rem; font-weight: 600; }
.muted { color: #4c6055; }
.dashboard-grid, .action-grid { display: grid; gap: 1rem; }
.dashboard-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.action-grid { display: flex; flex-wrap: wrap; gap: 1rem; }
.action-grid > .action-card { flex: 1 1 18rem; min-width: 0; }
.card { margin-bottom: 1rem; padding: clamp(1rem, 3vw, 1.5rem); background: #fff; border: 1px solid #d8e3de; border-radius: 12px; }
.verified { background: #f3faf5; }
.pending { background: #fffbf0; border-color: #e8c77c; }
dl { margin-bottom: 0; }
dt { margin-top: 0.85rem; color: #4c6055; font-size: 0.9rem; }
dd { margin: 0.25rem 0 0; overflow-wrap: anywhere; }
.badge { display: inline-block; padding: 0.25rem 0.65rem; border-radius: 999px; background: #e6f1eb; color: #185940; font-size: 0.85rem; font-weight: 600; }
.actions { display: flex; flex-wrap: wrap; gap: 0.6rem; }
button { min-height: 44px; padding: 0.65rem 1rem; border: 1px solid #185940; border-radius: 6px; background: #185940; color: #fff; font: inherit; cursor: pointer; }
button.secondary { background: #fff; color: #185940; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
.action-card { display: flex; flex-direction: column; gap: 0.65rem; padding: 1.25rem; border: 1px solid #d8e3de; border-radius: 8px; color: #185940; text-decoration: none; }
.action-card strong { display: flex; justify-content: space-between; gap: 1rem; }
.action-card > span { color: #4c6055; line-height: 1.6; }
.action-card:hover { background: #f3faf5; border-color: #185940; }
button:focus-visible, a:focus-visible { outline: 3px solid #a9d8c5; outline-offset: 3px; }
.notice { padding: 0.85rem; border-radius: 6px; background: #edf8f1; }
.error { background: #fff0ed; color: #b42318; }
@media (max-width: 640px) { .dashboard-grid, .action-grid { grid-template-columns: 1fr; } .page-header { align-items: flex-start; } }
</style>
