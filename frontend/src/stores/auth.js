import { defineStore } from 'pinia'
import { http, getToken, setToken, clearToken } from '../api/http'
import { startWs, stopWs } from '../api/ws'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: getToken(),
  }),

  getters: {
    isAuthed: (s) => !!s.user,
  },

  actions: {
    async bootstrap() {
      if (!this.token || this.user) return
      try {
        const data = await http.get('/api/me')
        this.user = data.user
        startWs()
      } catch (e) {
        this.clearSession()
      }
    },

    setSession(data) {
      this.token = data.token
      this.user = data.user
      setToken(data.token)
      startWs()
    },

    clearSession() {
      this.user = null
      this.token = ''
      clearToken()
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
        /* ignore network errors */
      }
      this.clearSession()
    },
  },
})
