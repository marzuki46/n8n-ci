<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { VueFlow, useVueFlow, getSmoothStepPath } from '@vue-flow/core'
import { Background, BackgroundVariant } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import { MiniMap } from '@vue-flow/minimap'
import api from '../api'
import { useNodesStore } from '../stores/nodes'
import FlowNode from '../components/FlowNode.vue'
import NodePalette from '../components/NodePalette.vue'
import NodeSettingsPanel from '../components/NodeSettingsPanel.vue'

const route = useRoute()
const router = useRouter()
const nodesStore = useNodesStore()
const workflowId = Number(route.params.id)

const nodes = ref([])
const edges = ref([])
const wf = ref({ name: '', active: false, version: 1 })
const loading = ref(true)
const saving = ref(false)
const dirty = ref(false)
const toast = ref('')
const toastType = ref('success')

const nodeTypes = { flow: FlowNode }

const {
  screenToFlowCoordinate,
  addNodes,
  addEdges,
  removeNodes,
  fitView,
} = useVueFlow()

const selectedNode = ref(null)
let suppressPaneClick = false

/* ── Live execution state ── */
const execution = ref(null)
const showResults = ref(false)
const executing = ref(false)       // legacy compat (true while polling)
const execControl = ref(null)      // 'pause' | null
const pollTimer = ref(null)

const nodeStates = computed(() => {
  const map = {}
  for (const n of nodes.value) {
    map[n.id] = execution.value?.node_states?.[n.id] || null
  }
  return map
})

// Compute activeEdgeSet: edges where both source + target are completed (success/error/skipped)
const activeEdgeSet = computed(() => {
  if (!execution.value) return new Set()
  const s = nodeStates.value
  const active = new Set()
  for (const e of edges.value) {
    const src = s[e.source]
    const tgt = s[e.target]
    if (src && tgt && src !== 'pending' && tgt !== 'pending') {
      active.add(e.id)
    }
  }
  return active
})

// Inject exec state directly into node.data (no object replacement)
watch(execution, (exec) => {
  for (const n of nodes.value) {
    const nd = exec?.nodes?.find(x => x.node_id === n.id)
    n.data._execState = nd?.status || (exec && !['queued','waiting','running'].includes(exec.status) ? 'pending' : null)
    n.data._execDuration = nd?.duration_ms ?? null
  }
  // Force VueFlow reactivity (shallow copy)
  nodes.value = [...nodes.value]
}, { deep: true })

// Progress
const execProgress = computed(() => {
  if (!execution.value || !execution.value.nodes) return null
  const total = nodes.value.length
  const done = execution.value.nodes.filter(n =>
    ['success', 'error', 'skipped'].includes(n.status)
  ).length
  return { done, total }
})

// Controls visibility
const canPause = computed(() =>
  execution.value && ['running', 'waiting'].includes(execution.value.status)
)
const canResume = computed(() => execution.value?.status === 'paused')
const canStop = computed(() =>
  execution.value && ['running', 'paused', 'waiting'].includes(execution.value.status)
)
const isTerminal = computed(() =>
  !execution.value || ['success', 'error', 'stopped', 'cancelled', 'timeout'].includes(execution.value.status)
)

onMounted(async () => {
  await nodesStore.load().catch(() => {})
  await loadWorkflow()
  loadPublication()
})

onUnmounted(() => {
  clearPolling()
})

function clearPolling() {
  if (pollTimer.value) {
    clearInterval(pollTimer.value)
    pollTimer.value = null
  }
}

async function loadWorkflow() {
  loading.value = true
  try {
    const res = await api.get(`/workflows/${workflowId}`)
    wf.value = res.data.workflow
    const metaMap = nodesStore.byType

    nodes.value = (res.data.nodes || []).map((n) => {
      const meta = metaMap[n.node_type] || {}
      let params = {}
      try {
        params = typeof n.parameters_json === 'string' ? JSON.parse(n.parameters_json) : n.parameters_json || {}
      } catch (e) {}
      return {
        id: n.node_id,
        type: 'flow',
        position: { x: n.position_x || 0, y: n.position_y || 0 },
        data: {
          name: n.name,
          nodeType: n.node_type,
          parameters: params,
          category: meta.category,
          icon: meta.icon,
          inputs: meta.isTrigger ? 0 : 1,
          outputs: meta.outputs || 1,
          subtitle: '',
        },
      }
    })

    edges.value = (res.data.connections || []).map((c) => ({
      id: `e-${c.source_node}-${c.target_node}-${c.source_output}-${c.target_input}`,
      source: c.source_node,
      target: c.target_node,
      sourceHandle: c.source_output === 'main' ? 'out-1' : `out-${c.source_output.replace('out-', '')}`,
      targetHandle: c.target_input === 'main' ? 'in-1' : `in-${c.target_input.replace('in-', '')}`,
      type: 'smoothstep',
    }))
  } catch (e) {
    showToast(e.message, 'error')
  } finally {
    loading.value = false
    requestAnimationFrame(() => fitView({ padding: 0.2, duration: 200 }))
  }
}

