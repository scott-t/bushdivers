# React → Vue Migration Plan

Laravel backend and Inertia protocol stay unchanged; only the frontend adapter, components, state and map layers move.

## 1. Current-state inventory
- React 18 + `react-dom`, `react-error-boundary`; bootstrap in `resources/js/app.jsx`
- `@inertiajs/react` (`router`, `usePage`, `useForm`) used widely in pages
- Chakra UI (`@chakra-ui/react`, emotion, framer-motion), `lucide-react` icons
- Jotai atoms in `resources/js/state`
- Maps: `react-map-gl` + `maplibre-gl`
- Tooling: Vite 6 + `laravel-vite-plugin`, ESLint (`eslint-plugin-react`), Prettier, husky/lint-staged

## 2. Target stack
- Vue 3 (`<script setup>`), `@inertiajs/vue3`, `@vitejs/plugin-vue`
- State: Pinia for shared state, composables for local/feature state (replaces Jotai)
- UI options: (a) Tailwind + headless components (Headless UI / Radix Vue), (b) PrimeVue/Vuetify, (c) in-house wrappers mimicking Chakra tokens
- Map options: `maplibre-gl` directly via a composable/wrapper component (preferred), or `vue-maplibre-gl`
- **UI decision:** Nuxt UI used standalone (without Nuxt) via its Vite/Vue plugin (`@nuxt/ui/vite`) with Tailwind CSS v4, themed to Bush Divers colours. Chakra's current palette (orange primary, gray neutrals; see `resources/js/theme.js`) is the starting point for the BD theme, defined as CSS theme tokens and `app.config` colour aliases (`primary`, `neutral`).
- Lint: `eslint-plugin-vue`

## 3. Phased rollout
- **Phase 0 (this PR):** docs, checklist, Vue deps, Vite Vue plugin, non-production Vue entry and placeholder page.
- **Phase 1 – Foundation:** dual-entry strategy, Vue layout/app shell, error handling, shared helpers, Pinia, eslint-plugin-vue, install/configure Nuxt UI without Nuxt and define the BD theme tokens (UI library decision made).
- **Phase 2+ – Feature slices:** auth/public pages → account/dashboard → admin → map-heavy pages. Each slice ships independently.
- **Final – Decommission:** remove React, Chakra, Jotai, react-map-gl, React lint rules; switch `app.jsx` references to the Vue entry; update docs.

## 4. Risks and mitigations
| Risk | Mitigation |
| --- | --- |
| UI parity loss from Chakra removal | Decide UI strategy in Phase 1; build shared primitives first; visual checks per slice |
| State conversion bugs | Convert one atom group at a time; unit-test stores |
| Map rewrite | Dedicated phase; wrapper component with the same props contract |
| Dual-runtime bundle size / conflicts | Separate entries; only one framework per page |
| Feature work conflicts | Slice-based PRs, freeze touched pages while migrating |

## 5. Definition of done
- **Phase 0:** docs merged; `npm install` and `npm run build` pass; React remains the only production entry.
- **Phase 1:** Vue shell renders a test page under a flag/route in non-prod; lint and CI cover Vue; UI and state decisions recorded.
- **Phase 2+ (per slice):** pages work in Vue with behavior parity, forms/validation verified, no React imports left for that slice, QA signoff.
- **Final:** no React/Chakra/Jotai deps, build/lint/tests green, production on Vue entry.

## 6. Rollback strategy
- Every phase is a separate PR; revert the PR to roll back.
- Until the final phase, React entry stays intact, so switching back is a Vite input / Blade `@vite` change.
- Backend is untouched, so no data or API rollback is required.
- Final decommission happens only after a stable period on Vue; keep a tag of the last React release.
