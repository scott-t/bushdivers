// MIGRATION SCAFFOLD (Phase 0): not wired as a production entry.
// The production entry remains resources/js/app.jsx (React + Inertia React).
// To experiment, temporarily add this file to the `input` list in vite.config.js.
import { createInertiaApp } from '@inertiajs/vue3'
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
      .mount(el)
  },
})
