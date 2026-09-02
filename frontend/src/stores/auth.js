import { defineStore } from 'pinia'
import api from '../api'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    initialized: false,
    initPromise: null,
  }),
  getters: {
    workspaceId: (s) => s.user?.workspace_id ?? null,
  },
  actions: {
    async init() {
      if (this.initPromise) return this.initPromise
      this.initPromise = (async () => {
        try {
          const res = await api.get('/auth/me')
          this.user = res.data
        } catch (e) {
          this.user = null
        } finally {
          this.initialized = true
        }
      })()
      return this.initPromise
    },
    async login(email, password) {
      const slug = localStorage.getItem('login_slug') || ''
      const url = slug ? `/auth/login/${encodeURIComponent(slug)}` : '/auth/login'
      const res = await api.post(url, { email, password })
      this.user = res.data
      return res
    },
    async logout() {
      try {
        await api.post('/auth/logout')
      } catch (e) {}
      this.user = null
    },
    async switchWorkspace(workspaceId) {
      const res = await api.post('/auth/select-workspace', { workspace_id: workspaceId })
      this.user = res.data
    },
  },
})
