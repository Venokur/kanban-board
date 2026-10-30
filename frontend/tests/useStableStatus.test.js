import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'
import { useStableStatus } from '../src/composables/useStableStatus'

/**
 * The composable registers `onBeforeUnmount`, so it must run inside a real
 * component. `mount` returns the source ref and the exposed stable ref together
 * with an unmount handle.
 */
function mount(source, delay) {
  const stable = ref(null)
  const host = document.createElement('div')
  const app = createApp({
    setup() {
      stable.value = useStableStatus(source, delay)
      return () => h('div')
    },
  })
  app.mount(host)
  return { stable, unmount: () => app.unmount() }
}

describe('useStableStatus', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('starts with the current source value', () => {
    const source = ref('open')
    const { stable, unmount } = mount(source, 500)
    expect(stable.value.value).toBe('open')
    unmount()
  })

  it('does not publish a value until it has been stable for the delay', async () => {
    const source = ref('open')
    const { stable, unmount } = mount(source, 500)

    source.value = 'connecting'
    await nextTick()
    expect(stable.value.value).toBe('open')

    vi.advanceTimersByTime(499)
    expect(stable.value.value).toBe('open')

    vi.advanceTimersByTime(1)
    expect(stable.value.value).toBe('connecting')
    unmount()
  })

  it('cancels the previous pending change when a newer value arrives', async () => {
    const source = ref('open')
    const { stable, unmount } = mount(source, 500)

    // A blip: closed for 400ms, then the connection comes back.
    source.value = 'closed'
    await nextTick()
    vi.advanceTimersByTime(400)
    source.value = 'open'
    await nextTick()

    // The stale "closed" timer must not fire in the middle.
    vi.advanceTimersByTime(400)
    expect(stable.value.value).toBe('open')

    // Nothing else arrives, but the value equals the displayed one, so no timer.
    vi.advanceTimersByTime(1000)
    expect(stable.value.value).toBe('open')
    unmount()
  })

  it('applies only the last value of a rapid burst', async () => {
    const source = ref('idle')
    const { stable, unmount } = mount(source, 300)

    for (const status of ['connecting', 'closed', 'connecting', 'open']) {
      source.value = status
      await nextTick()
      vi.advanceTimersByTime(50)
    }
    expect(stable.value.value).toBe('idle')

    vi.advanceTimersByTime(300)
    expect(stable.value.value).toBe('open')
    unmount()
  })

  it('clears the pending timer on unmount', async () => {
    const source = ref('open')
    const { stable, unmount } = mount(source, 300)

    source.value = 'closed'
    await nextTick()
    unmount()

    const pending = vi.getTimerCount()
    expect(pending).toBe(0)
    vi.advanceTimersByTime(1000)
    expect(stable.value.value).toBe('open')
  })
})
