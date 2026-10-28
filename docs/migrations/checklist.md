# React → Vue Migration Checklist

## Inertia page components
- [ ] Convert `.jsx` page to `.vue` (`<script setup>`) under `resources/js/pages`
- [ ] Props via `defineProps`; layouts via `defineOptions({ layout })`
- [ ] Verify page name resolution matches the controller's `Inertia::render`

## router / useForm / usePage
- [ ] `@inertiajs/react` → `@inertiajs/vue3` imports
- [ ] `router.get/post` calls unchanged in signature; check callbacks
- [ ] `useForm` → Vue `useForm` (reactive; use `form.field` in `v-model`)
- [ ] `usePage()` → `usePage()` with `computed(() => page.props...)`
- [ ] Flash messages, shared props and errors still displayed

## Chakra replacement
- [x] UI strategy: Nuxt UI (standalone, no Nuxt), themed to BD colours
- [ ] Install Nuxt UI + Tailwind v4 and register the Vite plugin
- [ ] Define BD colour tokens (primary/neutral) from `resources/js/theme.js` palette; confirm exact brand hex values
- [ ] Map Chakra components/tokens to replacements (Box, Flex, Button, Modal, Toast, Form controls)
- [ ] Replace emotion/framer-motion usage; `lucide-react` → `lucide-vue-next`

## Jotai → Pinia / composables
- [ ] Inventory atoms in `resources/js/state`
- [ ] Shared state → Pinia store; local state → composable
- [ ] Replace `useAtom` usage with store/composable access

## Map components
- [ ] Wrap `maplibre-gl` in a Vue component/composable
- [ ] Port sources, layers, markers, popups, and event handlers
- [ ] Verify request transformer and performance

## Testing and regression
- [ ] `npm install`, `npm run build`, `npm run lint` pass
- [ ] PHP tests unaffected
- [ ] Manual check of migrated page: forms, validation errors, navigation, flash
- [ ] Visual comparison against React version
- [ ] Rollback verified (revert PR works)
