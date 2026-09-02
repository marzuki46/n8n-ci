<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'
import { useProjectsStore } from '../stores/projects'

const projects = useProjectsStore()

const keys = ref([])
const loading = ref(true)
const label = ref('')
const expiresAt = ref('')
const workspaceId = ref('')
const creating = ref(false)
const error = ref('')
const created = ref(null)

async function load() {
  loading.value = true
  try {
    const data = await api.get('/api-keys')
    keys.value = data?.data || []
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function create() {
  creating.value = true
  error.value = ''
  try {
    const data = await api.post('/api-keys', {
      label: label.value,
      expires_at: expiresAt.value || null,
      workspace_id: workspaceId.value ? Number(workspaceId.value) : null,
    })
    created.value = data?.data || null
    label.value = ''
    expiresAt.value = ''
    workspaceId.value = ''
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    creating.value = false
  }
}

async function revoke(key) {
  if (!confirm(`Cabut API key "${key.label}"? Request lama dengan key ini akan ditolak.`)) return
  try {
    await api.post(`/api-keys/${key.id}/revoke`)
    await load()
  } catch (e) {
    error.value = e.message
  }
}

async function remove(key) {
  if (!confirm(`Hapus API key "${key.label}"?`)) return
  try {
    await api.delete(`/api-keys/${key.id}`)
    await load()
  } catch (e) {
    error.value = e.message
  }
}

async function copy(value) {
  try {
    await navigator.clipboard.writeText(value)
  } catch {
    const ta = document.createElement('textarea')
    ta.value = value
    document.body.appendChild(ta)
    ta.select()
    document.execCommand('copy')
    document.body.removeChild(ta)
  }
}

onMounted(() => {
  load()
  if (!projects.loaded) projects.load().catch(() => {})
})
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title">API Keys</h1>
        <p class="page-sub">
          API key untuk mengakses Public API <span class="code">/api/v1</span> secara programatik.
          Kirim lewat header <span class="code">X-API-Key</span> atau <span class="code">Authorization: Bearer</span>.
        </p>
      </div>
    </div>

    <div v-if="error" class="alert-error">{{ error }}</div>

    <div class="create-card card">
      <h3 style="margin: 0 0 12px">Buat API Key</h3>
      <div class="create-row">
        <div class="field" style="flex: 2; margin: 0">
          <label>Label</label>
          <input v-model="label" placeholder="cth: Server Produksi, CI/CD" maxlength="191" />
        </div>
        <div class="field" style="flex: 1; margin: 0">
          <label>Kedaluwarsa (opsional)</label>
          <input v-model="expiresAt" type="date" />
        </div>
        <div class="field" style="flex: 1; margin: 0">
          <label>Ikat ke proyek (opsional)</label>
          <select v-model="workspaceId">
            <option value="">Semua proyek</option>
            <option v-for="p in projects.list" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <button class="btn btn-primary" :disabled="creating" @click="create">
          <span v-if="creating" class="spinner"></span>
          <span v-else>+ Buat Key</span>
        </button>
      </div>

      <div v-if="created" class="created-box">
        <div class="flex-between">
          <div>
            <div class="created-title">API key dibuat — salin sekarang, tidak akan ditampilkan lagi.</div>
            <code class="created-key">{{ created.api_key }}</code>
          </div>
          <button class="btn btn-sm" @click="copy(created.api_key)">Salin</button>
        </div>
      </div>
    </div>

    <div class="card mt-16">
      <div class="flex-between">
        <h3 style="margin: 0">Daftar API Key</h3>
        <span class="muted" style="font-size: 12px">{{ keys.length }} key</span>
      </div>

      <div v-if="loading" class="empty-state" style="border: none; padding: 24px">
        <div class="spinner"></div>
      </div>

      <div v-else-if="keys.length === 0" class="empty-state">
        <div class="icon">⚿</div>
        <div>Belum ada API key. Buat key pertama di atas.</div>
      </div>

      <table v-else class="table">
        <thead>
          <tr>
            <th>Label</th>
            <th>Proyek</th>
            <th>Key</th>
            <th>Status</th>
            <th>Terakhir dipakai</th>
            <th>Kedaluwarsa</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="key in keys" :key="key.id">
            <td>{{ key.label }}</td>
            <td>
              <span v-if="key.workspace_name" class="badge badge-blue">{{ key.workspace_name }}</span>
              <span v-else class="muted">—</span>
            </td>
            <td><span class="code">{{ key.key_prefix }}</span></td>
            <td>
              <span class="badge" :class="key.status === 'active' ? 'badge-success' : 'badge-muted'">
                {{ key.status }}
              </span>
            </td>
            <td class="muted">{{ key.last_used_at || '—' }}</td>
            <td class="muted">{{ key.expires_at || '—' }}</td>
            <td class="actions">
              <button
                v-if="key.status === 'active'"
                class="btn btn-ghost btn-sm btn-danger"
                title="Cabut key"
                @click="revoke(key)"
              >Cabut</button>
              <button class="btn btn-ghost btn-sm btn-danger" title="Hapus key" @click="remove(key)">Hapus</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="card mt-16">
      <h3 style="margin: 0 0 8px">Referensi Public API</h3>
      <p class="muted">Semua endpoint memakai <span class="code">X-API-Key: &lt;key&gt;</span>. Respons memakai format JSON yang sama dengan API web.</p>
      <div class="ref">
        <div class="ref-row"><span class="code ref-method">GET</span><span class="code">/api/v1/workflows</span><span class="muted">Daftar workflow di projek aktif</span></div>
        <div class="ref-row"><span class="code ref-method">GET</span><span class="code">/api/v1/executions</span><span class="muted">Riwayat eksekusi (?workflow_id=&amp;status=&amp;limit=&amp;offset=)</span></div>
        <div class="ref-row"><span class="code ref-method">GET</span><span class="code">/api/v1/executions/:id</span><span class="muted">Detail eksekusi + output per node</span></div>
        <div class="ref-row"><span class="code ref-method">POST</span><span class="code">/api/v1/executions</span><span class="muted">Jalankan workflow. Body: <code>{"workflow_id": 1, "data": {...}}</code></span></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.alert-error {
  background: rgba(239, 107, 107, .12); border: 1px solid var(--red); color: var(--red);
  padding: 10px 14px; border-radius: 6px; margin-bottom: 14px; font-size: 13px;
}
.create-card { padding: 16px; }
.create-row { display: flex; gap: 12px; align-items: flex-end; }
.create-row .btn { padding: 8px 16px; }
@media (max-width: 760px) { .create-row { flex-direction: column; align-items: stretch; } }
.created-box {
  margin-top: 14px; padding: 12px; border: 1px dashed var(--green); border-radius: 6px;
  background: rgba(37, 194, 144, .07);
}
.created-title { font-size: 13px; margin-bottom: 8px; color: var(--green); font-weight: 500; }
.created-key {
  font-family: 'JetBrains Mono', Consolas, monospace; font-size: 13px; word-break: break-all;
}
.table { width: 100%; border-collapse: collapse; margin-top: 12px; }
.table th, .table td { text-align: left; padding: 8px 10px; font-size: 13px; border-bottom: 1px solid var(--border); }
.table th { color: var(--text-dim); font-weight: 500; font-size: 12px; }
.actions { text-align: right; white-space: nowrap; }
.ref { display: flex; flex-direction: column; gap: 6px; margin-top: 10px; }
.ref-row { display: flex; gap: 10px; align-items: baseline; font-size: 13px; }
.ref-method { color: var(--blue); font-weight: 600; }
</style>
