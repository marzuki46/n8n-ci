<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../api'
import { useWorkflowStore } from '../stores/workflow'
import { useUiStore } from '../stores/ui'
import { useAuthStore } from '../stores/auth'
import { t } from '../i18n'

const workflowStore = useWorkflowStore()
const ui = useUiStore()
const auth = useAuthStore()
const status = ref(null)
const schedules = ref([])
const showModal = ref(false)
const saving = ref(false)
const error = ref('')
const form = ref({ workflow_id: '', cron: '*/5 * * * *', timezone: 'Asia/Jakarta' })

// Preferensi akun (Paket notifikasi login)
const prefs = ref({ login_notify: 1 })
const prefsSaving = ref(false)
const prefsSavedAt = ref('')

// Keamanan: custom login path
const loginPath = ref({ slug: '', protected: false })
const slugInput = ref('')
const slugSaving = ref(false)
const slugError = ref('')
const slugSaved = ref(false)

// Profil publik + inquiry
const profileForm = ref({
  profile_name: '', profile_tagline: '', profile_bio: '', profile_location: '',
  contact_email: '', contact_whatsapp: '', contact_website: '',
  recaptcha_site_key: '', recaptcha_secret_key: '',
})
const profileSaving = ref(false)
const profileSaved = ref(false)

// Engine & runtime
const runtimes = ref(null)
const runtimesLoading = ref(true)

// Google OAuth + knowledge base vectors
const oauthForm = ref({ oauth_google_client_id: '', oauth_google_client_secret: '' })
const oauthSaving = ref(false)
const oauthSaved = ref(false)
const oauthEnabled = ref(false)
const oauthRedirect = ref('')
const vectorNamespaces = ref([])
const oauthRegistrationMode = ref('off')

// Keamanan akun: ganti password & ganti email
const pwForm = ref({ current_password: '', new_password: '' })
const pwSaving = ref(false)
const pwMsg = ref('')

const emForm = ref({ current_password: '', new_email: '' })
const emBusy = ref(false)
const emLink = ref('')

// AI Budget guardrail
const aiBudget = ref({ limit: 0, action: 'warn', used: 0 })
const aiBudgetSaving = ref(false)
const aiBudgetSaved = ref(false)

async function loadAiBudget() {
  try {
    const res = await api.get('/system/ai-budget')
    Object.assign(aiBudget.value, res.data || {})
  } catch (e) { /* abaikan */ }
}

async function saveAiBudget() {
  aiBudgetSaving.value = true
  aiBudgetSaved.value = false
  try {
    await api.put('/system/ai-budget', {
      limit: Number(aiBudget.value.limit) || 0,
      action: aiBudget.value.action,
    })
    aiBudgetSaved.value = true
    setTimeout(() => (aiBudgetSaved.value = false), 2500)
    await loadAiBudget()
  } catch (e) {
    alert(e.message)
  } finally {
    aiBudgetSaving.value = false
  }
}

async function loadRuntimes() {
  runtimesLoading.value = true
  try {
    const res = await api.get('/system/runtimes')
    runtimes.value = res.data || null
  } catch (e) {
    runtimes.value = null
  } finally {
    runtimesLoading.value = false
  }
}

async function loadOauth() {
  try {
    const res = await api.get('/security/oauth-google')
    Object.assign(oauthForm.value, res.data || {})
    oauthRedirect.value = res.data?.redirect_uri || ''
    oauthRegistrationMode.value = res.data?.registration_mode || 'off'
    oauthEnabled.value = !!(res.data?.oauth_google_client_id && res.data?.oauth_google_client_secret)
    oauthRegistrationMode.value = localStorage.getItem('oauth_reg_mode') || 'off'
  } catch (e) { /* non-owner */ }
}

async function saveOauth() {
  oauthSaving.value = true
  oauthSaved.value = false
  try {
    const res = await api.put('/security/oauth-google', { ...oauthForm.value, registration_mode: oauthRegistrationMode.value })
    oauthEnabled.value = !!res.data?.google_enabled
    oauthSaved.value = true
    setTimeout(() => (oauthSaved.value = false), 2500)
  } catch (e) {
    alert(e.message)
  } finally {
    oauthSaving.value = false
  }
}