function onDrop(e) {
  const raw = e.dataTransfer.getData('application/x-n8n-node')
  if (!raw) return
  const meta = JSON.parse(raw)
  const position = screenToFlowCoordinate({ x: e.clientX, y: e.clientY })
  const id = `${meta.type}-${Date.now().toString(36)}`
  const node = {
    id,
    type: 'flow',
    position,
    data: {
      name: meta.name,
      nodeType: meta.type,
      parameters: defaultParams(meta),
      category: meta.category,
      icon: meta.icon,
      inputs: meta.isTrigger ? 0 : 1,
      outputs: meta.outputs || 1,
      subtitle: '',
    },
  }
  addNodes([node])
  suppressPaneClick = true
  selectedNode.value = node
  dirty.value = true
}

function defaultParams(meta) {
  const params = {}
  for (const p of meta.parameters || []) {
    if (p.default !== undefined && p.default !== null) params[p.key] = p.default
  }
  return params
}

function onConnect(conn) {
  addEdges([{ ...conn, type: 'smoothstep' }])
  dirty.value = true
}

function onNodeClickHandler({ node }) {
  selectedNode.value = nodes.value.find((n) => n.id === node.id) || null
}

function onPaneClickHandler() {
  if (suppressPaneClick) {
    suppressPaneClick = false
    return
  }
  selectedNode.value = null
}

function onNodeDragStartHandler() {
  dirty.value = true
}

function updateSelectedNode(patch) {
  if (!selectedNode.value) return
  if (patch.parameters) selectedNode.value.data.parameters = patch.parameters
  if (patch.name) selectedNode.value.data.name = patch.name
  if ('testStatus' in patch && selectedNode.value.data) {
    selectedNode.value.data.testStatus = patch.testStatus
    return
  }
  dirty.value = true
}

async function saveWorkflow() {
  saving.value = true
  try {
    const payload = {
      name: wf.value.name,
      description: wf.value.description,
      active: wf.value.active,
      time_saved_minutes: Number(wf.value.time_saved_minutes) || 0,
      nodes: nodes.value.map((n) => ({
        id: n.id,
        type: n.data.nodeType,
        name: n.data.name,
        position: n.position,
        data: { name: n.data.name, parameters: n.data.parameters },
      })),
      connections: edges.value.map((e) => ({
        source: e.source,
        target: e.target,
        sourceHandle: e.sourceHandle || 'out-1',
        targetHandle: e.targetHandle || 'in-1',
      })),
    }
    await api.post(`/workflows/${workflowId}/save`, payload)
    dirty.value = false
    showToast('Draft disimpan')
  } catch (e) {
    showToast(e.message, 'error')
  } finally {
    saving.value = false
  }
}

const pubState = ref({ published: false, published_at: null, has_draft_changes: false })
const publishing = ref(false)

async function loadPublication() {
  try {
    pubState.value = (await api.get(`/workflows/${workflowId}/publication`)).data || pubState.value
  } catch (e) { /* abaikan */ }
}

async function publishWorkflow() {
  publishing.value = true
  try {
    await api.post(`/workflows/${workflowId}/publish`)
    await loadPublication()
    showToast('Workflow dipublikasikan')
  } catch (e) {
    showToast(e.message, 'error')
  } finally {
    publishing.value = false
  }
}

/* ── Live execution ── */

