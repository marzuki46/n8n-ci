<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useNodesStore } from '../stores/nodes'
import api from '../api'

const props = defineProps({
  node: { type: Object, required: true },
})
const emit = defineEmits(['update'])

const route = useRoute()
const nodesStore = useNodesStore()
const credentials = ref([])

const meta = computed(() => nodesStore.byType[props.node.data?.nodeType] || {})
const displayName = computed(() => props.node.data?.name || '')
const parameters = computed(() => props.node.data?.parameters || {})

// ===== Paket 2: contoh isian, validasi live, dan Coba Node =====

const showExamples = ref(false)
const activeExampleIdx = ref(0)
const sampleInputText = ref('')
const testResult = ref(null)
const testing = ref(false)
const testedOk = ref(false)

// Node yang memanggil API berbayar/eksternal saat dicoba.
const EXTERNAL_TYPES = [
  '9router', 'openai', 'no_ai_slop', 'http_request',
  'email', 'telegram', 'discord', 'slack', 'github', 'notion', 'mysql', 'postgres',
]

const examples = computed(() => meta.value.examples || [])
const activeExample = computed(() => examples.value[activeExampleIdx.value] || null)

function applyExample(ex) {
  const merged = { ...parameters.value }
  for (const [k, v] of Object.entries(ex.params || {})) {
    merged[k] = typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean' ? String(v) : JSON.stringify(v)
  }
  emit('update', { parameters: merged })
  sampleInputText.value = JSON.stringify(ex.input || {}, null, 2)
}

/**
 * Validasi live: 'merah' = wajib kosong / JSON rusak,
 * 'hijau' = semua lengkap, 'kuning' = lengkap tapi belum dicoba.
 */
const validationResult = computed(() => {
  const problems = []
  for (const p of meta.value.parameters || []) {
    const v = parameters.value[p.key]
    const filled = typeof v === 'string' ? v.trim() !== '' : v !== undefined && v !== null
    if ((p.type === 'json' || p.type === 'conditions') && filled && typeof v === 'string') {
      try { JSON.parse(v) } catch (e) { problems.push(`${p.label}: JSON tidak valid`) }
    }
    if (p.required && !filled) problems.push(`${p.label} belum diisi`)
    if (p.type === 'credentials' && !filled && !defaultFor(p)) {
      // tanpa credential terpilih & tanpa default → tidak fatal (engine pakai apa adanya)
      problems.push(null)
    }
  }
  if (problems.some((x) => x !== null)) return { level: 'red', problems: problems.filter(Boolean) }
  if (problems.length > 0) return { level: 'yellow', problems: ['Ada parameter opsional yang kosong'] }
  if (!testedOk.value) return { level: 'yellow', problems: ['Lengkap — coba node untuk memastikan'] }
  return { level: 'green', problems: [] }
})

async function runTest() {
  let sampleData = {}
  try {
    sampleData = sampleInputText.value.trim() ? JSON.parse(sampleInputText.value) : {}
  } catch (e) {
    testResult.value = { ok: false, error: 'Sample input bukan JSON valid.' }
    return
  }

  if (EXTERNAL_TYPES.includes(props.node.data?.nodeType)) {
    const okGo = window.confirm(
      'Node ini akan memanggil API eksternal sungguhan (bisa kena biaya / mengirim pesan). Lanjutkan uji coba?'
    )
    if (!okGo) return
  }

  testing.value = true
  testResult.value = null
  try {
    const res = await api.post('/nodes/test', {
      node_type: props.node.data?.nodeType,
      name: displayName.value,
      parameters: parameters.value,
      sample_data: sampleData,
      workflow_id: route.params.id ? Number(route.params.id) : undefined,
    })
    testResult.value = res.data?.data || res.data || { ok: false, error: 'Respons tidak dikenal' }
    testedOk.value = !!(testResult.value && testResult.value.ok)
  } catch (e) {
    testResult.value = { ok: false, error: e.message || 'Gagal memanggil API' }
  } finally {
    testing.value = false
    emit('update', { testStatus: testedOk.value ? 'green' : 'red' })
  }
}

onMounted(loadCredentials)

async function loadCredentials() {
  try {
    const res = await api.get('/credentials')
    credentials.value = res.data || []
  } catch (e) {
    credentials.value = []
  }
}

function setParam(key, value) {
  emit('update', { parameters: { ...parameters.value, [key]: value } })
}

