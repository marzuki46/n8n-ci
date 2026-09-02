import { defineStore } from 'pinia'
import api from '../api'

export const useProjectsStore = defineStore('projects', {
  state: () => ({
    list: [],
    loaded: false,
  }),
  actions: {
    async load() {
      const res = await api.get('/projects')
      this.list = res.data || []
      this.loaded = true
    },
  },
})
