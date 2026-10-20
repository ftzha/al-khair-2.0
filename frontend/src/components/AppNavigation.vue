<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue'
import { computed, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { hasCommitteeAccess } from '@/types/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const loggingOut = ref(false)
const logoutError = ref('')
const visible = computed(() => auth.isAuthenticated && auth.user !== null && route.meta.requiresAuth)
const links = computed(() => [
  { name: 'dashboard', label: 'Dashboard', icon: 'home', prefix: '/dashboard' },
  { name: 'applications', label: 'My Applications', icon: 'application', prefix: '/applications' },
  { name: 'invoices', label: 'My Invoices', icon: 'invoice', prefix: '/invoices' },
  ...(hasCommitteeAccess(auth.user?.role) ? [
    { name: 'committee-applications', label: 'Review Applications', icon: 'review', prefix: '/committee/applications' },
    { name: 'committee-memberships', label: 'Memberships', icon: 'members', prefix: '/committee/memberships' },
    { name: 'committee-payments', label: 'Verify Payments', icon: 'payment', prefix: '/committee/payments' },
  ] : []),
])
function isCurrent(prefix: string) {
  return route.path === prefix || route.path.startsWith(prefix + '/')
}
async function logout() {
  if (loggingOut.value) return
  loggingOut.value = true
  logoutError.value = ''
  try {
    await auth.logout()
  } catch {
    logoutError.value = 'Could not confirm server logout. Your local session was cleared.'
  } finally {
    try {
      await router.replace({ name: 'login' })
    } finally {
      loggingOut.value = false
    }
  }
}
</script>

<template>
  <header v-if="visible" class="portal-header">
    <div class="header-row">
      <RouterLink class="brand" :to="{ name: 'dashboard' }">al Khair <span>Member portal</span></RouterLink>
      <div class="account">
        <span class="account-name">{{ auth.user?.full_name }}</span>
        <button type="button" :disabled="loggingOut" @click="logout"><AppIcon name="logout" /> {{ loggingOut ? 'Logging out...' : 'Logout' }}</button>
      </div>
    </div>
    <nav aria-label="Main navigation">
      <RouterLink v-for="link in links" :key="link.name" :to="{ name: link.name }"
        :class="{ current: isCurrent(link.prefix) }" :aria-current="isCurrent(link.prefix) ? 'page' : undefined">
        <AppIcon :name="link.icon" /> {{ link.label }}
      </RouterLink>
    </nav>
  </header>
  <p v-if="logoutError" class="logout-error" role="alert">{{ logoutError }}</p>
</template>

<style scoped>
.portal-header { background: #f5f8f7; border-bottom: 1px solid #d8e3de; color: #1c3029; }
.header-row, nav { max-width: 64rem; margin: 0 auto; padding: 0.75rem 1rem; display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 1rem; }
.header-row { justify-content: space-between; }
.brand { font-size: 1.3rem; font-weight: 700; color: #185940; text-decoration: none; }
.brand span { display: block; font-size: 0.8rem; font-weight: 400; color: #4c6055; }
.account { display: flex; align-items: center; gap: 0.75rem; min-width: 0; }
.account-name { overflow-wrap: anywhere; }
button, nav a { min-height: 44px; box-sizing: border-box; padding: 0.65rem 0.85rem; border-radius: 6px; font: inherit; }
button { border: 1px solid #185940; background: #fff; color: #185940; cursor: pointer; white-space: nowrap; }
button:disabled { opacity: 0.6; cursor: wait; }
nav { padding-top: 0; gap: 0.35rem; }
nav a { display: flex; align-items: center; gap: 0.5rem; color: #185940; text-decoration: none; }
nav a:hover { background: #e6f1eb; }
nav a.current { background: #185940; color: #fff; }
a:focus-visible, button:focus-visible { outline: 3px solid #a9d8c5; outline-offset: 3px; }
.logout-error { max-width: 64rem; margin: 1rem auto; padding: 1rem; background: #fff0ed; color: #b42318; border-radius: 6px; }
@media (max-width: 480px) { .account { width: 100%; justify-content: space-between; } nav a { flex: 1 1 auto; justify-content: center; } }
</style>
