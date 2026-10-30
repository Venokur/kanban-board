import { defineStore } from 'pinia'
import { http } from '../api/http'
import { wsOn } from '../api/ws'
import { useAuthStore } from './auth'
import { dayDiff, parseDate, startOfDay } from '../utils/time'
import { hasActiveView, readViewFromUrl, writeViewToUrl } from '../utils/urlState'
import { toastError, toastUndo } from '../composables/useToast'

const emptyFilters = () => ({
  text: '',
  labelIds: [],
  assigneeId: null,
  priority: 0,
  due: '',
  mine: false,
})

const DUE_FILTERS = ['overdue', 'today', 'week', 'none']
const SORT_MODES = ['position', 'due', 'priority']

export function matchesFilters(card, filters, myId) {
  const query = filters.text.trim().toLowerCase()
  if (query) {
    const haystack = `${card.title} ${card.description || ''}`.toLowerCase()
    if (!haystack.includes(query)) return false
  }

  if (filters.labelIds.length) {
    const ids = (card.labels || []).map((label) => label.id)
    if (!filters.labelIds.every((id) => ids.includes(id))) return false
  }

  if (filters.mine) {
    if (!myId || !card.assignee || card.assignee.id !== myId) return false
  }
  if (filters.assigneeId === 'none') {
    if (card.assignee) return false
  } else if (filters.assigneeId !== null) {
    if (!card.assignee || card.assignee.id !== filters.assigneeId) return false
  }

  if (filters.priority === 'none') {
    if ((card.priority || 0) !== 0) return false
  } else if (filters.priority) {
    if ((card.priority || 0) !== filters.priority) return false
  }

  if (filters.due) {
    const date = parseDate(card.dueDate)
    if (filters.due === 'none') {
      if (date) return false
    } else {
      if (!date) return false
      const diff = dayDiff(date, startOfDay())
      if (filters.due === 'overdue' && diff >= 0) return false
      if (filters.due === 'today' && diff !== 0) return false
      if (filters.due === 'week' && (diff < 0 || diff > 7)) return false
    }
  }

  return true
}

export function sortCards(cards, mode) {
  const list = cards.slice()
  if (mode === 'due') {
    list.sort((a, b) => {
      const da = parseDate(a.dueDate)
      const db = parseDate(b.dueDate)
      if (!da && !db) return a.position - b.position
      if (!da) return 1
      if (!db) return -1
      return da - db || a.position - b.position
    })
  } else if (mode === 'priority') {
    list.sort((a, b) => (b.priority || 0) - (a.priority || 0) || a.position - b.position)
  }
  return list
}

