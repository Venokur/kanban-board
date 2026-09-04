<template>
  <div class="board-view">
    <TopBar />

    <div class="board-body">
      <div class="blob blob--violet" />
      <div class="blob blob--sky" />
      <div class="blob blob--mint" />

      <!-- Error banner -->
      <transition name="fade">
        <div v-if="store.error" class="error-banner">
          <SvgIcon name="help" :size="15" />
          <span>{{ store.error }}</span>
          <button class="error-close" @click="store.dismissError()">
            <SvgIcon name="close" :size="14" />
          </button>
        </div>
      </transition>

      <header class="board-header">
        <div class="board-heading">
          <span class="board-mark">
            <SvgIcon name="board" :size="20" />
          </span>
          <div class="board-titles">
            <h1 class="board-title">{{ store.boardName || 'Доска' }}</h1>
            <span class="board-subtitle">{{ summary }}</span>
          </div>

          <div class="board-badges">
            <span class="badge" :class="{ on: wsOk }">
              <span class="dot" />
              {{ wsOk ? 'синхронизировано' : 'переподключение…' }}
            </span>
            <span v-if="store.saving" class="badge badge--ghost">
              <span class="spinner" /> сохранение
            </span>
          </div>
        </div>
      </header>

      <div class="board-scroll">
        <div v-if="store.loading" class="board-loading">
          <span class="spinner spinner--dark" />
          <span>Загружаем доску…</span>
        </div>

        <div v-else class="board-canvas">
          <Column
            v-for="column in store.columns"
            :key="column.id"
            :column="column"
            @open-card="openCard"
            @cards-ordered="store.saveCardsOrder()"
          />
          <AddColumn />
        </div>
      </div>
    </div>

    <CardModal :card="activeCard" @close="activeCard = null" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useBoardStore } from '../stores/board'
import { isWsOpen } from '../api/ws'
import TopBar from '../components/board/TopBar.vue'
import Column from '../components/board/Column.vue'
import AddColumn from '../components/board/AddColumn.vue'
import CardModal from '../components/board/CardModal.vue'
import SvgIcon from '../components/SvgIcon.vue'

const store = useBoardStore()

const activeCard = ref(null)
const wsOk = ref(false)
let wsTimer = null

const summary = computed(() => {
  const cards = store.columns.reduce((n, c) => n + (c.cards || []).length, 0)
  const parts = []
  if (store.columns.length) parts.push(pluralize(store.columns.length, 'колонка', 'колонки', 'колонок'))
  if (cards) parts.push(pluralize(cards, 'задача', 'задачи', 'задач'))
  return parts.join(' · ')
})

function pluralize(n, one, few, many) {
  const abs = Math.abs(n) % 100
  const last = abs % 10
  if (abs > 10 && abs < 20) return `${n} ${many}`
  if (last > 1 && last < 5) return `${n} ${few}`
  if (last === 1) return `${n} ${one}`
  return `${n} ${many}`
}

function openCard(card) {
  activeCard.value = card
}

async function init() {
  store.subscribe()
  try {
    await store.load()
  } catch (e) {
    /* if unauthorized the app navigates to /login; otherwise banner shows */
  }
  wsTimer = setInterval(() => {
    wsOk.value = isWsOpen()
  }, 2000)
}

onMounted(init)
onBeforeUnmount(() => clearInterval(wsTimer))
</script>

<style scoped>
.board-view {
  height: 100%;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.board-body {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  position: relative;
  overflow: hidden;
  background:
    radial-gradient(1200px 600px at 80% -10%, rgba(139, 92, 246, 0.22), transparent 60%),
    radial-gradient(900px 500px at -10% 110%, rgba(79, 139, 247, 0.2), transparent 60%),
    linear-gradient(160deg, #e6e9ff 0%, #f1eeff 45%, #e3f0fb 100%);
}
.blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(60px);
  opacity: 0.55;
  pointer-events: none;
}
.blob--violet {
  width: 420px;
  height: 420px;
  background: #c9b8ff;
  top: -140px;
  right: -80px;
}
.blob--sky {
  width: 380px;
  height: 380px;
  background: #a5c9ff;
  bottom: -160px;
  left: -100px;
}
.blob--mint {
  width: 260px;
  height: 260px;
  background: #b6ecd7;
  top: 40%;
  left: 38%;
  opacity: 0.35;
}

.error-banner {
  position: absolute;
  top: 14px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 30;
  display: flex;
  align-items: center;
  gap: 8px;
  max-width: 70%;
  padding: 9px 12px;
  border-radius: var(--radius-pill);
  background: #fff;
  color: var(--danger);
  box-shadow: var(--shadow-pop);
  font-size: 13px;
  font-weight: 500;
}
.error-close {
  border: none;
  background: none;
  color: inherit;
  display: inline-flex;
  padding: 2px;
}

.board-header {
  padding: 20px 22px 14px;
  position: relative;
  z-index: 1;
}
.board-heading {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.board-mark {
  width: 42px;
  height: 42px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 14px;
  background: var(--accent-grad);
  color: #fff;
  box-shadow: 0 8px 18px -6px rgba(108, 92, 231, 0.7);
}
.board-titles {
  display: flex;
  flex-direction: column;
}
.board-title {
  margin: 0;
  font-size: 24px;
  font-weight: 800;
  color: #1d2337;
  letter-spacing: -0.3px;
}
.board-subtitle {
  font-size: 13px;
  color: var(--text-2);
  font-weight: 500;
}
.board-badges {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 8px;
}
.badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  border-radius: var(--radius-pill);
  background: rgba(255, 255, 255, 0.85);
  color: var(--text-2);
  font-size: 12px;
  font-weight: 600;
  box-shadow: 0 2px 8px -4px rgba(32, 38, 59, 0.2);
  backdrop-filter: blur(6px);
}
.badge--ghost {
  background: rgba(255, 255, 255, 0.6);
  box-shadow: none;
}
.badge .dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #f0a928;
}
.badge.on .dot {
  background: var(--success);
  box-shadow: 0 0 0 3px rgba(48, 164, 108, 0.2);
}

.board-scroll {
  flex: 1;
  min-height: 0;
  overflow-x: auto;
  overflow-y: hidden;
  padding-bottom: 4px;
  position: relative;
  z-index: 1;
}
.board-canvas {
  display: flex;
  align-items: stretch;
  gap: 14px;
  width: max-content;
  min-width: 100%;
  height: 100%;
  padding: 8px 22px 20px;
}
.board-loading {
  display: flex;
  align-items: center;
  gap: 12px;
  color: var(--text-2);
  padding: 30px;
  font-size: 14px;
  font-weight: 500;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
  transform: translate(-50%, -6px);
}
</style>

<style>
/* Spinner shared across views/components */
.spinner {
  width: 14px;
  height: 14px;
  flex: none;
  border-radius: 50%;
  border: 2px solid rgba(108, 92, 231, 0.25);
  border-top-color: var(--accent);
  animation: spin 0.8s linear infinite;
}
.spinner--dark {
  border: 2px solid var(--line-strong);
  border-top-color: var(--accent);
}
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
