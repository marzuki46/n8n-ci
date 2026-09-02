<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../api'
import { useWorkflowStore } from '../stores/workflow'

const route = useRoute()
const router = useRouter()
const workflowStore = useWorkflowStore()
const execution = ref(null)
const loading = ref(true)

const workflowName = computed(() => {
  if (!execution.value) return ''
  const wf = workflowStore.list.find((w) => w.id === execution.value.workflow_id)
  return wf ? wf.name : `Workflow #${execution.value.workflow_id}`
})

onMounted(async () => {
  await workflowStore.load().catch(() => {})
  try {
    const res = await api.get(`/executions/${route.params.id}`)
    execution.value = res.data
  } catch (e) {
    alert(e.message)
  } finally {
    loading.value = false
  }
})

const statusClass = computed(() => {
  if (!execution.value) return 'badge-muted'
  const map = { success: 'badge-success', error: 'badge-error', running: 'badge-warn' }
  return map[execution.value.status] || 'badge-muted'
})

function nodeStatusClass(s) {
  const map = { success: 'badge-success', error: 'badge-error', running: 'badge-warn', skipped: 'badge-muted' }
  return map[s] || 'badge-muted'
}

function prettyJson(obj) {
  try {
    return JSON.stringify(obj, null, 2)
  } catch (e) {
    return String(obj)
  }
}

const replaying = ref(false)

const firstErrorNodeId = computed(() => {
  const nodes = execution.value?.nodes || []
  const bad = nodes.find((n) => n.status === 'error')
  return bad ? bad.node_id : ''
})

async function replay() {
  if (!execution.value) return
  const msg = firstErrorNodeId.value
    ? `Replay mulai dari node "${firstErrorNodeId.value}"?`
    : 'Replay seluruh eksekusi?'
  if (!confirm(msg)) return
  replaying.value = true
  try {
    const res = await api.post(`/executions/${execution.value.id}/replay`, {
      from_node: firstErrorNodeId.value || undefined,
    })
    const newId = res.data?.execution_id
    if (newId) router.push(`/executions/${newId}`)
    else await load()
  } catch (e) {
    alert(e.message)
  } finally {
    replaying.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title">Execution Detail</h1>
        <p class="page-sub">
          <router-link :to="{ name: 'executions' }">← Kembali</router-link>
        </p>
      </div>
      <div class="replay-wrap">
        <button
          v-if="execution"
          class="btn btn-sm btn-primary"
          :disabled="replaying || execution.status === 'running'"
          title="Ulangi eksekusi mulai dari node yang gagal (memakai data tercatat)"
          @click="replay"
        >
          {{ replaying ? 'Mereplay…' : (firstErrorNodeId ? '↩ Replay dari node error' : '↩ Replay') }}
        </button>
        <button v-if="execution" class="btn btn-sm" @click="router.push({ name: 'workflow-editor', params: { id: execution.workflow_id } })">Buka Workflow →</button>
      </div>
    </div>

    <div v-if="loading" class="empty-state">Memuat…</div>

    <template v-else-if="execution">
      <div class="card mb-16">
        <div class="flex-between">
          <div class="flex gap-8" style="align-items: center">
            <span class="code">#{{ execution.id }}</span>
            <h3 style="margin: 0">{{ workflowName }}</h3>
            <span class="badge" :class="statusClass">{{ execution.status }}</span>
          </div>
          <span class="muted">{{ execution.started_at }} · {{ execution.trigger_type }}</span>
        </div>
        <div class="mt-8 flex gap-16 muted">
          <span>Durasi: {{ execution.duration ?? '–' }} ms</span>
          <span v-if="execution.error_message" class="red-text">{{ execution.error_message }}</span>
        </div>
      </div>

      <div class="exec-grid">
        <div class="card">
          <h3>Node Executions</h3>
          <table class="table">
            <thead><tr><th>Node</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="n in execution.nodes" :key="n.id">
                <td>
                  <div>{{ n.name }}</div>
                  <div class="faint">{{ n.node_type }}</div>
                </td>
                <td><span class="badge" :class="nodeStatusClass(n.status)">{{ n.status }}</span></td>
                <td>
                  <details>
                    <summary class="btn btn-sm">Lihat Output</summary>
                    <pre class="output-pre">{{ prettyJson(n.output_data) }}</pre>
                  </details>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="execution.errors?.length" class="card">
          <h3>Errors</h3>
          <div v-for="(err, i) in execution.errors" :key="i" class="error-box">
            <div class="code">{{ err.message }}</div>
            <details>
              <summary class="faint">Trace</summary>
              <pre class="output-pre">{{ err.trace }}</pre>
            </details>
          </div>
        </div>

        <div v-if="execution.logs?.length" class="card">
          <h3>Logs</h3>
          <div v-for="(log, i) in execution.logs" :key="i" class="log-line">
            <span class="code">{{ log.level }}</span> {{ log.message }}
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.exec-grid { display: grid; grid-template-columns: 1fr; gap: 16px; }
.red-text { color: var(--red); }
.output-pre {
  background: var(--bg); border: 1px solid var(--border); border-radius: 6px;
  padding: 10px; font-size: 12px; max-height: 300px; overflow: auto; white-space: pre-wrap; word-break: break-word;
}
.error-box { background: rgba(239,107,107,.06); border: 1px solid rgba(239,107,107,.3); border-radius: 6px; padding: 10px; margin-bottom: 10px; }
.log-line { padding: 4px 0; border-bottom: 1px dashed var(--border); font-size: 12px; }
summary { cursor: pointer; }
</style>

<style scoped>.replay-wrap { display: flex; gap: 8px; }</style>
