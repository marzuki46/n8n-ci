<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../api'
import { useUiStore } from '../stores/ui'
import { t } from '../i18n'

const ui = useUiStore()
const en = computed(() => ui.lang === 'en')
const list = ref([])
const loading = ref(true)
const expanded = ref(null)
const filter = ref('all') // all | unread | read

const filtered = computed(() => {
  if (filter.value === 'unread') return list.value.filter((i) => i.status === 'new')
  if (filter.value === 'read') return list.value.filter((i) => i.status === 'read')
  return list.value
})

const unreadCount = computed(() => list.value.filter((i) => i.status === 'new').length)

async function load() {
  loading.value = true
  try {
    const res = await api.get('/inquiries')
    list.value = res.data || []
  } catch (e) {
    list.value = []
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function mark(i, status) {
  try {
    await api.post(`/inquiries/${i.id}/mark`, { status })
    i.status = status
  } catch (e) { alert(e.message) }
}

async function remove(i) {
  const msg = en ? 'Delete this message?' : 'Hapus pesan ini?'
  if (!confirm(msg)) return
  try {
    await api.delete(`/inquiries/${i.id}`)
    list.value = list.value.filter((x) => x.id !== i.id)
  } catch (e) { alert(e.message) }
}

function fmtDate(d) {
  return (d || '').slice(0, 16).replace('T', ' ')
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title">📥 {{ en ? 'Inbox' : 'Kotak Masuk' }}</h1>
        <p class="page-sub">{{ en ? 'Inquiries from your public landing page.' : 'Pesan inquiry dari landing page publik.' }}</p>
      </div>
      <div class="filter-row">
        <button class="btn btn-sm" :class="{ 'btn-primary': filter === 'all' }" @click="filter = 'all'">
          {{ en ? 'All' : 'Semua' }} ({{ list.length }})
        </button>
        <button class="btn btn-sm" :class="{ 'btn-primary': filter === 'unread' }" @click="filter = 'unread'">
          {{ en ? 'Unread' : 'Belum dibaca' }} ({{ unreadCount }})
        </button>
        <button class="btn btn-sm" :class="{ 'btn-primary': filter === 'read' }" @click="filter = 'read'">
          {{ en ? 'Read' : 'Sudah dibaca' }} ({{ list.length - unreadCount }})
        </button>
      </div>
    </div>

    <div v-if="loading" class="muted">{{ t('common.loading') }}</div>

    <div v-else-if="!filtered.length" class="empty-state">
      <div class="icon">📭</div>
      <div>{{ en ? 'No messages here yet.' : 'Belum ada pesan di sini.' }}</div>
    </div>

    <div v-else class="inbox-list">
      <div
        v-for="i in filtered"
        :key="i.id"
        class="inbox-item"
        :class="{ unread: i.status === 'new', open: expanded === i.id }"
      >
        <button class="inbox-head" @click="expanded = expanded === i.id ? null : i.id; if (i.status === 'new') mark(i, 'read')">
          <span v-if="i.status === 'new'" class="unread-dot"></span>
          <strong class="sender">{{ i.name }}</strong>
          <span class="email">{{ i.email }}</span>
          <span class="date">{{ fmtDate(i.created_at) }}</span>
        </button>

        <div v-if="expanded === i.id" class="inbox-body">
          <p class="message">{{ i.message }}</p>
          <div class="actions">
            <a v-if="i.email" class="btn btn-sm btn-primary" :href="'mailto:' + i.email + '?subject=Re: your inquiry'">
              ✉️ {{ en ? 'Reply by email' : 'Balas via email' }}
            </a>
            <a v-if="i.phone" class="btn btn-sm" :href="'https://wa.me/' + String(i.phone).replace(/[^0-9]/g, '')" target="_blank" rel="noopener">
              💬 WhatsApp
            </a>
            <button class="btn btn-sm" @click="mark(i, i.status === 'new' ? 'read' : 'new')">
              {{ i.status === 'new' ? (en ? '✓ Mark as read' : '✓ Tandai sudah dibaca') : (en ? '↺ Mark as unread' : '↺ Tandai belum dibaca') }}
            </button>
            <button class="btn btn-sm btn-danger" style="margin-left:auto" @click="remove(i)">
              🗑 {{ t('common.delete') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.filter-row { display: flex; gap: 6px; }
.inbox-list { display: flex; flex-direction: column; gap: 8px; }
.inbox-item { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.inbox-item.unread { border-color: rgba(255, 109, 90, .45); }
.inbox-item.open { border-color: var(--accent); }
.inbox-head {
  width: 100%; display: flex; align-items: center; gap: 12px; padding: 13px 16px;
  background: transparent; border: none; color: var(--text); cursor: pointer; text-align: left;
}
.inbox-head:hover { background: var(--panel-hover); }
.sender { font-size: 13.5px; }
.unread .sender { font-weight: 700; }
.email { color: var(--text-dim); font-size: 12.5px; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.date { color: var(--faint); font-size: 11.5px; flex-shrink: 0; }
.unread-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--accent); flex-shrink: 0; }
.inbox-body { padding: 4px 16px 16px; border-top: 1px solid var(--border); }
.message { color: var(--text-dim); font-size: 13.5px; margin: 14px 0; white-space: pre-wrap; }
.actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
</style>
