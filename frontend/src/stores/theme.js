import { defineStore } from 'pinia'

const STORAGE_KEY = 'kb_theme'

function preferred() {
  if (typeof window === 'undefined') return 'light'
  const stored = localStorage.getItem(STORAGE_KEY)
  if (stored === 'light' || stored === 'dark') return stored
  return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
    ? 'dark'
    : 'light'
}

export const useThemeStore = defineStore('theme', {
  state: () => ({
    theme: preferred(),
  }),

  getters: {
    isDark: (s) => s.theme === 'dark',
  },

  actions: {
    apply() {
      const root = document.documentElement
      if (this.theme === 'dark') root.setAttribute('data-theme', 'dark')
      else root.removeAttribute('data-theme')
      const meta = document.querySelector('meta[name="theme-color"]')
      if (meta) meta.setAttribute('content', this.theme === 'dark' ? '#151827' : '#eef1fb')
    },

    set(theme) {
      this.theme = theme === 'dark' ? 'dark' : 'light'
      localStorage.setItem(STORAGE_KEY, this.theme)
      this.apply()
    },

    toggle() {
      this.set(this.theme === 'dark' ? 'light' : 'dark')
    },

    init() {
      this.apply()
    },
  },
})
