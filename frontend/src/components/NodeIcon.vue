<script setup>
import { computed } from 'vue'
import { icon } from '../icons'

const props = defineProps({
  name: { type: String, default: 'circle' },
  size: { type: [Number, String], default: 14 },
})

const glyph = computed(() => icon(props.name))
</script>

<template>
  <svg
    class="node-icon-svg"
    :viewBox="glyph.viewBox"
    :width="size"
    :height="size"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <template v-for="(el, i) in glyph.elements" :key="i">
      <component :is="el.type" v-bind="el.attrs" />
    </template>
  </svg>
</template>

<style scoped>
.node-icon-svg {
  flex-shrink: 0;
  vertical-align: middle;
}
</style>
