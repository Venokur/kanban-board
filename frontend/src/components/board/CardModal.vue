<template>
  <Teleport to="body">
    <transition name="slide">
      <div v-if="visible" class="overlay" @mousedown.self="close">
        <div
          ref="panelEl"
          class="panel"
          role="dialog"
          aria-modal="true"
          aria-label="Карточка"
          tabindex="-1"
          @keydown="onPanelKeydown"
        >
          <header class="panel-top">
            <div v-if="watchingCount > 1" class="watching" v-tooltip="'Столько людей сейчас смотрят карточку'">
              <SvgIcon name="eye" :size="14" />
              смотрят: {{ watchingCount }}
            </div>
            <button
              class="icon-btn"
              aria-label="Закрыть карточку"
              v-tooltip="'Закрыть (Esc)'"
              @click="close"
            >
              <SvgIcon name="close" :size="20" />
            </button>
          </header>

          <div class="panel-body">
            <!-- Title -->
            <textarea
              v-model="title"
              class="title-input"
              rows="1"
              maxlength="1000"
              aria-label="Заголовок карточки"
              @keydown.enter.prevent="$event.target.blur()"
              @blur="saveTitle"
            />

            <div class="meta-line">
              <span v-if="liveAuthor" class="meta-chip">
                <Avatar :name="liveAuthor.name" :size="22" />
                <span>{{ liveAuthor.name }}</span>
              </span>
              <span v-if="liveCard && liveCard.createdAt" class="meta-chip">
                <SvgIcon name="clock" :size="14" />
                создана {{ timeAgo(liveCard.createdAt) }}
              </span>
              <span v-if="due" class="meta-chip meta-due" :class="`due--${due.tone}`">
                <SvgIcon name="calendar" :size="14" />
                {{ due.label }}
              </span>
              <span v-if="priority" class="meta-chip" :class="`meta-priority p--${priorityValue}`">
                <SvgIcon name="flag" :size="14" />
                {{ priority }}
              </span>
            </div>

            <!-- Properties -->
            <section class="block">
              <h4 class="block-title">
                <SvgIcon name="sliders" :size="16" />
                <span>Свойства</span>
              </h4>

              <div class="props-grid">
                <div class="prop">
                  <span class="prop-label">Исполнитель</span>
                  <SelectMenu
                    :model-value="assigneeValue"
                    :options="assigneeOptions"
                    placeholder="Не назначен"
                    size="md"
                    block
                    aria-label="Исполнитель"
                    @change="setAssignee"
                  />
                </div>

                <div class="prop">
                  <span class="prop-label">Срок</span>
                  <DatePicker
                    :model-value="dueValue"
                    aria-label="Срок карточки"
                    placeholder="Без срока"
                    @change="setDueDate"
                  />
                </div>

                <div class="prop">
                  <span class="prop-label">Оценка</span>
                  <div class="estimate-row">
                    <input
                      v-model="estimateDraft"
                      class="input"
                      type="number"
                      min="0"
                      max="9999"
                      step="0.5"
                      inputmode="decimal"
                      placeholder="—"
                      aria-label="Оценка карточки"
                      @blur="saveEstimate"
                      @keydown.enter.prevent="$event.target.blur()"
                    />
                    <span class="prop-suffix">ч</span>
                  </div>
                </div>

                <div class="prop">
                  <span class="prop-label">Обложка</span>
                  <div class="cover-row">
                    <button
                      class="cover-dot cover-dot--none"
                      :class="{ on: !coverValue }"
                      aria-label="Без обложки"
                      :aria-pressed="!coverValue"
                      v-tooltip="'Без обложки'"
                      @click="setCover('')"
                    />
                    <button
                      v-for="key in LABEL_COLOR_KEYS"
                      :key="key"
                      class="cover-dot"
                      :class="{ on: coverValue === key }"
                      :style="{ background: labelSwatch(key) }"
                      :aria-label="`Обложка: ${LABEL_COLOR_NAMES[key]}`"
                      :aria-pressed="coverValue === key"
                      @click="setCover(key)"
                    />
                  </div>
                </div>

                <div class="prop prop--wide">
                  <span class="prop-label">Приоритет</span>
                  <div class="priority-row">
                    <button
                      v-for="option in PRIORITIES"
                      :key="option.value"
                      class="priority-btn"
                      :class="[`p--${option.value}`, { on: priorityValue === option.value }]"
                      :aria-pressed="priorityValue === option.value"
                      @click="setPriority(option.value)"
                    >
                      {{ option.label }}
                    </button>
                  </div>
                </div>
              </div>
            </section>

            <!-- Description -->
            <section class="block">
              <h4 class="block-title">
                <SvgIcon name="file" :size="16" />
                <span>Описание</span>
              </h4>

              <div v-if="!editingDesc" class="desc-view" @click="startEditingDesc">
                <p v-if="description.trim()" class="desc-text">{{ description }}</p>
                <p v-else class="desc-empty">Добавьте более подробное описание…</p>
              </div>

              <div v-else class="desc-edit">
                <textarea
                  ref="descEl"
                  v-model="description"
                  class="textarea"
                  rows="5"
                  maxlength="20000"
                  placeholder="Добавьте более подробное описание…"
                  aria-label="Описание карточки"
                />
                <div class="inline-actions">
                  <button class="btn" :disabled="saving" @click="saveDescription">Сохранить</button>
                  <button class="btn btn--ghost" @click="cancelEditingDesc">Отмена</button>
                </div>
              </div>
            </section>

            <!-- Labels -->
            <section class="block">
              <h4 class="block-title">
                <SvgIcon name="tag" :size="16" />
                <span>Метки</span>
                <span v-if="labels.length" class="count-pill">{{ labels.length }}</span>
              </h4>

              <div class="label-picker">
                <button
                  v-for="label in store.labels"
                  :key="label.id"
                  class="label-chip"
                  :class="{ on: hasLabel(label.id) }"
                  :aria-pressed="hasLabel(label.id)"
                  @click="toggleLabel(label.id)"
                >
                  <span class="label-dot" :style="{ background: labelSwatch(label.color) }" />
                  {{ label.name }}
                </button>

                <button class="label-add" @click="showLabelForm = !showLabelForm">
                  <SvgIcon name="plus" :size="13" />
                  метка
                </button>
              </div>

              <div v-if="showLabelForm" class="label-form">
                <input
                  v-model="labelName"
                  class="input"
                  maxlength="40"
                  placeholder="Название метки"
                  aria-label="Название новой метки"
                  @keydown.enter.prevent="createLabel"
                />
                <SelectMenu
                  v-model="labelColor"
                  class="label-color"
                  :options="labelColorOptions"
                  placeholder="Цвет"
                  size="md"
                  aria-label="Цвет метки"
                />
                <button class="btn btn--xs" :disabled="!labelName.trim()" @click="createLabel">
                  Создать
                </button>
              </div>
            </section>

            <!-- Checklist -->
            <section class="block">
              <h4 class="block-title">
                <SvgIcon name="check" :size="16" />
                <span>Чек-лист</span>
                <span v-if="checklistTotal" class="count-pill">{{ checklistDone }}/{{ checklistTotal }}</span>
              </h4>

              <div v-if="checklistTotal" class="progress">
                <span class="progress-fill" :style="{ width: `${progressPercent}%` }" />
              </div>

              <draggable
                v-if="checklistTotal"
                v-model="checklistLocal"
                class="checklist"
                item-key="id"
                handle=".check-grip"
                :animation="140"
                @end="saveChecklistOrder"
              >
                <template #item="{ element }">
                  <li class="checklist-item" :class="{ done: element.done }">
                    <span class="check-grip" aria-hidden="true">
                      <SvgIcon name="grip" :size="12" />
                    </span>
                    <button
                      class="check-box"
                      :class="{ on: element.done }"
                      role="checkbox"
                      :aria-checked="element.done"
                      :aria-label="element.done ? 'Снять отметку' : 'Отметить выполненным'"
                      @click="toggleChecklist(element)"
                    >
                      <SvgIcon v-if="element.done" name="check" :size="12" />
                    </button>
                    <span class="checklist-text">{{ element.text }}</span>
                    <button
                      class="icon-btn icon-btn--danger checklist-del"
                      aria-label="Удалить пункт чек-листа"
                      v-tooltip="'Удалить пункт'"
                      @click="removeChecklist(element)"
                    >
                      <SvgIcon name="trash" :size="12" />
                    </button>
                  </li>
                </template>
              </draggable>

              <div class="checklist-add">
                <input
                  v-model="newChecklist"
                  class="input"
                  maxlength="500"
                  placeholder="Новый пункт чек-листа"
                  aria-label="Новый пункт чек-листа"
                  @keydown.enter.prevent="addChecklist"
                />
                <button class="btn btn--xs" :disabled="!newChecklist.trim()" @click="addChecklist">
                  Добавить
                </button>
              </div>
            </section>

            <!-- Comments (always read from the live board store) -->
            <section class="block">
              <h4 class="block-title">
                <SvgIcon name="comment" :size="16" />
                <span>Комментарии</span>
                <span v-if="commentCount" class="count-pill">{{ commentCount }}</span>
              </h4>

              <div v-if="commentCount" class="comments">
                <article v-for="comment in liveComments" :key="comment.id" class="comment">
                  <div class="comment-head">
                    <Avatar :name="authorName(comment)" :size="24" />
                    <div class="comment-head-info">
                      <span class="comment-author">{{ authorName(comment) }}</span>
                      <span class="comment-time">{{ timeAgo(comment.createdAt) }}</span>
                    </div>
                    <button
                      v-if="canDeleteComment(comment)"
                      class="icon-btn icon-btn--danger comment-del"
                      aria-label="Удалить комментарий"
                      v-tooltip="'Удалить комментарий'"
                      @click="removeComment(comment)"
                    >
                      <SvgIcon name="trash" :size="13" />
                    </button>
                  </div>
                  <p class="comment-body">{{ comment.body }}</p>
                </article>
              </div>
              <p v-else class="comments-empty">Комментариев пока нет — будьте первым.</p>

              <div class="new-comment">
                <Avatar :name="currentUserName" :size="26" />
                <div class="new-comment-form">
                  <textarea
                    ref="commentEl"
                    v-model="newComment"
                    class="textarea"
                    rows="2"
                    maxlength="5000"
                    placeholder="Напишите комментарий…"
                    aria-label="Новый комментарий"
                    @keydown="onCommentKey"
                  />
                  <div v-if="newComment.trim()" class="inline-actions">
                    <button class="btn" :disabled="saving" @click="submitComment">Добавить</button>
                  </div>
                </div>
              </div>
            </section>

            <!-- Activity -->
            <section class="block">
              <h4 class="block-title">
                <SvgIcon name="history" :size="16" />
                <span>История</span>
                <button
                  class="icon-btn block-refresh"
                  aria-label="Обновить историю"
                  v-tooltip="'Обновить'"
                  @click="loadEvents"
                >
                  <SvgIcon name="clock" :size="13" />
                </button>
              </h4>

              <ul v-if="events.length" class="timeline">
                <li v-for="event in events" :key="event.id" class="timeline-item">
                  <span class="timeline-dot" />
                  <span class="timeline-text">
                    <strong>{{ event.user ? event.user.name : 'Кто-то' }}</strong>
                    {{ eventText(event) }}
                  </span>
                  <span class="timeline-time">{{ timeAgo(event.createdAt) }}</span>
                </li>
              </ul>
              <p v-else class="comments-empty">История появится после первого изменения.</p>
            </section>

            <!-- Danger zone -->
            <section class="block danger-zone">
              <div class="danger-actions">
                <button class="btn btn--ghost danger-ghost" @click="archiveCard">
                  <SvgIcon name="archive" :size="15" />
                  В архив
                </button>
                <button class="btn btn--ghost" @click="deleteCard">
                  <SvgIcon name="trash" :size="15" />
                  Удалить карточку
                </button>
              </div>
            </section>
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
import { useAuthStore } from '../../stores/auth'
import { dueMeta, timeAgo } from '../../utils/time'
import {
  LABEL_COLOR_KEYS,
  LABEL_COLOR_NAMES,
  PRIORITIES,
  labelColor as labelSwatch,
  priorityLabel,
} from '../../utils/labels'
import SvgIcon from '../SvgIcon.vue'
import Avatar from '../Avatar.vue'
import SelectMenu from '../SelectMenu.vue'
import DatePicker from '../DatePicker.vue'
import { confirmAction, confirmState } from '../../composables/useConfirm'

