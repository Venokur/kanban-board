<template>
  <div
    ref="root"
    class="datepicker"
    :class="[`datepicker--${size}`, { 'is-open': open, 'is-disabled': disabled, 'is-block': block }]"
  >
    <button
      ref="triggerEl"
      type="button"
      class="datepicker-trigger"
      aria-haspopup="dialog"
      :aria-expanded="open"
      :aria-label="ariaLabel || placeholder"
      :disabled="disabled"
      @click="toggle"
      @keydown="onTriggerKeydown"
    >
      <SvgIcon name="calendar" :size="15" class="datepicker-icon" />
      <span class="datepicker-value" :class="{ 'is-placeholder': !selectedDate }">
        {{ displayValue }}
      </span>
      <SvgIcon name="chevron-down" :size="14" class="datepicker-chevron" />
    </button>

    <Teleport to="body">
      <transition name="dp-pop">
        <div
          v-if="open"
          ref="panelEl"
          class="datepicker-panel"
          :class="{ 'is-dropup': dropUp }"
          :style="panelStyle"
          role="dialog"
          :aria-label="ariaLabel || 'Выбор даты'"
          tabindex="-1"
          @keydown="onPanelKeydown"
        >
          <div class="dp-head">
            <button type="button" class="dp-nav" aria-label="Предыдущий месяц" @click="shiftMonth(-1)">
              <SvgIcon name="chevron-left" :size="16" />
            </button>
            <button
              type="button"
              class="dp-title"
              :aria-label="`${monthName} ${viewYear}, перейти к сегодняшнему дню`"
              @click="goToday"
            >
              {{ monthName }} {{ viewYear }}
            </button>
            <button type="button" class="dp-nav" aria-label="Следующий месяц" @click="shiftMonth(1)">
              <SvgIcon name="chevron-right" :size="16" />
            </button>
          </div>

          <div class="dp-weekdays" aria-hidden="true">
            <span v-for="day in WEEKDAYS" :key="day">{{ day }}</span>
          </div>

          <div class="dp-grid" role="grid">
            <button
              v-for="cell in cells"
              :key="cell.key"
              type="button"
              class="dp-day"
              :class="{
                'is-outside': !cell.inMonth,
                'is-today': cell.isToday,
                'is-selected': cell.isSelected,
                'is-focus': cell.isFocus,
                'is-weekend': cell.isWeekend,
              }"
              role="gridcell"
              :aria-selected="cell.isSelected"
              :aria-current="cell.isToday ? 'date' : undefined"
              :aria-label="cell.fullLabel"
              @click="pick(cell.date)"
              @mouseenter="focusDate = cell.date"
            >
              {{ cell.day }}
            </button>
          </div>

          <div class="dp-foot">
            <button type="button" class="dp-foot-btn" @click="pickToday">Сегодня</button>
            <button
              type="button"
              class="dp-foot-btn dp-foot-btn--muted"
              :disabled="!selectedDate"
              @click="clear"
            >
              Без срока
            </button>
          </div>
        </div>
      </transition>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import SvgIcon from './SvgIcon.vue'

const props = defineProps({
  /** `YYYY-MM-DD` или пустая строка */
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: 'Без срока' },
  /** md — как .input, sm — компактный */
  size: { type: String, default: 'md' },
  block: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false },
  ariaLabel: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'change'])

const MONTHS = [
  'Январь',
  'Февраль',
  'Март',
  'Апрель',
  'Май',
  'Июнь',
  'Июль',
  'Август',
  'Сентябрь',
  'Октябрь',
  'Ноябрь',
  'Декабрь',
]
const MONTHS_GENITIVE = [
  'января',
  'февраля',
  'марта',
  'апреля',
  'мая',
  'июня',
  'июля',
  'августа',
  'сентября',
  'октября',
  'ноября',
  'декабря',
]
const MONTHS_SHORT = [
  'янв',
  'фев',
  'мар',
  'апр',
  'мая',
  'июн',
  'июл',
  'авг',
  'сен',
  'окт',
  'ноя',
  'дек',
]
const WEEKDAYS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']

