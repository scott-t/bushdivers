import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/app.jsx'],
      refresh: true,
    }),
    // Phase 0 migration scaffold: only compiles .vue files; React remains the production entry.
    vue(),
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
