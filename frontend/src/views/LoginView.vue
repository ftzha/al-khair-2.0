
<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { AxiosError } from 'axios'

import { useAuthStore } from '@/stores/auth'

import { useFlashStore } from '@/stores/flash'
import type { FlashMessage } from '@/stores/flash'

const flash = useFlashStore()

// Read the message when this page is created.
// consume() clears the shared store so it won't appear again
// on a later visit to the login page.
const notification = ref<FlashMessage | null>(flash.consume())

// Router lets us redirect after a successful login.
// Route gives us access to the original destination.
const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

// Form fields are reactive, so Vue keeps them in sync
// with the input elements through v-model.
const email = ref('')
const password = ref('')
const errorMessage = ref('')

async function handleLogin() {
    // Remove errors from previous login attempts.
    errorMessage.value = ''

    try {
        // Pinia sends the credentials to Laravel and saves
        // the Sanctum token and authenticated user.
        await auth.login({
            email: email.value,
            password: password.value,
        })

        // Return to the originally requested page, if provided.
        // Only accept an internal path to avoid external redirects.
        const redirect = route.query.redirect
        const destination =
        typeof redirect === 'string' &&
        redirect.startsWith('/') &&
        !redirect.startsWith('//') &&
        !redirect.startsWith('/\\')
        ? redirect
        : '/dashboard'

        await router.replace(destination)
    } catch (error) {
        // Laravel commonly returns validation errors with a
        // message field. Fall back to a generic message otherwise.
        if (error instanceof AxiosError) {
            errorMessage.value =
            error.response?.data?.message ??
            'Unable to log in. Please try again.'
        } else {
            errorMessage.value = 'Something went wrong. Please try again.'
        }
    }
}
</script>

<template>
    <main class="auth-page">
        <form class="auth-form" @submit.prevent="handleLogin">
            <h1>Welcome to al Khair</h1>
            <p>Sign in to your account.</p>
            
            <!-- Display a one-time message passed from the previous page. -->
            <p
            v-if="notification"
            :class="notification.type"
            :role="notification.type === 'error' ? 'alert' : 'status'"
            >
                {{ notification.text }}
            </p>

            <p class="required-note"><span class="required-marker" aria-hidden="true">*</span> Required field</p>
            <label for="email">Email <span class="required-marker" aria-hidden="true">*</span></label>
            <input
                id="email"
                v-model.trim="email"
                type="email"
                autocomplete="email"
                placeholder="e.g. name@example.com"
                required
            />

            <label for="password">Password <span class="required-marker" aria-hidden="true">*</span></label>
            <input
                id="password"
                v-model="password"
                type="password"
                autocomplete="current-password"
                placeholder="Enter your password"
                required
            />

            <p v-if="errorMessage" role="alert" class="error">
                {{ errorMessage }}
            </p>

            <button type="submit" :disabled="auth.isLoading">
                {{ auth.isLoading ? 'Signing in...' : 'Sign In' }}
            </button>

            <p>
                Don't have an account?
                <RouterLink to="/register">Create one</RouterLink>
            </p>
        </form>
    </main>
</template>

<style scoped>
.auth-page {
    display: grid;
    min-height: 100vh;
    place-items: center;
    padding: 1.5rem;
    background: #f5f8f7;
    color: #1c3029;
}
.auth-form {
    display: grid;
    gap: 0.65rem;
    box-sizing: border-box;
    width: min(100%, 520px);
    padding: clamp(1.25rem, 4vw, 2rem);
    background: #fff;
    border: 1px solid #d8e3de;
    border-radius: 12px;
}
h1, p { margin: 0 0 0.5rem; }
.auth-form label { font-weight: 600; margin-top: 0.65rem; }
.auth-form input {
    box-sizing: border-box;
    width: 100%;
    min-height: 44px;
    padding: 0.75rem;
    border: 1px solid #82968b;
    border-radius: 6px;
    font: inherit;
}
.auth-form input::placeholder { color: #617168; opacity: 1; }
.auth-form input:focus-visible, .auth-form button:focus-visible {
    outline: 3px solid #a9d8c5;
    outline-offset: 2px;
}
small { line-height: 1.5; color: #4c6055; }
.auth-form button {
    min-height: 44px;
    margin-top: 1rem;
    padding: 0.75rem;
    border: 0;
    border-radius: 6px;
    background: #185940;
    color: #fff;
    font: inherit;
    font-weight: 600;
    cursor: pointer;
}
.auth-form button:disabled { cursor: wait; opacity: 0.6; }
.error { color: #b42318; }
.required-marker { color: #b42318; }
.required-note { font-size: 0.875rem; }

.success, .info, .error { padding: 0.75rem; border-radius: 6px; line-height: 1.5; }
.success { background: #edf8f1; color: #185940; }
.info { background: #edf4fc; color: #234f7a; }
p.error { background: #fff0ed; }
</style>