async function loadVectors() {
  if ((auth.user?.role) !== 'owner') return
  try {
    vectorNamespaces.value = (await api.get('/vectors/summary')).data || []
  } catch (e) { vectorNamespaces.value = [] }
}

async function changePassword() {
  pwSaving.value = true
  pwMsg.value = ''
  try {
    await api.put('/user/password', pwForm.value)
    pwMsg.value = '✔ Password diganti'
    pwForm.value = { current_password: '', new_password: '' }
  } catch (e) {
    pwMsg.value = e.message
  } finally {
    pwSaving.value = false
  }
}

async function requestEmailChange() {
  emBusy.value = true
  emLink.value = ''
  try {
    const res = await api.post('/user/email/request-change', emForm.value)
    if (res.data?.verification_link) {
      emLink.value = res.data.verification_link
    }
    emForm.value = { current_password: '', new_email: '' }
  } catch (e) {
    alert(e.message)
  } finally {
    emBusy.value = false
  }
}

async function verifyEmailFromLink(token) {
  try {
    await api.get(`/user/email/verify?token=${encodeURIComponent(token)}`)
    alert('Email berhasil diverifikasi & diganti.')
    await auth.init().catch(() => {})
  } catch (e) {
    alert(e.message)
  }
}

async function deleteNamespace(ns) {
  if (!confirm(`Hapus knowledge base "${ns}"? Tindakan ini tidak dapat dibatalkan.`)) return
  try {
    await api.delete(`/vectors/namespace/${encodeURIComponent(ns)}`)
    await loadVectors()
  } catch (e) { alert(e.message) }
}

function engineBadge(rt) {
  if (!rt) return { cls: 'badge-muted', text: '—' }
  return rt.found ? { cls: 'badge-success', text: 'Aktif ✔' } : { cls: 'badge-error', text: 'Tidak aktif' }
}

// Langkah aktivasi per engine (bilingual, statis di frontend)
function activationSteps(key) {
  const en = ui.lang === 'en'
  if (key === 'node') {
    return en ? [
      'VPS: install via nvm → `curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash` then `nvm install --lts`',
      'Ubuntu/Debian: `sudo apt install nodejs npm`',
      'cPanel: open "Setup Node.js App" — the binary is usually available as `node`',
      'If installed in a non-standard location, set in .env: NODE_BINARY=/full/path/node',
    ] : [
      'VPS: instal via nvm → `curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash` lalu `nvm install --lts`',
      'Ubuntu/Debian: `sudo apt install nodejs npm`',
      'cPanel: buka menu "Setup Node.js App" — biasanya binary tersedia sebagai `node`',
      'Jika lokasi tidak standar, set di .env: NODE_BINARY=/full/path/node',
    ]
  }
  if (key === 'python') {
    return en ? [
      'Ubuntu/Debian: `sudo apt install python3 python3-pip`',
      'CentOS/Alma: `sudo dnf install python3`',
      'Windows VPS: install from python.org — tick "Add to PATH"',
      'cPanel: often pre-installed as `python3`; otherwise use "Setup Python App"',
      'If in a non-standard location, set in .env: PYTHON_BINARY=/full/path/python3',
    ] : [
      'Ubuntu/Debian: `sudo apt install python3 python3-pip`',
      'CentOS/Alma: `sudo dnf install python3`',
      'Windows VPS: instal dari python.org — centang "Add to PATH"',
      'cPanel: biasanya sudah ada sebagai `python3`; jika tidak, gunakan "Setup Python App"',
      'Jika lokasi tidak standar, set di .env: PYTHON_BINARY=/full/path/python3',
    ]
  }
  return []
}

function loginUrl(slug) {
  return `${location.origin}/#/${slug ? 'login/' + slug : 'login'}`
}

