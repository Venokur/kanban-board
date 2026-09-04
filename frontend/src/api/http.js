const TOKEN_KEY = 'kb_token'

export function getToken() {
  return localStorage.getItem(TOKEN_KEY) || ''
}

export function setToken(token) {
  localStorage.setItem(TOKEN_KEY, token)
}

export function clearToken() {
  localStorage.removeItem(TOKEN_KEY)
}

async function request(method, path, body) {
  const headers = { 'Content-Type': 'application/json' }
  const token = getToken()
  if (token) headers['X-Auth-Token'] = token

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
    try {
      const data = await res.json()
      if (data && data.error) message = data.error
    } catch (e) {
      /* ignore */
    }
    const err = new Error(message)
    err.status = res.status
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
