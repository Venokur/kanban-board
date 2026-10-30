import { defineStore } from 'pinia'
import { http, setCsrf } from '../api/http'
import { startWs, stopWs } from '../api/ws'

/**
 * The session itself lives in an HttpOnly cookie — JavaScript never sees or
 * stores the token. `bootstrap()` asks the server who we are; that is the only
 * source of truth on a cold start.
 */
export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    ready: false,
    csrf: '',
  }),

  getters: {
    isAuthed: (s) => !!s.user,
  },

  actions: {
    async bootstrap() {
      if (this.user) {
        this.ready = true
        return
      }
      try {
        const data = await http.get('/api/me')
        this.user = data.user
        startWs()
      } catch (e) {
        this.user = null
        stopWs()
      } finally {
        this.ready = true
      }
    },

    setSession(data) {
      this.user = data.user
      this.csrf = data.csrf || ''
      if (this.csrf) setCsrf(this.csrf)
      startWs()
    },

    clearSession() {
      this.user = null
      this.csrf = ''
      setCsrf('')
      stopWs()
    },

    async login(email, password) {
      const data = await http.post('/api/login', { email, password })
      this.setSession(data)
    },

    async register(name, email, password) {
      const data = await http.post('/api/register', { name, email, password })
      this.setSession(data)
    },

    async logout() {
      try {
        await http.post('/api/logout')
      } catch (e) {
        /* the cookie is cleared client-side regardless */
      }
      this.clearSession()
    },
  },
})
