<script setup lang="ts">
defineProps<{ name: string }>()
const paths: Record<string, string[]> = {
  'check-circle': ['M22 11.1V12a10 10 0 1 1-5.9-9.1', 'm22 4-10 10-3-3'],
  warning: ['m12 3 10 18H2Z', 'M12 9v4m0 4h.01'],
  members: ['M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8', 'M4 21v-2a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v2'],
  home: ['m3 10 9-7 9 7', 'M5 9v11h5v-6h4v6h5V9'],
  application: ['M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z', 'M14 2v6h6', 'M8 13h8M8 17h5'],
  invoice: ['M5 3h14v18l-3-2-4 2-4-2-3 2Z', 'M8 7h8M8 11h8M8 15h4'],
  review: ['M9 5H5v16h14V5h-4', 'M9 3h6v4H9Z', 'm8 14 3 3 5-6'],
  payment: ['M3 5h18v14H3Z', 'M3 9h18M7 15h4'],
  logout: ['M9 3H4v18h5', 'M9 12h12m-5-5 5 5-5 5'],
  back: ['M19 12H5m6-6-6 6 6 6'],
  refresh: ['M20 7v5h-5', 'M4 17v-5h5', 'M6 7a7 7 0 0 1 12-1l2 6M4 12l2 6a7 7 0 0 0 12-1'],
  download: ['M12 3v12m-5-5 5 5 5-5', 'M5 16v5h14v-5'],
  link: ['M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2', 'M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2'],
}
</script>

<template>
  <svg class="app-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
    stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <path v-for="(path, index) in paths[name] ?? []" :key="index" :d="path" />
  </svg>
</template>

<style scoped>
.app-icon { display: inline-block; width: 1.15em; height: 1.15em; flex-shrink: 0; vertical-align: -0.2em; }
</style>
