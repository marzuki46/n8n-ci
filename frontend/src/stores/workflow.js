import { defineStore } from 'pinia'
import api from '../api'

export const useWorkflowStore = defineStore('workflow', {
  state: () => ({
    list: [],
    loaded: false,
  }),
  actions: {
    async load() {
      const res = await api.get('/workflows')
      this.list = res.data || []
      this.loaded = true
    },
    async create(name) {
      const res = await api.post('/workflows', { name })
      this.list.unshift(res.data)
      return res.data
    },
    async toggleActive(id, active) {
      const res = await api.put(`/workflows/${id}`, { active })
      const item = this.list.find((w) => w.id === id)
      if (item) item.active = active
      return res.data
    },
    async remove(id) {
      await api.delete(`/workflows/${id}`)
      this.list = this.list.filter((w) => w.id !== id)
    },
    async duplicate(id) {
      const res = await api.post(`/workflows/${id}/duplicate`)
      this.list.unshift(res.data)
      return res.data
    },
  },
})
