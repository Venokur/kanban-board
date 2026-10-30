<template>
  <Teleport to="body">
    <transition name="fade">
      <div v-if="open" class="overlay" @mousedown.self="close">
        <div
          ref="dialogEl"
          class="panel"
          role="dialog"
          aria-modal="true"
          aria-labelledby="label-manager-title"
          @keydown="onKeydown"
        >
          <header class="panel-head">
            <div class="panel-title" id="label-manager-title">
              <SvgIcon name="palette" :size="18" />
              <span>Метки доски</span>
            </div>
            <button class="icon-btn" aria-label="Закрыть" v-tooltip="'Закрыть'" @click="close">
              <SvgIcon name="close" :size="18" />
            </button>
          </header>

          <div class="panel-body">
            <p class="panel-hint">
              Метки общие для всей доски. Порядок влияет на то, как они показываются в фильтрах.
            </p>

            <draggable
              v-if="store.labels.length"
              class="label-list"
              :list="orderedLabels"
              item-key="id"
              handle=".label-grip"
              :animation="150"
              @end="saveOrder"
            >
              <template #item="{ element }">
                <div class="label-row">
                  <span class="label-grip" aria-hidden="true">
                    <SvgIcon name="grip" :size="14" />
                  </span>

                  <template v-if="editingId === element.id">
                    <input
                      ref="editInput"
                      v-model="draftName"
                      class="input input--inline"
                      maxlength="40"
                      aria-label="Название метки"
                      @keydown.enter.prevent="saveEdit(element)"
                      @keydown.esc="cancelEdit"
                      @blur="saveEdit(element)"
                    />
                    <SelectMenu
                      v-model="draftColor"
                      class="label-color"
                      :options="colorOptions"
                      size="sm"
                      aria-label="Цвет метки"
                    />
                    <button class="icon-btn" aria-label="Сохранить метку" @mousedown.prevent @click="saveEdit(element)">
                      <SvgIcon name="check" :size="15" />
                    </button>
                  </template>

                  <template v-else>
                    <span class="label-dot" :style="{ background: labelColor(element.color) }" />
                    <span class="label-name">{{ element.name }}</span>
                    <span class="label-count">{{ cardCount(element.id) }}</span>
                    <button
                      class="icon-btn"
                      :aria-label="`Переименовать метку ${element.name}`"
                      v-tooltip="'Переименовать'"
                      @click="startEdit(element)"
                    >
                      <SvgIcon name="pencil" :size="14" />
                    </button>
                    <button
                      class="icon-btn icon-btn--danger"
                      :aria-label="`Удалить метку ${element.name}`"
                      v-tooltip="'Удалить'"
                      @click="remove(element)"
                    >
                      <SvgIcon name="trash" :size="14" />
                    </button>
                  </template>
                </div>
              </template>
            </draggable>

            <p v-else class="empty-state">
              <strong>Меток пока нет</strong>
              Создайте первую — она появится в фильтрах и на карточках.
            </p>

            <form class="create-row" @submit.prevent="create">
              <input
                v-model="newName"
                class="input"
                maxlength="40"
                placeholder="Название новой метки"
                aria-label="Название новой метки"
              />
              <SelectMenu
                v-model="newColor"
                class="label-color"
                :options="colorOptions"
                size="md"
                aria-label="Цвет новой метки"
              />
              <button class="btn btn--xs" type="submit" :disabled="!newName.trim() || busy">
                <SvgIcon name="plus" :size="14" />
                Создать
              </button>
            </form>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import draggable from 'vuedraggable'
import { useBoardStore } from '../../stores/board'
import { confirmAction } from '../../composables/useConfirm'
import { toastError } from '../../composables/useToast'
import { LABEL_COLOR_KEYS, LABEL_COLOR_NAMES, labelColor } from '../../utils/labels'
import SvgIcon from '../SvgIcon.vue'
import SelectMenu from '../SelectMenu.vue'

