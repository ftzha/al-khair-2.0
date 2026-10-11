import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

// Lazy loading downloads each page component only when needed.
// This helps keep the initial JavaScript bundle smaller.
const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  
  routes: [
    {
      path: '/',
      redirect: '/dashboard',
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { guestOnly: true },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/views/RegisterView.vue'),
      meta: { guestOnly: true },
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: () => import('@/views/DashboardView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/dashboard',
    },
  ],
})

// Global navigation guard runs before every route change.
router.beforeEach(async (to) => {
  const auth = useAuthStore()

  // On initial load, validate any saved Sanctum token.
  await auth.restoreSession()

  // A valid authenticated session requires a loaded user.
  const authenticated = auth.isAuthenticated && auth.user !== null

  if (to.meta.requiresAuth && !authenticated) {
    // Preserve the requested page so we can return after login.
    return {
      name: 'login',
      query: { redirect: to.fullPath },
    }
  }

  if (to.meta.guestOnly && authenticated) {
    return { name: 'dashboard' }
  }

  return true
})

export default router
