import { defineStore } from 'pinia'
import { useBoardStore } from './board'

/**
 * Command palette + global shortcuts. `/` focuses search, `Ctrl/⌘+K` opens the
 * palette, `N` creates a card in the first column, `?` shows the cheat sheet.
 */
export const useUiStore = defineStore('ui', {
  state: () => ({
    paletteOpen: false,
    shortcutsOpen: false,
    labelManagerOpen: false,
  }),

  actions: {
    openPalette() {
      this.paletteOpen = true
    },
    closePalette() {
      this.paletteOpen = false
    },
    toggleShortcuts() {
      this.shortcutsOpen = !this.shortcutsOpen
    },
    openLabels() {
      this.labelManagerOpen = true
    },
    closeLabels() {
      this.labelManagerOpen = false
    },
    closeTopLayer() {
      if (this.paletteOpen) {
        this.paletteOpen = false
        return true
      }
      if (this.shortcutsOpen) {
        this.shortcutsOpen = false
        return true
      }
      if (this.labelManagerOpen) {
        this.labelManagerOpen = false
        return true
      }
      return false
    },
  },
})

/** Cards across the board, flattened — used by the palette search. */
export function flattenCards(boardStore) {
  const out = []
  for (const column of boardStore.columns) {
    for (const card of column.cards || []) {
      out.push({ card, column })
    }
  }
  return out
}
