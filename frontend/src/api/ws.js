import { getToken } from './http'

let socket = null
let retryMs = 1500
let pingTimer = null
let stopped = true

const handlers = {}

export function wsOn(type, cb) {
  if (!handlers[type]) handlers[type] = []
  handlers[type].push(cb)
}

function emit(type, data) {
  const list = handlers[type]
  if (!list) return
  for (const fn of list) {
    try {
      fn(data)
    } catch (e) {
      console.error('[ws] handler error', e)
    }
  }
}

function connect() {
  if (!stopped) return
  const token = getToken()
  if (!token) return

  stopped = false
  const proto = location.protocol === 'https:' ? 'wss' : 'ws'
  const url = `${proto}://${location.host}/ws?token=${encodeURIComponent(token)}`

  try {
    socket = new WebSocket(url)
  } catch (e) {
    scheduleReconnect()
    return
  }

  socket.onopen = () => {
    retryMs = 1500
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

  socket.onclose = () => {
    clearInterval(pingTimer)
    socket = null
    if (!stopped && getToken()) scheduleReconnect()
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
  setTimeout(() => {
    if (!getToken()) return
    connect()
  }, retryMs)
  retryMs = Math.min(retryMs * 1.8, 30000)
}

export function startWs() {
  stopped = true
  connect()
}

export function stopWs() {
  stopped = true
  clearInterval(pingTimer)
  if (socket) {
    try {
      socket.close()
    } catch (e) {
      /* ignore */
    }
    socket = null
  }
}

export function isWsOpen() {
  return !!(socket && socket.readyState === WebSocket.OPEN)
}
