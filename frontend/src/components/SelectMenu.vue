<template>
  <div
    ref="root"
    class="select"
    :class="[`select--${size}`, { 'is-disabled': disabled, 'is-block': block, 'is-open': open }]"
  >
    <button
      ref="triggerEl"
      type="button"
      class="select-trigger"
      role="combobox"
      aria-haspopup="listbox"
      :aria-expanded="open"
      :aria-controls="listId"
      :aria-label="ariaLabel || placeholder"
      :disabled="disabled"
      @click="toggle"
      @keydown="onTriggerKeydown"
    >
      <span
        v-if="selectedOption && selectedOption.color"
        class="select-dot"
        :style="{ background: selectedOption.color }"
      />
      <span class="select-value" :class="{ 'is-placeholder': !selectedOption }">
        {{ selectedOption ? selectedOption.label : placeholder }}
      </span>
      <SvgIcon name="chevron-down" :size="14" class="select-chevron" />
    </button>

    <Teleport to="body">
      <transition name="select-pop">
        <div
          v-if="open"
          :id="listId"
          ref="panelEl"
          class="select-panel"
          :class="[`select-panel--${size}`, { 'is-dropup': dropUp }]"
          :style="panelStyle"
          role="listbox"
          :aria-label="ariaLabel || placeholder"
          @mousedown.prevent
        >
          <button
            v-for="(option, index) in options"
            :id="`${listId}-${index}`"
            :key="index"
            type="button"
            class="select-option"
            :class="{ 'is-active': index === activeIndex, 'is-selected': isSelected(option) }"
            role="option"
            :aria-selected="isSelected(option)"
            @mouseenter="activeIndex = index"
            @click="pick(option)"
          >
            <span
              v-if="option.color"
              class="select-dot"
              :style="{ background: option.color }"
            />
            <span class="select-option-label">{{ option.label }}</span>
            <span v-if="option.hint" class="select-option-hint">{{ option.hint }}</span>
            <SvgIcon v-if="isSelected(option)" name="check" :size="14" class="select-option-check" />
          </button>

          <p v-if="!options.length" class="select-empty">Нет вариантов</p>
        </div>
      </transition>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import SvgIcon from './SvgIcon.vue'

const props = defineProps({
  modelValue: { type: [String, Number, Boolean], default: '' },
  /** [{ value, label, color?, hint? }] */
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'Выберите…' },
  ariaLabel: { type: String, default: '' },
  /** sm — компактный (панель фильтров), md — как .input (формы) */
  size: { type: String, default: 'sm' },
  disabled: { type: Boolean, default: false },
  block: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'change'])

let counter = 0
const listId = `select-${Math.random().toString(36).slice(2, 9)}`

const root = ref(null)
const triggerEl = ref(null)
const panelEl = ref(null)

const open = ref(false)
const activeIndex = ref(-1)
const dropUp = ref(false)
const panelStyle = ref({})

const selectedOption = computed(
  () => props.options.find((option) => sameValue(option.value, props.modelValue)) || null,
)

const selectedIndex = computed(() =>
  props.options.findIndex((option) => sameValue(option.value, props.modelValue)),
)

function sameValue(a, b) {
  if (a === b) return true
  if (a === null || a === undefined || b === null || b === undefined) return false
  return String(a) === String(b)
}

function isSelected(option) {
  return sameValue(option.value, props.modelValue)
}

function toggle() {
  if (open.value) close()
  else openMenu()
}

async function openMenu() {
  if (props.disabled || open.value) return
  open.value = true
  activeIndex.value = selectedIndex.value >= 0 ? selectedIndex.value : 0
  await nextTick()
  position()
  activeIndex.value = scrollActiveIntoView()
  window.addEventListener('mousedown', onOutside, true)
  window.addEventListener('resize', close)
  window.addEventListener('scroll', onScroll, true)
}

function close() {
  if (!open.value) return
  open.value = false
  activeIndex.value = -1
  window.removeEventListener('mousedown', onOutside, true)
  window.removeEventListener('resize', close)
  window.removeEventListener('scroll', onScroll, true)
}

function position() {
  const trigger = triggerEl.value
  if (!trigger) return

  const rect = trigger.getBoundingClientRect()
  const gap = 6
  const width = Math.min(Math.max(rect.width, 176), window.innerWidth - 16)
  const panelHeight = panelEl.value ? panelEl.value.offsetHeight : 0
  const spaceBelow = window.innerHeight - rect.bottom - gap
  const spaceAbove = rect.top - gap

  const up = panelHeight > 0 ? spaceBelow < panelHeight && spaceAbove > spaceBelow : spaceBelow < 220
  dropUp.value = up

  const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))
  panelStyle.value = {
    left: `${left}px`,
    width: `${width}px`,
    ...(up
      ? { bottom: `${Math.max(8, window.innerHeight - rect.top + gap)}px` }
      : { top: `${rect.bottom + gap}px` }),
  }
}

function scrollActiveIntoView() {
  const panel = panelEl.value
  if (!panel || activeIndex.value < 0) return activeIndex.value
  const node = panel.children[activeIndex.value]
  if (node && node.scrollIntoView) node.scrollIntoView({ block: 'nearest' })
  return activeIndex.value
}

