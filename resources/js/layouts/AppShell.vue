<script setup>
import { onErrorCaptured, ref } from 'vue'

// Migration scaffold layout: Nuxt UI app wrapper + basic error boundary
// (replaces react-error-boundary).
const error = ref(null)
onErrorCaptured((err) => {
  error.value = err
  return false
})
</script>

<template>
  <UApp>
    <UContainer class="py-6">
      <UAlert
        v-if="error"
        color="error"
        title="Something went wrong"
        :description="error.message"
      />
      <slot v-else />
    </UContainer>
  </UApp>
</template>
