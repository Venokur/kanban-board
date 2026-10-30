import { onBeforeUnmount, ref, watch } from 'vue'

/**
 * Debounce a rapidly changing status value before it reaches the UI.
 *
 * The WebSocket layer flips through `connecting → open` while a page loads and
 * briefly through `closed → connecting` on every reconnect. Rendering those raw
 * values makes the status pill jump (the label also changes width, which drags
 * its neighbours around). This composable holds the currently displayed value
 * and only replaces it once the source has been stable for `delay` ms.
 *
 * A new source value always cancels the pending timer first, so the last value
 * wins instead of an older one firing in between.
 *
 * @template T
 * @param {import('vue').Ref<T> | (() => T)} source
 * @param {number} [delay] milliseconds the value has to stay stable
 * @returns {import('vue').Ref<T>}
 */
export function useStableStatus(source, delay = 500) {
  const read = typeof source === 'function' ? source : () => source.value
  const stable = ref(read())
  let timer = null

  function clear() {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  watch(
    read,
    (value) => {
      // Drop the previous pending change: only the newest value may be applied.
      clear()
      if (value === stable.value) return
      timer = setTimeout(() => {
        timer = null
        stable.value = value
      }, delay)
    },
    { flush: 'sync' },
  )

  onBeforeUnmount(clear)

  return stable
}
