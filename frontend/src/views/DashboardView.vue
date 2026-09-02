<script setup>
import { ref, watch, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useProjectsStore } from '../stores/projects'
import { useUiStore } from '../stores/ui'
import api from '../api'
import { t } from '../i18n'

const auth = useAuthStore()
const projects = useProjectsStore()
const ui = useUiStore()
const route = useRoute()
const router = useRouter()

const nav = computed(() => [
  { to: '/', label: t('nav.overview'), icon: '◧' },
  { to: '/workflows', label: t('nav.workflows'), icon: '▦' },
  { to: '/executions', label: t('nav.executions'), icon: '⏵' },
  { to: '/inbox', label: ui.lang === 'en' ? 'Inbox' : 'Kotak Masuk', icon: '📥', badge: unreadCount.value },
  { to: '/projects', label: t('nav.projects'), icon: '▣' },
  { to: '/credentials', label: t('nav.credentials'), icon: '⚿' },
  { to: '/api', label: 'API Keys', icon: '⧉' },
  { to: '/docs', label: ui.lang === 'en' ? 'Docs' : 'Dokumentasi', icon: '❓' },
  { to: '/settings', label: t('nav.settings'), icon: '⚙' },
])

const collapsed = ref(localStorage.getItem('sidebar_collapsed') === '1')

// Badge kotak masuk (jumlah belum dibaca)
const unreadCount = ref(0)
async function loadUnread() {
  try {
    const res = await api.get('/inquiries')
    unreadCount.value = (res.data || []).filter((i) => i.status === 'new').length
  } catch (e) { /* non-owner / gagal: abaikan */ }
}
onMounted(loadUnread)

function toggleSidebar() {
  collapsed.value = !collapsed.value
  localStorage.setItem('sidebar_collapsed', collapsed.value ? '1' : '0')
}

const pageTitle = ref('')
watch(
  () => route.name,
  (name) => {
    const map = {
      overview: 'Overview',
      workflows: 'Workflows',
      'workflow-editor': 'Workflow Editor',
      executions: 'Executions',
      'execution-detail': 'Execution Detail',
      projects: 'Projects',
      credentials: 'Credentials',
      settings: 'Settings',
      api: 'API Console',
    }
    pageTitle.value = map[name] || ''
  },
  { immediate: true }
)

async function logout() {
  await auth.logout()
  router.push('/login')
}

if (!projects.loaded) projects.load().catch(() => {})
</script>

<template>
  <div class="shell">
    <aside class="sidebar" :class="{ collapsed }">
      <div class="sidebar-logo">
        <div class="logo-mark">n8n</div>
        <div class="logo-text">
          <strong>n8n CI4</strong>
          <span>self-hosted</span>
        </div>
      </div>

      <nav class="sidebar-nav">
        <router-link v-for="item in nav" :key="item.to" :to="item.to" class="nav-item" :title="collapsed ? item.label : undefined">
          <span class="nav-icon">{{ item.icon }}</span>
          <span class="nav-label">{{ item.label }}</span>
          <span v-if="item.badge" class="nav-badge">{{ item.badge }}</span>
        </router-link>
      </nav>

      <div class="sidebar-bottom">
        <div class="workspace-chip">
          <select v-model="auth.user.workspace_id" @change="auth.switchWorkspace($event.target.value).catch(() => location.reload())">
            <option v-for="p in projects.list" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div class="user-row">
          <div class="avatar">{{ (auth.user?.name || '?').charAt(0).toUpperCase() }}</div>
          <div class="user-meta">
            <span class="user-name">{{ auth.user?.name }}</span>
            <span class="user-mail">{{ auth.user?.email }}</span>
          </div>
          <button class="btn btn-ghost btn-sm" title="Logout" @click="logout">⎋</button>
        </div>
      </div>
    </aside>

    <div class="main">
      <header class="topbar">
        <div class="topbar-left">
          <button class="btn btn-ghost btn-sm sidebar-toggle" title="Toggle sidebar" @click="toggleSidebar">☰</button>
          <div class="topbar-title">{{ pageTitle }}</div>
        </div>
        <div class="topbar-right">
          <button class="btn btn-ghost btn-sm" :title="ui.theme === 'dark' ? t('theme.light') : t('theme.dark')" @click="ui.toggleTheme()">
            {{ ui.theme === 'dark' ? '☀️' : '🌙' }}
          </button>
          <button class="btn btn-ghost btn-sm lang-toggle" title="Language" @click="ui.toggleLang()">
            {{ ui.lang === 'id' ? 'ID 🇮🇩' : 'EN 🇬🇧' }}
          </button>
          <router-link :to="{ name: 'workflows' }" class="btn btn-primary btn-sm">+ {{ t('nav.workflows') }}</router-link>
        </div>
      </header>
      <div class="main-content" :class="{ 'is-editor': route.name === 'workflow-editor' }">
        <router-view :key="route.fullPath" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.shell { display: flex; height: 100vh; overflow: hidden; }
