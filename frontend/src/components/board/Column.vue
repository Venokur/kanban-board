<template>
  <section class="column">
    <header class="column-header">
      <div class="column-head-main">
        <template v-if="editing">
          <input
            ref="inputEl"
            v-model="draft"
            class="column-title-input"
            maxlength="80"
            @keydown.enter.prevent="save"
            @keydown.esc="cancel"
            @blur="save"
          />
        </template>
        <template v-else>
          <h3 class="column-title" @dblclick="startEdit">{{ column.title }}</h3>
          <span class="column-count">{{ cardCount }}</span>
        </template>
      </div>

      <div class="column-head-actions" :class="{ open: editing || confirming }">
        <button class="icon-btn move-btn" title="Переместить колонку влево" @click="move(-1)">
          <SvgIcon name="chevron-left" :size="15" />
        </button>
        <button class="icon-btn move-btn" title="Переместить колонку вправо" @click="move(1)">
          <SvgIcon name="chevron-right" :size="15" />
        </button>
        <button class="icon-btn" title="Переименовать колонку" @click="startEdit">
          <SvgIcon name="pencil" :size="15" />
        </button>
        <template v-if="!confirming">
          <button class="icon-btn icon-btn--danger" title="Удалить колонку" @click="confirming = true">
            <SvgIcon name="trash" :size="15" />
          </button>
        </template>
        <template v-else>
          <button class="btn btn--danger btn--xs" @mousedown.prevent="remove">Удалить</button>
          <button class="icon-btn" title="Отмена" @click="confirming = false">
            <SvgIcon name="close" :size="15" />
          </button>
        </template>
      </div>
    </header>

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
import SvgIcon from '../SvgIcon.vue'
import CardItem from './CardItem.vue'
import AddCard from './AddCard.vue'

const props = defineProps({
  column: { type: Object, required: true },
})

const emit = defineEmits(['open-card', 'cardsOrdered'])

const store = useBoardStore()

const editing = ref(false)
const confirming = ref(false)
const draft = ref('')
const inputEl = ref(null)

const cardCount = computed(() => (props.column.cards || []).length)

async function startEdit() {
  editing.value = true
  draft.value = props.column.title
  confirming.value = false
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
      /* global error shows banner */
    }
  }
}

function cancel() {
  editing.value = false
  draft.value = props.column.title
}

async function remove() {
  confirming.value = false
  try {
    await store.deleteColumn(props.column.id)
  } catch (e) {
    /* handled by banner */
  }
}

async function move(dir) {
  try {
    await store.moveColumn(props.column.id, dir)
  } catch (e) {
    /* handled by banner */
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
  border: 1px solid rgba(255, 255, 255, 0.7);
  border-radius: var(--radius-l);
  box-shadow: 0 10px 26px -16px rgba(32, 38, 59, 0.28);
}
.column-header {
  display: flex;
  align-items: flex-start;
  gap: 2px;
  padding: 12px 10px 6px 14px;
}
.column-head-main {
  flex: 1;
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
  margin-top: 3px;
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
}
.column-title-input {
  flex: 1;
  min-width: 0;
  border: 1px solid var(--accent);
  border-radius: var(--radius-s);
  font-size: 14px;
  font-weight: 600;
  padding: 5px 8px;
  background: #fff;
  outline: none;
  box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.14);
}
.column-head-actions {
  display: flex;
  align-items: center;
  gap: 1px;
  flex: none;
  margin-top: -2px;
}
.move-btn {
  opacity: 0;
}
.column:hover .move-btn,
.column-head-actions.open .move-btn {
  opacity: 1;
}
.btn--xs {
  padding: 5px 11px;
  font-size: 12px;
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
  background: #fff;
  border: 1px dashed var(--accent);
  border-radius: var(--radius-m);
}
.card-dragging {
  box-shadow: var(--shadow-pop);
  transform: rotate(1.5deg) scale(1.01);
  opacity: 0.95;
}
</style>