const uid = Math.random().toString(36).slice(2, 9)
const panelId = `datepicker-${uid}`

const root = ref(null)
const triggerEl = ref(null)
const panelEl = ref(null)

const open = ref(false)
const dropUp = ref(false)
const panelStyle = ref({})

const today = startOfDay(new Date())
const viewYear = ref(today.getFullYear())
const viewMonth = ref(today.getMonth())
const focusDate = ref(today)

const selectedDate = computed(() => parseISO(props.modelValue))
const monthName = computed(() => MONTHS[viewMonth.value])

const displayValue = computed(() =>
  selectedDate.value ? formatShort(selectedDate.value) : props.placeholder,
)

const cells = computed(() => {
  const first = new Date(viewYear.value, viewMonth.value, 1)
  // Понедельник — первый день недели.
  const offset = (first.getDay() + 6) % 7
  const start = addDays(first, -offset)
  const selected = selectedDate.value
  const focus = focusDate.value

  return Array.from({ length: 42 }, (_, index) => {
    const date = addDays(start, index)
    return {
      key: toISO(date),
      date,
      day: date.getDate(),
      inMonth: date.getMonth() === viewMonth.value,
      isToday: sameDay(date, today),
      isSelected: !!selected && sameDay(date, selected),
      isFocus: !!focus && sameDay(date, focus),
      isWeekend: date.getDay() === 0 || date.getDay() === 6,
      fullLabel: formatFull(date),
    }
  })
})

function startOfDay(date) {
  const d = new Date(date)
  d.setHours(0, 0, 0, 0)
  return d
}

function parseISO(iso) {
  if (!iso) return null
  const date = new Date(`${iso}T00:00:00`)
  return Number.isNaN(date.getTime()) ? null : date
}

function toISO(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function addDays(date, amount) {
  const next = new Date(date)
  next.setDate(next.getDate() + amount)
  return next
}

function sameDay(a, b) {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  )
}

function formatFull(date) {
  return `${date.getDate()} ${MONTHS_GENITIVE[date.getMonth()]} ${date.getFullYear()}`
}

function formatShort(date) {
  const sameYear = date.getFullYear() === today.getFullYear()
  return `${date.getDate()} ${MONTHS_SHORT[date.getMonth()]}${sameYear ? '' : ` ${date.getFullYear()}`}`
}

function toggle() {
  if (open.value) close()
  else openMenu()
}

async function openMenu() {
  if (props.disabled || open.value) return
  const base = selectedDate.value || today
  viewYear.value = base.getFullYear()
  viewMonth.value = base.getMonth()
  focusDate.value = base
  open.value = true
  await nextTick()
  position()
  panelEl.value && panelEl.value.focus()
  window.addEventListener('mousedown', onOutside, true)
  window.addEventListener('resize', close)
  window.addEventListener('scroll', onScroll, true)
}

function close(refocus = false) {
  if (!open.value) return
  open.value = false
  window.removeEventListener('mousedown', onOutside, true)
  window.removeEventListener('resize', close)
  window.removeEventListener('scroll', onScroll, true)
  if (refocus) nextTick(() => triggerEl.value && triggerEl.value.focus())
}

