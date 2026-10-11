
import { ref } from 'vue'
import { defineStore } from 'pinia'

// Limit messages to known types so components can style them consistently.
export type FlashType = 'success' | 'error' | 'info'

export interface FlashMessage {
    type: FlashType
    text: string
}

export const useFlashStore = defineStore('flash', () => {
    // Keep the message in memory only.
    // It is not saved to sessionStorage or included in the URL.
    const message = ref<FlashMessage | null>(null)

    function show(type: FlashType, text: string): void {
        // Replace any previous message with the latest notification.
        message.value = { type, text }
    }

    function consume(): FlashMessage | null {
        // Return the current message and immediately clear it.
        // This makes the notification available to the next page only once.
        const current = message.value
        message.value = null

        return current
    }

    return {
        message,
        show,
        consume,
    }
})
