<script setup lang="ts">
// RouterView renders whichever page matches the current URL.
import { RouterView } from 'vue-router'
import AppNavigation from '@/components/AppNavigation.vue'
</script>

<template>
  <AppNavigation />
  <RouterView />
</template>

<style>
/* Shared return navigation across member and committee detail pages. */
main a.back-link {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  box-sizing: border-box;
  min-height: 44px;
  margin-bottom: 1.25rem;
  padding: 0.6rem 0.9rem;
  border: 1px solid #d8e3de;
  border-radius: 8px;
  background: #f5f8f7;
  color: #185940;
  font-size: 0.9rem;
  font-weight: 600;
  line-height: 1.4;
  text-decoration: none;
  transition: background-color 150ms ease, border-color 150ms ease;
}
main a.back-link:hover {
  background: #e6f1eb;
  border-color: #8caf9c;
}
main a.back-link:active { background: #d8eade; }
main a.back-link:focus-visible {
  outline: 3px solid #a9d8c5;
  outline-offset: 3px;
}
@media (prefers-reduced-motion: reduce) {
  main a.back-link { transition: none; }
}
</style>