function onOutside(event) {
  const target = event.target
  if (root.value && root.value.contains(target)) return
  if (panelEl.value && panelEl.value.contains(target)) return
  close()
}

function onScroll(event) {
  if (panelEl.value && panelEl.value.contains(event.target)) return
  close()
}

function move(step) {
  const total = props.options.length
  if (!total) return
  const current = activeIndex.value
  const next = (current + step + total) % total
  activeIndex.value = next
  scrollActiveIntoView()
}

function pick(option) {
  const changed = !sameValue(option.value, props.modelValue)
  close()
  if (changed) {
    emit('update:modelValue', option.value)
    emit('change', option.value)
  }
  nextTick(() => triggerEl.value && triggerEl.value.focus())
}

function onTriggerKeydown(event) {
  if (props.disabled) return
  const key = event.key

  if (!open.value) {
    if (key === 'ArrowDown' || key === 'ArrowUp' || key === 'Enter' || key === ' ') {
      event.preventDefault()
      openMenu()
    }
    return
  }

  switch (key) {
    case 'Escape':
      event.preventDefault()
      close()
      break
    case 'Tab':
      close()
      break
    case 'ArrowDown':
      event.preventDefault()
      move(1)
      break
    case 'ArrowUp':
      event.preventDefault()
      move(-1)
      break
    case 'Home':
      event.preventDefault()
      activeIndex.value = 0
      scrollActiveIntoView()
      break
    case 'End':
      event.preventDefault()
      activeIndex.value = props.options.length - 1
      scrollActiveIntoView()
      break
    case 'Enter':
    case ' ':
      event.preventDefault()
      if (props.options[activeIndex.value]) pick(props.options[activeIndex.value])
      break
  }
}

// Keep the highlighted option in sync when the model changes from outside.
watch(
  () => props.modelValue,
  () => {
    if (open.value) activeIndex.value = selectedIndex.value >= 0 ? selectedIndex.value : 0
  },
)

onBeforeUnmount(close)
</script>

<style scoped>
.select {
  position: relative;
  display: inline-flex;
  max-width: 100%;
  min-width: 0;
}
.select.is-block {
  display: flex;
  width: 100%;
}

.select-trigger {
  display: flex;
  align-items: center;
  gap: 7px;
  width: 100%;
  min-width: 0;
  border: 1px solid var(--line);
  background: rgba(255, 255, 255, 0.85);
  color: var(--text-2);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
  transition: border-color 0.13s ease, background 0.13s ease, color 0.13s ease,
    box-shadow 0.13s ease;
}
.select-trigger:hover {
  border-color: var(--line-strong);
  color: var(--text);
}
.select-trigger:focus-visible {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.16);
}
.select.is-open .select-trigger {
  border-color: var(--accent);
  color: var(--text);
  box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.16);
}
.select.is-disabled .select-trigger {
  opacity: 0.6;
  cursor: not-allowed;
}

.select--sm .select-trigger {
  height: 32px;
  padding: 0 10px 0 12px;
  border-radius: var(--radius-pill);
  font-size: 12.5px;
}
.select--md .select-trigger {
  padding: 9px 12px;
  border-radius: var(--radius-m);
  border-color: var(--line-strong);
  background: var(--surface);
  color: var(--text);
  font-size: 14px;
  font-weight: 400;
}

.select-value {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-align: left;
}
.select-value.is-placeholder {
  color: var(--text-3);
}
.select-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  flex: none;
}
.select-chevron {
  flex: none;
  color: var(--text-3);
  transition: transform 0.15s ease;
}
.select.is-open .select-chevron {
  transform: rotate(180deg);
}

.select-panel {
  position: fixed;
  z-index: 200;
  padding: 5px;
  border: 1px solid var(--line);
  border-radius: var(--radius-m);
  background: var(--surface);
  box-shadow: var(--shadow-pop);
  max-height: 280px;
  overflow-y: auto;
  overscroll-behavior: contain;
}
.select-panel--sm {
  border-radius: var(--radius-m);
}

.select-option {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 8px 10px;
  border: none;
  border-radius: var(--radius-s);
  background: transparent;
  color: var(--text-2);
  font: inherit;
  font-size: 13.5px;
  text-align: left;
  cursor: pointer;
}
.select-option.is-active {
  background: var(--accent-soft);
  color: var(--accent);
}
.select-option.is-selected {
  color: var(--text);
  font-weight: 700;
}
.select-option-label {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.select-option-hint {
  flex: none;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--text-3);
}
.select-option-check {
  flex: none;
  color: var(--accent);
}
.select-empty {
  margin: 6px 8px;
  font-size: 12.5px;
  color: var(--text-3);
}

.select-pop-enter-active,
.select-pop-leave-active {
  transition: opacity 0.13s ease, transform 0.13s ease;
}
.select-pop-enter-from,
.select-pop-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
.select-panel.is-dropup.select-pop-enter-from,
.select-panel.is-dropup.select-pop-leave-to {
  transform: translateY(4px);
}
</style>
