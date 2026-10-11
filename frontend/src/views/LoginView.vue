
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

            <label for="email">Email</label>
            <input
                id="email"
                v-model.trim="email"
                type="email"
                autocomplete="email"
                required
            />

            <label for="password">Password</label>
            <input
                id="password"
                v-model="password"
                type="password"
                autocomplete="current-password"
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
}

.auth-form {
    display: grid;
    gap: 0.75rem;
    width: min(100%, 400px);
}

.auth-form input {
    padding: 0.75rem;
    border: 1px solid #aaa;
    border-radius: 6px;
}

.auth-form button {
    padding: 0.75rem;
    cursor: pointer;
}

.auth-form button:disabled {
    cursor: wait;
    opacity: 0.6;
}

.error {
    color: #b42318;
}

.success {
  color: #067647;
}

.info {
  color: #175cd3;
}
</style>
