import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueDevTools(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    proxy: {
      // Forward API requests from Vite (5173) to Laravel's Nginx service.
      // "web" is the Docker Compose service name, accessible inside the network.
      '/api': {
        target: 'http://web',
        changeOrigin: true,
      },
    },
  },
})
