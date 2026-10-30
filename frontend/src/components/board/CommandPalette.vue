<template>
  <Teleport to="body">
    <transition name="palette">
      <div v-if="ui.paletteOpen" class="palette-overlay" @mousedown.self="close">
        <div
          class="palette"
          role="dialog"
          aria-modal="true"
          aria-label="Командная палитра"
          @keydown="onKeydown"
        >
          <div class="palette-search">
            <SvgIcon name="search" :size="17" />
            <input
              ref="inputEl"
              v-model="query"
              class="palette-input"
              type="text"
              role="combobox"
              aria-expanded="true"
              aria-controls="palette-list"
              aria-autocomplete="list"
              placeholder="Найти карточку или выполнить действие…"
              aria-label="Поиск по карточкам и действиям"
              @keydown.down.prevent="move(1)"
              @keydown.up.prevent="move(-1)"
              @keydown.enter.prevent="runActive"
            />
            <kbd class="palette-kbd">Esc</kbd>
          </div>

          <ul id="palette-list" class="palette-list" role="listbox">
            <li v-if="!items.length" class="palette-empty">
              Ничего не найдено. Попробуйте другой запрос.
            </li>

            <li
              v-for="(item, index) in items"
              :key="item.key"
              class="palette-item"
              :class="{ active: index === activeIndex }"
              role="option"
              :aria-selected="index === activeIndex"
              @mouseenter="activeIndex = index"
              @click="run(item)"
            >
              <span class="palette-icon" :class="`palette-icon--${item.tone || 'plain'}`">
                <SvgIcon :name="item.icon" :size="15" />
              </span>
              <span class="palette-main">
                <span class="palette-title">{{ item.label }}</span>
                <span v-if="item.hint" class="palette-hint">{{ item.hint }}</span>
              </span>
              <SvgIcon v-if="index === activeIndex" name="chevron-right" :size="14" class="palette-go" />
            </li>
          </ul>

          <footer class="palette-foot">
            <span><kbd>↑</kbd><kbd>↓</kbd> навигация</span>
            <span><kbd>Enter</kbd> открыть</span>
            <span><kbd>Ctrl</kbd>+<kbd>K</kbd> закрыть</span>
          </footer>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import { useBoardStore } from '../../stores/board'
import { useUiStore, flattenCards } from '../../stores/ui'
import { useThemeStore } from '../../stores/theme'
import SvgIcon from '../SvgIcon.vue'

const emit = defineEmits(['open-card'])

const store = useBoardStore()
const ui = useUiStore()
const theme = useThemeStore()

const MAX_RESULTS = 8

const inputEl = ref(null)
const query = ref('')
const activeIndex = ref(0)

watch(
  () => ui.paletteOpen,
  async (open) => {
    if (!open) {
      query.value = ''
      activeIndex.value = 0
      window.removeEventListener('keydown', onGlobalKey)
      return
    }
    window.addEventListener('keydown', onGlobalKey)
    await nextTick()
    inputEl.value && inputEl.value.focus()
  },
  { immediate: true },
)

const cardItems = computed(() => {
  const q = query.value.trim().toLowerCase()
  return flattenCards(store)
    .filter(({ card }) => {
      if (!q) return true
      const haystack = `${card.title} ${card.description || ''}`.toLowerCase()
      return haystack.includes(q)
    })
    .slice(0, MAX_RESULTS)
    .map(({ card, column }) => ({
      key: `card-${card.id}`,
      kind: 'card',
      icon: 'file',
      tone: 'card',
      label: card.title,
      hint: column.title,
      card,
    }))
})

const actionItems = computed(() => {
  const q = query.value.trim().toLowerCase()
  const actions = [
    {
      key: 'action-labels',
      kind: 'labels',
      icon: 'palette',
      tone: 'action',
      label: 'Управление метками',
      hint: 'Создать, переименовать, удалить',
    },
    {
      key: 'action-archive',
      kind: 'archive',
      icon: 'archive',
      tone: 'action',
      label: 'Открыть архив',
      hint: 'Восстановить или удалить карточки',
    },
    {
      key: 'action-theme',
      kind: 'theme',
      icon: theme.isDark ? 'sun' : 'moon',
      tone: 'action',
      label: theme.isDark ? 'Светлая тема' : 'Тёмная тема',
      hint: 'Переключить оформление',
    },
    {
      key: 'action-shortcuts',
      kind: 'shortcuts',
      icon: 'keyboard',
      tone: 'action',
      label: 'Горячие клавиши',
      hint: 'Все сочетания',
    },
  ]
  if (!q) return actions
  return actions.filter((action) => action.label.toLowerCase().includes(q))
})

