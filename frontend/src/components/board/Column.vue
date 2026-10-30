<template>
  <section class="column" :class="{ 'has-accent': !!column.accent, 'is-over': overLimit }">
    <header class="column-header">
      <!-- Row 1: drag handle on the left, actions on the right. Keeping the
           actions on their own row leaves the whole width to the title below;
           in a single row five 30px buttons squeezed it down to ~52px and the
           title wrapped one letter per line. -->
      <div class="column-head-top">
        <span
          class="column-grip"
          v-tooltip="store.dragDisabled ? 'Снимите фильтр, чтобы менять порядок' : 'Перетащите колонку'"
          aria-hidden="true"
        >
          <SvgIcon name="grip" :size="14" />
        </span>

        <div class="column-head-actions" :class="{ open: editing }">
          <button
            class="icon-btn move-btn"
            aria-label="Переместить колонку влево"
            v-tooltip="'Переместить влево'"
            @click="move(-1)"
          >
            <SvgIcon name="chevron-left" :size="15" />
          </button>
          <button
            class="icon-btn move-btn"
            aria-label="Переместить колонку вправо"
            v-tooltip="'Переместить вправо'"
            @click="move(1)"
          >
            <SvgIcon name="chevron-right" :size="15" />
          </button>
          <button
            class="icon-btn"
            aria-label="Настроить колонку"
            v-tooltip="'Настроить колонку'"
            :disabled="editing"
            @click="openSettings"
          >
            <SvgIcon name="sliders" :size="15" />
          </button>
          <button
            class="icon-btn"
            aria-label="Переименовать колонку"
            v-tooltip="'Переименовать'"
            @click="startEdit"
          >
            <SvgIcon name="pencil" :size="15" />
          </button>
          <button
            class="icon-btn icon-btn--danger"
            aria-label="Удалить колонку"
            v-tooltip="'Удалить колонку'"
            @click="remove"
          >
            <SvgIcon name="trash" :size="15" />
          </button>
        </div>
      </div>

      <!-- Row 2: title and card counter, using the full column width. -->
      <div class="column-head-main">
        <template v-if="editing">
          <input
            ref="inputEl"
            v-model="draft"
            class="column-title-input"
            maxlength="80"
            aria-label="Название колонки"
            @keydown.enter.prevent="save"
            @keydown.esc="cancel"
            @blur="save"
          />
        </template>
        <template v-else>
          <h3 class="column-title" @dblclick="startEdit">{{ column.title }}</h3>
          <span
            class="column-count"
            :class="{ 'is-over': overLimit }"
            v-tooltip="wipHint"
          >
            {{ cardCount }}<template v-if="column.wipLimit">/{{ column.wipLimit }}</template>
          </span>
        </template>
      </div>
    </header>

    <!-- Column settings: WIP limit + accent -->
    <div v-if="settingsOpen" class="column-settings">
      <label class="settings-row">
        <span class="settings-label">Лимит карточек</span>
        <input
          v-model.number="wipDraft"
          class="input input--tiny"
          type="number"
          min="1"
          max="999"
          inputmode="numeric"
          placeholder="нет"
          aria-label="Лимит карточек в колонке"
        />
      </label>
      <div class="settings-row">
        <span class="settings-label">Цвет</span>
        <button
          v-for="key in accentKeys"
          :key="key"
          class="accent-dot"
          :class="{ on: accentDraft === key }"
          :style="{ background: labelColor(key) }"
          :aria-label="`Цвет колонки: ${key}`"
          :aria-pressed="accentDraft === key"
          @click="accentDraft = accentDraft === key ? '' : key"
        />
      </div>
      <div class="settings-actions">
        <button class="btn btn--xs" :disabled="!settingsDirty" @click="saveSettings">Сохранить</button>
        <button class="btn btn--ghost btn--xs" @click="settingsOpen = false">Отмена</button>
      </div>
    </div>

    <div class="column-cards-scroll">
      <draggable
        class="card-list"
        :list="column.cards"
        group="cards"
        item-key="id"
        :animation="160"
        ghost-class="card-ghost"
        drag-class="card-dragging"
        :force-fallback="true"
        :fallback-on-body="true"
        :disabled="store.dragDisabled"
        @end="emit('cardsOrdered')"
      >
        <template #item="{ element }">
          <CardItem :card="element" @open="emit('open-card', element)" />
        </template>
      </draggable>

      <div class="column-footer">
        <AddCard :column-id="column.id" />
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref, nextTick, computed } from 'vue'
import draggable from 'vuedraggable'
import { useBoardStore } from '../../stores/board'
import { confirmAction } from '../../composables/useConfirm'
import { LABEL_COLOR_KEYS, labelColor } from '../../utils/labels'
import SvgIcon from '../SvgIcon.vue'
import CardItem from './CardItem.vue'
import AddCard from './AddCard.vue'

const props = defineProps({
  column: { type: Object, required: true },
})

const emit = defineEmits(['open-card', 'cardsOrdered'])

const store = useBoardStore()

const editing = ref(false)
const draft = ref('')
const inputEl = ref(null)

const settingsOpen = ref(false)
const wipDraft = ref('')
const accentDraft = ref('')

const accentKeys = LABEL_COLOR_KEYS

const cardCount = computed(() => (props.column.cards || []).length)

const overLimit = computed(
  () => !!props.column.wipLimit && cardCount.value > props.column.wipLimit,
)

const wipHint = computed(() =>
  props.column.wipLimit
    ? `Лимит: ${props.column.wipLimit}${overLimit.value ? ' — превышен' : ''}`
    : String(cardCount.value),
)