function position() {
  const trigger = triggerEl.value
  if (!trigger) return

  const rect = trigger.getBoundingClientRect()
  const gap = 6
  const width = Math.min(Math.max(rect.width, 292), window.innerWidth - 16)
  const panelHeight = panelEl.value ? panelEl.value.offsetHeight : 0
  const spaceBelow = window.innerHeight - rect.bottom - gap
  const spaceAbove = rect.top - gap
  const up = panelHeight > 0 ? spaceBelow < panelHeight && spaceAbove > spaceBelow : spaceBelow < 360
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

function onOutside(event) {
  if (root.value && root.value.contains(event.target)) return
  if (panelEl.value && panelEl.value.contains(event.target)) return
  close()
}

function onScroll(event) {
  if (panelEl.value && panelEl.value.contains(event.target)) return
  close()
}

function shiftMonth(step) {
  const next = new Date(viewYear.value, viewMonth.value + step, 1)
  viewYear.value = next.getFullYear()
  viewMonth.value = next.getMonth()
  // Курсор клавиатуры переезжает в тот же день нового месяца.
  const focus = focusDate.value || today
  const day = Math.min(focus.getDate(), daysInMonth(next.getFullYear(), next.getMonth()))
  focusDate.value = new Date(next.getFullYear(), next.getMonth(), day)
}

function daysInMonth(year, month) {
  return new Date(year, month + 1, 0).getDate()
}

function focusDay(date) {
  focusDate.value = date
  if (date.getMonth() !== viewMonth.value || date.getFullYear() !== viewYear.value) {
    viewYear.value = date.getFullYear()
    viewMonth.value = date.getMonth()
  }
}

function pick(date) {
  const iso = toISO(date)
  const changed = iso !== props.modelValue
  close(true)
  if (changed) {
    emit('update:modelValue', iso)
    emit('change', iso)
  }
}

function pickToday() {
  pick(today)
}

function clear() {
  close(true)
  if (props.modelValue) {
    emit('update:modelValue', '')
    emit('change', '')
  }
}

function goToday() {
  viewYear.value = today.getFullYear()
  viewMonth.value = today.getMonth()
  focusDate.value = today
}

function onTriggerKeydown(event) {
  if (props.disabled) return
  if (!open.value && (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ')) {
    event.preventDefault()
    openMenu()
  } else if (open.value && event.key === 'Escape') {
    event.preventDefault()
    close(true)
  }
}

function onPanelKeydown(event) {
  const focus = focusDate.value || today
  let handled = true

  switch (event.key) {
    case 'Escape':
      close(true)
      break
    case 'ArrowLeft':
      focusDay(addDays(focus, -1))
      break
    case 'ArrowRight':
      focusDay(addDays(focus, 1))
      break
    case 'ArrowUp':
      focusDay(addDays(focus, -7))
      break
    case 'ArrowDown':
      focusDay(addDays(focus, 7))
      break
    case 'Home':
      focusDay(addDays(focus, -((focus.getDay() + 6) % 7)))
      break
    case 'End':
      focusDay(addDays(focus, 6 - ((focus.getDay() + 6) % 7)))
      break
    case 'PageUp':
      shiftMonth(event.shiftKey ? -12 : -1)
      break
    case 'PageDown':
      shiftMonth(event.shiftKey ? 12 : 1)
      break
    case 'Enter':
    case ' ':
      if (focus) pick(focus)
      break
    case 'Tab':
      // Панель телепортирована в конец body — возвращаем фокус на триггер.
      close(true)
      break
    default:
      handled = false
  }

  if (handled) event.preventDefault()
}

watch(
  () => props.modelValue,
  () => {
    if (!open.value) return
    const next = parseISO(props.modelValue)
    if (next) focusDate.value = next
  },
)

onBeforeUnmount(() => close())
</script>

<style scoped>
.datepicker {
  position: relative;
  display: inline-flex;
  max-width: 100%;
  min-width: 0;
}
.datepicker.is-block {
  display: flex;
  width: 100%;
}

.datepicker-trigger {
  display: flex;
  align-items: center;
  gap: 7px;
  width: 100%;
  min-width: 0;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius-m);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-size: 14px;
  text-align: left;
  cursor: pointer;
  transition: border-color 0.13s ease, box-shadow 0.13s ease, background 0.13s ease;
}
.datepicker-trigger:hover {
  border-color: var(--accent);
}
.datepicker-trigger:focus-visible {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.16);
}
.datepicker.is-open .datepicker-trigger {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.16);
}
.datepicker.is-disabled .datepicker-trigger {
  opacity: 0.6;
  cursor: not-allowed;
}