const props = defineProps({
  card: { type: Object, default: null },
})
const emit = defineEmits(['close'])

const store = useBoardStore()
const auth = useAuthStore()

const panelEl = ref(null)
const visible = computed(() => !!props.card)

// The live card, always read from the reactive store — anything committed
// (our own requests or WebSocket board:sync) is reflected immediately.
const liveCard = computed(() => {
  if (!props.card) return null
  for (const column of store.columns) {
    const found = (column.cards || []).find((c) => c.id === props.card.id)
    if (found) return found
  }
  return null
})

// If the card disappears (deleted here or archived from another tab), the panel
// must not keep showing a ghost of it.
watch(liveCard, (card) => {
  if (props.card && !card) {
    emit('close')
  }
})

const liveAuthor = computed(() => (liveCard.value ? liveCard.value.author : null))
const liveComments = computed(() => (liveCard.value ? liveCard.value.comments || [] : []))
const commentCount = computed(() => liveComments.value.length)

const labels = computed(() => (liveCard.value ? liveCard.value.labels || [] : []))
const checklist = computed(() => (liveCard.value ? liveCard.value.checklist || [] : []))
const checklistTotal = computed(() => checklist.value.length)
const checklistDone = computed(() => checklist.value.filter((item) => item.done).length)
const progressPercent = computed(() =>
  checklistTotal.value ? Math.round((checklistDone.value / checklistTotal.value) * 100) : 0,
)

