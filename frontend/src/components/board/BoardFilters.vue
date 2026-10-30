<template>
  <div class="board-filters" :class="{ active: store.filtersActive }">
    <div class="filters-row">
      <div class="search-box">
        <SvgIcon name="search" :size="15" />
        <input
          ref="searchEl"
          v-model="text"
          class="search-input"
          type="text"
          role="searchbox"
          placeholder="Поиск по карточкам"
          aria-label="Поиск по карточкам"
          @keydown.esc.prevent="onEscape"
        />
        <button
          v-if="text"
          class="search-clear"
          aria-label="Очистить поиск"
          v-tooltip="'Очистить поиск'"
          @click="text = ''"
        >
          <SvgIcon name="close" :size="13" />
        </button>
        <kbd v-else class="search-kbd">/</kbd>
      </div>

      <button class="filter-chip" :class="{ on: store.filters.mine }" @click="toggleMine">
        <Avatar :name="myName" :size="18" />
        <span>Мои</span>
      </button>

      <SelectMenu
        v-model="assignee"
        class="filter-select"
        :options="assigneeOptions"
        placeholder="Все исполнители"
        aria-label="Фильтр по исполнителю"
      />

      <SelectMenu
        v-model="priority"
        class="filter-select"
        :options="priorityOptions"
        placeholder="Любой приоритет"
        aria-label="Фильтр по приоритету"
      />

      <SelectMenu
        v-model="due"
        class="filter-select"
        :options="dueOptions"
        placeholder="Любой срок"
        aria-label="Фильтр по сроку"
      />

      <SelectMenu
        v-model="sortMode"
        class="filter-select"
        :options="sortOptions"
        placeholder="По порядку"
        aria-label="Сортировка карточек"
      />
    </div>

    <div class="filters-row filters-row--labels">
      <span class="labels-label"><SvgIcon name="filter" :size="13" /> Метки</span>

      <button
        v-for="label in store.labels"
        :key="label.id"
        class="filter-chip label-chip"
        :class="{ on: isLabelActive(label.id) }"
        @click="store.toggleLabelFilter(label.id)"
      >
        <span class="label-dot" :style="{ background: labelColor(label.color) }" />
        {{ label.name }}
      </button>

      <span v-if="!store.labels.length" class="hint">Метки создаются в карточке</span>

      <button
        v-if="store.filtersActive || store.sortMode !== 'position'"
        class="btn btn--ghost btn--xs reset"
        @click="resetView"
      >
        <SvgIcon name="close" :size="12" />
        Сбросить
      </button>

      <span v-if="store.hiddenCards" class="hidden">скрыто: {{ store.hiddenCards }}</span>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useBoardStore } from '../../stores/board'
import { useAuthStore } from '../../stores/auth'
import { labelColor } from '../../utils/labels'
import SvgIcon from '../SvgIcon.vue'
import Avatar from '../Avatar.vue'
import SelectMenu from '../SelectMenu.vue'

const store = useBoardStore()
const auth = useAuthStore()

const searchEl = ref(null)

const myName = computed(() => (auth.user ? auth.user.name : 'Я'))

const assigneeOptions = computed(() => [
  { value: '', label: 'Все исполнители' },
  { value: 'none', label: 'Без исполнителя' },
  ...store.users.map((user) => ({ value: user.id, label: user.name })),
])

const priorityOptions = [
  { value: '', label: 'Любой приоритет' },
  { value: 3, label: 'Высокий', hint: '3' },
  { value: 2, label: 'Средний', hint: '2' },
  { value: 1, label: 'Низкий', hint: '1' },
  { value: 'none', label: 'Без приоритета' },
]

const dueOptions = [
  { value: '', label: 'Любой срок' },
  { value: 'overdue', label: 'Просроченные' },
  { value: 'today', label: 'На сегодня' },
  { value: 'week', label: 'На 7 дней' },
  { value: 'none', label: 'Без срока' },
]

const sortOptions = [
  { value: 'position', label: 'По порядку' },
  { value: 'due', label: 'По сроку' },
  { value: 'priority', label: 'По приоритету' },
]

const text = computed({
  get: () => store.filters.text,
  set: (value) => store.setFilter({ text: value }),
})