const props = defineProps({
  open: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const store = useBoardStore()

const dialogEl = ref(null)
const editInput = ref(null)

const newName = ref('')
const newColor = ref(LABEL_COLOR_KEYS[0])
const editingId = ref(null)
const draftName = ref('')
const draftColor = ref(LABEL_COLOR_KEYS[0])
const busy = ref(false)

const colorOptions = LABEL_COLOR_KEYS.map((key) => ({
  value: key,
  label: LABEL_COLOR_NAMES[key],
  color: labelColor(key),
}))

/**
 * Local copy so vuedraggable can mutate the list during a drag without touching
 * the store snapshot; the order is persisted on drop and the snapshot replaces it.
 */
const orderedLabels = computed({
  get: () => store.labels.slice(),
  set: (value) => {
    if (store.board) store.board.labels = value
  },
})

const counts = computed(() => {
  const map = {}
  for (const column of store.columns) {
    for (const card of column.cards || []) {
      for (const label of card.labels || []) {
        map[label.id] = (map[label.id] || 0) + 1
      }
    }
  }
  return map
})

function cardCount(id) {
  const n = counts.value[id] || 0
  return n ? String(n) : '—'
}

watch(
  () => props.open,
  async (open) => {
    if (!open) {
      window.removeEventListener('keydown', onKeydown)
      return
    }
    window.addEventListener('keydown', onKeydown)
    newName.value = ''
    editingId.value = null
    await nextTick()
    dialogEl.value && dialogEl.value.focus()
  },
  { immediate: true },
)

async function create() {
  const name = newName.value.trim()
  if (!name || busy.value) return
  busy.value = true
  try {
    await store.createLabel(name, newColor.value)
    newName.value = ''
  } catch (e) {
    /* toast */
  } finally {
    busy.value = false
  }
}

async function startEdit(label) {
  editingId.value = label.id
  draftName.value = label.name
  draftColor.value = label.color
  await nextTick()
  const input = Array.isArray(editInput.value) ? editInput.value[0] : editInput.value
  input && input.focus()
  input && input.select()
}

function cancelEdit() {
  editingId.value = null
}

async function saveEdit(label) {
  if (editingId.value !== label.id) return
  const name = draftName.value.trim()
  const color = draftColor.value
  editingId.value = null
  if (!name) {
    toastError('Название метки не может быть пустым')
    return
  }
  if (name === label.name && color === label.color) return
  try {
    await store.updateLabel(label.id, { name, color })
  } catch (e) {
    /* toast */
  }
}

async function remove(label) {
  const used = counts.value[label.id] || 0
  const ok = await confirmAction({
    title: 'Удалить метку?',
    message: used
      ? `Метка «${label.name}» снимется с ${used} карточ(ек). Сами карточки останутся.`
      : `Метка «${label.name}» будет удалена.`,
    confirmText: 'Удалить метку',
    icon: 'tag',
  })
  if (!ok) return
  try {
    await store.deleteLabel(label.id)
  } catch (e) {
    /* toast */
  }
}

async function saveOrder() {
  try {
    await store.saveLabelOrder(orderedLabels.value.map((label) => label.id))
  } catch (e) {
    await store.load().catch(() => {})
  }
}

function close() {
  emit('close')
}

function onKeydown(event) {
  if (!props.open) return
  if (event.key === 'Escape') {
    event.preventDefault()
    if (editingId.value !== null) {
      editingId.value = null
      return
    }
    close()
  }
}

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 70;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  background: var(--overlay);
  backdrop-filter: blur(3px);
}
.panel {
  width: 100%;
  max-width: 520px;
  max-height: 78vh;
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
  padding: 14px 18px 18px;
  overflow-y: auto;
}
.panel-hint {
  margin: 0 2px 12px;
  font-size: 12.5px;
  line-height: 1.5;
  color: var(--text-3);
}
.label-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 14px;
}
.label-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 8px 7px 6px;
  border: 1px solid var(--line);
  border-radius: var(--radius-m);
  background: var(--surface-2);
}
.label-grip {
  display: inline-flex;
  color: var(--text-3);
  cursor: grab;
  flex: none;
}
.label-grip:active {
  cursor: grabbing;
}
.label-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex: none;
}
.label-name {
  flex: 1;
  min-width: 0;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--text);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.label-count {
  flex: none;
  min-width: 18px;
  text-align: center;
  font-size: 11.5px;
  font-weight: 700;
  color: var(--text-3);
}
.input--inline {
  flex: 1;
  min-width: 0;
  padding: 6px 9px;
}
.label-color {
  flex: 0 0 130px;
}
.create-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding-top: 14px;
  border-top: 1px solid var(--line);
}
.create-row .input {
  flex: 1;
  min-width: 0;
}
.create-row .btn {
  flex: none;
}
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.16s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

@media (max-width: 560px) {
  .create-row {
    flex-wrap: wrap;
  }
  .create-row .input {
    flex: 1 1 100%;
  }
  .label-color {
    flex: 1;
  }
}
</style>