const checklistLocal = ref([])
watch(
  checklist,
  (items) => {
    checklistLocal.value = items.slice()
  },
  { immediate: true },
)

const watchingCount = computed(() => {
  if (!props.card) return 0
  const map = store.presence.watching || {}
  return Number(map[props.card.id] || 0)
})

const assigneeValue = computed(() => {
  const assignee = liveCard.value ? liveCard.value.assignee : null
  return assignee ? assignee.id : ''
})

const assigneeOptions = computed(() => [
  { value: '', label: 'Не назначен' },
  ...store.users.map((user) => ({ value: user.id, label: user.name })),
])

const labelColorOptions = LABEL_COLOR_KEYS.map((key) => ({
  value: key,
  label: LABEL_COLOR_NAMES[key],
  color: labelSwatch(key),
}))
const dueValue = computed(() => (liveCard.value && liveCard.value.dueDate ? liveCard.value.dueDate : ''))
const due = computed(() => dueMeta(dueValue.value))
const priorityValue = computed(() => (liveCard.value ? liveCard.value.priority || 0 : 0))
const priority = computed(() => priorityLabel(priorityValue.value))
const coverValue = computed(() => (liveCard.value && liveCard.value.coverColor ? liveCard.value.coverColor : ''))

// Editable fields (kept local so typing is smooth).
const title = ref('')
const description = ref('')
const newComment = ref('')
const editingDesc = ref(false)
const saving = ref(false)
const estimateDraft = ref('')