const createItems = computed(() => {
  const title = query.value.trim()
  if (!title || !store.columns.length) return []
  return [
    {
      key: 'create-card',
      kind: 'create',
      icon: 'plus',
      tone: 'create',
      label: `Создать карточку «${title}»`,
      hint: `в колонке «${store.columns[0].title}»`,
      title,
    },
  ]
})

const items = computed(() => {
  const query_ = query.value.trim()
  if (!query_) {
    // Browsing: cards are the useful starting point.
    return [...cardItems.value, ...actionItems.value]
  }
  // Typing: an action ("управление метками") should win over a card whose
  // title merely contains the same word, and "создать" must come last so Enter
  // never silently creates a card when the user meant an action.
  return [...actionItems.value, ...cardItems.value, ...createItems.value]
})

watch(items, () => {
  if (activeIndex.value >= items.value.length) activeIndex.value = 0
})

function move(step) {
  const total = items.value.length
  if (!total) return
  activeIndex.value = (activeIndex.value + step + total) % total
}

function runActive() {
  const item = items.value[activeIndex.value]
  if (item) run(item)
}

async function run(item) {
  close()
  if (item.kind === 'card') {
    emit('open-card', item.card)
    return
  }
  if (item.kind === 'create') {
    const column = store.columns[0]
    if (!column) return
    try {
      await store.createCard(column.id, item.title)
    } catch (e) {
      /* toast */
    }
    return
  }
  if (item.kind === 'labels') {
    ui.openLabels()
    return
  }
  if (item.kind === 'archive') {
    window.dispatchEvent(new CustomEvent('board:open-archive'))
    return
  }
  if (item.kind === 'theme') {
    theme.toggle()
    return
  }
  if (item.kind === 'shortcuts') {
    ui.toggleShortcuts()
  }
}

function close() {
  ui.closePalette()
}

function onKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    close()
  }
}

function onGlobalKey(event) {
  const mod = event.ctrlKey || event.metaKey
  if (mod && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    close()
  }
}

onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKey))
</script>

<style scoped>
.palette-overlay {
  position: fixed;
  inset: 0;
  z-index: 80;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 12vh 16px 16px;
  background: var(--overlay);
  backdrop-filter: blur(4px);
}
.palette {
  width: 100%;
  max-width: 620px;
  display: flex;
  flex-direction: column;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--radius-l);
  box-shadow: var(--shadow-pop);
  overflow: hidden;
}
.palette-search {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 13px 16px;
  border-bottom: 1px solid var(--line);
  color: var(--text-3);
}
.palette-input {
  flex: 1;
  min-width: 0;
  border: none;
  background: transparent;
  outline: none;
  font-size: 15px;
  color: var(--text);
}
.palette-kbd {
  flex: none;
  padding: 2px 6px;
  border-radius: 5px;
  border: 1px solid var(--line);
  background: var(--surface-2);
  font-size: 11px;
  font-weight: 700;
  color: var(--text-3);
}
.palette-list {
  list-style: none;
  margin: 0;
  padding: 6px;
  max-height: 52vh;
  overflow-y: auto;
}
.palette-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  border-radius: var(--radius-m);
  cursor: pointer;
}
.palette-item.active {
  background: var(--accent-soft);
}
.palette-icon {
  flex: none;
  width: 28px;
  height: 28px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
  background: var(--surface-3);
  color: var(--text-2);
}
.palette-icon--create {
  background: var(--accent-soft);
  color: var(--accent);
}
.palette-icon--card {
  background: var(--surface-3);
  color: var(--text-2);
}
.palette-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.palette-title {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--text);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.palette-item.active .palette-title {
  color: var(--accent);
}
.palette-hint {
  font-size: 11.5px;
  color: var(--text-3);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.palette-go {
  flex: none;
  color: var(--accent);
}
.palette-empty {
  padding: 18px 12px;
  text-align: center;
  font-size: 13px;
  color: var(--text-3);
}
.palette-foot {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 9px 14px;
  border-top: 1px solid var(--line);
  background: var(--surface-2);
  font-size: 11.5px;
  color: var(--text-3);
}
.palette-foot kbd {
  display: inline-block;
  margin-right: 3px;
  padding: 1px 5px;
  border-radius: 4px;
  border: 1px solid var(--line-strong);
  background: var(--surface);
  font-size: 10.5px;
  font-weight: 700;
}
.palette-enter-active,
.palette-leave-active {
  transition: opacity 0.15s ease;
}
.palette-enter-from,
.palette-leave-to {
  opacity: 0;
}
.palette-enter-active .palette,
.palette-leave-active .palette {
  transition: transform 0.15s ease;
}
.palette-enter-from .palette,
.palette-leave-to .palette {
  transform: translateY(-8px) scale(0.99);
}

@media (max-width: 560px) {
  .palette-overlay {
    padding-top: 6vh;
  }
  .palette-foot {
    display: none;
  }
}
</style>
