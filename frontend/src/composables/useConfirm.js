import { reactive } from 'vue'

/**
 * Глобальные подтверждения вместо браузерного `confirm()` и инлайновых
 * «Удалить? / Отмена» в компонентах. Promise резолвится в boolean.
 *
 *   const ok = await confirmAction({ title: 'Удалить карточку?', message: '…' })
 *   if (!ok) return
 */

const DEFAULTS = {
  title: 'Подтвердите действие',
  message: '',
  confirmText: 'Подтвердить',
  cancelText: 'Отмена',
  tone: 'danger', // danger | primary
  icon: '',
}

export const confirmState = reactive({ ...DEFAULTS, open: false })

const queue = []
let current = null

/** @returns {Promise<boolean>} */
export function confirmAction(options = {}) {
  return new Promise((resolve) => {
    queue.push({ options, resolve })
    if (!current) showNext()
  })
}

function showNext() {
  if (current) return
  const item = queue.shift()
  if (!item) {
    confirmState.open = false
    return
  }
  current = item
  Object.assign(confirmState, DEFAULTS, item.options, { open: true })
}

/** Вызывается только из ConfirmDialog. */
export function settleConfirm(value) {
  if (!current) return
  const { resolve } = current
  current = null
  confirmState.open = false
  resolve(Boolean(value))
  // Даём анимации закрытия доиграть, чтобы следующий диалог не «мигал».
  if (queue.length) {
    setTimeout(() => {
      if (!current) showNext()
    }, 170)
  }
}