const newChecklist = ref('')
const showLabelForm = ref(false)
const labelName = ref('')
const labelColor = ref(LABEL_COLOR_KEYS[0])

const events = ref([])

const descEl = ref(null)
const commentEl = ref(null)

watch(
  () => props.card,
  (c) => {
    if (!c) return
    title.value = c.title
    description.value = c.description || ''
    newComment.value = ''
    editingDesc.value = false
    newChecklist.value = ''
    showLabelForm.value = false
    labelName.value = ''
  },
  { immediate: true },
)

watch(
  () => (liveCard.value ? liveCard.value.estimate : null),
  (value) => {
    estimateDraft.value = value === null || value === undefined ? '' : String(value)
  },
  { immediate: true },
)

watch(visible, async (v) => {
  if (!v) {
    events.value = []
    return
  }
  await nextTick()
  // Focus the dialog itself, not the title: opening a card must not put the
  // caret in the title (that reads as "edit mode") and must not scroll the
  // panel. A focus target inside the panel also keeps the Tab trap and Esc
  // handling working, which body focus would bypass.
  if (panelEl.value) panelEl.value.focus({ preventScroll: true })
  loadEvents()
})

// Refresh the activity trail whenever someone changes the board.
watch(
  () => store.revision,
  () => {
    if (visible.value) loadEvents()
  },
)

const currentUserName = computed(() => (auth.user ? auth.user.name : ''))

const authorName = (comment) => (comment.author ? comment.author.name : 'Удалённый пользователь')

const canDeleteComment = (comment) =>
  !comment.author || (auth.user && auth.user.id === comment.author.id)

// ------------------------------------------------------------- activity

const EVENT_TEXT = {
  'card.created': 'создал(а) карточку',
  'card.updated': 'изменил(а) карточку',
  'card.moved': 'переместил(а) карточку',
  'card.archived': 'отправил(а) карточку в архив',
  'card.restored': 'восстановил(а) карточку',
  'card.deleted': 'удалил(а) карточку',
  'comment.added': 'добавил(а) комментарий',
  'comment.deleted': 'удалил(а) комментарий',
  'checklist.added': 'добавил(а) пункт чек-листа',
  'checklist.updated': 'изменил(а) пункт чек-листа',
  'checklist.deleted': 'удалил(а) пункт чек-листа',
  'label.attached': 'добавил(а) метку',
  'label.detached': 'снял(а) метку',
}

// Server field names are storage details; the timeline speaks Russian.
const FIELD_TEXT = {
  title: 'название',
  description: 'описание',
  assignee_id: 'исполнитель',
  due_date: 'срок',
  priority: 'приоритет',
  cover_color: 'обложка',
  estimate: 'оценка',
}

