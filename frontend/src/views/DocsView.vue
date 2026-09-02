<script setup>
import { ref, computed } from 'vue'
import { useNodesStore } from '../stores/nodes'
import { useUiStore } from '../stores/ui'
import { t } from '../i18n'
import { docsSections } from '../docs-content'

const ui = useUiStore()
const nodesStore = useNodesStore()

if (!nodesStore.loaded) nodesStore.load().catch(() => {})

const activeSection = ref('quickstart')
const search = ref('')
const expandedNode = ref(null)
const copied = ref(false)

const sections = computed(() => docsSections)

function sectionTitle(s) {
  return s.title[ui.lang] || s.title.en
}

async function copyCode(text) {
  try {
    await navigator.clipboard.writeText(text)
    copied.value = true
    setTimeout(() => (copied.value = false), 1500)
  } catch (e) {}
}

// ============ Referensi node dari registry (otomatis sinkron) ============
const nodeRefs = computed(() => {
  const q = search.value.trim().toLowerCase()
  return nodesStore.registry
    .filter((n) => !q || n.name.toLowerCase().includes(q) || n.type.toLowerCase().includes(q))
    .map((n) => ({
      type: n.type,
      name: n.name,
      category: n.category,
      description: n.description,
      isTrigger: n.isTrigger,
      outputs: n.outputs || [],
      params: (n.parameters || []).map((p) => ({
        key: p.key,
        label: p.label || p.key,
        type: p.type,
        description: p.description || '',
        required: !!p.required,
        default: p.default,
        options: p.options || null,
      })),
      example: n.examples?.[0] || null,
      output: n.exampleOutput || null,
      // Penjelasan pemakaian bilingual berdasarkan kategori + trigger
      usage:
        ui.lang === 'en'
          ? usageEn(n)
          : usageId(n),
    }))
})

function usageId(n) {
  const cat = (n.category || '').toLowerCase()
  if (n.isTrigger) return `Node trigger — titik awal workflow. Jalankan manual, lewat jadwal, atau event eksternal sesuai tipenya, lalu data mengalir ke node berikutnya melalui sambungan keluar.`
  if (cat === 'ai') return `Hubungkan setelah node trigger/data lain. Isi credential AI (atau andalkan credential Default), tulis prompt dengan ekspresi {{$json.*}}, lalu hasilnya tersedia sebagai field content pada item keluar untuk dipakai node berikutnya.`
  if (cat === 'integrations') return `Butuh kredensial layanan terkait (buat di menu Kredensial). Sambungkan dari node sebelumnya; node ini memanggil layanan eksternal per item input dan meneruskan hasilnya ke cabang main.`
  if (cat === 'http') return `Sambungkan dari trigger/node mana pun. Atau method, URL, header, dan body (bisa pakai ekspresi). Gunakan opsi onError=continue agar workflow tetap lanjut saat API gagal.`
  if (cat === 'flow') return `Mengatur alur data antar node: percabangan, penggabungan, loop, atau penghentian. Hubungkan tiap output ke tujuan yang berbeda sesuai logika yang diinginkan.`
  return `Taruh di antara dua node: data masuk dari sambungan kiri, diproses sesuai parameter di bawah, lalu hasil diteruskan ke sambungan kanan untuk dipakai node berikutnya.`
}

function usageEn(n) {
  const cat = (n.category || '').toLowerCase()
  if (n.isTrigger) return `A trigger node — the starting point of a workflow. It runs manually, on a schedule, or from an external event depending on its type, then pushes data downstream through its outgoing connection.`
  if (cat === 'ai') return `Connect after a trigger/data node. Fill in the AI credential (or rely on your Default credential), write prompts using {{$json.*}} expressions, and the result becomes a content field on each outgoing item for the next nodes to consume.`
  if (cat === 'integrations') return `Requires credentials for the service (create one under Credentials). Wire it after any previous node; it calls the external service once per input item and forwards results through its main branch.`
  if (cat === 'http') return `Connect from any trigger or node. Set method, URL, headers and body (expressions supported). Use onError=continue to keep the workflow running when the remote API fails.`
  if (cat === 'flow') return `Controls how data moves between nodes: branching, merging, looping, or stopping. Connect each output to a different target based on your logic.`
  return `Place between two nodes: data enters from the left connection, is processed according to the parameters below, then flows out of the right connection for the next node.`
}

