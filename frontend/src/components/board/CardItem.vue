<template>
  <article
    class="card"
    :class="{ 'has-cover': !!card.coverColor }"
    role="button"
    tabindex="0"
    :aria-label="card.title"
    @click="emit('open', card)"
    @keydown.enter.prevent="emit('open', card)"
    @keydown.space.prevent="emit('open', card)"
  >
    <span
      v-if="card.coverColor"
      class="card-cover"
      :style="{ background: labelColor(card.coverColor) }"
      aria-hidden="true"
    />

    <div v-if="labelList.length" class="card-labels">
      <span
        v-for="label in labelList"
        :key="label.id"
        class="card-label"
        :style="{ background: labelColor(label.color) }"
        v-tooltip="label.name"
      />
    </div>

    <div class="card-body">
      <span class="card-title">{{ card.title }}</span>
      <p v-if="card.description" class="card-desc">{{ card.description }}</p>
    </div>

    <footer v-if="hasMeta" class="card-meta">
      <span
        v-if="priority"
        class="meta-chip meta-chip--priority"
        :class="`p--${card.priority}`"
        v-tooltip="`Приоритет: ${priority}`"
      >
        <SvgIcon name="flag" :size="12" />
        {{ priority }}
      </span>

      <span v-if="due" class="meta-chip" :class="`due--${due.tone}`" v-tooltip="dueTooltip">
        <SvgIcon name="calendar" :size="13" />
        {{ due.label }}
      </span>

      <span v-if="checklistTotal" class="meta-chip" :class="{ 'is-complete': checklistDone === checklistTotal }" v-tooltip="'Чек-лист'">
        <SvgIcon name="check" :size="13" />
        {{ checklistDone }}/{{ checklistTotal }}
      </span>

      <span v-if="commentCount" class="meta-chip" v-tooltip="'Комментарии'">
        <SvgIcon name="comment" :size="13" />
        {{ commentCount }}
      </span>

      <span v-if="estimate" class="meta-chip" v-tooltip="'Оценка'">
        <SvgIcon name="clock" :size="12" />
        {{ estimate }}
      </span>

      <span v-if="assignee" class="card-assignee" v-tooltip="`Исполнитель: ${assignee.name}`">
        <Avatar :name="assignee.name" :size="20" />
      </span>
    </footer>
  </article>
</template>

<script setup>
import { computed } from 'vue'
import SvgIcon from '../SvgIcon.vue'
import Avatar from '../Avatar.vue'
import { dueMeta } from '../../utils/time'
import { labelColor, priorityLabel } from '../../utils/labels'

const props = defineProps({
  card: { type: Object, required: true },
})

const emit = defineEmits(['open'])

const labelList = computed(() => props.card.labels || [])
const assignee = computed(() => props.card.assignee || null)
const due = computed(() => dueMeta(props.card.dueDate))
const priority = computed(() => priorityLabel(props.card.priority))
const commentCount = computed(() => (props.card.comments || []).length)
const checklistTotal = computed(() => (props.card.checklist || []).length)
const checklistDone = computed(() => (props.card.checklist || []).filter((i) => i.done).length)

const estimate = computed(() => {
  const value = props.card.estimate
  if (value === null || value === undefined) return ''
  return Number(value).toLocaleString('ru-RU', { maximumFractionDigits: 1 })
})

const dueTooltip = computed(() => (due.value ? `Срок: ${props.card.dueDate}` : ''))

const hasMeta = computed(
  () =>
    !!assignee.value ||
    !!due.value ||
    !!priority.value ||
    checklistTotal.value > 0 ||
    commentCount.value > 0 ||
    !!estimate.value,
)
</script>

<style scoped>
.card {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--radius-m);
  box-shadow: var(--shadow-card);
  padding: 10px 12px;
  margin-bottom: 9px;
  cursor: pointer;
  position: relative;
  transition: box-shadow 0.16s ease, transform 0.16s ease, border-color 0.16s ease;
  user-select: none;
  overflow: hidden;
  outline: none;
}
.card.has-cover {
  padding-top: 12px;
}
.card::before {
  content: '';
  position: absolute;
  inset: 0 auto 0 0;
  width: 3px;
  background: transparent;
  transition: background 0.16s ease;
}
.card:hover,
.card:focus-visible {
  transform: translateY(-1px);
  box-shadow: var(--shadow-card-hover);
  border-color: var(--line-strong);
}
.card:focus-visible {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.18);
}
.card:hover::before,
.card:focus-visible::before {
  background: var(--accent-grad);
}
.card:active {
  cursor: grabbing;
}
.card-cover {
  position: absolute;
  inset: 0 0 auto 0;
  height: 4px;
}
.card-labels {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-bottom: 7px;
}
.card-label {
  height: 5px;
  min-width: 26px;
  max-width: 44px;
  flex: 0 1 44px;
  border-radius: var(--radius-pill);
}
.card-body {
  min-height: 18px;
}
.card-title {
  display: block;
  font-size: 14px;
  font-weight: 500;
  line-height: 1.35;
  color: var(--text);
  word-break: break-word;
  white-space: pre-wrap;
}
.card-desc {
  margin: 6px 0 0;
  font-size: 13px;
  color: var(--text-3);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  word-break: break-word;
}
.card-meta {
  margin-top: 8px;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}
.meta-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 7px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  font-size: 12px;
  font-weight: 600;
  color: var(--text-2);
}
.meta-chip.is-complete {
  background: var(--success-bg);
  color: var(--success);
}
.due--overdue {
  background: var(--due-overdue-bg);
  color: var(--due-overdue-fg);
}
.due--today {
  background: var(--due-today-bg);
  color: var(--due-today-fg);
}
.due--soon {
  background: var(--due-soon-bg);
  color: var(--due-soon-fg);
}
.meta-chip--priority.p--1 {
  background: var(--priority-1-bg);
  color: var(--priority-1-fg);
}
.meta-chip--priority.p--2 {
  background: var(--priority-2-bg);
  color: var(--priority-2-fg);
}
.meta-chip--priority.p--3 {
  background: var(--priority-3-bg);
  color: var(--priority-3-fg);
}
.card-assignee {
  margin-left: auto;
  display: inline-flex;
}
</style>
