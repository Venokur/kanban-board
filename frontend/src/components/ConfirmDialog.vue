<template>
  <Teleport to="body">
    <transition name="confirm">
      <div v-if="confirmState.open" class="confirm-overlay" @mousedown.self="cancel">
        <div
          class="confirm-card"
          role="alertdialog"
          aria-modal="true"
          :aria-labelledby="titleId"
          :aria-describedby="confirmState.message ? messageId : undefined"
          @keydown="onKeydown"
        >
          <span class="confirm-icon" :class="`tone--${confirmState.tone}`">
            <SvgIcon :name="confirmState.icon || (confirmState.tone === 'danger' ? 'trash' : 'help')" :size="20" />
          </span>

          <h2 :id="titleId" class="confirm-title">{{ confirmState.title }}</h2>
          <p v-if="confirmState.message" :id="messageId" class="confirm-message">
            {{ confirmState.message }}
          </p>

          <div class="confirm-actions">
            <button ref="cancelEl" type="button" class="btn btn--ghost" @click="cancel">
              {{ confirmState.cancelText }}
            </button>
            <button
              ref="acceptEl"
              type="button"
              class="btn"
              :class="{ 'btn--danger': confirmState.tone === 'danger' }"
              @click="accept"
            >
              {{ confirmState.confirmText }}
            </button>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<script setup>
import { ref, watch, nextTick, onBeforeUnmount } from 'vue'
import { confirmState, settleConfirm } from '../composables/useConfirm'
import SvgIcon from './SvgIcon.vue'

const uid = Math.random().toString(36).slice(2, 9)
const titleId = `confirm-title-${uid}`
const messageId = `confirm-message-${uid}`

const cancelEl = ref(null)
const acceptEl = ref(null)

watch(
  () => confirmState.open,
  async (open) => {
    if (!open) {
      window.removeEventListener('keydown', onKeydown)
      return
    }
    window.addEventListener('keydown', onKeydown)
    await nextTick()
    // Destructive actions must not be confirmed by a stray Enter: focus "Отмена".
    cancelEl.value && cancelEl.value.focus()
  },
  { immediate: true },
)

function onKeydown(event) {
  if (!confirmState.open) return
  if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    cancel()
    return
  }
  if (event.key === 'Tab') {
    const first = cancelEl.value
    const last = acceptEl.value
    if (!first || !last) return
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first.focus()
    }
  }
}

function cancel() {
  settleConfirm(false)
}

function accept() {
  settleConfirm(true)
}

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<style scoped>
.confirm-overlay {
  position: fixed;
  inset: 0;
  z-index: 420;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  background: rgba(21, 24, 39, 0.45);
  backdrop-filter: blur(3px);
}
.confirm-card {
  width: 100%;
  max-width: 420px;
  padding: 22px 22px 18px;
  border-radius: var(--radius-xl);
  background: var(--surface);
  box-shadow: var(--shadow-pop);
  text-align: center;
}
.confirm-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  margin-bottom: 10px;
  border-radius: 50%;
}
.confirm-icon.tone--danger {
  background: var(--danger-soft);
  color: var(--danger);
}
.confirm-icon.tone--primary {
  background: var(--accent-soft);
  color: var(--accent);
}
.confirm-title {
  margin: 0 0 6px;
  font-size: 17px;
  font-weight: 800;
  color: var(--text);
}
.confirm-message {
  margin: 0;
  font-size: 13.5px;
  line-height: 1.5;
  color: var(--text-2);
}
.confirm-actions {
  display: flex;
  gap: 8px;
  margin-top: 18px;
}
.confirm-actions .btn {
  flex: 1;
}
.confirm-enter-active,
.confirm-leave-active {
  transition: opacity 0.15s ease;
}
.confirm-enter-from,
.confirm-leave-to {
  opacity: 0;
}
</style>