const settingsDirty = computed(() => {
  const limit = wipDraft.value === '' || wipDraft.value === null ? null : Number(wipDraft.value)
  const savedLimit = props.column.wipLimit ?? null
  const savedAccent = props.column.accent || ''
  return (limit || null) !== savedLimit || accentDraft.value !== savedAccent
})

async function startEdit() {
  editing.value = true
  draft.value = props.column.title
  await nextTick()
  inputEl.value && inputEl.value.focus()
  inputEl.value && inputEl.value.select()
}

async function save() {
  if (!editing.value) return
  editing.value = false
  const title = draft.value.trim()
  if (title && title !== props.column.title) {
    try {
      await store.renameColumn(props.column.id, title)
    } catch (e) {
      /* the toast reports the error */
    }
  }
}

function cancel() {
  editing.value = false
  draft.value = props.column.title
}

function openSettings() {
  wipDraft.value = props.column.wipLimit ?? ''
  accentDraft.value = props.column.accent || ''
  settingsOpen.value = !settingsOpen.value
}

async function saveSettings() {
  const raw = wipDraft.value
  const limit = raw === '' || raw === null || Number(raw) < 1 ? null : Math.min(999, Math.floor(Number(raw)))
  try {
    await store.updateColumn(props.column.id, {
      wipLimit: limit,
      accent: accentDraft.value || null,
    })
    settingsOpen.value = false
  } catch (e) {
    /* the toast reports the error */
  }
}

async function remove() {
  const ok = await confirmAction({
    title: 'Удалить колонку?',
    message: `Колонка «${props.column.title}» и все её карточки (${cardCount.value}) будут удалены безвозвратно.`,
    confirmText: 'Удалить колонку',
  })
  if (!ok) return
  try {
    await store.deleteColumn(props.column.id)
  } catch (e) {
    /* toast */
  }
}

async function move(dir) {
  try {
    await store.moveColumn(props.column.id, dir)
  } catch (e) {
    /* toast */
  }
}
</script>

<style scoped>
.column {
  width: 280px;
  flex: none;
  max-height: 100%;
  display: flex;
  flex-direction: column;
  background: var(--glass);
  backdrop-filter: blur(14px) saturate(150%);
  -webkit-backdrop-filter: blur(14px) saturate(150%);
  border: 1px solid var(--line);
  border-radius: var(--radius-l);
  box-shadow: var(--shadow-soft);
  position: relative;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.column.is-over {
  border-color: var(--danger);
}
.column-header {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 8px 10px 6px 8px;
}
.column-head-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
}
.column-grip {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 24px;
  border-radius: var(--radius-s);
  color: var(--text-3);
  cursor: grab;
  opacity: 0.5;
  transition: opacity 0.13s ease, background 0.13s ease;
}
.column:hover .column-grip {
  opacity: 1;
}
.column-grip:hover {
  background: var(--surface-3);
  color: var(--text-2);
}
.column-grip:active {
  cursor: grabbing;
}
.column-head-main {
  min-width: 0;
  display: flex;
  align-items: flex-start;
  gap: 8px;
}
.column-title {
  flex: 1 1 auto;
  min-width: 0;
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: var(--text);
  overflow-wrap: anywhere;
  line-height: 1.4;
}
.column-count {
  flex: none;
  margin-top: 0;
  padding: 0 7px;
  min-width: 20px;
  height: 20px;
  line-height: 20px;
  text-align: center;
  border-radius: var(--radius-pill);
  background: var(--accent-soft);
  color: var(--accent);
  font-size: 11px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.column-count.is-over {
  background: var(--due-overdue-bg);
  color: var(--due-overdue-fg);
}
.column-title-input {
  flex: 1;
  min-width: 0;
  border: 1px solid var(--accent);
  border-radius: var(--radius-s);
  font-size: 14px;
  font-weight: 600;
  padding: 5px 8px;
  background: var(--surface);
  color: var(--text);
  outline: none;
  box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.14);
}
.column-head-actions {
  display: flex;
  align-items: center;
  gap: 1px;
  flex: none;
}
.move-btn {
  color: var(--text-3);
}

/* ---------- settings ---------- */
.column-settings {
  margin: 2px 10px 8px;
  padding: 10px;
  border: 1px solid var(--line);
  border-radius: var(--radius-m);
  background: var(--surface-2);
  display: flex;
  flex-direction: column;
  gap: 9px;
}
.settings-row {
  display: flex;
  align-items: center;
  gap: 6px;
}
.settings-label {
  flex: 1;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-2);
}
.input--tiny {
  width: 64px;
  flex: none;
  padding: 5px 8px;
  font-size: 12.5px;
  text-align: center;
}
.accent-dot {
  width: 14px;
  height: 14px;
  flex: none;
  border-radius: 50%;
  border: 2px solid transparent;
  box-shadow: 0 0 0 1px var(--line-strong);
}
.accent-dot.on {
  border-color: var(--surface);
  box-shadow: 0 0 0 2px var(--accent);
}
.settings-actions {
  display: flex;
  gap: 6px;
}

.column-cards-scroll {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 0 10px 10px;
  display: flex;
  flex-direction: column;
}
.card-list {
  flex: 1 1 auto;
  min-height: 24px;
  display: flex;
  flex-direction: column;
}
.column-footer {
  flex: none;
  padding-top: 2px;
}
.card-ghost {
  opacity: 0.45;
  background: var(--surface);
  border: 1px dashed var(--accent);
  border-radius: var(--radius-m);
}
.card-dragging {
  box-shadow: var(--shadow-pop);
  transform: rotate(1.5deg) scale(1.01);
  opacity: 0.95;
}

@media (max-width: 768px) {
  .column {
    width: 86vw;
  }
  .column-grip {
    opacity: 1;
  }
}
</style>
