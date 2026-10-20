
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
    <main>
        <h1>Dashboard</h1>
        <RouterLink to="/applications">My Applications</RouterLink>
        <RouterLink v-if="hasCommitteeAccess(auth.user?.role)" to="/committee/applications">Review Applications</RouterLink>

        <p>Welcome, {{ auth.user?.full_name }}!</p>
        <p>Email: {{ auth.user?.email }}</p>
        <p>Role: {{ auth.user?.role }}</p>

        <!-- Email verification is separate from account activation. -->
        <section v-if="!auth.user?.email_verified">
            <h2>Email Verification</h2>

            <p>
                Your email address is not verified yet.
                Please verify it before submitting a membership application.
            </p>

            <button
                type="button"
                :disabled="isSendingVerification"
                @click="handleResendVerification"
            >
                {{ isSendingVerification ? 'Sending...' : 'Resend Verification Email' }}
            </button>

            <button
                type="button"
                :disabled="isRefreshingUser"
                @click="handleRefreshVerification"
            >
                {{ isRefreshingUser ? 'Checking...' : 'Refresh Verification Status' }}
            </button>
        </section>

        <section v-else>
            <h2>Email Verification</h2>
            <p>Your email address is verified.</p>
        </section>

        <p v-if="verificationMessage" role="status">
            {{ verificationMessage }}
        </p>

        <p v-if="verificationError" role="alert">
            {{ verificationError }}
        </p>

        <button type="button" @click="handleLogout">
            Logout
        </button>

        <p v-if="logoutError" role="alert">
            {{ logoutError }}
        </p>
    </main>
</template>
