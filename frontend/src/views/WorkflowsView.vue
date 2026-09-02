<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useWorkflowStore } from '../stores/workflow'
import api from '../api'

const store = useWorkflowStore()
const router = useRouter()

const newName = ref('')
const showCreate = ref(false)
const busy = ref(false)
const fileInput = ref(null)

// Template Gallery
const showTemplates = ref(false)
const templates = ref([])
const templatesLoading = ref(false)
const installingSlug = ref('')

async function openTemplates() {
  showTemplates.value = true
  templatesLoading.value = true
  try {
    const res = await api.get('/templates')
    templates.value = res.data || []
  } catch (e) {
    alert(e.message)
  } finally {
    templatesLoading.value = false
  }
}

async function installTemplate(tpl) {
  installingSlug.value = tpl.slug
  try {
    const res = await api.post(`/templates/${tpl.slug}/install`)
    const wfId = res.data?.workflow_id
    showTemplates.value = false
    if (wfId) router.push({ name: 'workflow-editor', params: { id: wfId } })
    else await store.load()
  } catch (e) {
    alert(e.message)
  } finally {
    installingSlug.value = ''
  }
}

onMounted(() => store.load().catch(() => {}))

async function create() {
  if (!newName.value.trim()) return
  busy.value = true
  try {
    const wf = await store.create(newName.value.trim())
    newName.value = ''
    showCreate.value = false
    router.push({ name: 'workflow-editor', params: { id: wf.id } })
  } catch (e) {
    alert(e.message)
  } finally {
    busy.value = false
  }
}

async function exportWorkflow(wf) {
  try {
    const res = await api.get(`/workflows/${wf.id}/export`)
    const data = JSON.stringify(res.data, null, 2)
    const blob = new Blob([data], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${wf.name.replace(/[^\w\- ]+/g, '_').replace(/\s+/g, '_')}.json`
    a.click()
    URL.revokeObjectURL(url)
  } catch (e) {
    alert(e.message)
  }
}

function pickImportFile() {
  fileInput.value?.click()
}

async function onFileSelected(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return

  try {
    const text = await file.text()
    const json = JSON.parse(text)
    const workflow = json.workflow || json
    const res = await api.post('/workflows/import', { workflow })
    alert(`Workflow "${workflow.name || 'diimpor'}" berhasil diimpor.`)
    await store.load()
    router.push({ name: 'workflow-editor', params: { id: res.data.id } })
  } catch (e) {
    alert('Gagal impor: ' + e.message)
  }
}

function statusPill(w) {
  if (w.active) return 'active'
  if (w.status === 'paused') return 'paused'
  return 'inactive'
}

function openWorkflow(id) {
  router.push({ name: 'workflow-editor', params: { id } })
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title">Workflows</h1>
        <p class="page-sub">Automation yang berjalan di projek ini.</p>
      </div>
      <div class="header-actions">
        <button class="btn" @click="pickImportFile">Impor JSON</button>
        <button class="btn btn-primary" @click="showCreate = true">+ Workflow Baru</button>
        <button class="btn" style="margin-left:8px" @click="openTemplates">📋 Dari Template</button>
      </div>
      <input ref="fileInput" type="file" accept=".json,application/json" style="display: none" @change="onFileSelected" />
    </div>

    <div v-if="store.list.length" class="wf-grid">
      <div v-for="wf in store.list" :key="wf.id" class="card wf-card" @click="openWorkflow(wf.id)">
        <div class="wf-header">
          <div class="wf-icon">▦</div>
          <div class="wf-name-block">
            <div class="wf-name">{{ wf.name }}</div>
            <div class="wf-meta">{{ wf.node_count || 0 }} node · diupdate {{ wf.updated_at }}</div>
          </div>
        </div>
        <div class="wf-footer">
          <span class="status-pill" :class="statusPill(wf)">{{ wf.active ? 'Aktif' : wf.status === 'paused' ? 'Dijeda' : 'Nonaktif' }}</span>
          <div class="card-actions">
            <button class="btn btn-sm" title="Export JSON" @click.stop="exportWorkflow(wf)">⤓</button>
            <button class="btn btn-sm" @click.stop="router.push({ name: 'workflow-editor', params: { id: wf.id } })">Buka Editor</button>
          </div>
        </div>
      </div>
    </div>
    <div v-else class="empty-state">
      <div class="icon">▦</div>
      <div>Belum ada workflow.</div>
      <div class="empty-actions">
        <button class="btn" @click="pickImportFile">Impor dari JSON</button>
        <button class="btn btn-primary mt-8" @click="showCreate = true">Buat workflow pertama</button>
      </div>
    </div>

    <div v-if="showCreate" class="modal-overlay" @click.self="showCreate = false">

<!-- Template Gallery modal -->
<div v-if="showTemplates" class="modal-overlay" @click.self="showTemplates = false">
  <div class="modal" style="max-width: 720px; width: 92vw; max-height: 85vh; overflow: auto">
    <h3 style="margin:0 0 12px">📋 Template Siap Pakai</h3>
    <div v-if="templatesLoading">Memuat…</div>
    <div v-else-if="!templates.length" class="muted">Belum ada template.</div>
    <div v-else class="tpl-grid">
      <div v-for="t in templates" :key="t.slug" class="tpl-card card">
        <div class="flex-between">
          <strong>{{ t.name }}</strong>
          <span class="badge badge-blue">{{ t.category }}</span>
        </div>
        <p class="muted" style="font-size:12px; margin:6px 0 10px">{{ t.description }}</p>
        <button class="btn btn-sm btn-primary" :disabled="installingSlug === t.slug" @click="installTemplate(t)">
          {{ installingSlug === t.slug ? 'Menginstal…' : 'Install' }}
        </button>
      </div>
    </div>
    <button class="btn mt-16" @click="showTemplates = false">Tutup</button>
  </div>
</div>
      <div class="modal">
        <h3>Buat Workflow Baru</h3>
        <div class="field">
          <label>Nama Workflow</label>
          <input v-model="newName" placeholder="cth: Kirim email pelanggan baru" @keyup.enter="create" />
        </div>
        <div class="form-actions">
          <button class="btn" @click="showCreate = false">Batal</button>
          <button class="btn btn-primary" :disabled="busy" @click="create">{{ busy ? 'Membuat…' : 'Buat' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.tpl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.tpl-card { padding: 14px; }
.wf-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
.wf-card { padding: 16px; cursor: pointer; transition: border-color .12s, transform .12s; }
.wf-card:hover { border-color: var(--border-light); transform: translateY(-1px); }
.wf-header { display: flex; gap: 12px; align-items: center; margin-bottom: 14px; }
.wf-icon {
  width: 38px; height: 38px; border-radius: 8px; flex-shrink: 0;
  background: var(--panel-hover); display: flex; align-items: center; justify-content: center; font-size: 16px;
}
.wf-name { font-weight: 600; font-size: 14px; word-break: break-word; }
.wf-meta { color: var(--text-faint); font-size: 11px; }
.wf-footer { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.card-actions { display: flex; gap: 6px; }
.header-actions { display: flex; gap: 8px; }
.empty-actions { display: flex; gap: 8px; align-items: center; justify-content: center; }
</style>
