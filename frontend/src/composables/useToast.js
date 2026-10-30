import { reactive } from 'vue'

/**
 * Global toast queue — replaces the single error banner with messages that
 * stack, auto-dismiss and can carry an action (e.g. "Вернуть" for undo).
 */

const TOAST_TTL = 6000

export const toasts = reactive([])

let counter = 0

/**
 * @param {object} options
 * @param {string} options.message
 * @param {'info'|'success'|'error'} [options.tone]
 * @param {string} [options.actionLabel]
 * @param {() => void|Promise<void>} [options.onAction]
 * @param {number} [options.ttl]
 */
export function pushToast(options) {
  const toast = {
    id: ++counter,
    message: String(options.message || ''),
    tone: options.tone || 'info',
    actionLabel: options.actionLabel || '',
    onAction: options.onAction || null,
    ttl: options.ttl ?? TOAST_TTL,
  }
  toasts.push(toast)
  const limit = 4
  while (toasts.length > limit) toasts.shift()
  if (toast.ttl > 0) {
    setTimeout(() => dismissToast(toast.id), toast.ttl)
  }
  return toast.id
}

export function dismissToast(id) {
  const index = toasts.findIndex((t) => t.id === id)
  if (index !== -1) toasts.splice(index, 1)
}

export function toastError(message) {
  return pushToast({ message, tone: 'error', ttl: 8000 })
}

export function toastSuccess(message) {
  return pushToast({ message, tone: 'success' })
}

/** Toast with an "Отменить" button. */
export function toastUndo(message, onUndo) {
  return pushToast({
    message,
    tone: 'info',
    actionLabel: 'Вернуть',
    onAction: onUndo,
    ttl: 7000,
  })
}