function fieldList(fields) {
  if (!Array.isArray(fields) || !fields.length) return ''
  const names = fields.map((field) => FIELD_TEXT[field] || field)
  return names.join(', ')
}

function eventText(event) {
  const payload = event.payload || {}
  switch (event.kind) {
    case 'card.moved':
      if (payload.to) return `переместил(а) в «${payload.to}»`
      return EVENT_TEXT['card.moved']
    case 'card.updated': {
      const names = fieldList(payload.fields)
      return names ? `изменил(а): ${names}` : EVENT_TEXT['card.updated']
    }
    case 'label.attached':
      return payload.labels && payload.labels.length
        ? `добавил(а) метки: ${payload.labels.join(', ')}`
        : EVENT_TEXT['label.attached']
    case 'label.detached':
      return payload.labels && payload.labels.length
        ? `снял(а) метки: ${payload.labels.join(', ')}`
        : EVENT_TEXT['label.detached']
    case 'checklist.updated':
      if (payload.done === true) return 'отметил(а) пункт чек-листа'
      if (payload.done === false) return 'снял(а) отметку с пункта чек-листа'
      return EVENT_TEXT['checklist.updated']
    default:
      return EVENT_TEXT[event.kind] || 'изменил(а) карточку'
  }
}

async function loadEvents() {
  if (!props.card) return
  try {
    events.value = await store.cardEvents(props.card.id)
  } catch (e) {
    events.value = []
  }
}

// ------------------------------------------------------------- title/desc

async function saveTitle() {
  const trimmed = title.value.trim()
  if (!trimmed) {
    title.value = liveCard.value ? liveCard.value.title : ''
    return
  }
  if (liveCard.value && trimmed === liveCard.value.title) return
  try {
    await store.updateCard(props.card.id, { title: trimmed })
  } catch (e) {
    /* the toast reports the error */
  }
}

async function startEditingDesc() {
  editingDesc.value = true
  await nextTick()
  descEl.value && descEl.value.focus()
}

function cancelEditingDesc() {
  editingDesc.value = false
  description.value = liveCard.value ? liveCard.value.description || '' : ''
}

async function saveDescription() {
  if (saving.value) return
  saving.value = true
  try {
    await store.updateCard(props.card.id, { description: description.value.trim() })
    editingDesc.value = false
  } catch (e) {
    /* toast */
  } finally {
    saving.value = false
  }
}

// ------------------------------------------------------------- properties

async function setAssignee(value) {
  try {
    await store.updateCard(props.card.id, { assigneeId: value === '' ? null : Number(value) })
  } catch (e) {
    /* toast */
  }
}

async function setDueDate(value) {
  try {
    await store.updateCard(props.card.id, { dueDate: value || null })
  } catch (e) {
    /* toast */
  }
}

async function setPriority(value) {
  const next = priorityValue.value === value ? 0 : value
  try {
    await store.updateCard(props.card.id, { priority: next })
  } catch (e) {
    /* toast */
  }
}

async function setCover(key) {
  try {
    await store.updateCard(props.card.id, { coverColor: key || null })
  } catch (e) {
    /* toast */
  }
}

async function saveEstimate() {
  const raw = String(estimateDraft.value).replace(',', '.').trim()
  const current = liveCard.value ? liveCard.value.estimate : null
  if (raw === '') {
    if (current === null) return
    try {
      await store.updateCard(props.card.id, { estimate: null })
    } catch (e) {
      /* toast */
    }
    return
  }
  const value = Number(raw)
  if (!Number.isFinite(value) || value < 0) {
    estimateDraft.value = current === null ? '' : String(current)
    return
  }
  if (current !== null && Number(current) === value) return
  try {
    await store.updateCard(props.card.id, { estimate: value })
  } catch (e) {
    /* toast */
  }
}

// ----------------------------------------------------------------- labels

function hasLabel(id) {
  return labels.value.some((label) => label.id === id)
}

async function toggleLabel(id) {
  const current = labels.value.map((label) => label.id)
  const next = current.includes(id) ? current.filter((x) => x !== id) : [...current, id]
  try {
    await store.updateCard(props.card.id, { labelIds: next })
  } catch (e) {
    /* toast */
  }
}

async function createLabel() {
  const name = labelName.value.trim()
  if (!name) return
  try {
    const created = await store.createLabel(name, labelColor.value)
    labelName.value = ''
    showLabelForm.value = false
    if (created) await toggleLabel(created.id)
  } catch (e) {
    /* toast */
  }
}

