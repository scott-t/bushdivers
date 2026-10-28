import vue from '@vitejs/plugin-vue'
import ui from '@nuxt/ui/vite'
import laravel from 'laravel-vite-plugin'
import { defineConfig } from 'vite'

// Migration experiment flag (non-default). `npm run dev:vue` / `build:vue` set VUE_ENTRY=1,
// which swaps the React input for the Vue scaffold entry. Default builds stay React-only.
const vueEntry = process.env.VUE_ENTRY === '1'

export default defineConfig({
  plugins: [
    laravel({
      input: vueEntry
        ? ['resources/css/vue.css', 'resources/js/app.vue-entry.js']
        : ['resources/css/app.css', 'resources/js/app.jsx'],
      refresh: true,
    }),
    // Only compiles .vue files; React remains the production entry.
    vue(),
    // Nuxt UI (standalone, no Nuxt) + Tailwind v4 - only loaded for the Vue experiment.
    ...(vueEntry
      ? [
          ui({
            inertia: true,
            colorMode: false,
            ui: { colors: { primary: 'bd', neutral: 'slate' } },
          }),
        ]
      : []),
  ],

  build: {
    sourcemap: true,
    rollupOptions: {
      onwarn: (warning, rollupWarn) => {
        // Ignore specific warnings
        if (
          warning.code === 'MODULE_LEVEL_DIRECTIVE' &&
          warning.message.includes('"use client"')
        )
          return
        rollupWarn(warning)
      },
    },
  },
})
