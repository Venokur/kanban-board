<template>
  <Teleport to="body">
    <transition name="toast-list">
      <div v-if="toasts.length" class="toast-host" role="status" aria-live="polite">
        <div
          v-for="toast in toasts"
          :key="toast.id"
          class="toast"
          :class="`toast--${toast.tone}`"
        >
          <span class="toast-text">{{ toast.message }}</span>
          <button v-if="toast.actionLabel" class="toast-action" @click="run(toast)">
            {{ toast.actionLabel }}
          </button>
          <button class="toast-close" aria-label="Закрыть уведомление" @click="dismissToast(toast.id)">
            <SvgIcon name="close" :size="13" />
          </button>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<script setup>
import { toasts, dismissToast } from '../composables/useToast'
import SvgIcon from './SvgIcon.vue'

async function run(toast) {
  dismissToast(toast.id)
  if (typeof toast.onAction === 'function') {
    try {
      await toast.onAction()
    } catch (e) {
      /* the action reports its own errors */
    }
  }
}
</script>

<style scoped>
.toast-list-enter-active,
.toast-list-leave-active {
  transition: opacity 0.15s ease;
}
.toast-list-enter-from,
.toast-list-leave-to {
  opacity: 0;
}
</style>
