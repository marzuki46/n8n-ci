import { defineStore } from 'pinia'

function detectLang() {
  const saved = localStorage.getItem('ui_lang')
  if (saved === 'id' || saved === 'en') return saved
  return 'id'
}

export const useUiStore = defineStore('ui', {
  state: () => ({
    theme: localStorage.getItem('ui_theme') || 'dark',
    lang: detectLang(),
  }),
  actions: {
    applyTheme() {
      document.documentElement.setAttribute('data-theme', this.theme)
    },
    setTheme(t) {
      this.theme = t === 'light' ? 'light' : 'dark'
      localStorage.setItem('ui_theme', this.theme)
      this.applyTheme()
    },
    toggleTheme() {
      this.setTheme(this.theme === 'dark' ? 'light' : 'dark')
    },
    setLang(l) {
      this.lang = l === 'en' ? 'en' : 'id'
      localStorage.setItem('ui_lang', this.lang)
    },
    toggleLang() {
      this.setLang(this.lang === 'id' ? 'en' : 'id')
    },
  },
})
