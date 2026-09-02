<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()
const stats = ref(null)
const recentExecutions = ref([])
const runtimes = ref(null)

onMounted(async () => {
  try {
    const res = await api.get('/dashboard/overview')
    stats.value = res.data
    if (res.recent_executions) recentExecutions.value = res.recent_executions.slice(0, 5)
  } catch (e) {}
  try {
    runtimes.value = (await api.get('/system/runtimes')).data
  } catch (e) {}
})

function rtBadge(rt) {
  if (!rt) return 'badge-muted'
  return rt.found ? 'badge-success' : 'badge-error'
}

function statusLabel(s) {
  const map = { success: 'badge-success', error: 'badge-error', running: 'badge-warn' }
  return map[s] || 'badge-muted'
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title">Selamat datang, {{ auth.user?.name }} 👋</h1>
        <p class="page-sub">Kelola workflow, jadwal, dan integrasi dari satu tempat.</p>
      </div>
    </div>

    <div class="stat-grid">
      <div class="card stat-card">
        <div class="stat-value">{{ stats?.workflows ?? '–' }}</div>
        <div class="stat-label">Total Workflow</div>
      </div>
      <div class="card stat-card">
        <div class="stat-value">{{ stats?.active_workflows ?? '–' }}</div>
        <div class="stat-label">Aktif</div>
      </div>
      <div class="card stat-card">
        <div class="stat-value">{{ stats?.executions ?? '–' }}</div>
        <div class="stat-label">Total Eksekusi</div>
      </div>
      <div class="card stat-card">
        <div class="stat-value">{{ stats?.success_rate ?? '–' }}%</div>
        <div class="stat-label">Tingkat Sukses</div>
      </div>
      <div class="card stat-card">
        <div class="stat-value">{{ stats?.schedules ?? '–' }}</div>
        <div class="stat-label">Jadwal Aktif</div>
      </div>
      <div class="card stat-card" :title="'Total ' + (stats?.time_saved_minutes ?? 0) + ' menit dari ' + (stats?.time_saved_runs ?? 0) + ' eksekusi'">
        <div class="stat-value">⏱ {{ stats?.time_saved_minutes != null ? Math.round(stats.time_saved_minutes / 60 * 10) / 10 + 'j' : '–' }}</div>
        <div class="stat-label">Waktu Kerja Dihemat</div>
      </div>
    </div>

    <div class="card mt-16">
      <div class="flex-between mb-16">
        <h3 style="margin: 0">🖥️ Status Hosting</h3>
        <router-link :to="{ name: 'settings' }" class="btn btn-sm">Pengaturan →</router-link>
      </div>
      <div v-if="runtimes" class="host-grid">
        <div v-for="(rt, key) in runtimes.runtimes" :key="key" v-show="key !== 'extensions'" class="host-item">
          <div class="host-head">
            <strong>{{ rt.name }}</strong>
            <span class="badge" :class="rtBadge(rt)">{{ rt.found ? 'Aktif' : 'Mati' }}</span>
          </div>
          <div class="host-ver">{{ rt.version || 'tidak tersedia' }}</div>
        </div>
      </div>
      <div v-else class="muted">Memuat status hosting…</div>
    </div>

    <div class="card mt-16">
      <div class="flex-between mb-16">
        <h3 style="margin: 0">Eksekusi Terbaru</h3>
        <router-link :to="{ name: 'executions' }" class="btn btn-sm">Lihat semua →</router-link>
      </div>
      <table v-if="recentExecutions.length" class="table">
        <thead>
          <tr><th>ID</th><th>Workflow</th><th>Trigger</th><th>Status</th><th>Waktu</th></tr>
        </thead>
        <tbody>
          <tr v-for="ex in recentExecutions" :key="ex.id" style="cursor: pointer" @click="router.push({ name: 'execution-detail', params: { id: ex.id } })">
            <td class="code">#{{ ex.id }}</td>
            <td>{{ ex.workflow_name }}</td>
            <td><span class="badge badge-muted">{{ ex.trigger_type }}</span></td>
            <td><span class="badge" :class="statusLabel(ex.status)">{{ ex.status }}</span></td>
            <td class="muted">{{ ex.started_at }}</td>
          </tr>
        </tbody>
      </table>
      <div v-else class="empty-state">
        <div class="icon">⏱</div>
        <div>Belum ada eksekusi. Coba jalankan workflow dari editor.</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.stat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
.stat-card { padding: 18px; }
.stat-value { font-size: 28px; font-weight: 700; }
.stat-label { color: var(--text-dim); font-size: 12px; margin-top: 2px; }
.host-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }
.host-item { border: 1px solid var(--border); border-radius: 8px; padding: 12px 14px; background: var(--bg); }
.host-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.host-ver { color: var(--text-dim); font-size: 12px; margin-top: 6px; font-family: ui-monospace, Consolas, monospace; }
</style>
