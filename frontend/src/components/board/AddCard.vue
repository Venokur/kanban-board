<template>
  <div class="add-card">
    <button v-if="!open" class="add-card-btn" @click="openEditor">
      <SvgIcon name="plus" :size="16" />
      <span>Добавить карточку</span>
    </button>

    <div v-else class="add-card-form">
      <textarea
        ref="area"
        v-model="draft"
        class="textarea"
        rows="3"
        maxlength="1000"
        placeholder="Введите заголовок карточки"
        @keydown.esc="close"
        @keydown.enter="onKey"
      />
      <div class="add-card-actions">
        <button class="btn" :disabled="!canSave || saving" @click="submit">
          {{ saving ? '…' : 'Добавить' }}
        </button>
        <button class="icon-btn" title="Отмена" @click="close">
          <SvgIcon name="close" :size="16" />
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, nextTick, computed } from 'vue'
import { useBoardStore } from '../../stores/board'
import SvgIcon from '../SvgIcon.vue'

const props = defineProps({
  columnId: { type: Number, required: true },
})

const store = useBoardStore()

const open = ref(false)
const saving = ref(false)
const draft = ref('')
const area = ref(null)

const canSave = computed(() => draft.value.trim().length > 0)

async function openEditor() {
  open.value = true
  saving.value = false
  await nextTick()
  area.value && area.value.focus()
}

function close() {
  open.value = false
  draft.value = ''
}

function onKey(e) {
  if (e.shiftKey || e.isComposing) return
  e.preventDefault()
  submit()
}

async function submit() {
  const title = draft.value.trim()
  if (!title || saving.value) return
  saving.value = true
  try {
    await store.createCard(props.columnId, title)
    draft.value = ''
    area.value && area.value.focus()
  } catch (e) {
    /* banner */
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.add-card {
  margin-top: 2px;
}
.add-card-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 8px 10px;
  border: none;
  border-radius: var(--radius-s);
  background: transparent;
  color: var(--text-3);
  font-size: 14px;
  font-weight: 600;
  text-align: left;
  transition: background 0.12s ease, color 0.12s ease;
}
.add-card-btn:hover {
  background: var(--surface-3);
  color: var(--accent);
}
.add-card-form .textarea {
  margin-top: 2px;
  background: #fff;
}
.add-card-actions {
  margin-top: 6px;
  display: flex;
  align-items: center;
  gap: 6px;
}
</style>
