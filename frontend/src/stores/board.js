import { defineStore } from 'pinia'
import { http } from '../api/http'
import { wsOn } from '../api/ws'

export const useBoardStore = defineStore('board', {
  state: () => ({
    board: null, // { board: {id,name}, columns: [...] }
    loading: false,
    saving: false,
    error: null,
  }),

  getters: {
    boardName: (s) => (s.board ? s.board.board.name : ''),
    columns: (s) => (s.board ? s.board.columns : []),
    isBusy: (s) => s.loading || s.saving,
  },

  actions: {
    subscribe() {
      wsOn('board:sync', (snapshot) => {
        if (snapshot && snapshot.board) this.applySnapshot(snapshot)
      })
    },

    async load() {
      this.loading = true
      this.error = null
      try {
        const snapshot = await http.get('/api/board')
        this.applySnapshot(snapshot)
      } catch (e) {
        this.error = e.message
        throw e
      } finally {
        this.loading = false
      }
    },

    applySnapshot(snapshot) {
      this.board = snapshot
    },

    async createColumn(title) {
      await this.run(() => http.post('/api/columns', { title }))
    },

    async renameColumn(id, title) {
      await this.run(() => http.put(`/api/columns/${id}`, { title }))
    },

    async deleteColumn(id) {
      await this.run(() => http.del(`/api/columns/${id}`))
    },

    async saveColumnOrder() {
      const columnIds = this.columns.map((c) => c.id)
      await this.run(() => http.post('/api/columns/order', { columnIds }))
    },

    async moveColumn(id, dir) {
      const columns = this.columns
      const from = columns.findIndex((c) => c.id === id)
      const to = from + dir
      if (from < 0 || to < 0 || to >= columns.length) return
      const [moved] = columns.splice(from, 1)
      columns.splice(to, 0, moved)
      await this.saveColumnOrder()
    },

    async createCard(columnId, title, description = '') {
      await this.run(() => http.post('/api/cards', { columnId, title, description }))
    },

    async updateCard(id, { title, description }) {
      const body = {}
      if (title !== undefined) body.title = title
      if (description !== undefined) body.description = description
      await this.run(() => http.put(`/api/cards/${id}`, body))
    },

    async deleteCard(id) {
      await this.run(() => http.del(`/api/cards/${id}`))
    },

    async saveCardsOrder() {
      if (this.saving) return
      this.saving = true
      try {
        const columns = this.columns.map((c) => ({
          id: c.id,
          cards: (c.cards || []).map((card) => card.id),
        }))
        const snapshot = await http.post('/api/cards/order', { columns })
        if (snapshot && snapshot.board) this.applySnapshot(snapshot)
      } catch (e) {
        this.error = e.message
        await this.load().catch(() => {})
      } finally {
        this.saving = false
      }
    },

    async addComment(cardId, bodyText) {
      await this.run(() => http.post(`/api/cards/${cardId}/comments`, { body: bodyText }))
    },

    async deleteComment(id) {
      await this.run(() => http.del(`/api/comments/${id}`))
    },

    async run(fn) {
      this.error = null
      try {
        const snapshot = await fn()
        if (snapshot && snapshot.board) this.applySnapshot(snapshot)
      } catch (e) {
        this.error = e.message
        throw e
      }
    },

    dismissError() {
      this.error = null
    },
  },
})
