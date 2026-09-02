import { defineStore } from 'pinia'
import api from '../api'

export const useNodesStore = defineStore('nodes', {
  state: () => ({
    registry: [],
    loaded: false,
  }),
  getters: {
    byType: (s) => Object.fromEntries(s.registry.map((n) => [n.type, n])),
    categories: (s) => {
      const map = {}
      for (const n of s.registry) {
        if (!map[n.category]) map[n.category] = []
        map[n.category].push(n)
      }
      return map
    },
  },
  actions: {
    async load() {
      if (this.loaded) return
      const res = await api.get('/nodes')
      this.registry = res.data || []
      this.loaded = true
    },
  },
})
