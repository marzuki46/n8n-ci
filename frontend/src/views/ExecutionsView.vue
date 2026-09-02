<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

const router = useRouter()
const executions = ref([])
const loading = ref(true)

onMounted(async () => {
  try {
    const res = await api.get('/executions')
    executions.value = res.data || []
  } catch (e) {
  } finally {
    loading.value = false
  }
})

function statusClass(s) {
  const map = { success: 'badge-success', error: 'badge-error', running: 'badge-warn' }
  return map[s] || 'badge-muted'
}

function statusDot(s) {
  const map = { success: 'dot-success', error: 'dot-error', running: 'dot-warn' }
  return map[s] || ''
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title">Executions</h1>
        <p class="page-sub">Riwayat eksekusi workflow di projek ini.</p>
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="empty-state">Memuat…</div>
      <table v-else-if="executions.length" class="table">
        <thead>
          <tr><th>ID</th><th>Workflow</th><th>Trigger</th><th>Status</th><th>Durasi</th><th>Mulai</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="ex in executions" :key="ex.id" style="cursor: pointer" @click="router.push({ name: 'execution-detail', params: { id: ex.id } })">
            <td class="code">#{{ ex.id }}</td>
            <td>{{ ex.workflow_name }}</td>
            <td><span class="badge badge-muted">{{ ex.trigger_type }}</span></td>
            <td>
              <span class="status-pill" :class="ex.status === 'success' ? 'active' : ex.status === 'error' ? 'inactive' : ''">
                <span class="dot" :class="statusDot(ex.status)"></span>{{ ex.status }}
              </span>
            </td>
            <td class="muted">{{ ex.duration ?? '–' }} ms</td>
            <td class="muted">{{ ex.started_at }}</td>
            <td><span class="muted">→</span></td>
          </tr>
        </tbody>
      </table>
      <div v-else class="empty-state">
        <div class="icon">⏱</div>
        <div>Belum ada eksekusi.</div>
      </div>
    </div>
  </div>
</template>