.sidebar {
  width: var(--sidebar-width); flex-shrink: 0;
  background: var(--bg-secondary); border-right: 1px solid var(--border);
  display: flex; flex-direction: column;
  transition: width 0.2s ease;
}
.sidebar.collapsed { width: 56px; }
.sidebar.collapsed .logo-text,
.sidebar.collapsed .nav-label,
.sidebar.collapsed .workspace-chip,
.sidebar.collapsed .user-meta { display: none; }
.sidebar.collapsed .sidebar-logo { justify-content: center; padding: 16px 0; }
.sidebar.collapsed .sidebar-bottom { align-items: center; }
.sidebar.collapsed .user-row { justify-content: center; }
.sidebar.collapsed .user-row .btn { display: none; }
.sidebar.collapsed .nav-item { justify-content: center; padding: 8px 0; }
.sidebar.collapsed .nav-icon { width: auto; font-size: 15px; }
.sidebar-logo { display: flex; align-items: center; gap: 10px; padding: 16px 14px; border-bottom: 1px solid var(--border); }
.logo-mark {
  width: 34px; height: 34px; border-radius: 8px; background: var(--accent); color: #fff;
  font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: 13px;
}
.logo-text { display: flex; flex-direction: column; line-height: 1.2; }
.logo-text strong { font-size: 14px; }
.logo-text span { font-size: 11px; color: var(--text-faint); }
.sidebar-nav { flex: 1; padding: 12px 8px; display: flex; flex-direction: column; gap: 2px; }
.nav-item {
  display: flex; align-items: center; gap: 10px; padding: 8px 12px;
  border-radius: var(--radius); color: var(--text-dim); font-size: 13px; text-decoration: none;
}
.nav-item:hover { background: var(--panel-hover); color: var(--text); text-decoration: none; }
.nav-item.router-link-exact-active { background: var(--panel-hover); color: var(--text); }
.nav-item.router-link-exact-active .nav-icon { color: var(--accent); }
.nav-icon { width: 18px; text-align: center; }
.nav-badge {
  margin-left: auto; background: var(--accent); color: #fff; font-size: 10.5px; font-weight: 700;
  min-width: 18px; height: 18px; border-radius: 9px; display: flex; align-items: center; justify-content: center; padding: 0 5px;
}
.sidebar-bottom { border-top: 1px solid var(--border); padding: 12px; display: flex; flex-direction: column; gap: 10px; }
.workspace-chip select { width: 100%; font-size: 12px; }
.user-row { display: flex; align-items: center; gap: 8px; }
.avatar {
  width: 30px; height: 30px; border-radius: 50%; background: var(--accent); color: #fff;
  display: flex; align-items: center; justify-content: center; font-weight: 600; flex-shrink: 0;
}
.user-meta { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; flex: 1; }
.user-name { font-size: 13px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-mail { font-size: 11px; color: var(--text-faint); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.main { flex: 1; display: flex; flex-direction: column; min-width: 0; height: 100vh; overflow: hidden; }
/* Area konten: inilah yang di-scroll. Sidebar & topbar tetap diam.
   Lebar mengikuti sisa ruang sehingga otomatis menyesuaikan sidebar collapse/show. */
.main-content {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overflow-x: hidden;
}
/* Editor workflow: canvas harus pas tanpa scrollbar halaman. */
.main-content.is-editor { overflow: hidden; display: flex; flex-direction: column; }
.main-content.is-editor > * { flex: 1; min-height: 0; }
.topbar {
  height: 48px; display: flex; align-items: center; justify-content: space-between;
  padding: 0 20px; border-bottom: 1px solid var(--border); background: var(--bg-secondary);
}
.topbar-title { font-size: 14px; font-weight: 500; }
.topbar-left { display: flex; align-items: center; gap: 10px; }
.topbar-right { display: flex; align-items: center; gap: 6px; }
.lang-toggle { font-weight: 600; }
.sidebar-toggle { font-size: 16px; line-height: 1; }
</style>
