<script setup>
import { ref, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'
import api from '../api'

const auth = useAuthStore()
const projects = ref([])
const loading = ref(true)
const showModal = ref(false)
const editing = ref(null)
const form = ref({ name: '', description: '' })
const saving = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  try {
    const res = await api.get('/projects')
    projects.value = res.data || []
  } catch (e) {
  } finally {
    loading.value = false
  }
}
onMounted(load)

function openCreate() {
  editing.value = null
  form.value = { name: '', description: '' }
  error.value = ''
  showModal.value = true
}

function openEdit(p) {
  editing.value = p
  form.value = { name: p.name, description: p.description || '' }
  error.value = ''
  showModal.value = true
}

async function save() {
  if (!form.value.name.trim()) {
    error.value = 'Nama projek wajib diisi.'
    return
  }
  saving.value = true
  error.value = ''
  try {
    if (editing.value) {
      await api.put(`/projects/${editing.value.id}`, form.value)
    } else {
      await api.post('/projects', form.value)
    }
    showModal.value = false
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

async function remove(p) {
  if (!confirm(`Hapus projek "${p.name}"? Semua workflow di dalamnya ikut terhapus.`)) return
  try {
    await api.delete(`/projects/${p.id}`)
    if (auth.workspaceId === p.id) {
      auth.user.workspace_id = null
      await auth.switchWorkspace(projects.value[0]?.id).catch(() => {})
      location.reload()
    }
    await load()
  } catch (e) {
    alert(e.message)
  }
}

async function select(p) {
  await auth.switchWorkspace(p.id)
  location.reload()
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title">Projects</h1>
        <p class="page-sub">Workflow dikelompokkan ke dalam projek (workspace).</p>
      </div>
      <button class="btn btn-primary" @click="openCreate">+ Projek Baru</button>
    </div>

    <div v-if="loading" class="empty-state">Memuat…</div>

    <div v-else-if="projects.length" class="proj-grid">
      <div v-for="p in projects" :key="p.id" class="card proj-card" :class="{ current: auth.workspaceId === p.id }">
        <div class="proj-head">
          <div class="proj-icon">▣</div>
          <div class="proj-info">
            <div class="proj-name">{{ p.name }}</div>
            <div class="muted">{{ p.workflow_count ?? 0 }} workflow · dibuat {{ (p.created_at || '').slice(0, 10) }}</div>
          </div>
        </div>
        <p class="proj-desc">{{ p.description || 'Tidak ada deskripsi.' }}</p>
        <div class="proj-actions">
          <button class="btn btn-sm" :class="{ 'btn-primary': auth.workspaceId === p.id }" @click="select(p)">
            {{ auth.workspaceId === p.id ? 'Aktif' : 'Pilih' }}
          </button>
          <button class="btn btn-sm" @click="openEdit(p)">Edit</button>
          <button class="btn btn-sm btn-danger" @click="remove(p)">Hapus</button>
        </div>
      </div>
    </div>
    <div v-else class="empty-state">
      <div class="icon">▣</div>
      <div>Belum ada projek.</div>
      <button class="btn btn-primary mt-8" @click="openCreate">Buat projek pertama</button>
    </div>

    <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
      <div class="modal">
        <h3>{{ editing ? 'Edit Projek' : 'Projek Baru' }}</h3>
        <div class="field">
          <label>Nama</label>
          <input v-model="form.name" placeholder="cth: Marketing, Operations" @keyup.enter="save" />
        </div>
        <div class="field">
          <label>Deskripsi</label>
          <textarea v-model="form.description" rows="3"></textarea>
        </div>
        <p v-if="error" style="color: var(--red)">{{ error }}</p>
        <div class="form-actions">
          <button class="btn" @click="showModal = false">Batal</button>
          <button class="btn btn-primary" :disabled="saving" @click="save">{{ saving ? 'Menyimpan…' : 'Simpan' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.proj-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
.proj-card { padding: 16px; border-color: var(--border); }
.proj-card.current { border-color: var(--accent); }
.proj-head { display: flex; gap: 12px; align-items: center; margin-bottom: 10px; }
.proj-icon {
  width: 36px; height: 36px; border-radius: 8px; flex-shrink: 0;
  background: var(--panel-hover); display: flex; align-items: center; justify-content: center; font-size: 15px;
}
.proj-name { font-weight: 600; font-size: 14px; }
.proj-desc { color: var(--text-dim); font-size: 12px; min-height: 32px; }
.proj-actions { display: flex; gap: 8px; }
</style>