const assignee = computed({
  get: () => (store.filters.assigneeId === null ? '' : store.filters.assigneeId),
  set: (value) => {
    if (value === '' || value === 'none') store.setFilter({ assigneeId: value === '' ? null : 'none' })
    else store.setFilter({ assigneeId: Number(value) })
  },
})

const priority = computed({
  get: () => (store.filters.priority ? store.filters.priority : ''),
  set: (value) => {
    if (value === 'none') store.setFilter({ priority: 'none' })
    else store.setFilter({ priority: value === '' ? 0 : Number(value) })
  },
})

const due = computed({
  get: () => store.filters.due,
  set: (value) => store.setDueFilter(value),
})

const sortMode = computed({
  get: () => store.sortMode,
  set: (value) => store.setSortMode(value),
})

function toggleMine() {
  store.setFilter({ mine: !store.filters.mine })
}

/** Clears filters *and* a non-default sort, so the drag & drop lock is released too. */
function resetView() {
  store.resetFilters()
  store.setSortMode('position')
}

function isLabelActive(id) {
  return store.filters.labelIds.includes(id)
}

/** Esc in the search box clears the query, then leaves the field. */
function onEscape() {
  if (text.value) {
    text.value = ''
    return
  }
  searchEl.value && searchEl.value.blur()
}

/** Global `/` shortcut lands here. */
function focusSearch() {
  const el = searchEl.value
  if (!el) return
  el.focus()
  el.select()
}

defineExpose({ focusSearch })
</script>

<style scoped>
.board-filters {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 0 22px 12px;
  position: relative;
  z-index: 2;
}
.filters-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.search-box {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 220px;
  padding: 5px 10px;
  border-radius: var(--radius-pill);
  border: 1px solid var(--line);
  background: var(--surface);
  color: var(--text-3);
  transition: border-color 0.13s ease, box-shadow 0.13s ease;
}
.search-box:focus-within {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.14);
}
.search-input {
  flex: 1;
  min-width: 120px;
  border: none;
  background: transparent;
  outline: none;
  font-size: 13px;
  color: var(--text);
}
.search-input::placeholder {
  color: var(--text-3);
}
.search-input::-webkit-search-cancel-button {
  display: none;
}
.search-clear {
  display: inline-flex;
  border: none;
  background: none;
  color: var(--text-3);
  padding: 2px;
  border-radius: 50%;
}
.search-clear:hover {
  color: var(--text);
}
.search-kbd {
  flex: none;
  padding: 1px 6px;
  border-radius: 5px;
  border: 1px solid var(--line);
  background: var(--surface-2);
  font-size: 10.5px;
  font-weight: 700;
  color: var(--text-3);
}
.filter-select {
  max-width: 190px;
  flex: 0 1 auto;
}
.filter-select :deep(.select-trigger) {
  height: 32px;
  padding: 0 10px 0 12px;
  border-radius: var(--radius-pill);
  background: var(--surface);
  font-size: 12.5px;
  font-weight: 600;
}
.filter-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 32px;
  padding: 0 11px;
  border-radius: var(--radius-pill);
  border: 1px solid var(--line);
  background: var(--surface);
  color: var(--text-2);
  font-size: 12.5px;
  font-weight: 600;
  transition: background 0.13s ease, border-color 0.13s ease, color 0.13s ease;
}
.filter-chip:hover {
  border-color: var(--line-strong);
  color: var(--text);
}
.filter-chip.on {
  background: var(--accent-soft);
  border-color: var(--accent);
  color: var(--accent);
}
.label-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  flex: none;
}
.labels-label {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-3);
}
.hint,
.hidden {
  font-size: 12px;
  color: var(--text-3);
}
.hidden {
  margin-left: auto;
  font-weight: 600;
}
.reset {
  margin-left: 2px;
}

@media (max-width: 768px) {
  .board-filters {
    padding: 0 14px 10px;
  }
  .search-box {
    flex: 1 1 100%;
    min-width: 0;
  }
  .search-kbd {
    display: none;
  }
  .filter-select {
    flex: 1 1 45%;
    max-width: none;
  }
}
</style>