function toggleNode(type) {
  expandedNode.value = expandedNode.value === type ? null : type
}
</script>

<template>
  <div class="page docs-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">{{ ui.lang === 'en' ? 'Documentation' : 'Dokumentasi' }}</h1>
        <p class="page-sub">{{ ui.lang === 'en' ? 'Guides, usage and code examples.' : 'Panduan, cara penggunaan, dan contoh kode.' }}</p>
      </div>
      <button class="btn btn-sm" @click="ui.toggleLang()">{{ ui.lang === 'id' ? 'EN 🇬🇧' : 'ID 🇮🇩' }}</button>
    </div>

    <div v-for="s in sections" :key="s.id" class="card doc-section">
      <button class="doc-section-head" @click="activeSection = activeSection === s.id ? '' : s.id">
        <span>{{ activeSection === s.id ? '▾' : '▸' }} {{ sectionTitle(s) }}</span>
      </button>

      <div v-if="activeSection === s.id" class="doc-body">
        <template v-for="(b, i) in s.blocks" :key="i">
          <p v-if="b.type === 'p'" class="doc-p">{{ b[ui.lang] ?? b.en }}</p>
          <div v-else-if="b.type === 'list'" class="doc-list">
            <div v-for="(item, j) in (b.items[ui.lang] || b.items.en)" :key="j" class="doc-list-item">{{ j + 1 }}. {{ item }}</div>
          </div>
          <div v-else-if="b.type === 'note'" class="doc-note">💡 {{ b[ui.lang] ?? b.en }}</div>
          <pre v-else-if="b.type === 'code'" class="doc-code"><code>{{ b.code }}</code></pre>
        </template>
        <button
          v-if="s.blocks.some((b) => b.type === 'code')"
          class="btn btn-sm copy-btn"
          @click="copyCode(s.blocks.filter((b) => b.type === 'code').map((b) => b.code).join('\n\n'))"
        >{{ copied ? '✓ Copied' : '⧉ Copy' }}</button>
      </div>
    </div>

    <!-- ===== Referensi node detail ===== -->
    <div class="card doc-section">
      <button class="doc-section-head" @click="activeSection = activeSection === 'noderef' ? '' : 'noderef'">
        <span>{{ activeSection === 'noderef' ? '▾' : '▸' }} {{ ui.lang === 'en' ? 'Node Reference' : 'Referensi Node' }} ({{ nodeRefs.length }})</span>
      </button>
      <div v-if="activeSection === 'noderef'" class="doc-body">
        <input v-model="search" class="docs-search" :placeholder="t('common.search')" />
        <div v-for="n in nodeRefs" :key="n.type" class="node-ref">
          <button class="node-ref-head" @click="toggleNode(n.type)">
            <span class="badge badge-blue">{{ n.category }}</span>
            <strong>{{ n.name }}</strong>
            <span class="faint mono-inline">{{ n.type }}</span>
            <span v-if="n.isTrigger" class="badge badge-warn">{{ ui.lang === 'en' ? 'trigger' : 'pemicu' }}</span>
          </button>

          <div v-if="expandedNode === n.type" class="node-ref-body">
            <p class="doc-p"><strong>{{ n.name }}</strong> — {{ n.description }}</p>
            <p class="doc-p usage">{{ n.usage }}</p>

            <!-- Parameter -->
            <div v-if="n.params.length" class="sub-title">{{ ui.lang === 'en' ? 'Parameters' : 'Parameter' }}</div>
            <table v-if="n.params.length" class="param-table">
              <thead><tr><th>{{ ui.lang === 'en' ? 'Key' : 'Kunci' }}</th><th>{{ ui.lang === 'en' ? 'Type' : 'Tipe' }}</th><th>{{ ui.lang === 'en' ? 'Description' : 'Deskripsi' }}</th></tr></thead>
              <tbody>
                <tr v-for="p in n.params" :key="p.key">
                  <td><code class="mono-inline">{{ p.key }}</code><span v-if="p.required" class="req">*</span></td>
                  <td class="faint">{{ p.type }}</td>
                  <td>{{ p.description || p.label }}<span v-if="p.default !== undefined && p.default !== '' && p.default !== null" class="faint"> ({{ ui.lang === 'en' ? 'default' : 'bawaan' }}: {{ String(p.default) }})</span></td>
                </tr>
              </tbody>
            </table>

            <!-- Contoh -->
            <template v-if="n.example">
              <div class="sub-title">{{ n.example.title }}</div>
              <div v-if="Object.keys(n.example.input || {}).length" class="example-block">
                <div class="ex-label">{{ ui.lang === 'en' ? 'Sample input' : 'Sample input' }}</div>
                <pre class="doc-code"><code>{{ JSON.stringify(n.example.input, null, 2) }}</code></pre>
              </div>
              <div class="example-block">
                <div class="ex-label">{{ ui.lang === 'en' ? 'Example parameters' : 'Contoh parameter' }}</div>
                <pre class="doc-code"><code>{{ JSON.stringify(n.example.params, null, 2) }}</code></pre>
                <button class="btn btn-sm copy-btn" @click="copyCode(JSON.stringify(n.example.params, null, 2))">⧉ Copy</button>
              </div>
              <div class="faint small-text">{{ ui.lang === 'en'
                ? 'Paste these values into the node settings panel, then press "Try Node" to test safely.'
                : 'Tempel nilai ini ke panel settings node, lalu tekan "Coba Node" untuk menguji dengan aman.' }}</div>
            </template>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.docs-page { max-width: 900px; }