function rename(e) {
  emit('update', { name: e.target.value })
}

function listFor(p) {
  const opts = p.options || []
  return opts.map((o) => (typeof o === 'object' && o !== null ? { value: o.value, label: o.label } : { value: o, label: o }))
}

function credsFor(p) {
  const slug = p.credentialType
  if (!slug) return credentials.value
  return credentials.value.filter((c) => c.type_slug === slug)
}

function defaultFor(p) {
  return credsFor(p).find((c) => c.is_default) || null
}

function textValue(p) {
  const v = parameters.value[p.key]
  if (v === undefined || v === null) return ''
  return typeof v === 'string' ? v : JSON.stringify(v, null, 2)
}

function jsonOk(p) {
  const v = parameters.value[p.key]
  if (typeof v !== 'string' || v.trim() === '') return null
  try {
    JSON.parse(v)
    return true
  } catch (e) {
    return false
  }
}
</script>

<template>
  <aside class="settings">
    <div class="settings-head">
      <input :value="displayName" class="settings-title-input" placeholder="Nama node" @input="rename" />
      <span class="badge badge-muted">{{ meta.type }}</span>
    </div>
    <p v-if="meta.description" class="settings-desc">{{ meta.description }}</p>

    <!-- Paket 2: validasi live + tombol Coba Node -->
    <div v-if="meta.type && !meta.isTrigger" class="validate-box">
      <div class="validate-row">
        <span
          class="dot"
          :class="{ red: validationResult.level === 'red', green: validationResult.level === 'green', yellow: validationResult.level === 'yellow' }"
        ></span>
        <span v-if="validationResult.level === 'red'" class="validate-text err">{{ validationResult.problems[0] }}</span>
        <span v-else-if="validationResult.level === 'yellow'" class="validate-text warn">{{ validationResult.problems[0] }}</span>
        <span v-else class="validate-text ok">Semua isian lengkap ✓</span>
      </div>
      <button class="btn-test" :disabled="testing || validationResult.level === 'red'" @click="runTest">
        {{ testing ? 'Menguji…' : '▶ Coba Node' }}
      </button>
      <div v-if="testResult && testResult.ok" class="test-result ok-box">
        <div class="result-title">Hasil:</div>
        <pre class="mono small">{{ JSON.stringify(testResult.output, null, 2) }}</pre>
      </div>
      <div v-else-if="testResult && !testResult.ok" class="test-result err-box">
        <div class="result-title">Gagal:</div>
        <div class="small">{{ testResult.error }}</div>
      </div>
    </div>

    <div v-if="node.data?.nodeType === 'webhook' || node.data?.nodeType === 'manual_trigger'" class="settings-info">
      <div v-if="node.data?.nodeType === 'webhook'">
        <div class="field">
          <label>URL Webhook</label>
          <div class="code-line">
            <span class="code">{{ (parameters.path || '').length ? '/webhook/' + parameters.path : '/webhook/[path-belum-diisi]' }}</span>
          </div>
        </div>
      </div>
      <p v-else class="settings-note">
        Node pemicu. Jalankan workflow dari tombol <strong>Execute</strong> di toolbar, lewat jadwal, atau via webhook.
      </p>
    </div>

    <template v-for="p in meta.parameters" :key="p.key">
      <div v-if="p.type === 'text' || p.type === 'string'" class="field">
        <label>{{ p.label }}<span v-if="p.required" class="req"> *</span></label>
        <input
          :value="textValue(p)"
          :placeholder="p.placeholder || ''"
          @input="setParam(p.key, $event.target.value)"
        />
        <div v-if="p.description" class="field-hint">{{ p.description }}</div>
      </div>

      <div v-else-if="p.type === 'number'" class="field">
        <label>{{ p.label }}<span v-if="p.required" class="req"> *</span></label>
        <input
          type="number"
          :value="textValue(p)"
          :placeholder="p.placeholder || ''"
          @input="setParam(p.key, $event.target.value)"
        />
        <div v-if="p.description" class="field-hint">{{ p.description }}</div>
      </div>

      <div v-else-if="p.type === 'password'" class="field">
        <label>{{ p.label }}<span v-if="p.required" class="req"> *</span></label>
        <input
          type="password"
          :value="textValue(p)"
          :placeholder="p.placeholder || '••••••••'"
          autocomplete="new-password"
          @input="setParam(p.key, $event.target.value)"
        />
        <div v-if="p.description" class="field-hint">{{ p.description }}</div>
      </div>

      <div v-else-if="p.type === 'select'" class="field">
        <label>{{ p.label }}<span v-if="p.required" class="req"> *</span></label>
        <select :value="parameters[p.key] ?? ''" @change="setParam(p.key, $event.target.value)">
          <option v-if="!parameters[p.key]" value="" disabled>{{ p.placeholder || 'Pilih…' }}</option>
          <option v-for="opt in listFor(p)" :key="String(opt.value)" :value="opt.value">{{ opt.label }}</option>
        </select>
        <div v-if="p.description" class="field-hint">{{ p.description }}</div>
      </div>

      <div v-else-if="p.type === 'textarea' || p.type === 'code'" class="field">
        <label>{{ p.label }}<span v-if="p.required" class="req"> *</span></label>
        <textarea
          :class="{ mono: p.type === 'code' }"
          :value="textValue(p)"
          :placeholder="p.placeholder || ''"
          rows="4"
          @input="setParam(p.key, $event.target.value)"
        ></textarea>
        <div v-if="p.description" class="field-hint">{{ p.description }}</div>
      </div>

      <div v-else-if="p.type === 'json' || p.type === 'conditions'" class="field">
        <label>{{ p.label }}<span v-if="p.required" class="req"> *</span></label>
        <textarea
          class="mono"
          :value="textValue(p)"
          :placeholder="p.placeholder || '{}'"
          rows="5"
          spellcheck="false"
          @input="setParam(p.key, $event.target.value)"
        ></textarea>
        <div v-if="jsonOk(p) === false" class="field-hint err">JSON tidak valid.</div>
        <div v-else-if="p.description" class="field-hint">{{ p.description }}</div>
      </div>

      <div v-else-if="p.type === 'boolean'" class="field toggle-field">
        <label>{{ p.label }}</label>
        <button class="toggle" :class="{ on: !!parameters[p.key] }" @click="setParam(p.key, !parameters[p.key])">
          <span class="toggle-knob"></span>
        </button>
        <div v-if="p.description" class="field-hint">{{ p.description }}</div>
      </div>

      <div v-else-if="p.type === 'credentials'" class="field">
        <label>{{ p.label }}<span v-if="p.required" class="req"> *</span></label>
        <select :value="parameters[p.key] || ''" @change="setParam(p.key, $event.target.value)">
          <option value="" disabled>Pilih credential…</option>
          <option v-for="c in credsFor(p)" :key="c.id" :value="String(c.id)">{{ c.name }}{{ c.is_default ? ' (default)' : '' }}</option>
        </select>
        <div v-if="!parameters[p.key] && defaultFor(p)" class="field-hint" style="color: var(--green, #25c290)">
          ✓ Otomatis memakai default: {{ defaultFor(p).name }}
        </div>
        <div v-if="p.description" class="field-hint">{{ p.description }}</div>
        <div class="field-hint">
          <router-link to="/credentials" class="link">Kelola credential →</router-link>
        </div>
      </div>
    </template>

    <!-- Paket 2: panel contoh & cara pakai -->
    <div v-if="examples.length" class="examples-box">
      <button class="examples-toggle" @click="showExamples = !showExamples">
        {{ showExamples ? '▾' : '▸' }} Contoh &amp; Cara Pakai ({{ examples.length }})
      </button>
      <template v-if="showExamples">
        <select v-model.number="activeExampleIdx" class="example-select">
          <option v-for="(ex, i) in examples" :key="i" :value="i">{{ ex.title || 'Contoh ' + (i + 1) }}</option>
        </select>
        <div v-if="activeExample" class="example-body">
          <div class="ex-label">Sample input (dapat diedit):</div>
          <textarea
            v-model="sampleInputText"
            class="mono"
            rows="4"
            spellcheck="false"
            placeholder="{}"
          ></textarea>
          <div class="ex-label">Parameter contoh:</div>
          <pre class="mono small ex-params">{{ JSON.stringify(activeExample.params, null, 2) }}</pre>
          <button class="btn-fill" @click="applyExample(activeExample)">Isi Otomatis Contoh</button>
        </div>
      </template>
    </div>

    <div v-if="node.data?.nodeType === 'schedule_trigger'" class="settings-info">
      <p class="settings-note">
        Jadwal otomatis terdaftar saat workflow <strong>disimpan</strong>. Cron dijalankan oleh server (lihat status di menu Pengaturan).
      </p>
    </div>

    <div v-if="node.data?.nodeType === 'if' || node.data?.nodeType === 'switch' || node.data?.nodeType === 'filter'" class="settings-info">
      <p class="settings-note">
        <template v-if="node.data?.nodeType !== 'filter'">Node ini punya beberapa output. Hubungkan kabel dari masing-masing output (kanan) ke node tujuan.</template>
        <template v-else>Gunakan operator <span class="code">contains</span>, <span class="code">&gt;</span>, <span class="code">empty</span>, dll. Referensi nilai dengan <span class="code">{{ '{{' }} $json.namaField }}</span>.</template>
      </p>
    </div>
  </aside>
