const CSRF_COOKIE = 'kb_csrf'
const CSRF_HEADER = 'X-CSRF-Token'

/** In-memory fallback when the CSRF cookie is not readable (e.g. blocked). */
let csrfMemory = ''

export function setCsrf(token) {
  csrfMemory = token || ''
}

function readCookie(name) {
  const prefix = `${name}=`
  for (const part of document.cookie.split(';')) {
    const entry = part.trim()
    if (entry.startsWith(prefix)) return decodeURIComponent(entry.slice(prefix.length))
  }
  return ''
}

export function csrfToken() {
  return readCookie(CSRF_COOKIE) || csrfMemory
}

async function request(method, path, body) {
  const headers = { 'Content-Type': 'application/json' }
  if (method !== 'GET') {
    const csrf = csrfToken()
    if (csrf) headers[CSRF_HEADER] = csrf
  }

  const res = await fetch(path, {
    method,
    headers,
    credentials: 'include',
    body: body === undefined ? undefined : JSON.stringify(body),
  })

  if (!res.ok) {
    if (res.status === 401) {
      window.dispatchEvent(new CustomEvent('auth:unauthorized'))
    }
    let message = 'Ошибка запроса'
    let code = res.status
    try {
      const data = await res.json()
      if (data && data.error) message = data.error
      if (data && data.code) code = data.code
    } catch (e) {
      /* ignore non-JSON error bodies */
    }
    const err = new Error(message)
    err.status = res.status
    err.code = code
    throw err
  }
  if (res.status === 204) return null
  return res.json()
}

export const http = {
  get: (path) => request('GET', path),
  post: (path, body) => request('POST', path, body),
  put: (path, body) => request('PUT', path, body),
  del: (path) => request('DELETE', path),
}
