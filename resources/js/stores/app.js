import { defineStore } from 'pinia'

// Vue migration scaffold: example store replacing Jotai atoms (see docs/migrations/checklist.md)
export const useAppStore = defineStore('app', {
  state: () => ({ sidebarOpen: false }),
  actions: {
    toggleSidebar() {
      this.sidebarOpen = !this.sidebarOpen
    },
  },
})
