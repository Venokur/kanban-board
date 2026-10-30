<template>
  <header class="topbar">
    <!-- One header for the whole screen: brand, board identity and every
         action live on the same line, so the board title no longer sits in a
         second bar that looked detached from the app. -->
    <div class="topbar-left">
      <span class="brand-mark">
        <SvgIcon name="board" :size="18" />
      </span>
      <div class="brand-text">
        <span class="brand-name">Kanban</span>
        <div class="board-line">
          <input
            v-if="editingName"
            ref="nameEl"
            v-model="nameDraft"
            class="board-title-input"
            maxlength="80"
            aria-label="Название доски"
            @keydown.enter.prevent="saveName"
            @keydown.esc="cancelName"
            @blur="saveName"
          />
          <template v-else>
            <h1
              class="board-title"
              v-tooltip="'Двойной клик — переименовать'"
              @dblclick="startEditName"
            >
              {{ store.boardName || 'Доска' }}
            </h1>
            <button
              class="icon-btn board-rename"
              aria-label="Переименовать доску"
              v-tooltip="'Переименовать доску'"
              @click="startEditName"
            >
              <SvgIcon name="pencil" :size="14" />
            </button>
          </template>
        </div>
        <span class="board-subtitle">{{ summary }}</span>
      </div>
    </div>

    <div class="topbar-right">
      <!-- Only other people are worth showing: your own avatar is already in
           the user chip, and a duplicate "DU" next to it looked like a bug. -->
      <div v-if="othersOnline.length" class="presence" v-tooltip="presenceHint">
        <Avatar
          v-for="user in visibleOnline"
          :key="user.id"
          :name="user.name"
          :size="26"
          :title="user.name"
        />
        <span v-if="othersOnline.length > visibleOnline.length" class="presence-more">
          +{{ othersOnline.length - visibleOnline.length }}
        </span>
      </div>

      <span class="badge" :class="wsTone" v-tooltip="wsHint">
        <span v-if="store.saving" class="spinner" />
        <span v-else class="dot" />
        {{ store.saving ? 'сохранение…' : wsLabel }}
      </span>

      <button class="btn btn--ghost btn--xs" @click="ui.openLabels()">
        <SvgIcon name="palette" :size="14" />
        Метки
      </button>
      <button class="btn btn--ghost btn--xs" @click="emit('open-archive')">
        <SvgIcon name="archive" :size="14" />
        Архив
      </button>

      <span class="topbar-divider" aria-hidden="true" />

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
import { computed, nextTick, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { useBoardStore } from '../../stores/board'
import { useThemeStore } from '../../stores/theme'
import { useUiStore } from '../../stores/ui'
import { wsState } from '../../api/ws'
import { useStableStatus } from '../../composables/useStableStatus'
import { plural } from '../../utils/time'
import SvgIcon from '../SvgIcon.vue'
import Avatar from '../Avatar.vue'

const emit = defineEmits(['open-archive'])

const auth = useAuthStore()
const store = useBoardStore()
const theme = useThemeStore()
const ui = useUiStore()
const router = useRouter()

const summary = computed(() => {
  const cards = store.totalCards
  const parts = []
  if (store.columns.length) {
    parts.push(plural(store.columns.length, 'колонка', 'колонки', 'колонок'))
  }
  if (cards) parts.push(plural(cards, 'задача', 'задачи', 'задач'))
  if (store.filtersActive) {
    parts.push(`фильтр${store.hiddenCards ? `: скрыто ${store.hiddenCards}` : ''}`)
  }
  return parts.join(' · ')
})

/**
 * The raw socket status flips through connecting → open on load and through
 * closed → connecting on every reconnect. Showing that directly made the pill
 * visibly jump, so the UI reads a debounced copy; `wsState` itself stays raw
 * because reconnects and backoff depend on it.
 */
const stableStatus = useStableStatus(() => wsState.status, 600)

const wsLabel = computed(() => {
  if (stableStatus.value === 'open') return 'синхронизировано'
  if (stableStatus.value === 'connecting') return 'подключение…'
  return 'переподключение…'
})
const wsTone = computed(() => ({ on: stableStatus.value === 'open' }))
const wsHint = computed(() =>
  wsState.status === 'open'
    ? 'Изменения приходят в реальном времени'
    : `Соединение потеряно, повтор через ${wsState.retryIn || 1} с`,
)

const othersOnline = computed(() =>
  store.onlineUsers.filter((user) => !auth.user || user.id !== auth.user.id),
)
const visibleOnline = computed(() => othersOnline.value.slice(0, 4))

const presenceHint = computed(() => {
  const names = store.onlineUsers.map((user) => user.name).join(', ')
  return names ? `На доске: ${names}` : ''
})

// Document title reflects the board name (tab title, history, bookmarks).
watch(
  () => store.boardName,
  (name) => {
    document.title = name ? `${name} · Kanban` : 'Kanban'
  },
  { immediate: true },
)

const editingName = ref(false)
const nameDraft = ref('')
const nameEl = ref(null)

async function startEditName() {
  nameDraft.value = store.boardName
  editingName.value = true
  await nextTick()
  nameEl.value && nameEl.value.select()
}

async function saveName() {
  if (!editingName.value) return
  editingName.value = false
  const name = nameDraft.value.trim()
  if (!name || name === store.boardName) return
  try {
    await store.renameBoard(name)
  } catch (e) {
    /* toast shows the error */
  }
}

function cancelName() {
  editingName.value = false
}

async function onLogout() {
  await auth.logout()
  router.push('/login')
}
</script>

<style scoped>
.topbar {
  padding: 14px 22px 10px;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  position: relative;
  z-index: 10;
}
.topbar-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.topbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  justify-content: flex-end;
  padding-top: 3px;
}
.brand-mark {
  width: 34px;
  height: 34px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 11px;
  background: var(--accent-grad);
  color: #fff;
  flex: none;
  box-shadow: 0 8px 18px -6px rgba(108, 92, 231, 0.7);
}
.brand-text {
  display: flex;
  flex-direction: column;
  gap: 1px;
  min-width: 0;
}
.brand-name {
  font-size: 10.5px;
  font-weight: 800;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--text-3);
}
.board-line {
  display: flex;
  align-items: center;
  gap: 4px;
  min-width: 0;
}
.board-title {
  margin: 0;
  font-size: 22px;
  font-weight: 800;
  line-height: 1.2;
  color: var(--text);
  letter-spacing: -0.3px;
  cursor: text;
  overflow-wrap: anywhere;
}
.board-rename {
  width: 24px;
  height: 24px;
  flex: none;
  opacity: 0;
  transition: opacity 0.13s ease;
}
.brand-text:hover .board-rename,
.board-rename:focus-visible {
  opacity: 1;
}
.board-title-input {
  font-size: 22px;
  font-weight: 800;
  letter-spacing: -0.3px;
  color: var(--text);
  background: var(--surface);
  border: 1px solid var(--accent);
  border-radius: var(--radius-s);
  padding: 1px 8px;
  outline: none;
  box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.16);
  max-width: 340px;
}
.board-subtitle {
  font-size: 12.5px;
  color: var(--text-2);
  font-weight: 500;
}
.topbar-divider {
  width: 1px;
  height: 22px;
  background: var(--line-strong);
  flex: none;
}
.presence {
  display: flex;
  align-items: center;
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
/* The three status labels differ in width (126 / 147 / 153px). Reserving the
   widest one keeps the neighbouring buttons from shifting on every change. */
.badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 5px 12px;
  border-radius: var(--radius-pill);
  background: var(--glass);
  color: var(--text-2);
  font-size: 12px;
  font-weight: 600;
  box-shadow: 0 2px 8px -4px rgba(32, 38, 59, 0.2);
  backdrop-filter: blur(6px);
  min-width: 158px;
}
.badge .dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #f0a928;
  flex: none;
}
.badge .spinner {
  flex: none;
}
.badge.on .dot {
  background: var(--success);
  box-shadow: 0 0 0 3px rgba(48, 164, 108, 0.2);
}
.user-chip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 3px 4px 3px 6px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
}
/* The initials are centred in the circle; what looked off was the 6px inset on
   the left of the pill against 4px on the right. Pull the avatar in, leaving the
   name and the logout button exactly where they were. */
.user-chip :deep(.avatar) {
  margin-left: -3px;
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

@media (max-width: 900px) {
  .badge {
    min-width: 0;
  }
  .user-name {
    display: none;
  }
}

@media (max-width: 720px) {
  .user-name,
  .brand-name,
  .board-subtitle {
    display: none;
  }
  .presence {
    display: none;
  }
  .topbar {
    padding: 10px;
    flex-wrap: wrap;
  }
}
</style>
