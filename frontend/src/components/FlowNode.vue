<script setup>
import { computed } from 'vue'
import { Handle, Position } from '@vue-flow/core'
import NodeIcon from './NodeIcon.vue'

const props = defineProps({
  data: { type: Object, required: true },
  selected: Boolean,
})

const inputCount = computed(() => props.data.inputs || 0)
const outputCount = computed(() => {
  const v = props.data.outputs
  return Array.isArray(v) ? v.length : Number(v) || 0
})

function colorFor(category) {
  const map = {
    trigger: '#ff6d5a',
    core: '#4d9fff',
    ai: '#a06bff',
    http: '#25c290',
    data: '#f0a24b',
  }
  return map[category] || '#9aa3b2'
}

const execState = computed(() => props.data._execState || null)
const execDuration = computed(() => props.data._execDuration ?? null)

const execIcon = computed(() => {
  switch (execState.value) {
    case 'running': return '\u27F3'  // ⟳
    case 'success': return '\u2713'  // ✓
    case 'error':   return '\u2715'  // ✕
    case 'paused':  return '\u23F8'  // ⏸
    case 'skipped': return '\u2013'  // –
    default:        return null
  }
})
</script>

<template>
  <div
    class="flow-node"
    :class="[
      { selected },
      execState ? 'exec-' + execState : '',
    ]"
    :style="{ '--node-accent': colorFor(data.category) }"
  >
    <Handle
      v-for="i in inputCount"
      :key="'in' + i"
      :id="'in-' + i"
      type="target"
      :position="Position.Left"
      :style="{ top: `${(i - 0.5) * (100 / inputCount)}%` }"
    />
    <div class="node-header">
      <span class="node-icon"><NodeIcon :name="data.icon || 'circle'" :size="12" /></span>
      <span class="node-name">{{ data.name }}</span>
      <span v-if="execState === 'running'" class="exec-spinner"></span>
      <span
        v-else-if="execIcon"
        class="exec-badge"
        :class="'exec-badge-' + execState"
        :title="execState"
      >{{ execIcon }}</span>
    </div>
    <div v-if="execDuration != null && execState === 'success'" class="node-sub exec-duration">{{ execDuration }} ms</div>
    <div v-else-if="data.subtitle" class="node-sub">{{ data.subtitle }}</div>
    <Handle
      v-for="o in outputCount"
      :key="'out' + o"
      :id="'out-' + o"
      type="source"
      :position="Position.Right"
      :style="{ top: `${(o - 0.5) * (100 / outputCount)}%` }"
    />
  </div>
</template>

<style scoped>
.flow-node {
  min-width: 168px; max-width: 200px;
  background: var(--panel); border: 1px solid var(--border); border-radius: 8px;
  box-shadow: 0 2px 12px rgba(0,0,0,.35);
  font-size: 12px;
  transition: border-color .3s, box-shadow .3s, opacity .3s;
}
.flow-node.selected { border-color: var(--node-accent); box-shadow: 0 0 0 1px var(--node-accent), 0 4px 16px rgba(0,0,0,.4); }

/* --- Execution states --- */
.flow-node.exec-running {
  border-color: #f0a24b;
  box-shadow: 0 0 0 2px rgba(240,162,75,.35), 0 0 18px rgba(240,162,75,.2);
  animation: node-pulse 1.2s ease-in-out infinite;
}
.flow-node.exec-success {
  border-color: rgba(37,194,144,.6);
}
.flow-node.exec-error {
  border-color: rgba(239,107,107,.6);
  box-shadow: 0 0 0 1px rgba(239,107,107,.3);
}
.flow-node.exec-paused {
  border-color: rgba(99,102,241,.5);
  opacity: .7;
}
.flow-node.exec-skipped {
  opacity: .4;
}
.flow-node.exec-pending {
  opacity: .6;
}

@keyframes node-pulse {
  0%, 100% { box-shadow: 0 0 0 2px rgba(240,162,75,.35), 0 0 18px rgba(240,162,75,.2); }
  50%      { box-shadow: 0 0 0 4px rgba(240,162,75,.2), 0 0 28px rgba(240,162,75,.3); }
}

.node-header { display: flex; align-items: center; gap: 8px; padding: 8px 10px; }
.node-icon {
  width: 20px; height: 20px; border-radius: 5px; flex-shrink: 0;
  background: var(--node-accent); color: #fff;
  display: flex; align-items: center; justify-content: center;
}
.node-name { font-weight: 600; color: var(--text); }
.node-sub { padding: 0 10px 8px; color: var(--text-faint); font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Exec spinner */
.exec-spinner {
  width: 14px; height: 14px; border: 2px solid rgba(240,162,75,.3);
  border-top-color: #f0a24b; border-radius: 50%;
  animation: spin .7s linear infinite; margin-left: auto; flex-shrink: 0;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Exec badge */
.exec-badge {
  width: 18px; height: 18px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 10px; font-weight: 700; margin-left: auto; flex-shrink: 0;
}
.exec-badge-success { background: rgba(37,194,144,.15); color: #25c290; }
.exec-badge-error   { background: rgba(239,107,107,.15); color: #ef6b6b; }
.exec-badge-paused  { background: rgba(99,102,241,.15); color: #6366f1; }
.exec-badge-skipped { background: rgba(148,163,184,.15); color: #94a3b8; }

.exec-duration { color: #25c290; font-weight: 600; }
</style>