.datepicker--md .datepicker-trigger {
  padding: 9px 12px;
}
.datepicker--sm .datepicker-trigger {
  height: 32px;
  padding: 0 10px 0 12px;
  border-radius: var(--radius-pill);
  font-size: 12.5px;
  font-weight: 600;
}

.datepicker-icon {
  flex: none;
  color: var(--text-3);
}
.datepicker-value {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.datepicker-value.is-placeholder {
  color: var(--text-3);
}
.datepicker-chevron {
  flex: none;
  color: var(--text-3);
  transition: transform 0.15s ease;
}
.datepicker.is-open .datepicker-chevron {
  transform: rotate(180deg);
}

.datepicker-panel {
  position: fixed;
  z-index: 220;
  padding: 10px;
  border: 1px solid var(--line);
  border-radius: var(--radius-l);
  background: var(--surface);
  box-shadow: var(--shadow-pop);
  outline: none;
  user-select: none;
}

.dp-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
  margin-bottom: 8px;
}
.dp-nav {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: none;
  border-radius: var(--radius-s);
  background: transparent;
  color: var(--text-2);
  cursor: pointer;
}
.dp-nav:hover {
  background: var(--surface-3);
  color: var(--accent);
}
.dp-title {
  flex: 1;
  height: 28px;
  border: none;
  border-radius: var(--radius-s);
  background: transparent;
  color: var(--text);
  font: inherit;
  font-size: 13.5px;
  font-weight: 700;
  cursor: pointer;
}
.dp-title:hover {
  background: var(--surface-3);
  color: var(--accent);
}

.dp-weekdays,
.dp-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 2px;
}
.dp-weekdays {
  margin-bottom: 4px;
}
.dp-weekdays span {
  padding: 4px 0;
  text-align: center;
  font-size: 11px;
  font-weight: 700;
  color: var(--text-3);
  text-transform: uppercase;
}

.dp-day {
  position: relative;
  height: 34px;
  border: 1px solid transparent;
  border-radius: var(--radius-s);
  background: transparent;
  color: var(--text);
  font: inherit;
  font-size: 13px;
  cursor: pointer;
  transition: background 0.1s ease, color 0.1s ease, border-color 0.1s ease;
}
.dp-day:hover {
  background: var(--surface-3);
}
.dp-day.is-weekend {
  color: var(--text-2);
}
.dp-day.is-outside {
  color: var(--text-3);
  opacity: 0.55;
}
.dp-day.is-today {
  border-color: var(--accent);
  font-weight: 700;
}
.dp-day.is-focus {
  background: var(--accent-soft);
}
.dp-day.is-selected {
  background: var(--accent);
  border-color: var(--accent);
  color: #fff;
  font-weight: 700;
  box-shadow: 0 6px 14px -6px rgba(108, 92, 231, 0.8);
}
.dp-day.is-selected.is-outside {
  opacity: 0.7;
}

.dp-foot {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid var(--line);
}
.dp-foot-btn {
  padding: 5px 9px;
  border: none;
  border-radius: var(--radius-s);
  background: transparent;
  color: var(--accent);
  font: inherit;
  font-size: 12.5px;
  font-weight: 700;
  cursor: pointer;
}
.dp-foot-btn:hover {
  background: var(--accent-soft);
}
.dp-foot-btn--muted {
  color: var(--text-3);
}
.dp-foot-btn--muted:hover {
  background: var(--surface-3);
  color: var(--text);
}
.dp-foot-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
  background: transparent;
}

.dp-pop-enter-active,
.dp-pop-leave-active {
  transition: opacity 0.13s ease, transform 0.13s ease;
}
.dp-pop-enter-from,
.dp-pop-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
.datepicker-panel.is-dropup.dp-pop-enter-from,
.datepicker-panel.is-dropup.dp-pop-leave-to {
  transform: translateY(4px);
}
</style>
