<template>
  <Teleport to="body">
    <transition name="fade">
      <div v-if="open" class="overlay" @mousedown.self="close">
        <div
          ref="panelEl"
          class="panel"
          role="dialog"
          aria-modal="true"
          aria-label="Архив карточек"
          @keydown="onKeydown"
        >
          <header class="panel-head">
            <div class="panel-title">
              <SvgIcon name="archive" :size="18" />
              <span>Архив карточек</span>
            </div>
            <button
              class="icon-btn"
              aria-label="Закрыть архив"
              v-tooltip="'Закрыть'"
              @click="close"
            >
              <SvgIcon name="close" :size="18" />
            </button>
          </header>

          <div class="panel-body">
            <div v-if="store.archiveLoading" class="state">
              <span class="spinner" />
              <span>Загружаем архив…</span>
            </div>

            <p v-else-if="store.archiveError" class="state state--error">{{ store.archiveError }}</p>

            <p v-else-if="!store.archive.length" class="state">
              Архив пуст. Карточки, отправленные в архив, появятся здесь.
            </p>

            <ul v-else class="list">
              <li v-for="card in store.archive" :key="card.id" class="item">
                <div class="item-main">
                  <span class="item-title">{{ card.title }}</span>
                  <span class="item-meta">
                    {{ card.columnTitle }} · {{ timeAgo(card.archivedAt) }}
                  </span>
                </div>
                <button class="btn btn--ghost btn--xs" :disabled="busyId === card.id" @click="restore(card)">
                  Вернуть
                </button>
                <button
                  class="icon-btn icon-btn--danger"
                  aria-label="Удалить карточку навсегда"
                  v-tooltip="'Удалить навсегда'"
                  :disabled="busyId === card.id"
                  @click="remove(card)"
                >
                  <SvgIcon name="trash" :size="14" />
                </button>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<script setup>
import { ref, watch, onBeforeUnmount } from 'vue'
import { useBoardStore } from '../../stores/board'
import { confirmAction } from '../../composables/useConfirm'
import { timeAgo } from '../../utils/time'
import SvgIcon from '../SvgIcon.vue'

const props = defineProps({
  open: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const store = useBoardStore()
const busyId = ref(null)
const panelEl = ref(null)

watch(
  () => props.open,
  (value) => {
    if (value) {
      store.loadArchive().catch(() => {})
      window.addEventListener('keydown', onKeydown)
    } else {
      window.removeEventListener('keydown', onKeydown)
    }
  },
  { immediate: true },
)

function onKeydown(event) {
  if (!props.open) return
  if (event.key === 'Escape') {
    event.preventDefault()
    close()
    return
  }
  if (event.key !== 'Tab' || !panelEl.value) return
  const list = Array.from(
    panelEl.value.querySelectorAll('button:not([disabled])'),
  ).filter((el) => el.offsetParent !== null)
  if (!list.length) return
  const first = list[0]
  const last = list[list.length - 1]
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

async function restore(card) {
  if (busyId.value) return
  busyId.value = card.id
  try {
    await store.restoreCard(card.id)
  } catch (e) {
    /* banner shows the error */
  } finally {
    busyId.value = null
  }
}

async function remove(card) {
  if (busyId.value) return
  const ok = await confirmAction({
    title: 'Удалить карточку навсегда?',
    message: `Карточка «${card.title}» будет удалена вместе с комментариями и чек-листом. Восстановить её нельзя.`,
    confirmText: 'Удалить навсегда',
  })
  if (!ok) return
  busyId.value = card.id
  try {
    await store.deleteCard(card.id)
    store.archive = store.archive.filter((item) => item.id !== card.id)
  } catch (e) {
    /* banner shows the error */
  } finally {
    busyId.value = null
  }
}

function close() {
  emit('close')
}
</script>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  background: var(--overlay);
  backdrop-filter: blur(3px);
}
.panel {
  width: 100%;
  max-width: 560px;
  max-height: 76vh;
  display: flex;
  flex-direction: column;
  background: var(--surface);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-pop);
  overflow: hidden;
}
.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px 12px 20px;
  border-bottom: 1px solid var(--line);
}
.panel-title {
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: 15px;
  font-weight: 800;
  color: var(--text);
}
.panel-body {
  padding: 12px 18px 18px;
  overflow-y: auto;
}
.state {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 10px 2px;
  color: var(--text-3);
  font-size: 13px;
}
.state--error {
  color: var(--danger);
}
.list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 10px 9px 12px;
  border: 1px solid var(--line);
  border-radius: var(--radius-m);
  background: var(--surface-2);
}
.item-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.item-title {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--text);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.item-meta {
  font-size: 12px;
  color: var(--text-3);
}
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.16s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