async function executeWorkflow() {
  executing.value = true
  execution.value = null
  showResults.value = true
  execControl.value = null
  try {
    // Kirim ke queue (mode background)
    const res = await api.post(`/workflows/${workflowId}/execute`, { queued: 1 })
    const execId = res.data.execution_id
    execution.value = {
      execution_id: execId,
      status: 'queued',
      nodes: [],
    }
    // Mulai polling
    startPolling(execId)
  } catch (e) {
    executing.value = false
    showToast(e.message, 'error')
  }
}

function startPolling(execId) {
  clearPolling()
  pollTimer.value = setInterval(async () => {
    try {
      const res = await api.get(`/executions/${execId}`)
      execution.value = res.data

      // Update node subtitle di canvas
      if (res.data.nodes) {
        updateNodeSubtitles(res.data.nodes)
      }

      if (['success', 'error', 'stopped', 'cancelled', 'timeout'].includes(res.data.status)) {
        clearPolling()
        executing.value = false
        execControl.value = null
        const msg = res.data.status === 'success' ? 'Eksekusi sukses' :
                    res.data.status === 'stopped' ? 'Eksekusi dihentikan' :
                    'Eksekusi error'
        showToast(msg, ['success', 'stopped'].includes(res.data.status) ? 'success' : 'error')
      }
    } catch (e) {
      // 404 = belum ada di DB, lanjut polling
    }
  }, 800)
}

function updateNodeSubtitles(execNodes) {
  for (const n of nodes.value) {
    const nd = execNodes.find(x => x.node_id === n.id)
    if (nd) {
      const st = nd.status || 'pending'
      const dur = nd.duration_ms != null ? ` (${nd.duration_ms}ms)` : ''
      n.data.subtitle = `${st}${dur}`
    } else if (execution.value && execution.value.status !== 'queued') {
      n.data.subtitle = 'menunggu…'
    }
  }
}

async function pauseExecution() {
  if (!execution.value) return
  try {
    await api.post(`/executions/${execution.value.execution_id}/pause`)
    execControl.value = 'pause'
    showToast('Menjeda eksekusi…', 'info')
  } catch (e) {
    showToast(e.message, 'error')
  }
}

async function resumeExecution() {
  if (!execution.value) return
  try {
    await api.post(`/executions/${execution.value.execution_id}/resume`)
    execControl.value = null
    showToast('Melanjutkan eksekusi…', 'success')
  } catch (e) {
    showToast(e.message, 'error')
  }
}

async function stopExecution() {
  if (!execution.value) return
  try {
    await api.post(`/executions/${execution.value.execution_id}/stop`)
    execControl.value = 'stop'
    showToast('Menghentikan eksekusi…', 'error')
  } catch (e) {
    showToast(e.message, 'error')
  }
}

async function toggleActive() {
  const next = !wf.value.active
  wf.value.active = next
  await api.put(`/workflows/${workflowId}`, { active: next }).catch(() => {})
  dirty.value = true
}

function duplicateNode() {
  if (!selectedNode.value) return
  const src = selectedNode.value
  const id = `${src.data.nodeType}-${Date.now().toString(36)}`
  const node = {
    id,
    type: 'flow',
    position: { x: src.position.x + 30, y: src.position.y + 30 },
    data: { ...src.data, name: src.data.name + ' (copy)' },
  }
  addNodes([node])
  selectedNode.value = node
  dirty.value = true
}

function deleteSelected() {
  if (!selectedNode.value) return
  const id = selectedNode.value.id
  removeNodes([id])
  edges.value = edges.value.filter((e) => e.source !== id && e.target !== id)
  selectedNode.value = null
  dirty.value = true
}

function onNodesDeleteHandler({ nodes: deleted }) {
  const ids = new Set(deleted.map((n) => n.id))
  edges.value = edges.value.filter((e) => !ids.has(e.source) && !ids.has(e.target))
  if (selectedNode.value && ids.has(selectedNode.value.id)) selectedNode.value = null
  dirty.value = true
}

function closeEditor() {
  clearPolling()
  router.push({ name: 'workflows' })
}

function showToast(msg, type = 'success') {
  toast.value = msg
  toastType.value = type
  setTimeout(() => (toast.value = ''), 3500)
}

function computeEdgePath(p) {
  const [path] = getSmoothStepPath({
    sourceX: p.sourceX,
    sourceY: p.sourceY,
    targetX: p.targetX,
    targetY: p.targetY,
    borderRadius: 8,
  })
  return path
}

