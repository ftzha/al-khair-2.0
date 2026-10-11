
import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api,setUnauthorizedHandler } from '@/services/api'

import type {
    AuthUser,
    LoginPayload,
    LoginResponse,
    MeResponse,
    VerificationNotificationResponse,
} from '@/types/auth'

export const useAuthStore = defineStore('auth', () => {
    // Reactive user state shared across Vue components.
    const user = ref<AuthUser | null>(null)

    // Restore the token after a page refresh.
    // Store the token separately from user data.
    const token = ref<string | null>(sessionStorage.getItem('auth_token'))

    // Tracks whether we've checked the saved token with Laravel.
    // This prevents repeated /me requests during route navigation.
    const sessionChecked = ref(false)

    // Derived state: components can check whether a token exists.
    // A token alone does not prove it is still valid.
    const isAuthenticated = computed(() => token.value !== null)

    // Prevent duplicate requests and support loading indicators.
    const isLoading = ref(false)

    async function login(credentials: LoginPayload): Promise<void> {
        isLoading.value = true
    
        try {
            // Axios response.data contains Laravel's JSON response.
            const response = await api.post<LoginResponse>('/login', credentials)

            const loginData = response.data.data

            // Save the bearer token for future authenticated API requests.
            token.value = loginData.token
            sessionStorage.setItem('auth_token', loginData.token)

            // Update reactive user state immediately after login.
            user.value = loginData.user

            // The login response already provides a verified user.
            sessionChecked.value = true
        } finally {
            isLoading.value = false
        }
    }

  
    async function restoreSession(): Promise<void> {
        // Avoid calling /me repeatedly when navigating between pages.
        if (sessionChecked.value) return

        // Without a saved token, there is no session to restore.
        if (!token.value) {
            sessionChecked.value = true
            return
        }

        try {
            // Validate the saved token and retrieve the current user.
            const response = await api.get<MeResponse>('/me')

            user.value = response.data.data
        } catch {
            // An invalid, revoked, or otherwise unusable token
            // must not grant access to protected frontend pages.
            clearSession()
        } finally {
            sessionChecked.value = true
        }
    }

    function clearSession(): void {
        // Clear local authentication state.
        // This does not revoke the token on the Laravel server.
        token.value = null
        user.value = null
        sessionStorage.removeItem('auth_token')

        sessionChecked.value = true
    }

    async function logout(): Promise<void> {
        try {
            // Laravel revokes the current Sanctum token.
            if (token.value) {
            await api.post('/logout')
            }
        } finally {
            // Clear local state even if the request fails.
            // Note: a network failure may leave the server token valid.
            clearSession()
        }
    }
    
    async function refreshUser(): Promise<void> {
        // Fetch the latest user information from Laravel.
        // Unlike restoreSession(), this always makes a new request.
        const response = await api.get<MeResponse>('/me')

        // Keep the current user's verification status up to date.
        user.value = response.data.data
    }

    async function resendVerificationEmail(): Promise<string> {
        // Laravel requires an authenticated, active account.
        const response = await api.post<VerificationNotificationResponse>(
            '/email/verification-notification',
        )

        return response.data.message
    }
    
    // Register once when the auth store is initialized.
    // This keeps Axios independent of Pinia while synchronizing session state.
    setUnauthorizedHandler(clearSession)

    return {
        user,
        token,
        isAuthenticated,
        isLoading,
        sessionChecked,
        login,
        logout,
        clearSession,
        restoreSession,
        refreshUser,
        resendVerificationEmail,
    }
})
