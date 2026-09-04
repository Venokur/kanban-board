<template>
  <Teleport to="body">
    <transition name="slide">
      <div v-if="visible" class="overlay" @mousedown.self="close">
        <div class="panel" role="dialog" aria-modal="true">
          <header class="panel-top">
            <button class="icon-btn" title="Закрыть (Esc)" @click="close">
              <SvgIcon name="close" :size="20" />
            </button>
          </header>

          <div class="panel-body">
            <!-- Title -->
            <textarea
              ref="titleEl"
              v-model="title"
              class="title-input"
              rows="1"
              maxlength="1000"
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
            </div>

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
                />
                <div class="inline-actions">
                  <button class="btn" :disabled="saving" @click="saveDescription">Сохранить</button>
                  <button class="btn btn--ghost" @click="cancelEditingDesc">Отмена</button>
                </div>
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
                      title="Удалить комментарий"
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
                    @keydown="onCommentKey"
                  />
                  <div v-if="newComment.trim()" class="inline-actions">
                    <button class="btn" :disabled="saving" @click="submitComment">Добавить</button>
                  </div>
                </div>
              </div>
            </section>

            <!-- Danger zone -->
            <section class="block danger-zone">
              <template v-if="!confirmingDelete">
                <button class="btn btn--ghost danger-ghost" @click="confirmingDelete = true">
                  <SvgIcon name="trash" :size="15" />
                  Удалить карточку
                </button>
              </template>
              <template v-else>
                <span class="danger-question">Удалить карточку навсегда?</span>
                <div class="inline-actions">
                  <button class="btn btn--danger" @click="deleteCard">Да, удалить</button>
                  <button class="btn btn--ghost" @click="confirmingDelete = false">Отмена</button>
                </div>
              </template>
            </section>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import { useBoardStore } from '../../stores/board'
import { useAuthStore } from '../../stores/auth'
import { timeAgo } from '../../utils/time'
import SvgIcon from '../SvgIcon.vue'
import Avatar from '../Avatar.vue'

const props = defineProps({
  card: { type: Object, default: null },
})
const emit = defineEmits(['close'])

const store = useBoardStore()
const auth = useAuthStore()

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

const liveAuthor = computed(() => (liveCard.value ? liveCard.value.author : null))
const liveComments = computed(() => (liveCard.value ? liveCard.value.comments || [] : []))
const commentCount = computed(() => liveComments.value.length)

// Editable fields (kept local so typing is smooth).
const title = ref('')
const description = ref('')
const newComment = ref('')
const editingDesc = ref(false)
const saving = ref(false)
const confirmingDelete = ref(false)

const titleEl = ref(null)
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
    confirmingDelete.value = false
  },
  { immediate: true },
)

watch(visible, async (v) => {
  if (v) {
    await nextTick()
    titleEl.value && titleEl.value.focus()
  }
})

const currentUserName = computed(() => (auth.user ? auth.user.name : ''))

const authorName = (comment) => (comment.author ? comment.author.name : 'Удалённый пользователь')

const canDeleteComment = (comment) =>
  !comment.author || (auth.user && auth.user.id === comment.author.id)

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
    /* banner shows the error */
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
    /* banner */
  } finally {
    saving.value = false
  }
}

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
    /* banner */
  } finally {
    saving.value = false
  }
}

async function removeComment(comment) {
  try {
    await store.deleteComment(comment.id)
  } catch (e) {
    /* banner */
  }
}

async function deleteCard() {
  try {
    await store.deleteCard(props.card.id)
    close()
  } catch (e) {
    /* banner */
  }
}

function close() {
  emit('close')
}

function onKeydown(e) {
  if (e.key === 'Escape' && visible.value) close()
}
window.addEventListener('keydown', onKeydown)
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(21, 24, 39, 0.45);
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
  box-shadow: -18px 0 60px -20px rgba(21, 24, 39, 0.5);
  display: flex;
  flex-direction: column;
  overflow: hidden;
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
  justify-content: flex-end;
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
  background: #fff;
}
.meta-line {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
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
.danger-zone {
  margin-top: 8px;
  border-top: 1px solid var(--line);
  padding-top: 18px;
}
.danger-ghost {
  background: var(--danger-soft);
  color: var(--danger);
}
.danger-ghost:hover {
  background: #f8d7d8;
  color: var(--danger-hover);
}
.danger-question {
  color: var(--text-2);
  font-weight: 500;
}
</style>