export const useBoardStore = defineStore('board', {
  state: () => ({
    board: null, // { revision, board, labels, users, columns, presence }
    loading: false,
    saving: false,
    error: null,
    revision: 0,
    filters: emptyFilters(),
    sortMode: 'position',
    archive: [],
    archiveLoading: false,
    archiveError: null,
    presence: { users: [], watching: {} },
    unsubscribers: [],
    orderQueue: null,
  }),

  getters: {
    boardName: (s) => (s.board ? s.board.board.name : ''),
    columns: (s) => (s.board ? s.board.columns : []),
    users: (s) => (s.board && s.board.users ? s.board.users : []),
    labels: (s) => (s.board && s.board.labels ? s.board.labels : []),
    isBusy: (s) => s.loading || s.saving,

    filtersActive: (s) =>
      hasActiveView({
        text: s.filters.text,
        labelIds: s.filters.labelIds,
        assigneeId: s.filters.assigneeId,
        priority: s.filters.priority,
        due: s.filters.due,
        mine: s.filters.mine,
      }),

    /** Drag & drop would corrupt the order while a filter/sort is applied. */
    dragDisabled() {
      return this.filtersActive || this.sortMode !== 'position'
    },

    hiddenCards(state) {
      if (!state.board || !this.filtersActive) return 0
      const auth = useAuthStore()
      const myId = auth.user ? auth.user.id : null
      let hidden = 0
      for (const column of state.columns) {
        for (const card of column.cards || []) {
          if (!matchesFilters(card, state.filters, myId)) hidden += 1
        }
      }
      return hidden
    },

    visibleColumns(state) {
      const active = hasActiveView({
        text: state.filters.text,
        labelIds: state.filters.labelIds,
        assigneeId: state.filters.assigneeId,
        priority: state.filters.priority,
        due: state.filters.due,
        mine: state.filters.mine,
      })
      if (!active && state.sortMode === 'position') {
        // Raw objects: vuedraggable mutates `column.cards` in place.
        return state.columns
      }
      const auth = useAuthStore()
      const myId = auth.user ? auth.user.id : null
      return state.columns.map((column) => {
        let cards = column.cards || []
        if (active) cards = cards.filter((card) => matchesFilters(card, state.filters, myId))
        return { ...column, cards: sortCards(cards, state.sortMode) }
      })
    },

    onlineUsers(state) {
      const ids = state.presence.users || []
      return (this.users || []).filter((user) => ids.includes(user.id))
    },

    totalCards: (s) =>
      s.board ? s.board.columns.reduce((n, column) => n + (column.cards || []).length, 0) : 0,
  },

  actions: {
    /** Idempotent: repeated calls (remounts, hot reload) do not stack handlers. */
    subscribe() {
      this.unsubscribe()
      this.unsubscribers = [
        wsOn('board:sync', (snapshot) => {
          if (snapshot && snapshot.board) this.applySnapshot(snapshot)
        }),
        wsOn('presence:sync', (data) => {
          if (!data) return
          this.presence = {
            users: data.users || [],
            watching: data.watching || {},
          }
          if (this.board) this.board.presence = this.presence
        }),
        wsOn('open', () => {
          // Reconnected: pull a fresh snapshot instead of trusting stale state.
          this.load().catch(() => {})
        }),
      ]
    },

    unsubscribe() {
      for (const off of this.unsubscribers) {
        try {
          off()
        } catch (e) {
          /* ignore */
        }
      }
      this.unsubscribers = []
    },

    async load() {
      this.loading = true
      this.error = null
      try {
        const snapshot = await http.get('/api/board')
        this.applySnapshot(snapshot, { force: true })
      } catch (e) {
        this.error = e.message
        throw e
      } finally {
        this.loading = false
      }
    },

    /**
     * Apply a snapshot unless it is older than what we already have. HTTP
     * responses and WS broadcasts can arrive in any order, and a stale one must
     * never overwrite a newer state.
     */
    applySnapshot(snapshot, options = {}) {
      if (!snapshot || !snapshot.board) return
      const revision = Number(snapshot.revision || 0)
      if (!options.force && this.board && revision && revision < this.revision) return
      this.board = snapshot
      this.revision = Math.max(this.revision, revision)
      if (snapshot.presence) this.presence = snapshot.presence
    },

    // ------------------------------------------------------------- filters

    setFilter(patch) {
      this.filters = { ...this.filters, ...patch }
      this.syncUrl()
    },

    toggleLabelFilter(id) {
      const ids = this.filters.labelIds
      this.filters = {
        ...this.filters,
        labelIds: ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id],
      }
      this.syncUrl()
    },

    setDueFilter(due) {
      this.setFilter({ due: DUE_FILTERS.includes(due) ? due : '' })
    },

    resetFilters() {
      this.filters = emptyFilters()
      this.syncUrl()
    },

    applyView(view) {
      this.filters = {
        text: view.text || '',
        labelIds: Array.isArray(view.labelIds) ? view.labelIds.slice() : [],
        assigneeId: view.assigneeId ?? null,
        priority: view.priority || 0,
        due: view.due || '',
        mine: !!view.mine,
      }
      this.sortMode = SORT_MODES.includes(view.sortMode) ? view.sortMode : 'position'
    },

    restoreFromUrl() {
      this.applyView(readViewFromUrl())
    },

    syncUrl() {
      writeViewToUrl({ ...this.filters, sortMode: this.sortMode })
    },

    setSortMode(mode) {
      this.sortMode = SORT_MODES.includes(mode) ? mode : 'position'
      this.syncUrl()
    },

    // --------------------------------------------------------------- board

    async renameBoard(name) {
      await this.run(() => http.put('/api/board', { name }))
    },

    // ------------------------------------------------------------- columns

    async createColumn(title) {
      await this.run(() => http.post('/api/columns', { title }))
    },

    async renameColumn(id, title) {
      await this.run(() => http.put(`/api/columns/${id}`, { title }))
    },

    async updateColumn(id, fields) {
      await this.run(() => http.put(`/api/columns/${id}`, fields))
    },

    async deleteColumn(id) {
      await this.run(() => http.del(`/api/columns/${id}`))
    },

    async saveColumnOrder() {
      const columnIds = this.columns.map((c) => c.id)
      await this.run(() => http.post('/api/columns/order', { columnIds }))
    },

    async saveColumnOrderFrom(list) {
      const columnIds = list.map((c) => c.id)
      await this.run(() => http.post('/api/columns/order', { columnIds }))
    },

    /** Optimistic move with rollback: the local array is restored on failure. */
    async moveColumn(id, dir) {
      const columns = this.columns.slice()
      const from = columns.findIndex((c) => c.id === id)
      const to = from + dir
      if (from < 0 || to < 0 || to >= columns.length) return

      const [moved] = columns.splice(from, 1)
      columns.splice(to, 0, moved)
      const next = columns.map((column, index) => ({ ...column, position: index + 1 }))
      if (this.board) this.board.columns = next

      try {
        await http.post('/api/columns/order', { columnIds: next.map((c) => c.id) })
      } catch (e) {
        if (this.board) this.board.columns = this.columns.map((c) => c)
        await this.load().catch(() => {})
        throw e
      }
    },

    // --------------------------------------------------------------- cards

    async createCard(columnId, title, description = '') {
      await this.run(() => http.post('/api/cards', { columnId, title, description }))
    },

    /** Patch any subset of card fields. */
    async updateCard(id, fields) {
      await this.run(() => http.put(`/api/cards/${id}`, fields))
    },

    async deleteCard(id) {
      await this.run(() => http.del(`/api/cards/${id}`))
    },

    async archiveCard(id) {
      await this.run(() => http.post(`/api/cards/${id}/archive`))
      toastUndo('Карточка в архиве', async () => {
        await this.restoreCard(id).catch(() => {})
      })
    },

    async restoreCard(id) {
      await this.run(() => http.post(`/api/cards/${id}/restore`))
      this.archive = this.archive.filter((card) => card.id !== id)
    },

    async loadArchive() {
      this.archiveLoading = true
      this.archiveError = null
      try {
        const data = await http.get('/api/board/archive')
        this.archive = data.cards || []
      } catch (e) {
        this.archiveError = e.message
        throw e
      } finally {
        this.archiveLoading = false
      }
    },

    /**
     * Persist drag & drop order. Requests are serialised: a second drop that
     * happens while the first is in flight is queued instead of dropped.
     */
    async saveCardsOrder() {
      if (this.saving) {
        // Remember that another save is needed once the current one finishes.
        this.orderQueue = true
        return
      }
      this.saving = true
      try {
        do {
          this.orderQueue = false
          const columns = this.columns.map((c) => ({
            id: c.id,
            cards: (c.cards || []).map((card) => card.id),
          }))
          const snapshot = await http.post('/api/cards/order', { columns })
          if (snapshot && snapshot.board) this.applySnapshot(snapshot)
        } while (this.orderQueue)
      } catch (e) {
        this.error = e.message
        toastError(e.message)
        await this.load().catch(() => {})
      } finally {
        this.saving = false
        this.orderQueue = false
      }
    },

    async reorderChecklist(cardId, itemIds) {
      await this.run(() => http.post(`/api/cards/${cardId}/checklist/order`, { itemIds }))
    },

    async cardEvents(cardId) {
      const data = await http.get(`/api/cards/${cardId}/events`)
      return data.events || []
    },

    // -------------------------------------------------------------- labels

    async createLabel(name, color) {
      const snapshot = await this.run(() => http.post('/api/labels', { name, color }))
      return snapshot && snapshot.createdLabelId ? { id: snapshot.createdLabelId } : null
    },

    async updateLabel(id, fields) {
      await this.run(() => http.put(`/api/labels/${id}`, fields))
    },

    async deleteLabel(id) {
      await this.run(() => http.del(`/api/labels/${id}`))
    },

    async saveLabelOrder(ids) {
      await this.run(() => http.post('/api/labels/order', { labelIds: ids }))
    },

    // ----------------------------------------------------------- checklist

    async addChecklistItem(cardId, text) {
      await this.run(() => http.post(`/api/cards/${cardId}/checklist`, { text }))
    },

    async updateChecklistItem(id, fields) {
      await this.run(() => http.put(`/api/checklist/${id}`, fields))
    },

    async deleteChecklistItem(id) {
      await this.run(() => http.del(`/api/checklist/${id}`))
    },

    // ------------------------------------------------------------- comments

    async cardComments(cardId) {
      const data = await http.get(`/api/cards/${cardId}/comments`)
      return data.comments || []
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
        return snapshot
      } catch (e) {
        this.error = e.message
        toastError(e.message)
        throw e
      }
    },

    dismissError() {
      this.error = null
    },
  },
})
