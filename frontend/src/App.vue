<template>
  <router-view />
  <ToastHost />
  <ConfirmDialog />
</template>

<script setup>
import { onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'
import { useThemeStore } from './stores/theme'
import ToastHost from './components/ToastHost.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'

const auth = useAuthStore()
const theme = useThemeStore()
const router = useRouter()

function onUnauthorized() {
  auth.clearSession()
  if (router.currentRoute.value.name !== 'login') {
    router.push('/login')
  }
}

onMounted(() => {
  window.addEventListener('auth:unauthorized', onUnauthorized)
  theme.init()
  auth.bootstrap()
})

onBeforeUnmount(() => {
  window.removeEventListener('auth:unauthorized', onUnauthorized)
})
</script>
