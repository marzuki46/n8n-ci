<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useUiStore } from '../stores/ui'
import { t } from '../i18n'

const auth = useAuthStore()
const ui = useUiStore()
const router = useRouter()

// Login custom path sudah diganti (localStorage.login_slug di-set router).
// Ketika URL login diganti, kredensial default tidak boleh ditampilkan/diisi.
const customLogin = !!localStorage.getItem('login_slug')

const email = ref(customLogin ? '' : 'owner@local.dev')
const password = ref(customLogin ? '' : 'owner123')
const loading = ref(false)
const error = ref('')
const googleEnabled = ref(false)

// Pesan error OAuth dari callback redirect
if (location.hash.includes('oauth_error=')) {
  const m = location.hash.match(/oauth_error=([^&]+)/)
  if (m) error.value = decodeURIComponent(m[1])
}

fetch('/api/auth/oauth/status', { headers: { 'X-Requested-With': 'xmlhttprequest' } })
  .then((r) => r.json())
  .then((j) => { googleEnabled.value = !!(j.data && j.data.google_enabled) })
  .catch(() => {})

async function submit() {
  loading.value = true
  error.value = ''
  try {
    await auth.login(email.value, password.value)
    router.push('/')
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <div class="login-theme-lang">
      <button class="btn btn-ghost btn-sm" @click="ui.toggleTheme()">{{ ui.theme === 'dark' ? '☀️' : '🌙' }}</button>
      <button class="btn btn-ghost btn-sm" @click="ui.toggleLang()">{{ ui.lang === 'id' ? 'EN' : 'ID' }}</button>
    </div>
    <div class="login-card">
      <div class="login-logo">n8n</div>
      <h1 class="login-title">n8n CodeIgniter</h1>
      <p class="login-sub">{{ t('auth.tagline') }}</p>

      <form @submit.prevent="submit" class="login-form">
        <div class="field">
          <label>{{ t('auth.email') }}</label>
          <input v-model="email" type="email" required autocomplete="email" />
        </div>
        <div class="field">
          <label>{{ t('auth.password') }}</label>
          <input v-model="password" type="password" required autocomplete="current-password" />
        </div>
        <p v-if="error" class="login-error">{{ error }}</p>
        <button class="btn btn-primary login-btn" :disabled="loading" type="submit">
          <span v-if="loading" class="spinner"></span>
          <span v-else>{{ t('auth.login') }}</span>
        </button>

        <div v-if="googleEnabled" class="oauth-divider"><span>atau</span></div>
        <a v-if="googleEnabled" href="/api/auth/oauth/google/start" class="btn login-btn google-btn">
          <svg width="16" height="16" viewBox="0 0 48 48" style="vertical-align:middle;margin-right:8px">
            <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
            <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
            <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
            <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
          </svg>
          Masuk dengan Google
        </a>
      </form>

      <p v-if="!customLogin" class="login-hint">Demo: owner@local.dev / owner123</p>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  height: 100vh; display: flex; align-items: center; justify-content: center;
  position: relative;
  background: radial-gradient(ellipse at 50% -20%, #1a2030 0%, var(--bg) 60%);
}
.login-theme-lang { position: absolute; top: 16px; right: 16px; display: flex; gap: 6px; }
.login-card {
  background: var(--panel); border: 1px solid var(--border); border-radius: 10px;
  padding: 40px 36px; width: 380px; text-align: center;
  box-shadow: 0 20px 60px rgba(0,0,0,.4);
}
.login-logo {
  width: 56px; height: 56px; margin: 0 auto 16px; border-radius: 12px;
  background: var(--accent); color: #fff; font-weight: 700; font-size: 22px;
  display: flex; align-items: center; justify-content: center;
}
.login-title { font-size: 20px; margin: 0 0 4px; }
.login-sub { color: var(--text-dim); margin: 0 0 24px; font-size: 13px; }
.login-form { text-align: left; }
.login-error { color: var(--red); font-size: 13px; margin: 0 0 12px; }
.login-btn { width: 100%; justify-content: center; }
.oauth-divider { display: flex; align-items: center; gap: 10px; margin: 14px 0; color: var(--text-faint); font-size: 11px; }
.oauth-divider::before, .oauth-divider::after { content: ""; flex: 1; height: 1px; background: var(--border); }
.google-btn { background: #fff; color: #1f2937; border: 1px solid #d1d5db; margin-top: 10px; text-decoration: none; }
.login-hint { color: var(--text-faint); font-size: 12px; margin: 18px 0 0; }
</style>