.doc-section { margin-bottom: 10px; overflow: hidden; }
.doc-section-head {
  width: 100%; text-align: left; background: transparent; border: none;
  color: var(--text); font-size: 14px; font-weight: 600; padding: 14px 16px; cursor: pointer;
}
.doc-body { padding: 4px 16px 16px; border-top: 1px solid var(--border); }
.doc-p { color: var(--text-dim); font-size: 13px; margin: 12px 0; }
.doc-p.usage { color: var(--text); }
.doc-list-item { color: var(--text-dim); font-size: 13px; margin-bottom: 6px; }
.doc-note {
  background: rgba(77, 159, 255, .08); border: 1px solid rgba(77, 159, 255, .25);
  border-radius: var(--radius); padding: 10px 12px; color: var(--text-dim); font-size: 12px; margin: 10px 0;
}
.doc-code {
  background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 12px; font-family: ui-monospace, Consolas, monospace; font-size: 12px;
  overflow-x: auto; white-space: pre-wrap; word-break: break-word; margin: 6px 0 10px;
}
.copy-btn { margin-top: 2px; }
.docs-search { width: 100%; box-sizing: border-box; margin-bottom: 10px; }
.node-ref { border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 6px; }
.node-ref-head {
  width: 100%; display: flex; align-items: center; gap: 8px; text-align: left; flex-wrap: wrap;
  background: transparent; border: none; color: var(--text); padding: 8px 10px; cursor: pointer;
}
.node-ref-head:hover { background: var(--panel-hover); }
.node-ref-body { padding: 0 12px 12px; border-top: 1px dashed var(--border); }
.sub-title { font-weight: 600; font-size: 12px; margin: 14px 0 6px; color: var(--accent); }
.param-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 8px; }
.param-table th, .param-table td { text-align: left; padding: 5px 8px; border-bottom: 1px solid var(--border); vertical-align: top; }
.param-table th { color: var(--text-dim); font-weight: 500; }
.req { color: var(--red); margin-left: 2px; }
.mono-inline { font-family: ui-monospace, Consolas, monospace; font-size: 11px; }
.small-text { font-size: 11px; margin-bottom: 8px; }
.ex-label { font-size: 11px; color: var(--blue); margin-top: 10px; font-weight: 600; }
</style>
