<template>
  <router-view />
</template>

<script setup>
import { onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'

const auth = useAuthStore()
const router = useRouter()

function onUnauthorized() {
  auth.clearSession()
  if (router.currentRoute.value.name !== 'login') {
    router.push('/login')
  }
}

onMounted(() => {
  window.addEventListener('auth:unauthorized', onUnauthorized)
  auth.bootstrap()
})

onBeforeUnmount(() => {
  window.removeEventListener('auth:unauthorized', onUnauthorized)
})
</script>
