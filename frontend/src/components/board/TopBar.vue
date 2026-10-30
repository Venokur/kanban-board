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
      <!-- Who else is on the board right now -->
      <div v-if="store.onlineUsers.length" class="presence" v-tooltip="presenceHint">
        <Avatar
          v-for="user in visibleOnline"
          :key="user.id"
          :name="user.name"
          :size="26"
          :title="user.name"
        />
        <span v-if="store.onlineUsers.length > visibleOnline.length" class="presence-more">
          +{{ store.onlineUsers.length - visibleOnline.length }}
        </span>
      </div>

      <button
        class="icon-btn"
        :aria-label="theme.isDark ? 'Светлая тема' : 'Тёмная тема'"
        v-tooltip="theme.isDark ? 'Светлая тема' : 'Тёмная тема'"
        @click="theme.toggle()"
      >
        <SvgIcon :name="theme.isDark ? 'sun' : 'moon'" :size="17" />
      </button>

      <button
        class="icon-btn"
        aria-label="Горячие клавиши"
        v-tooltip="'Горячие клавиши (?)'"
        @click="ui.toggleShortcuts()"
      >
        <SvgIcon name="keyboard" :size="17" />
      </button>

      <div v-if="auth.user" class="user-chip">
        <Avatar :name="auth.user.name" :size="30" />
        <span class="user-name">{{ auth.user.name }}</span>
        <button
          class="icon-btn"
          aria-label="Выйти из аккаунта"
          v-tooltip="'Выйти'"
          @click="onLogout"
        >
          <SvgIcon name="logout" :size="16" />
        </button>
      </div>
      <button v-else class="btn btn--ghost" @click="router.push('/login')">Войти</button>
    </div>
  </header>
</template>

<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { useBoardStore } from '../../stores/board'
import { useThemeStore } from '../../stores/theme'
import { useUiStore } from '../../stores/ui'
import SvgIcon from '../SvgIcon.vue'
import Avatar from '../Avatar.vue'

const auth = useAuthStore()
const store = useBoardStore()
const theme = useThemeStore()
const ui = useUiStore()
const router = useRouter()

const visibleOnline = computed(() => store.onlineUsers.slice(0, 4))

const presenceHint = computed(() => {
  const names = store.onlineUsers.map((user) => user.name).join(', ')
  return names ? `На доске: ${names}` : ''
})

async function onLogout() {
  await auth.logout()
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
  gap: 12px;
  background: var(--glass);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border-bottom: 1px solid var(--line);
  position: relative;
  z-index: 10;
}
.topbar-left,
.topbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.brand-mark {
  width: 30px;
  height: 30px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  background: var(--accent-grad);
  color: #fff;
  flex: none;
}
.brand-name {
  font-size: 15px;
  font-weight: 800;
  letter-spacing: -0.2px;
  color: var(--text);
}
.presence {
  display: flex;
  align-items: center;
  gap: -4px;
  padding: 0 6px 0 2px;
}
.presence :deep(.avatar) {
  margin-left: -6px;
  border: 2px solid var(--surface);
  box-shadow: 0 1px 4px rgba(20, 24, 40, 0.14);
}
.presence :deep(.avatar:first-child) {
  margin-left: 0;
}
.presence-more {
  margin-left: 4px;
  font-size: 11.5px;
  font-weight: 700;
  color: var(--text-3);
}
.user-chip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 3px 4px 3px 6px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
}
.user-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-2);
  max-width: 140px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@media (max-width: 720px) {
  .user-name,
  .brand-text {
    display: none;
  }
  .presence {
    display: none;
  }
  .topbar {
    padding: 0 10px;
  }
}
</style>
