import { reactive } from 'vue'

let socket = null
let retryMs = 1500
let pingTimer = null
let reconnectTimer = null
let stopped = true
let manualStop = false

const handlers = new Map()

/** Reactive connection state for badges and empty states. */
export const wsState = reactive({
  status: 'idle', // idle | connecting | open | closed
  retryIn: 0,
  lastOpenAt: 0,
})

/**
 * Subscribe to a server message type. Returns the unsubscribe function, so
 * components can clean up in `onBeforeUnmount` (the old API leaked a handler
 * on every mount).
 */
export function wsOn(type, cb) {
  if (!handlers.has(type)) handlers.set(type, new Set())
  handlers.get(type).add(cb)
  return () => {
    const list = handlers.get(type)
    if (!list) return
    list.delete(cb)
    if (!list.size) handlers.delete(type)
  }
}

function emit(type, data) {
  const list = handlers.get(type)
  if (!list) return
  for (const fn of [...list]) {
    try {
      fn(data)
    } catch (e) {
      console.error('[ws] handler error', e)
    }
  }
}

function connect() {
  if (!stopped) return
  stopped = false
  clearTimeout(reconnectTimer)
  wsState.status = 'connecting'

  const proto = location.protocol === 'https:' ? 'wss' : 'ws'
  // No token in the URL: the HttpOnly session cookie is sent with the handshake.
  const url = `${proto}://${location.host}/ws`

  try {
    socket = new WebSocket(url)
  } catch (e) {
    scheduleReconnect()
    return
  }

  socket.onopen = () => {
    retryMs = 1500
    wsState.status = 'open'
    wsState.retryIn = 0
    wsState.lastOpenAt = Date.now()
    clearInterval(pingTimer)
    pingTimer = setInterval(() => {
      if (socket && socket.readyState === WebSocket.OPEN) {
        socket.send(JSON.stringify({ type: 'ping' }))
      }
    }, 20000)
    emit('open', null)
  }

  socket.onmessage = (event) => {
    let msg = null
    try {
      msg = JSON.parse(event.data)
    } catch (e) {
      return
    }
    emit(msg.type, msg.data)
  }

  socket.onclose = (event) => {
    clearInterval(pingTimer)
    socket = null
    wsState.status = 'closed'
    emit('close', event || null)
    // 1008 = rejected handshake (expired session): do not hammer the server.
    if (!manualStop && event && event.code === 1008) {
      window.dispatchEvent(new CustomEvent('auth:unauthorized'))
      return
    }
    if (!stopped) scheduleReconnect()
  }

  socket.onerror = () => {
    try {
      socket && socket.close()
    } catch (e) {
      /* ignore */
    }
  }
}

function scheduleReconnect() {
  stopped = true
  // Jittered backoff so a server restart does not produce a thundering herd.
  const wait = Math.round(retryMs * (0.8 + Math.random() * 0.4))
  wsState.retryIn = Math.round(wait / 1000)
  reconnectTimer = setTimeout(() => {
    if (manualStop) return
    connect()
  }, wait)
  retryMs = Math.min(retryMs * 1.8, 30000)
}

export function startWs() {
  manualStop = false
  stopped = true
  connect()
}

export function stopWs() {
  manualStop = true
  stopped = true
  clearInterval(pingTimer)
  clearTimeout(reconnectTimer)
  if (socket) {
    try {
      socket.close()
    } catch (e) {
      /* ignore */
    }
    socket = null
  }
  wsState.status = 'idle'
}

export function isWsOpen() {
  return !!(socket && socket.readyState === WebSocket.OPEN)
}

/** Ask the server to broadcast which card we are looking at. */
export function wsWatch(cardId) {
  if (!isWsOpen()) return
  socket.send(JSON.stringify({ type: 'presence:watch', cardId: cardId ?? null }))
}

export function wsRequestBoard() {
  if (!isWsOpen()) return false
  socket.send(JSON.stringify({ type: 'board:get' }))
  return true
}