</template>

<style scoped>
.settings {
  width: 300px; flex-shrink: 0; background: var(--bg-secondary);
  border-left: 1px solid var(--border); overflow-y: auto;
}
.settings-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 14px 16px; border-bottom: 1px solid var(--border); }
.settings-title-input {
  background: transparent; border: 1px solid transparent; font-weight: 600; font-size: 14px; min-width: 0; flex: 1;
}
.settings-title-input:hover, .settings-title-input:focus { border-color: var(--border); background: var(--bg); }
.settings-desc { padding: 12px 16px 0; color: var(--text-dim); font-size: 12px; }
.settings > .field, .settings-info { padding: 12px 16px; border-top: 1px solid var(--border); }
.settings-info { color: var(--text-dim); font-size: 12px; }
.field-hint { color: var(--text-faint); font-size: 11px; margin-top: 4px; }
.field-hint.err { color: var(--danger, #ef6b6b); }
.settings-note { margin: 0; }
.code-line { display: flex; align-items: center; gap: 6px; }
.req { color: var(--danger, #ef6b6b); }
.link { color: var(--accent); text-decoration: none; }
textarea.mono { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; }
.toggle-field { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.toggle-field label { margin: 0; }
.toggle-field .field-hint { width: 100%; }
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

/* Paket 2 */
.validate-box { padding: 12px 16px; border-top: 1px solid var(--border); }
.validate-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; background: var(--text-faint); }
.dot.red { background: var(--danger, #ef6b6b); }
.dot.green { background: var(--green, #25c290); }
.dot.yellow { background: #e8b93c; }
.validate-text { font-size: 12px; }
.validate-text.err { color: var(--danger, #ef6b6b); }
.validate-text.warn { color: #d9a92f; }
.validate-text.ok { color: var(--green, #25c290); }
.btn-test {
  width: 100%; padding: 7px 0; border-radius: 6px; cursor: pointer;
  border: 1px solid var(--accent); background: var(--accent); color: #fff; font-weight: 600; font-size: 13px;
}
.btn-test:disabled { opacity: .5; cursor: not-allowed; }
.btn-test:not(:disabled):hover { filter: brightness(1.1); }
.test-result { margin-top: 8px; padding: 8px; border-radius: 6px; font-size: 12px; }
.test-result.ok-box { background: rgba(37, 194, 144, .08); border: 1px solid rgba(37, 194, 144, .35); }
.test-result.err-box { background: rgba(239, 107, 107, .08); border: 1px solid rgba(239, 107, 107, .35); color: var(--danger, #ef6b6b); }
.result-title { font-weight: 600; margin-bottom: 4px; }
.mono.small, pre.small { font-size: 11px; max-height: 180px; overflow: auto; margin: 0; white-space: pre-wrap; word-break: break-word; }

.examples-box { padding: 12px 16px; border-top: 1px solid var(--border); font-size: 12px; }
.examples-toggle {
  width: 100%; text-align: left; background: transparent; border: none; cursor: pointer;
  color: var(--accent); font-size: 12px; font-weight: 600; padding: 0;
}
.example-select { width: 100%; margin-top: 8px; }
.ex-label { color: var(--text-dim); margin: 10px 0 4px; font-size: 11px; }
.example-body textarea { width: 100%; box-sizing: border-box; }
.ex-params { background: var(--bg); border: 1px solid var(--border); border-radius: 4px; padding: 6px; }
.btn-fill {
  margin-top: 8px; width: 100%; padding: 6px 0; border-radius: 6px; cursor: pointer;
  border: 1px solid var(--border); background: var(--bg); color: var(--accent); font-weight: 600; font-size: 12px;
}
.btn-fill:hover { border-color: var(--accent); }
</style>
