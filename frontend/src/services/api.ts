
import axios from 'axios'

// Create one shared Axios instance for all Laravel API requests.
// This keeps base URL, headers, and interceptors in one place.
export const api = axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL || '/api',

    headers: {
        Accept: 'application/json',
    },
})

// Read the saved Sanctum bearer token before each API request.
// Laravel uses this token to identify the authenticated user.
api.interceptors.request.use((config) => {
    const token = sessionStorage.getItem('auth_token')

    // Login and account registration are public endpoints.
    // Do not attach a previous user's bearer token to these requests.
    const publicEndpoints = ['/login', '/register']

    if (token && !publicEndpoints.includes(config.url ?? '')) {
        config.headers.Authorization = `Bearer ${token}`
    }

    return config
})

// Clear an invalid session when Laravel rejects an authenticated request.
// Only handle 401 here; a 403 may mean insufficient permissions,
// which should not automatically log the user out.
api.interceptors.response.use(
    (response) => response,
    (error: unknown) => {
        if (axios.isAxiosError(error) && error.response?.status === 401) {
            const token = sessionStorage.getItem('auth_token')

            // A failed login should display its own validation error.
            // Only clear an existing session if its bearer token was rejected.
            if (token && error.config?.headers?.Authorization) {
                // Let Pinia clear both the saved token and reactive user state.
                if (onUnauthorized) {
                    onUnauthorized()
                } else {
                    sessionStorage.removeItem('auth_token')
                }
            }
        }

        return Promise.reject(error)
    },
)

// Authentication state belongs to Pinia, not the HTTP client.
// A callback lets Axios notify Pinia without importing the store.
let onUnauthorized: (() => void) | null = null

export function setUnauthorizedHandler(handler: (() => void) | null): void {
    onUnauthorized = handler
}
