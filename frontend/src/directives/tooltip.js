/**
 * v-tooltip — своя подсказка вместо браузерного `title`.
 *
 *   <button v-tooltip="'Удалить колонку'" aria-label="Удалить колонку">…</button>
 *
 * Показывается по наведению мыши и по фокусу с клавиатуры, позиционируется
 * фиксированно у элемента и не обрезается `overflow` контейнеров.
 */

const SHOW_DELAY = 350
const GAP = 8

let tipEl = null
let hostEl = null
let showTimer = null

function ensureTip() {
  if (tipEl && tipEl.isConnected) return tipEl
  tipEl = document.createElement('div')
  tipEl.className = 'kb-tooltip'
  tipEl.setAttribute('role', 'tooltip')
  document.body.appendChild(tipEl)
  return tipEl
}

function toText(value) {
  if (value === null || value === undefined) return ''
  return typeof value === 'string' ? value : String(value)
}

function place() {
  if (!tipEl || !hostEl) return
  const host = hostEl.getBoundingClientRect()
  const tip = tipEl.getBoundingClientRect()
  const gap = 8

  let top = host.top - tip.height - gap
  let placement = 'top'
  if (top < gap) {
    top = host.bottom + gap
    placement = 'bottom'
  }

  const left = Math.max(
    gap,
    Math.min(host.left + host.width / 2 - tip.width / 2, window.innerWidth - tip.width - gap),
  )

  tipEl.style.top = `${Math.round(top)}px`
  tipEl.style.left = `${Math.round(left)}px`
  tipEl.dataset.placement = placement
}

function show(el) {
  const text = toText(el.__kbTip)
  if (!text) return
  ensureTip()
  hostEl = el
  tipEl.textContent = text
  place()
  tipEl.classList.add('is-visible')
  window.addEventListener('scroll', hide, true)
  window.addEventListener('resize', hide)
}

function hide() {
  clearTimeout(showTimer)
  showTimer = null
  hostEl = null
  if (tipEl) tipEl.classList.remove('is-visible')
  window.removeEventListener('scroll', hide, true)
  window.removeEventListener('resize', hide)
}

function schedule(el) {
  clearTimeout(showTimer)
  showTimer = setTimeout(() => show(el), SHOW_DELAY)
}

function bind(el) {
  const onEnter = (event) => {
    if (event.pointerType === 'touch') return
    schedule(el)
  }
  const onLeave = () => hide()
  const onDown = () => hide()
  const onFocus = () => {
    // Мышь тоже фокусирует кнопку при клике — подсказка нужна только с клавиатуры.
    if (el.matches && el.matches(':focus-visible')) show(el)
  }
  const onBlur = () => hide()

  el.addEventListener('pointerenter', onEnter)
  el.addEventListener('pointerleave', onLeave)
  el.addEventListener('pointerdown', onDown)
  el.addEventListener('focus', onFocus)
  el.addEventListener('blur', onBlur)
  el.__kbTipHandlers = { onEnter, onLeave, onDown, onFocus, onBlur }
}

function unbind(el) {
  const handlers = el.__kbTipHandlers
  if (!handlers) return
  el.removeEventListener('pointerenter', handlers.onEnter)
  el.removeEventListener('pointerleave', handlers.onLeave)
  el.removeEventListener('pointerdown', handlers.onDown)
  el.removeEventListener('focus', handlers.onFocus)
  el.removeEventListener('blur', handlers.onBlur)
  delete el.__kbTipHandlers
  if (hostEl === el) hide()
}

export const tooltip = {
  mounted(el, binding) {
    el.__kbTip = binding.value
    bind(el)
  },
  updated(el, binding) {
    el.__kbTip = binding.value
    if (hostEl === el) {
      const text = toText(binding.value)
      if (text) {
        tipEl.textContent = text
        place()
      } else {
        hide()
      }
    }
  },
  unmounted(el) {
    unbind(el)
  },
}