// -------------------------------------------------------------- checklist

async function addChecklist() {
  const text = newChecklist.value.trim()
  if (!text) return
  try {
    await store.addChecklistItem(props.card.id, text)
    newChecklist.value = ''
  } catch (e) {
    /* toast */
  }
}

async function toggleChecklist(item) {
  try {
    await store.updateChecklistItem(item.id, { done: !item.done })
  } catch (e) {
    /* toast */
  }
}

async function removeChecklist(item) {
  try {
    await store.deleteChecklistItem(item.id)
  } catch (e) {
    /* toast */
  }
}

async function saveChecklistOrder() {
  const ids = checklistLocal.value.map((item) => item.id)
  try {
    await store.reorderChecklist(props.card.id, ids)
  } catch (e) {
    checklistLocal.value = checklist.value.slice()
  }
}

// --------------------------------------------------------------- comments

function onCommentKey(e) {
  if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
    e.preventDefault()
    submitComment()
  }
}

async function submitComment() {
  const text = newComment.value.trim()
  if (!text || saving.value) return
  saving.value = true
  try {
    await store.addComment(props.card.id, text)
    newComment.value = ''
    commentEl.value && commentEl.value.focus()
  } catch (e) {
    /* toast */
  } finally {
    saving.value = false
  }
}

async function removeComment(comment) {
  const ok = await confirmAction({
    title: 'Удалить комментарий?',
    message: 'Комментарий исчезнет у всех участников доски. Действие нельзя отменить.',
    confirmText: 'Удалить',
    icon: 'comment',
  })
  if (!ok) return
  try {
    await store.deleteComment(comment.id)
  } catch (e) {
    /* toast */
  }
}

async function deleteCard() {
  const ok = await confirmAction({
    title: 'Удалить карточку?',
    message: `Карточка «${props.card ? props.card.title : ''}» будет удалена вместе с комментариями, метками и чек-листом. Восстановить её нельзя.`,
    confirmText: 'Удалить карточку',
  })
  if (!ok) return
  try {
    await store.deleteCard(props.card.id)
    close()
  } catch (e) {
    /* toast */
  }
}

async function archiveCard() {
  try {
    await store.archiveCard(props.card.id)
    close()
  } catch (e) {
    /* toast */
  }
}

function close() {
  emit('close')
}

// ------------------------------------------------------------ a11y / keys

function focusables() {
  const panel = panelEl.value
  if (!panel) return []
  return Array.from(
    panel.querySelectorAll(
      'button:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
    ),
  ).filter((el) => el.offsetParent !== null || el === document.activeElement)
}

function onPanelKeydown(event) {
  if (event.key === 'Escape') {
    if (confirmState.open) return
    event.preventDefault()
    close()
    return
  }
  if (event.key !== 'Tab') return
  const list = focusables()
  if (!list.length) return
  const first = list[0]
  const last = list[list.length - 1]
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

function onGlobalKey(event) {
  if (event.key !== 'Escape' || !visible.value) return
  // Esc закрывает подтверждение, а не карточку под ним.
  if (confirmState.open) return
  close()
}
window.addEventListener('keydown', onGlobalKey)
onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKey))
</script>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: var(--overlay);
  backdrop-filter: blur(3px);
  display: flex;
  justify-content: flex-end;
  z-index: 50;
}
.panel {
  width: 100%;
  max-width: 640px;
  height: 100%;
  background: var(--surface);
  box-shadow: -18px 0 60px -20px rgba(10, 12, 24, 0.55);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  /* The panel is a programmatic focus target (see watch(visible)), so it must
     never paint a focus ring. */
  outline: none;
}
.slide-enter-active,
.slide-leave-active {
  transition: transform 0.22s ease, opacity 0.22s ease;
}
.slide-enter-from,
.slide-leave-to {
  transform: translateX(40px);
  opacity: 0;
}
.panel-top {
  padding: 10px 12px 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.watching {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 9px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  font-size: 11.5px;
  font-weight: 600;
  color: var(--text-3);
}
.panel-body {
  flex: 1;
  overflow-y: auto;
  padding: 4px 30px 30px;
}
.title-input {
  width: 100%;
  border: none;
  border-radius: var(--radius-s);
  font-size: 21px;
  font-weight: 800;
  letter-spacing: -0.2px;
  line-height: 1.3;
  color: var(--text);
  background: transparent;
  resize: none;
  overflow: hidden;
  padding: 6px 8px;
  margin-left: -8px;
}
.title-input:focus {
  outline: 2px solid var(--accent);
  outline-offset: 0;
  border-radius: var(--radius-s);
  background: var(--surface-2);
}
.meta-line {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  padding: 6px 0 16px;
  border-bottom: 1px solid var(--line);
}
.meta-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--text-3);
  font-size: 13px;
}
.meta-due.due--overdue {
  color: var(--due-overdue-fg);
}
.meta-due.due--today {
  color: var(--due-today-fg);
}
.meta-due.due--soon {
  color: var(--due-soon-fg);
}
.meta-priority.p--1 {
  color: var(--priority-1-fg);
}
.meta-priority.p--2 {
  color: var(--priority-2-fg);
}
.meta-priority.p--3 {
  color: var(--priority-3-fg);
}
.block {
  padding: 20px 0 6px;
}
.block-title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 12px;
  font-size: 15px;
  font-weight: 700;
  color: var(--text);
}
.block-refresh {
  margin-left: auto;
  width: 24px;
  height: 24px;
}
.count-pill {
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  line-height: 20px;
  text-align: center;
  border-radius: var(--radius-pill);
  background: var(--accent-soft);
  color: var(--accent);
  font-size: 11px;
  font-weight: 700;
}

