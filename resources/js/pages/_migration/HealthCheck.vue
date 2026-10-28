<script setup>
import AppShell from '../../layouts/AppShell.vue'
import { useAppStore } from '../../stores/app'

// Migration placeholder. Not routed by any Laravel controller.
defineOptions({ layout: AppShell })
const app = useAppStore()
</script>

<template>
  <div class="space-y-4">
    <p>Vue migration health check: OK</p>
    <UButton @click="app.toggleSidebar()">
      Sidebar: {{ app.sidebarOpen }}
    </UButton>
  </div>
</template>
