<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '../api'

const credentials = ref([])
const types = ref([])
const loading = ref(true)
const showModal = ref(false)
const editing = ref(null)
const saving = ref(false)
const error = ref('')
const selectedType = ref(null)

const form = ref({ name: '', credential_type_id: '', data: {} })

const schemaFields = computed(() => {
  const t = types.value.find((x) => x.id === Number(form.value.credential_type_id))
  return t?.schema || []
})

async function load() {
  loading.value = true
  try {
    const [cres, tres] = await Promise.all([api.get('/credentials'), api.get('/credential-types')])
    credentials.value = cres.data || []
    types.value = tres.data || []
  } catch (e) {
  } finally {
    loading.value = false
  }
}
onMounted(load)

function openCreate() {
  editing.value = null
  form.value = { name: '', credential_type_id: types.value[0]?.id || '', data: {} }
  error.value = ''
  showModal.value = true
}

function openEdit(c) {
  editing.value = c
  form.value = { name: c.name, credential_type_id: c.credential_type_id, data: {} }
  error.value = ''
  showModal.value = true
}

function onTypeChange() {
  const data = {}
  for (const f of schemaFields.value) {
    if (f.default !== undefined) data[f.key] = f.default
  }
  form.value.data = data
}

function selectOptions(f) {
  const map = {}
  for (const o of f.options || []) {
    if (o && typeof o === 'object') map[o.value] = o.label
    else map[o] = o
  }
  return map
}

async function save() {
  if (!form.value.name.trim() || !form.value.credential_type_id) {
    error.value = 'Nama dan tipe wajib diisi.'
    return
  }
  saving.value = true
  error.value = ''
  try {
    if (editing.value) {
      await api.put(`/credentials/${editing.value.id}`, form.value)
    } else {
      await api.post('/credentials', form.value)
    }
    showModal.value = false
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

async function remove(c) {
  if (!confirm(`Hapus credential "${c.name}"?`)) return
  try {
    await api.delete(`/credentials/${c.id}`)
    await load()
  } catch (e) {
    alert(e.message)
  }
}

async function toggleDefault(c) {
  try {
    await api.put(`/credentials/${c.id}`, { is_default: !c.is_default })
    await load()
  } catch (e) {
    alert(e.message)
  }
}

function statusClass(s) {
  return s === 'active' ? 'badge-success' : 'badge-muted'
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title">Credentials</h1>
        <p class="page-sub">Simpan API key & kredensial, lalu pakai lewat dropdown Credential di node mana pun. Tandai satu sebagai <strong>Default</strong> agar node tanpa credential otomatis memakainya.</p>
      </div>
      <button class="btn btn-primary" @click="openCreate">+ Credential Baru</button>
    </div>

    <div v-if="loading" class="empty-state">Memuat…</div>

    <div v-else-if="credentials.length" class="card">
      <table class="table">
        <thead><tr><th>Nama</th><th>Tipe</th><th>Status</th><th>Default</th><th>Dibuat</th><th></th></tr></thead>
        <tbody>
          <tr v-for="c in credentials" :key="c.id">
            <td>
              {{ c.name }}
              <span v-if="c.is_default" class="badge badge-success">default</span>
            </td>
            <td><span class="badge badge-blue">{{ c.type_name }}</span></td>
            <td><span class="badge" :class="statusClass(c.status)">{{ c.status }}</span></td>
            <td>
              <button class="btn btn-sm" :class="c.is_default ? 'btn-primary' : ''" :disabled="!c.is_default && c.status !== 'active'" @click="toggleDefault(c)">
                {{ c.is_default ? '✓ Default' : 'Jadikan Default' }}
              </button>
            </td>
            <td class="muted">{{ (c.created_at || '').slice(0, 16).replace('T', ' ') }}</td>
            <td class="flex gap-8" style="justify-content: flex-end">
              <button class="btn btn-sm" @click="openEdit(c)">Edit</button>
              <button class="btn btn-sm btn-danger" @click="remove(c)">Hapus</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-else class="empty-state">
      <div class="icon">⚿</div>
      <div>Belum ada credential.</div>
      <button class="btn btn-primary mt-8" @click="openCreate">Tambah credential pertama</button>
    </div>

    <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
      <div class="modal">
        <h3>{{ editing ? 'Edit Credential' : 'Credential Baru' }}</h3>
        <div class="field">
          <label>Nama</label>
          <input v-model="form.name" placeholder="cth: API Key 9Router" />
        </div>
        <div class="field">
          <label>Tipe</label>
          <select v-model="form.credential_type_id" :disabled="!!editing" @change="onTypeChange">
            <option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option>
          </select>
        </div>
        <div v-for="f in schemaFields" :key="f.key" class="field">
          <label>{{ f.label || f.key }}</label>
          <select v-if="f.type === 'select'" v-model="form.data[f.key]">
            <option v-for="(label, val) in selectOptions(f)" :key="val" :value="val">{{ label }}</option>
          </select>
          <input
            v-else
            :type="f.type === 'password' ? 'password' : 'text'"
            :placeholder="f.placeholder || ''"
            v-model="form.data[f.key]"
            :autocomplete="f.type === 'password' ? 'new-password' : 'off'"
          />
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