/* properties */
.props-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.prop {
  display: flex;
  flex-direction: column;
  gap: 5px;
  min-width: 0;
}
.prop--wide {
  grid-column: 1 / -1;
}
.prop-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-3);
}
.estimate-row {
  display: flex;
  align-items: center;
  gap: 6px;
}
.estimate-row .input {
  flex: 1;
  min-width: 0;
}
.prop-suffix {
  font-size: 12.5px;
  color: var(--text-3);
}
.cover-row {
  display: flex;
  align-items: center;
  gap: 6px;
  min-height: 38px;
}
.cover-dot {
  width: 20px;
  height: 20px;
  flex: none;
  border-radius: 50%;
  border: 2px solid transparent;
  box-shadow: 0 0 0 1px var(--line-strong);
  transition: box-shadow 0.13s ease, transform 0.13s ease;
}
.cover-dot:hover {
  transform: scale(1.1);
}
.cover-dot.on {
  box-shadow: 0 0 0 2px var(--accent);
}
.cover-dot--none {
  background: var(--surface);
  position: relative;
  overflow: hidden;
}
.cover-dot--none::after {
  content: '';
  position: absolute;
  inset: -4px;
  border-top: 1.5px solid var(--danger);
  transform: rotate(-45deg);
  transform-origin: center;
}
.priority-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.priority-btn {
  padding: 6px 12px;
  border-radius: var(--radius-pill);
  border: 1px solid var(--line-strong);
  background: var(--surface-2);
  color: var(--text-2);
  font-size: 12.5px;
  font-weight: 600;
  transition: background 0.12s ease, border-color 0.12s ease, color 0.12s ease;
}
.priority-btn:hover {
  background: var(--surface-3);
}
.priority-btn.on.p--0 {
  background: var(--surface-3);
  border-color: var(--text-3);
  color: var(--text);
}
.priority-btn.on.p--1 {
  background: var(--priority-1-bg);
  border-color: var(--priority-1-fg);
  color: var(--priority-1-fg);
}
.priority-btn.on.p--2 {
  background: var(--priority-2-bg);
  border-color: var(--priority-2-fg);
  color: var(--priority-2-fg);
}
.priority-btn.on.p--3 {
  background: var(--priority-3-bg);
  border-color: var(--priority-3-fg);
  color: var(--priority-3-fg);
}

/* description */
.desc-view {
  border-radius: var(--radius-m);
  padding: 11px 13px;
  background: var(--surface-2);
  border: 1px solid transparent;
  cursor: text;
  transition: border-color 0.15s ease, background 0.15s ease;
}
.desc-view:hover {
  background: var(--surface-3);
  border-color: var(--line);
}
.desc-text {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
  color: var(--text);
}
.desc-empty {
  margin: 0;
  color: var(--text-3);
}
.inline-actions {
  margin-top: 8px;
  display: flex;
  gap: 8px;
}

/* labels */
.label-picker {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.label-chip,
.label-add {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 11px;
  border-radius: var(--radius-pill);
  border: 1px solid var(--line-strong);
  background: var(--surface-2);
  color: var(--text-2);
  font-size: 12.5px;
  font-weight: 600;
  transition: background 0.12s ease, border-color 0.12s ease;
}
.label-chip:hover,
.label-add:hover {
  background: var(--surface-3);
}
.label-chip.on {
  border-color: var(--accent);
  background: var(--accent-soft);
  color: var(--accent);
}
.label-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  flex: none;
}
.label-add {
  border-style: dashed;
  color: var(--text-3);
}
.label-form {
  display: flex;
  gap: 8px;
  margin-top: 9px;
  align-items: center;
}
.label-form .input {
  flex: 1;
  min-width: 0;
}
.label-color {
  flex: 0 0 130px;
}
.label-form .btn {
  flex: none;
}