function handleKeydown(e) {
  if (e.key === 'Delete' && selectedNode.value) {
    deleteSelected()
  }
  if ((e.metaKey || e.ctrlKey) && e.key === 's') {
    e.preventDefault()
    saveWorkflow()
  }
}
</script>

<template>
  <div class="editor" tabindex="0" @keydown="handleKeydown">
    <div class="editor-toolbar">
      <button class="btn btn-ghost btn-sm" title="Kembali" @click="closeEditor">←</button>
      <input
        v-model="wf.name"
        class="wf-name-input"
        placeholder="Nama workflow"
        @input="dirty = true"
      />
      <span class="badge badge-muted">v{{ wf.version }}</span>
      <input
        v-model.number="wf.time_saved_minutes"
        type="number" min="0" max="10000"
        class="ts-input"
        title="Menit kerja manual yang dihemat per run"
        placeholder="menit hemat"
      />
      <span class="badge" :class="wf.active ? 'badge-success' : 'badge-muted'">
        {{ wf.active ? 'Aktif' : 'Nonaktif' }}
      </span>
      <div class="toolbar-spacer"></div>
      <span v-if="dirty" class="faint" style="font-size: 11px">● belum disimpan</span>
      <span
        class="badge"
        :class="!pubState.published ? 'badge-warn' : (pubState.has_draft_changes ? 'badge-warn' : 'badge-success')"
        :title="pubState.published ? ('Dipublish ' + pubState.published_at) : 'Belum pernah dipublish'"
      >
        {{ !pubState.published ? 'DRAFT' : (pubState.has_draft_changes ? 'PERUBAHAN DRAFT' : 'LIVE') }}
      </span>
      <button class="btn btn-sm" @click="toggleActive">{{ wf.active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
      <button class="btn btn-sm" :disabled="saving" @click="saveWorkflow">
        {{ saving ? 'Menyimpan…' : 'Save' }}
      </button>
      <button
        class="btn btn-sm"
        :class="pubState.has_draft_changes || !pubState.published ? 'btn-primary' : ''"
        :disabled="publishing"
        title="Terapkan draft ke eksekusi live"
        @click="publishWorkflow"
      >
        {{ publishing ? '…' : '🚀 Publish' }}
      </button>

      <!-- Execution controls -->
      <div class="exec-controls">
        <button
          v-if="isTerminal"
          class="btn btn-primary btn-sm"
          :disabled="executing"
          @click="executeWorkflow"
        >
          <span v-if="executing" class="spinner"></span>
          <span v-else>▶</span>
          {{ executing ? 'Menjalankan…' : 'Execute' }}
        </button>
        <template v-else>
          <button v-if="canPause" class="btn btn-sm exec-btn-pause" @click="pauseExecution" title="Jeda">⏸</button>
          <button v-if="canResume" class="btn btn-sm exec-btn-resume" @click="resumeExecution" title="Lanjutkan">▶</button>
          <button v-if="canStop" class="btn btn-sm exec-btn-stop" @click="stopExecution" title="Hentikan">⏹</button>
        </template>
      </div>
    </div>

    <div class="editor-body">
      <NodePalette />

      <div class="canvas-wrap">
        <div v-if="loading" class="editor-loading">Memuat workflow…</div>
        <VueFlow
          v-else
          v-model:nodes="nodes"
          v-model:edges="edges"
          :node-types="nodeTypes"
          :default-edge-options="{ type: 'smoothstep', animated: false }"
          :default-node-options="{ width: 180, height: 60 }"
          :snap-to-grid="true"
          :snap-grid="[8, 8]"
          :class="{ 'canvas-exec-active': !isTerminal }"
          class="canvas"
          @drop="onDrop"
          @dragover.prevent
          @connect="onConnect"
          @node-click="onNodeClickHandler"
          @pane-click="onPaneClickHandler"
          @nodes-delete="onNodesDeleteHandler"
          @node-drag-start="onNodeDragStartHandler"
        >
          <!-- Custom animated edges -->
          <template #edge-smoothstep="edgeProps">
            <svg class="animated-edge" :class="{ 'edge-active': activeEdgeSet.has(edgeProps.id) }">
              <path
                :d="computeEdgePath(edgeProps)"
                fill="none"
                :stroke="activeEdgeSet.has(edgeProps.id) ? '#25c290' : '#555'"
                :stroke-width="activeEdgeSet.has(edgeProps.id) ? 2.5 : 1.5"
                :stroke-dasharray="activeEdgeSet.has(edgeProps.id) ? '8 4' : 'none'"
                class="edge-path"
                :class="{ 'edge-animated': activeEdgeSet.has(edgeProps.id) }"
              />
            </svg>
          </template>

          <Background :variant="BackgroundVariant.Dots" gap="16" size="1" />
          <Controls position="bottom-left" />
          <MiniMap position="bottom-right" :pannable="true" :zoomable="true" />
        </VueFlow>

        <!-- Progress bar -->
        <transition name="slide-up">
          <div v-if="!isTerminal && execProgress" class="exec-progress">
            <div class="progress-text">{{ execProgress.done }} / {{ execProgress.total }} node selesai</div>
            <div class="progress-bar-track">
              <div class="progress-bar-fill" :style="{ width: ((execProgress.done / Math.max(execProgress.total, 1)) * 100) + '%' }"></div>
            </div>
          </div>
        </transition>

        <!-- Results panel -->
        <transition name="slide-up">
          <div v-if="showResults" class="results-panel">
            <div class="results-head">
              <strong>Hasil Eksekusi</strong>
              <span v-if="execution" class="badge" :class="{
                'badge-success': execution.status === 'success',
                'badge-muted': execution.status === 'stopped',
                'badge-error': execution.status === 'error',
                'badge-warn': execution.status === 'running' || execution.status === 'paused',
              }">
                {{ execution.status === 'queued' ? 'dalam antrian' :
                   execution.status === 'running' ? 'berjalan…' :
                   execution.status === 'paused' ? 'dijeda' :
                   execution.status }}
              </span>
              <button class="btn btn-ghost btn-sm" @click="showResults = false">✕</button>
            </div>
            <div v-if="execution" class="results-body">
              <div class="exec-info">
                <span class="code">#{{ execution.execution_id }}</span>
                <span v-if="execution.duration_ms" class="muted">{{ execution.duration_ms }} ms</span>
                <router-link
                  v-if="execution.execution_id && isTerminal"
                  :to="{ name: 'execution-detail', params: { id: execution.execution_id } }"
                  class="btn btn-sm"
                >Lihat Detail →</router-link>
              </div>
              <div class="node-state-grid">
                <div
                  v-for="n in nodes"
                  :key="n.id"
                  class="node-state"
                  :class="nodeStates[n.id]"
                >
                  <span class="node-state-dot" :class="'ns-' + (nodeStates[n.id] || 'pending')"></span>
                  <span>{{ n.data.name }}</span>
                  <span v-if="n.data.subtitle" class="node-state-sub">{{ n.data.subtitle }}</span>
                </div>
              </div>
            </div>
          </div>
        </transition>
      </div>

      <NodeSettingsPanel v-if="selectedNode" :node="selectedNode" @update="updateSelectedNode" />
      <aside v-else class="settings settings-empty">
        <div class="settings-empty-text">
          <div class="icon">◈</div>
          Pilih node untuk melihat pengaturannya.
        </div>
      </aside>
    </div>

    <div v-if="toast" class="toast-wrap">
      <div class="toast" :class="'toast-' + toastType">{{ toast }}</div>
    </div>
  </div>
</template>

<style scoped>
.editor {
  display: flex; flex-direction: column; height: 100%; outline: none;
}
.editor-toolbar {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 12px; background: var(--bg-secondary); border-bottom: 1px solid var(--border);
}
.wf-name-input {
  background: transparent; border: 1px solid transparent; font-size: 14px; font-weight: 600; width: 260px;
}
.wf-name-input:hover, .wf-name-input:focus { border-color: var(--border); background: var(--bg); }
.toolbar-spacer { flex: 1; }
.editor-body { display: flex; flex: 1; min-height: 0; }
.canvas-wrap { flex: 1; position: relative; min-width: 0; }
.canvas { width: 100%; height: 100%; }
.canvas-exec-active { background: rgba(240,162,75,.03); }
.editor-loading {
  position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: var(--text-dim);
}
.settings-empty { display: flex; align-items: center; justify-content: center; }
.settings-empty-text { text-align: center; color: var(--text-faint); }
.settings-empty-text .icon { font-size: 26px; margin-bottom: 8px; }

/* ── Exec controls ── */
.exec-controls { display: flex; gap: 6px; margin-left: 8px; }
.exec-btn-pause  { background: rgba(240,162,75,.15); color: #f0a24b; border-color: rgba(240,162,75,.3); }
.exec-btn-resume { background: rgba(37,194,144,.15); color: #25c290; border-color: rgba(37,194,144,.3); }
.exec-btn-stop   { background: rgba(239,107,107,.15); color: #ef6b6b; border-color: rgba(239,107,107,.3); }
.exec-btn-pause:hover  { background: rgba(240,162,75,.3); }
.exec-btn-resume:hover { background: rgba(37,194,144,.3); }
.exec-btn-stop:hover   { background: rgba(239,107,107,.3); }

/* ── Progress bar ── */
.exec-progress {
  position: absolute; left: 50%; bottom: 20px; transform: translateX(-50%);
  background: var(--panel); border: 1px solid var(--border); border-radius: 8px;
  padding: 8px 16px; min-width: 240px; box-shadow: 0 -2px 20px rgba(0,0,0,.3); z-index: 10;
}
.progress-text { font-size: 12px; color: var(--text-dim); margin-bottom: 6px; text-align: center; }
.progress-bar-track {
  height: 4px; background: var(--border); border-radius: 2px; overflow: hidden;
}
.progress-bar-fill {
  height: 100%; background: linear-gradient(90deg, #25c290, #4d9fff);
  border-radius: 2px; transition: width .4s ease;
}

/* ── Results panel ── */
.results-panel {
  position: absolute; left: 16px; right: 16px; bottom: 16px;
  background: var(--panel); border: 1px solid var(--border); border-radius: 8px;
  box-shadow: 0 -4px 30px rgba(0,0,0,.4); max-height: 35%; display: flex; flex-direction: column; z-index: 10;
}
.results-head {
  display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-bottom: 1px solid var(--border);
}
.results-body { padding: 12px 14px; overflow: auto; }
.exec-info { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
.node-state-grid { display: flex; flex-wrap: wrap; gap: 8px; }
.node-state {
  display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px;
  background: var(--bg-secondary); border: 1px solid var(--border); font-size: 12px;
  transition: border-color .3s, opacity .3s;
}
.node-state.success { border-color: rgba(37,194,144,.4); }
.node-state.error   { border-color: rgba(239,107,107,.4); }
.node-state.running { border-color: rgba(240,162,75,.4); animation: state-pulse 1.2s ease-in-out infinite; }
.node-state.paused  { border-color: rgba(99,102,241,.4); opacity: .7; }
.node-state.skipped { opacity: .5; }
.node-state-sub { font-size: 10px; color: var(--text-faint); }

.node-state-dot {
  width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
}
.ns-pending { background: #555; }
.ns-running { background: #f0a24b; animation: dot-blink 1s infinite; }
.ns-success { background: #25c290; }
.ns-error   { background: #ef6b6b; }
.ns-paused  { background: #6366f1; }
.ns-skipped { background: #555; opacity: .5; }

@keyframes dot-blink {
  0%, 100% { opacity: 1; }
  50%      { opacity: .3; }
}
@keyframes state-pulse {
  0%, 100% { box-shadow: none; }
  50%      { box-shadow: 0 0 8px rgba(240,162,75,.3); }
}

/* ── Edge animations ── */
.animated-edge { width: 100%; height: 100%; overflow: visible; pointer-events: none; }
.edge-path { transition: stroke .3s, stroke-width .3s; }
.edge-animated { animation: dash-flow 0.6s linear infinite; }
@keyframes dash-flow {
  to { stroke-dashoffset: -12; }
}

/* ── Transitions ── */
.slide-up-enter-active, .slide-up-leave-active { transition: transform .2s, opacity .2s; }
.slide-up-enter-from, .slide-up-leave-to { transform: translateY(20px); opacity: 0; }

/* ── Spinner ── */
.spinner {
  display: inline-block; width: 12px; height: 12px;
  border: 2px solid rgba(255,255,255,.3); border-top-color: #fff; border-radius: 50%;
  animation: spin .7s linear infinite; vertical-align: middle; margin-right: 4px;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<style scoped>.ts-input { width: 110px; font-size: 11px; padding: 4px 8px; }</style>
