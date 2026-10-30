<template>
  <div class="board-view">
    <div class="board-body">
      <div class="blob blob--violet" />
      <div class="blob blob--sky" />
      <div class="blob blob--mint" />

      <TopBar @open-archive="openArchive" />

      <BoardFilters ref="filtersEl" />

      <div class="board-scroll">
        <div v-if="store.loading && !store.board" class="board-canvas board-canvas--skeleton">
          <div v-for="n in 3" :key="n" class="column-skeleton">
            <div class="skeleton" style="height: 20px; width: 60%" />
            <div class="skeleton" style="height: 62px" />
            <div class="skeleton" style="height: 62px" />
            <div class="skeleton" style="height: 62px; width: 80%" />
          </div>
        </div>

        <div v-else class="board-scroll-inner">
          <draggable
            class="board-canvas"
            :list="store.columns"
            item-key="id"
            group="columns"
            :animation="180"
            handle=".column-grip"
            ghost-class="column-ghost"
            :disabled="store.dragDisabled || store.columns.length < 2"
            @end="onColumnsReordered"
          >
            <template #item="{ element }">
              <Column
                :column="visibleById[element.id] || element"
                @open-card="openCard"
                @cards-ordered="store.saveCardsOrder()"
              />
            </template>
          </draggable>
          <AddColumn />
        </div>
      </div>
    </div>

    <CardModal :card="activeCard" @close="activeCard = null" />
    <ArchivePanel :open="archiveOpen" @close="archiveOpen = false" />
    <LabelManager :open="ui.labelManagerOpen" @close="ui.closeLabels()" />
    <CommandPalette @open-card="openCard" />
    <ShortcutsDialog />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import draggable from 'vuedraggable'
import { useBoardStore } from '../stores/board'
import { useUiStore } from '../stores/ui'
import { wsWatch } from '../api/ws'
import TopBar from '../components/board/TopBar.vue'
import BoardFilters from '../components/board/BoardFilters.vue'
import Column from '../components/board/Column.vue'
import AddColumn from '../components/board/AddColumn.vue'
import CardModal from '../components/board/CardModal.vue'
import ArchivePanel from '../components/board/ArchivePanel.vue'
import LabelManager from '../components/board/LabelManager.vue'
import CommandPalette from '../components/board/CommandPalette.vue'
import ShortcutsDialog from '../components/board/ShortcutsDialog.vue'

const store = useBoardStore()
const ui = useUiStore()

/**
 * Displayed columns (filtered/sorted). Dragging still mutates `store.columns`
 * directly, and it is disabled whenever a filter or a non-default sort is on.
 */
const visibleById = computed(() => {
  const map = {}
  for (const column of store.visibleColumns) map[column.id] = column
  return map
})

const activeCard = ref(null)
const archiveOpen = ref(false)
const filtersEl = ref(null)

function openCard(card) {
  activeCard.value = card
}

watch(activeCard, (card) => wsWatch(card ? card.id : null))

function openArchive() {
  archiveOpen.value = true
}

async function onColumnsReordered() {
  try {
    await store.saveColumnOrder()
  } catch (e) {
    /* toast */
  }
}

// ------------------------------------------------------------- shortcuts

function isTypingTarget(target) {
  if (!target) return false
  const tag = target.tagName
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable
}

function onKeydown(e) {
  const mod = e.ctrlKey || e.metaKey

  if (mod && e.key.toLowerCase() === 'k') {
    e.preventDefault()
    ui.openPalette()
    return
  }
  if (isTypingTarget(e.target)) return

  if (ui.closeTopLayer()) {
    // Let the layer close first, then stop the rest of the handling.
    e.preventDefault()
    return
  }

  if (e.key === '/') {
    e.preventDefault()
    filtersEl.value && filtersEl.value.focusSearch()
    return
  }
  if (e.key === '?') {
    e.preventDefault()
    ui.toggleShortcuts()
    return
  }
  if (e.key === 'n' || e.key === 'N') {
    e.preventDefault()
    const first = store.columns[0]
    if (first) {
      document.querySelector(`[data-column-add="${first.id}"]`)?.click()
    }
  }
}

async function init() {
  store.subscribe()
  store.restoreFromUrl()
  try {
    await store.load()
  } catch (e) {
    /* unauthorized → router sends to /login, otherwise the toast explains */
  }
  window.addEventListener('keydown', onKeydown)
  window.addEventListener('board:open-archive', openArchive)
}

onMounted(init)
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('board:open-archive', openArchive)
  store.unsubscribe()
  wsWatch(null)
})
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
    var(--bg);
}
[data-theme='dark'] .board-body {
  background:
    radial-gradient(1200px 600px at 80% -10%, rgba(139, 92, 246, 0.18), transparent 60%),
    radial-gradient(900px 500px at -10% 110%, rgba(79, 139, 247, 0.12), transparent 60%),
    var(--bg);
}
.blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(60px);
  opacity: 0.55;
  pointer-events: none;
}
[data-theme='dark'] .blob {
  opacity: 0.18;
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

.board-scroll {
  flex: 1;
  min-height: 0;
  overflow-x: auto;
  overflow-y: hidden;
  padding-bottom: 4px;
  position: relative;
  z-index: 1;
}
.board-scroll-inner {
  display: flex;
  align-items: stretch;
  gap: 14px;
  width: max-content;
  min-width: 100%;
  height: 100%;
  padding: 8px 22px 20px;
}
.board-canvas {
  display: flex;
  align-items: stretch;
  gap: 14px;
  height: 100%;
}
.board-canvas--skeleton {
  padding: 8px 22px 20px;
}
.column-skeleton {
  width: 280px;
  flex: none;
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px;
  border-radius: var(--radius-l);
  background: var(--glass);
  border: 1px solid var(--line);
}
.column-ghost {
  opacity: 0.4;
}

/* ---------- narrow screens: one column, snap scrolling ---------- */
@media (max-width: 768px) {
  .board-scroll {
    scroll-snap-type: x mandatory;
  }
  .board-scroll-inner {
    gap: 10px;
    padding: 6px 14px 14px;
  }
  .board-canvas {
    gap: 10px;
  }
  .board-canvas > * {
    scroll-snap-align: start;
  }
  .column-skeleton {
    width: 86vw;
  }
}
</style>