/* checklist */
.progress {
  height: 6px;
  border-radius: var(--radius-pill);
  background: var(--surface-3);
  overflow: hidden;
  margin-bottom: 10px;
}
.progress-fill {
  display: block;
  height: 100%;
  border-radius: var(--radius-pill);
  background: var(--accent-grad);
  transition: width 0.2s ease;
}
.checklist {
  list-style: none;
  margin: 0 0 10px;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.checklist-item {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 5px 6px;
  border-radius: var(--radius-s);
}
.checklist-item:hover {
  background: var(--surface-2);
}
.check-grip {
  display: inline-flex;
  color: var(--text-3);
  cursor: grab;
  opacity: 0;
  transition: opacity 0.12s ease;
}
.checklist-item:hover .check-grip {
  opacity: 1;
}
.check-box {
  width: 18px;
  height: 18px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  border: 1.5px solid var(--line-strong);
  background: var(--surface);
  color: #fff;
}
.check-box.on {
  background: var(--accent);
  border-color: var(--accent);
}
.checklist-text {
  flex: 1;
  min-width: 0;
  font-size: 13.5px;
  color: var(--text);
  overflow-wrap: anywhere;
}
.checklist-item.done .checklist-text {
  color: var(--text-3);
  text-decoration: line-through;
}
.checklist-del {
  width: 24px;
  height: 24px;
  opacity: 0;
}
.checklist-item:hover .checklist-del {
  opacity: 1;
}
.checklist-add {
  display: flex;
  gap: 8px;
  align-items: center;
}
.checklist-add .input {
  flex: 1;
  min-width: 0;
}
.checklist-add .btn {
  flex: none;
}

/* comments */
.comments-empty {
  color: var(--text-3);
  font-size: 13px;
}
.comments {
  margin-bottom: 10px;
}
.comment {
  margin-bottom: 16px;
}
.comment-head {
  display: flex;
  align-items: center;
  gap: 8px;
}
.comment-head-info {
  display: flex;
  align-items: baseline;
  gap: 8px;
  min-width: 0;
}
.comment-author {
  font-size: 13px;
  font-weight: 700;
  color: var(--text);
}
.comment-time {
  font-size: 12px;
  color: var(--text-3);
}
.comment-del {
  margin-left: auto;
  width: 26px;
  height: 26px;
  opacity: 0;
  transition: opacity 0.12s ease;
}
.comment:hover .comment-del {
  opacity: 1;
}
.comment-body {
  margin: 6px 0 0 32px;
  white-space: pre-wrap;
  word-break: break-word;
  font-size: 14px;
  color: var(--text);
  background: var(--surface-2);
  border: 1px solid var(--line);
  border-radius: var(--radius-m);
  padding: 9px 12px;
}
.new-comment {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  margin-top: 14px;
}
.new-comment-form {
  flex: 1;
}

/* timeline */
.timeline {
  list-style: none;
  margin: 0;
  padding: 0 0 0 4px;
  display: flex;
  flex-direction: column;
  gap: 9px;
}
.timeline-item {
  display: flex;
  align-items: baseline;
  gap: 8px;
  font-size: 12.5px;
  color: var(--text-2);
}
.timeline-dot {
  flex: none;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--accent);
  opacity: 0.6;
}
.timeline-text {
  flex: 1;
  min-width: 0;
  overflow-wrap: anywhere;
}
.timeline-text strong {
  color: var(--text);
  font-weight: 700;
}
.timeline-time {
  flex: none;
  font-size: 11.5px;
  color: var(--text-3);
}

/* danger zone */
.danger-zone {
  margin-top: 8px;
  border-top: 1px solid var(--line);
  padding-top: 18px;
}
.danger-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.danger-ghost {
  background: var(--danger-soft);
  color: var(--danger);
}
.danger-ghost:hover {
  background: var(--danger-soft);
  filter: brightness(1.06);
  color: var(--danger-hover);
}

@media (max-width: 700px) {
  .panel-body {
    padding: 4px 16px 24px;
  }
  .props-grid {
    grid-template-columns: 1fr;
  }
  .label-form {
    flex-wrap: wrap;
  }
  .label-color {
    flex: 1 1 100%;
  }
}
</style>
