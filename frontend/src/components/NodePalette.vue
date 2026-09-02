<script setup>
import { computed } from 'vue'
import { useNodesStore } from '../stores/nodes'
import NodeIcon from './NodeIcon.vue'

const nodesStore = useNodesStore()
const search = defineModel('search', { type: String, default: '' })

const groups = computed(() => {
  const q = search.value.toLowerCase()
  const map = {}
  for (const n of nodesStore.registry) {
    if (q && !n.name.toLowerCase().includes(q) && !n.description.toLowerCase().includes(q)) continue
    if (!map[n.category]) map[n.category] = []
    map[n.category].push(n)
  }
  return map
})

function onDragStart(e, node) {
  e.dataTransfer.setData('application/x-n8n-node', JSON.stringify(node))
  e.dataTransfer.effectAllowed = 'move'
}
</script>

<template>
  <aside class="palette">
    <div class="palette-search">
      <input v-model="search" placeholder="Cari node…" />
    </div>
    <div v-if="nodesStore.loaded" class="palette-body">
      <div v-for="(items, cat) in groups" :key="cat" class="palette-group">
        <div class="palette-cat">{{ cat }}</div>
        <div
          v-for="n in items"
          :key="n.type"
          class="palette-item"
          draggable="true"
          :title="n.description"
          @dragstart="onDragStart($event, n)"
        >
          <span class="palette-icon"><NodeIcon :name="n.icon || 'circle'" :size="14" /></span>
          <span class="palette-name">{{ n.name }}</span>
        </div>
      </div>
      <div v-if="!Object.keys(groups).length" class="palette-empty">Node tidak ditemukan</div>
    </div>
    <div v-else class="palette-empty">Memuat node…</div>
  </aside>
</template>

<style scoped>
.palette {
  width: 220px; flex-shrink: 0; background: var(--bg-secondary);
  border-right: 1px solid var(--border); display: flex; flex-direction: column;
}
.palette-search { padding: 10px; border-bottom: 1px solid var(--border); }
.palette-search input { width: 100%; }
.palette-body { flex: 1; overflow-y: auto; padding: 8px; }
.palette-group { margin-bottom: 10px; }
.palette-cat { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-faint); padding: 4px 6px; }
.palette-item {
  display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 6px;
  cursor: grab; font-size: 12px; color: var(--text);
}
.palette-item:hover { background: var(--panel-hover); }
.palette-icon { width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; color: var(--text-dim); }
.palette-empty { padding: 16px; color: var(--text-faint); font-size: 12px; text-align: center; }
</style>
