<template>
  <header class="topbar">
    <div class="topbar-left">
      <span class="brand-mark">
        <SvgIcon name="board" :size="18" />
      </span>
      <div class="brand-text">
        <span class="brand-name">Kanban</span>
      </div>
    </div>

    <div class="topbar-right">
      <div v-if="auth.user" class="user-chip">
        <Avatar :name="auth.user.name" :size="30" />
        <span class="user-name">{{ auth.user.name }}</span>
        <button class="icon-btn" title="Выйти" @click="onLogout">
          <SvgIcon name="logout" :size="16" />
        </button>
      </div>
      <button v-else class="btn btn--ghost" @click="$router.push('/login')">Войти</button>
    </div>
  </header>
</template>

<script setup>
import { useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import SvgIcon from '../SvgIcon.vue'
import Avatar from '../Avatar.vue'

const emit = defineEmits(['logout'])
const auth = useAuthStore()
const router = useRouter()

async function onLogout() {
  await auth.logout()
  emit('logout')
  router.push('/login')
}
</script>

<style scoped>
.topbar {
  height: 52px;
  padding: 0 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: rgba(255, 255, 255, 0.85);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border-bottom: 1px solid var(--line);
  position: relative;
  z-index: 10;
}
.topbar-left {
  display: flex;
  align-items: center;
  gap: 10px;
}
.brand-mark {
  width: 32px;
  height: 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  background: var(--accent-grad);
  color: #fff;
  box-shadow: 0 6px 14px -6px rgba(108, 92, 231, 0.65);
}
.brand-name {
  font-size: 16px;
  font-weight: 800;
  color: var(--text);
  letter-spacing: -0.2px;
}
.topbar-right {
  display: flex;
  align-items: center;
}
.user-chip {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 5px 8px 5px 5px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  border: 1px solid var(--line);
}
.user-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--text);
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
