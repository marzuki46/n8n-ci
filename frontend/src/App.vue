<template>
  <router-view />
</template>

<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'
import { useUiStore } from './stores/ui'

const auth = useAuthStore()
const ui = useUiStore()
const router = useRouter()

ui.applyTheme()

onMounted(async () => {
  if (!auth.initialized) {
    await auth.init().catch(() => (auth.user = null))
  }
})
</script>