const lastTickAgo = computed(() => {
  if (!status.value?.last_tick) return 'belum pernah'
  const diff = (Date.now() / 1000) - (new Date(status.value.last_tick.replace(' ', 'T') + 'Z').getTime() / 1000)
  if (diff < 120) return 'sekarang (sehat)'
  if (diff < 3600) return `${Math.floor(diff / 60)} menit lalu (perlu penjadwal)`
  return `${Math.floor(diff / 3600)} jam lalu (perlu penjadwal)`
})

const healthy = computed(() => {
  if (!status.value?.last_tick) return false
  const diff = (Date.now() / 1000) - (new Date(status.value.last_tick.replace(' ', 'T') + 'Z').getTime() / 1000)
  return diff < 120
})

async function load() {
  await workflowStore.load().catch(() => {})
  const [s, sch] = await Promise.all([api.get('/schedules/status'), api.get('/schedules')])
  status.value = s.data
  schedules.value = sch.data || []
}

async function loadPrefs() {
  try {
    const res = await api.get('/user/preferences')
    prefs.value.login_notify = !!(res.data?.login_notify ?? 1)
  } catch (e) { /* biarkan default */ }
}

async function savePrefs() {
  prefsSaving.value = true
  try {
    await api.put('/user/preferences', { login_notify: prefs.value.login_notify })
    prefsSavedAt.value = new Date().toLocaleTimeString()
  } catch (e) {
    alert(e.message)
  } finally {
    prefsSaving.value = false
  }
}
async function loadLoginPath() {
  try {
    const res = await api.get('/security/login-path')
    loginPath.value = res.data || { slug: '', protected: false }
    slugInput.value = loginPath.value.slug
  } catch (e) { /* abaikan */ }
}

async function saveLoginPath() {
  slugSaving.value = true
  slugError.value = ''
  slugSaved.value = false
  try {
    const res = await api.put('/security/login-path', { slug: slugInput.value })
    loginPath.value = res.data
    // Simpan slug di browser ini agar form login tahu endpoint-nya.
    localStorage.setItem('login_slug', res.data.slug || '')
    slugSaved.value = true
  } catch (e) {
    slugError.value = e.message
  } finally {
    slugSaving.value = false
  }
}
async function loadProfile() {
  try {
    const res = await api.get('/settings/profile')
    if (res.data) Object.assign(profileForm.value, res.data)
  } catch (e) { /* non-owner: abaikan */ }
}

async function saveProfileSettings() {
  profileSaving.value = true
  profileSaved.value = false
  try {
    await api.put('/settings/profile', profileForm.value)
    profileSaved.value = true
    setTimeout(() => (profileSaved.value = false), 2500)
  } catch (e) {
    alert(e.message)
  } finally {
    profileSaving.value = false
  }
}
onMounted(() => { load(); loadPrefs(); loadLoginPath(); loadProfile(); loadRuntimes(); loadOauth(); loadVectors(); loadAiBudget() })

function openCreate() {
  form.value = { workflow_id: workflowStore.list[0]?.id || '', cron: '*/5 * * * *', timezone: 'Asia/Jakarta' }
  error.value = ''
  showModal.value = true
}

