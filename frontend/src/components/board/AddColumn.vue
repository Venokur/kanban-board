<template>
  <div class="add-col" :class="{ open }">
    <button v-if="!open" class="add-col-btn" @click="openEditor">
      <span class="add-col-plus"><SvgIcon name="plus" :size="16" /></span>
      <span>Добавить колонку</span>
    </button>

    <div v-else class="add-col-form">
      <input
        ref="input"
        v-model="draft"
        class="input add-col-input"
        placeholder="Название колонки"
        maxlength="80"
        @keydown.enter.prevent="submit"
        @keydown.esc="close"
      />
      <div class="add-col-actions">
        <button class="btn" :disabled="!canSave || saving" @click="submit">
          {{ saving ? '…' : 'Добавить колонку' }}
        </button>
        <button class="icon-btn" aria-label="Отменить добавление" v-tooltip="'Отмена'" @click="close">
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

const store = useBoardStore()

const open = ref(false)
const saving = ref(false)
const draft = ref('')
const input = ref(null)

const canSave = computed(() => draft.value.trim().length > 0)

async function openEditor() {
  open.value = true
  await nextTick()
  input.value && input.value.focus()
}

function close() {
  open.value = false
  draft.value = ''
}

async function submit() {
  const title = draft.value.trim()
  if (!title || saving.value) return
  saving.value = true
  try {
    await store.createColumn(title)
    close()
  } catch (e) {
    /* banner */
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.add-col {
  width: 280px;
  flex: none;
  align-self: flex-start;
}
.add-col-btn {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 12px 14px;
  border: 1px dashed rgba(120, 130, 160, 0.45);
  border-radius: var(--radius-l);
  background: rgba(255, 255, 255, 0.55);
  backdrop-filter: blur(8px);
  color: var(--text-2);
  font-size: 14px;
  font-weight: 600;
  text-align: left;
  transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
}
.add-col-btn:hover {
  background: #fff;
  color: var(--accent);
  border-color: var(--accent);
  border-style: solid;
  box-shadow: var(--shadow-soft);
}
.add-col-plus {
  width: 24px;
  height: 24px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  background: var(--accent-soft);
  color: var(--accent);
}
.add-col.open {
  background: rgba(255, 255, 255, 0.9);
  backdrop-filter: blur(12px);
  border-radius: var(--radius-l);
  padding: 10px;
  box-shadow: var(--shadow-soft);
}
.add-col-input {
  margin-bottom: 8px;
}
.add-col-actions {
  display: flex;
  align-items: center;
  gap: 6px;
}
</style>
