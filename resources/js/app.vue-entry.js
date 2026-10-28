// MIGRATION SCAFFOLD: not a production entry.
// The production entry remains resources/js/app.jsx (React + Inertia React).
// Run with `npm run dev:vue` / `npm run build:vue` (sets VUE_ENTRY=1 in vite.config.js).
import { createInertiaApp } from '@inertiajs/vue3'
import ui from '@nuxt/ui/vue-plugin'
import { createPinia } from 'pinia'
import { createApp, h } from 'vue'

createInertiaApp({
  // Vue pages live in ./pages/**/*.vue (React pages are .jsx and are not matched)
  resolve: (name) => {
    const pages = import.meta.glob('./pages/**/*.vue', { eager: true })
    const page = pages[`./pages/${name}.vue`]
    if (!page) throw new Error(`Vue page not found: ${name}`)
    return page
  },
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .use(createPinia())
      .use(ui)
      .mount(el)
  },
})