async function save() {
  if (!form.value.workflow_id) {
    error.value = 'Pilih workflow.'
    return
  }
  saving.value = true
  error.value = ''
  try {
    await api.post('/schedules', form.value)
    showModal.value = false
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

async function remove(s) {
  if (!confirm('Hapus jadwal ini?')) return
  try {
    await api.delete(`/schedules/${s.id}`)
    await load()
  } catch (e) {
    alert(e.message)
  }
}

async function runNow(s) {
  try {
    await api.post(`/schedules/${s.id}/run-now`, {})
    await load()
  } catch (e) {
    alert(e.message)
  }
}

function statusBadge(s) {
  if (!s.last_status) return 'badge-muted'
  if (s.last_status.startsWith('success')) return 'badge-success'
  if (s.last_status.startsWith('skipped')) return 'badge-warn'
  return 'badge-error'
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title">{{ t('settings.title') }}</h1>
        <p class="page-sub">Status cron worker & jadwal workflow.</p>
      </div>
    </div>

    <!-- Preferensi akun + tampilan -->
    <div class="card prefs-card">
      <h3 style="margin: 0 0 12px">{{ t('settings.preferences') }}</h3>
      <div class="pref-row">
        <label class="toggle" :class="{ on: prefs.login_notify }" @click="prefs.login_notify = !prefs.login_notify; savePrefs()">
          <span class="toggle-knob"></span>
        </label>
        <div class="pref-text">
          <strong>Email notifikasi login</strong>
          <div class="faint">{{ t('settings.loginNotify') }}</div>
        </div>
        <span v-if="prefsSavedAt" class="badge badge-success">{{ t('common.saved') }} ✔</span>
      </div>

      <div class="pref-row" style="border-top: 1px dashed var(--border); padding-top: 14px">
        <div class="pref-text">
          <strong>{{ t('settings.appearance') }}</strong>
          <div class="faint">Mode {{ ui.theme === 'dark' ? t('theme.dark') : t('theme.light') }}</div>
        </div>
        <button class="btn btn-sm" @click="ui.toggleTheme()">
          {{ ui.theme === 'dark' ? '☀️ ' + t('theme.light') : '🌙 ' + t('theme.dark') }}
        </button>
      </div>

      <div class="pref-row" style="border-top: 1px dashed var(--border); padding-top: 14px">
        <div class="pref-text">
          <strong>{{ t('settings.language') }}</strong>
        </div>
        <select :value="ui.lang" @change="ui.setLang($event.target.value)">
          <option value="id">Bahasa Indonesia</option>
          <option value="en">English</option>
        </select>
      </div>

      <!-- Profil publik landing page -->
      <div class="pref-row" style="border-top: 1px dashed var(--border); padding-top: 14px; flex-wrap: wrap">
        <div class="pref-text" style="width: 100%; margin-bottom: 8px">
          <strong>🌐 Profil Publik & Kontak</strong>
          <div class="faint">{{ ui.lang === 'en' ? 'Shown on the public landing page + inquiry form.' : 'Tampil di landing page publik + form inquiry.' }}</div>
        </div>
        <div class="profile-grid">
          <input v-model="profileForm.profile_name" placeholder="Nama / brand" />
          <input v-model="profileForm.profile_tagline" placeholder="Tagline (mis. Website Developer & SEO)" />
          <input v-model="profileForm.profile_location" placeholder="Lokasi (mis. Solo, Indonesia)" />
          <textarea v-model="profileForm.profile_bio" rows="2" placeholder="Bio singkat"></textarea>
          <input v-model="profileForm.contact_email" placeholder="Email kontak" />
          <input v-model="profileForm.contact_whatsapp" placeholder="WhatsApp (mis. 62812xxxxxxx)" />
          <input v-model="profileForm.contact_website" placeholder="Website" />
          <input v-model="profileForm.recaptcha_site_key" placeholder="reCAPTCHA site key (opsional)" />
          <input v-model="profileForm.recaptcha_secret_key" placeholder="reCAPTCHA secret key (opsional)" type="password" />
        </div>
        <p class="faint" style="width:100%;margin:4px 0 0;font-size:11px">
          {{ ui.lang === 'en'
            ? 'Leave reCAPTCHA keys empty to use built-in math captcha. Form is protected by honeypot + rate limit (5/hour per IP).'
            : 'Biarkan kunci reCAPTCHA kosong untuk memakai captcha matematika bawaan. Form dilindungi honeypot + rate limit (5/jam per IP).' }}
        </p>
        <button class="btn btn-sm btn-primary" :disabled="profileSaving" @click="saveProfileSettings">
          {{ profileSaving ? '…' : t('common.save') }}
        </button>
        <span v-if="profileSaved" class="badge badge-success">✔ {{ t('common.saved') }}</span>
      </div>
      <!-- AI Budget guardrail -->
      <div class="pref-row" style="border-top: 1px dashed var(--border); padding-top: 14px; flex-wrap: wrap">
        <div class="pref-text" style="width: 100%; margin-bottom: 8px">
          <strong>💰 AI Budget (Guardrail)</strong>
          <div class="faint">{{ ui.lang === 'en'
            ? 'Monthly token limit for this server. Exceeded → warn or block all AI nodes.'
            : 'Limit token bulanan untuk server ini. Lewat batas → warning atau blokir semua node AI.' }}</div>
        </div>
        <div v-if="(aiBudget.limit || 0) > 0" style="width:100%; margin-bottom:8px">
          <div class="budget-bar"><div class="budget-fill" :style="{ width: Math.min(100, Math.round(aiBudget.used / aiBudget.limit * 100)) + '%' }"></div></div>
          <div class="faint" style="font-size:11px; margin-top:3px">
            {{ Number(aiBudget.used).toLocaleString() }} / {{ Number(aiBudget.limit).toLocaleString() }} tokens
            ({{ ui.lang === 'en' ? 'this month' : 'bulan ini' }})
          </div>
        </div>
        <input v-model.number="aiBudget.limit" type="number" min="0" step="10000" placeholder="0 = tanpa limit" style="min-width:180px" />
        <select v-model="aiBudget.action" style="min-width:150px">
          <option value="warn">{{ ui.lang === 'en' ? 'Warn only' : 'Warning saja' }}</option>
          <option value="block">{{ ui.lang === 'en' ? 'Block AI nodes' : 'Blokir node AI' }}</option>
        </select>
        <button class="btn btn-sm btn-primary" :disabled="aiBudgetSaving" @click="saveAiBudget">
          {{ aiBudgetSaving ? '…' : t('common.save') }}
        </button>
        <span v-if="aiBudgetSaved" class="badge badge-success">✔ {{ t('common.saved') }}</span>
      </div>

      <!-- Keamanan akun: ganti password & email -->
      <div class="pref-row" style="border-top: 1px dashed var(--border); padding-top: 14px; flex-wrap: wrap">
        <div class="pref-text" style="width: 100%; margin-bottom: 8px">
          <strong>🔑 Kredensial Akun</strong>
        </div>
        <div class="profile-grid">
          <input v-model="pwForm.current_password" type="password" placeholder="Password saat ini" autocomplete="current-password" />
          <input v-model="pwForm.new_password" type="password" placeholder="Password baru (min 8)" autocomplete="new-password" />
        </div>
        <button class="btn btn-sm btn-primary" :disabled="pwSaving || !pwForm.current_password || pwForm.new_password.length < 8" @click="changePassword">
          Ganti Password
        </button>
        <span v-if="pwMsg" class="faint">{{ pwMsg }}</span>

        <div class="profile-grid" style="width:100%; margin-top:10px">
          <input v-model="emForm.current_password" type="password" placeholder="Password saat ini (konfirmasi)" autocomplete="current-password" />
          <input v-model="emForm.new_email" type="email" placeholder="Email baru — kirim verifikasi" />
        </div>
        <button class="btn btn-sm" :disabled="emBusy || !emForm.current_password || !emForm.new_email" @click="requestEmailChange">
          {{ emBusy ? '…' : 'Kirim Verifikasi Email' }}
        </button>
        <a v-if="emLink" :href="emLink" class="btn btn-sm btn-primary">Buka Link Verifikasi →</a>
      </div>

      <!-- SSO Google OAuth -->
      <div class="pref-row" style="border-top: 1px dashed var(--border); padding-top: 14px; flex-wrap: wrap">
        <div class="pref-text" style="width: 100%; margin-bottom: 8px">
          <strong>📐 Masuk dengan Google (SSO)</strong>
          <div class="faint">
            {{ ui.lang === 'en'
              ? 'Enable Google sign-in. New users join the first workspace as member.'
              : 'Aktifkan login via Google. User baru masuk ke workspace pertama sebagai member.' }}
          </div>
        </div>
        <div class="profile-grid">
          <input v-model="oauthForm.oauth_google_client_id" placeholder="Google Client ID" />
          <input v-model="oauthForm.oauth_google_client_secret" type="password" placeholder="Google Client Secret" />
        </div>
        <div class="pref-text" style="width:100%; margin-top:6px">
          <label class="faint" style="font-size:11px">Registrasi akun baru via Google:</label>
          <select v-model="oauthRegistrationMode">
            <option value="off">{{ ui.lang === 'en' ? 'Closed — registered emails only' : 'Tertutup — hanya email terdaftar' }}</option>
            <option value="member-auto">{{ ui.lang === 'en' ? 'Open — auto-create member' : 'Terbuka — auto-buat member' }}</option>
          </select>
        </div>
        <button class="btn btn-sm btn-primary" :disabled="oauthSaving" @click="saveOauth">
          {{ oauthSaving ? '…' : t('common.save') }}
        </button>
        <span :class="oauthEnabled ? 'badge badge-success' : 'badge badge-warn'">
          {{ oauthEnabled ? (ui.lang === 'en' ? 'Enabled' : 'Aktif') : (ui.lang === 'en' ? 'Disabled' : 'Nonaktif') }}
        </span>
        <p v-if="oauthRedirect" class="faint mono-inline" style="width:100%;margin:4px 0 0;word-break:break-all;font-size:11px">
          Redirect URI untuk Google Cloud Console:<br /><a :href="oauthRedirect" style="pointer-events:none">{{ oauthRedirect }}</a>
        </p>
      </div>

      <!-- Knowledge base vectors -->
      <div v-if="vectorNamespaces.length" class="pref-row" style="border-top: 1px dashed var(--border); padding-top: 14px; width: 100%">
        <div class="pref-text" style="width: 100%; margin-bottom: 8px">
          <strong>📁️ AI Knowledge Base</strong>
          <div class="faint">{{ ui.lang === 'en' ? 'Vector namespaces created by Vector Store nodes.' : 'Namespace vektor yang dibuat node Vector Store.' }}</div>
        </div>
        <table class="table" style="width:100%">
          <thead><tr><th>Namespace</th><th>Vectors</th><th>Terakhir</th><th></th></tr></thead>
          <tbody>
            <tr v-for="ns in vectorNamespaces" :key="ns.namespace">
              <td><span class="code">{{ ns.namespace }}</span></td>
              <td>{{ ns.vectors }}</td>
              <td class="muted">{{ (ns.last_at || '').slice(0, 16) }}</td>
              <td><button class="btn btn-sm btn-danger" @click="deleteNamespace(ns.namespace)">✖</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Keamanan: custom login path -->
    <div class="card prefs-card">
      <h3 style="margin: 0 0 12px">🔐 {{ ui.lang === 'en' ? 'Security' : 'Keamanan' }}</h3>
      <div class="pref-row" style="flex-wrap: wrap">
        <div class="pref-text" style="width: 100%; margin-bottom: 8px">
          <strong>Login Path Kustom</strong>
          <div class="faint">{{ t('settings.loginPathHint') }}</div>
        </div>
        <input v-model="slugInput" placeholder="mis. masuk-rahasia-99" style="min-width: 240px" />
        <button class="btn btn-sm btn-primary" :disabled="slugSaving" @click="saveLoginPath">
          {{ slugSaving ? '…' : t('common.save') }}
        </button>
        <span :class="loginPath.protected ? 'badge badge-success' : 'badge badge-warn'">
          {{ loginPath.protected ? (ui.lang === 'en' ? 'Protected' : 'Terlindungi') : (ui.lang === 'en' ? 'Default URL' : 'URL default') }}
        </span>
        <p v-if="slugError" style="color: var(--red); width: 100%; margin: 0">{{ slugError }}</p>
        <p v-else-if="slugSaved" class="faint" style="width: 100%; margin: 0">✔ {{ t('common.saved') }}</p>
        <p v-if="loginPath.slug" class="faint mono-inline" style="width: 100%; margin: 0; word-break: break-all">
          {{ ui.lang === 'en' ? 'Your private login URL:' : 'URL login privat Anda:' }}<br />
          <a :href="loginUrl(loginPath.slug)">{{ loginUrl(loginPath.slug) }}</a>
        </p>
      </div>
    </div>

    <div class="cron-grid">
      <div class="card cron-card">
        <div class="cron-head">
          <h3 style="margin: 0">Cron Worker</h3>
          <span class="badge" :class="healthy ? 'badge-success' : 'badge-error'">
            {{ healthy ? 'Sehat' : 'Tidak aktif' }}
          </span>
        </div>
        <p class="muted">Worker membaca tabel <code class="code">schedules</code> dan menjalankan workflow yang jatuh tempo.</p>
        <div class="cron-row">
          <span class="muted">Last tick:</span>
          <strong>{{ status?.last_tick || '–' }}</strong>
        </div>
        <div class="cron-row">
          <span class="muted">Status:</span>
          <span>{{ lastTickAgo }}</span>
        </div>
        <div class="cron-note">
          <strong>Setup penjadwal:</strong>
          <pre class="code">php spark cron:run   (setiap menit via Task Scheduler / cron)</pre>
        </div>
      </div>

      <div class="card cron-card">
        <div class="cron-head">
          <h3 style="margin: 0">Berikutnya</h3>
        </div>
        <div v-if="status?.upcoming?.length" class="upcoming-list">
          <div v-for="u in status.upcoming" :key="u.id" class="upcoming-row">
            <div>
              <div>{{ u.workflow_name }}</div>
              <div class="faint">{{ u.cron }} Â· {{ u.timezone }}</div>
      <!-- Engine & runtime -->
      <div class="pref-row" style="border-top: 1px dashed var(--border); padding-top: 14px; flex-wrap: wrap">
        <div class="pref-text" style="width: 100%; margin-bottom: 8px">
          <strong>⚙️ {{ ui.lang === 'en' ? 'Engines & Runtimes' : 'Engine & Runtime' }}</strong>
          <div class="faint">{{ ui.lang === 'en'
            ? 'Executors used by certain nodes (Code Node, Python Node, etc).'
            : 'Mesin eksekusi yang dipakai node tertentu (Code Node, Python Node, dll).' }}</div>
        </div>
        <div v-if="runtimesLoading" class="faint">…</div>
        <table v-else-if="runtimes" class="runtime-table">
          <tbody>
            <tr v-for="(rt, key) in runtimes.runtimes" :key="key" v-show="key !== 'extensions'">
              <td class="rt-name">
                <strong>{{ rt.name }}</strong>
                <span v-if="!rt.required && !rt.found" class="badge badge-muted" style="margin-left:6px">{{ ui.lang === 'en' ? 'optional' : 'opsional' }}</span>
                <div class="faint" style="font-size:11px">{{ rt.engine_for }}</div>
              </td>
              <td class="rt-ver mono-inline">{{ rt.version || '—' }}</td>
              <td><span :class="'badge ' + engineBadge(rt).cls">{{ engineBadge(rt).text }}</span></td>
            </tr>
          </tbody>
        </table>

        <details v-if="runtimes" class="activation-details">
          <summary>{{ ui.lang === 'en' ? 'How to activate on hosting' : 'Cara mengaktifkan di hosting' }}</summary>
          <div v-for="(rt, key) in runtimes.runtimes" :key="'act-' + key" class="act-block">
            <template v-if="(key === 'node' || key === 'python')">
              <strong :style="{ color: rt.found ? 'var(--green)' : 'var(--red)' }">
                {{ rt.name }} — {{ rt.found ? (ui.lang === 'en' ? 'already active (' + rt.version + ')' : 'sudah aktif (' + rt.version + ')') : (ui.lang === 'en' ? 'not found' : 'tidak ditemukan') }}
              </strong>
              <ol class="act-steps">
                <li v-for="(s, i) in activationSteps(key)" :key="i"><span class="code" style="font-size:11.5px">{{ s }}</span></li>
              </ol>
            </template>
          </div>
          <p class="faint" style="font-size:11px; margin-top:6px">
            {{ ui.lang === 'en'
              ? 'After installing, restart PHP (or the web server) then reload this page.'
              : 'Setelah instal, restart PHP (atau web server) lalu muat ulang halaman ini.' }}
          </p>
        </details>
      </div>
    </div>
            <span class="muted">{{ u.next_run }}</span>
          </div>
        </div>
        <p v-else class="muted">Tidak ada jadwal aktif.</p>
      </div>
    </div>

    <div class="card mt-16">
      <div class="flex-between mb-16">
        <h3 style="margin: 0">Jadwal</h3>
        <button class="btn btn-sm btn-primary" @click="openCreate">+ Jadwal Baru</button>
      </div>
      <table v-if="schedules.length" class="table">
        <thead>
          <tr><th>Workflow</th><th>Cron</th><th>Zona Waktu</th><th>Next Run</th><th>Terakhir</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="s in schedules" :key="s.id">
            <td>{{ s.workflow_name }}</td>
            <td><span class="code">{{ s.cron }}</span></td>
            <td class="muted">{{ s.timezone }}</td>
            <td class="muted">{{ s.next_run }}</td>
            <td>
              <span v-if="s.last_run" class="badge" :class="statusBadge(s)">{{ s.last_status }} Â· {{ s.last_run }}</span>
              <span v-else class="faint">–</span>
            </td>
            <td class="flex gap-8" style="justify-content: flex-end">
              <button class="btn btn-sm" @click="runNow(s)">Jalankan</button>
              <button class="btn btn-sm btn-danger" @click="remove(s)">Hapus</button>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-else class="empty-state">
        <div class="icon">🕐</div>
        <div>Belum ada jadwal.</div>
      </div>
    </div>

    <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
      <div class="modal">
        <h3>Jadwal Baru</h3>
        <div class="field">
          <label>Workflow</label>
          <select v-model="form.workflow_id">
            <option v-for="w in workflowStore.list" :key="w.id" :value="w.id">{{ w.name }}</option>
          </select>
        </div>
        <div class="field">
          <label>Ekspresi Cron</label>
          <input v-model="form.cron" placeholder="*/5 * * * *" />
        </div>
        <div class="field">
          <label>Zona Waktu</label>
          <select v-model="form.timezone">
            <option value="UTC">UTC</option>
            <option value="Asia/Jakarta">Asia/Jakarta (WIB)</option>
            <option value="Asia/Makassar">Asia/Makassar (WITA)</option>
            <option value="Asia/Jayapura">Asia/Jayapura (WIT)</option>
          </select>
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
.cron-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 900px) { .cron-grid { grid-template-columns: 1fr; } }
.cron-card { padding: 16px; }
.cron-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.cron-row { display: flex; gap: 8px; margin-bottom: 6px; }
.cron-note { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); }
.cron-note pre { margin: 6px 0 0; overflow: auto; }
.upcoming-list { display: flex; flex-direction: column; gap: 8px; }
.upcoming-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px dashed var(--border); }
.upcoming-row:last-child { border-bottom: none; }
.prefs-card { padding: 16px; margin-bottom: 14px; }
.pref-row { display: flex; align-items: center; gap: 14px; margin-bottom: 12px; }
.pref-text { flex: 1; }
.toggle {
  width: 38px; height: 22px; border-radius: 12px; border: 1px solid var(--border);
  background: var(--bg); position: relative; cursor: pointer; transition: background .15s; flex-shrink: 0;
}
.toggle-knob {
  position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 50%;
  background: var(--text-dim); transition: left .15s, background .15s;
}
.toggle.on { background: var(--accent); border-color: var(--accent); }
.toggle.on .toggle-knob { left: 18px; background: #fff; }
.profile-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 8px; width: 100%;
}
.profile-grid textarea { font-family: inherit; }
@media (max-width: 700px) { .profile-grid { grid-template-columns: 1fr; } }
.runtime-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 10px; }
.runtime-table td { padding: 7px 8px; border-bottom: 1px dashed var(--border); vertical-align: middle; }
.rt-name { min-width: 180px; }
.rt-ver { color: var(--text-dim); }
.activation-details { margin-top: 6px; font-size: 12.5px; }
.activation-details summary { cursor: pointer; color: var(--accent); font-weight: 600; }
.act-block { margin-top: 12px; }
.act-steps { margin: 6px 0 0 18px; padding: 0; }
.act-steps li { margin-bottom: 4px; }
.budget-bar { width: 100%; height: 8px; background: var(--bg); border: 1px solid var(--border); border-radius: 4px; overflow: hidden; }
.budget-fill { height: 100%; background: var(--accent); transition: width .3s; }
</style>
