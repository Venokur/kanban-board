import { describe, it, expect, beforeEach } from 'vitest'
import {
  emptyView,
  readViewFromUrl,
  viewToParams,
  writeViewToUrl,
  hasActiveView,
} from '../src/utils/urlState'

function setSearch(search) {
  window.history.replaceState(null, '', `/${search}`)
}

beforeEach(() => setSearch(''))

describe('emptyView', () => {
  it('describes the unfiltered board', () => {
    const view = emptyView()
    expect(view.sortMode).toBe('position')
    expect(view.labelIds).toEqual([])
    expect(hasActiveView(view)).toBe(false)
  })
})

describe('readViewFromUrl', () => {
  it('reads every supported parameter', () => {
    setSearch('?q=докер&labels=2,5&assignee=1&priority=3&due=week&mine=1&sort=due')
    const view = readViewFromUrl()
    expect(view.text).toBe('докер')
    expect(view.labelIds).toEqual([2, 5])
    expect(view.assigneeId).toBe(1)
    expect(view.priority).toBe(3)
    expect(view.due).toBe('week')
    expect(view.mine).toBe(true)
    expect(view.sortMode).toBe('due')
  })

  it('accepts the "none" sentinels for assignee and priority', () => {
    setSearch('?assignee=none&priority=none')
    const view = readViewFromUrl()
    expect(view.assigneeId).toBe('none')
    expect(view.priority).toBe('none')
  })

  it('drops unknown or malformed values instead of trusting them', () => {
    setSearch('?labels=abc,-1,0,4&priority=9&due=когда&sort=random&assignee=x')
    const view = readViewFromUrl()
    expect(view.labelIds).toEqual([4])
    expect(view.priority).toBe(0)
    expect(view.due).toBe('')
    expect(view.sortMode).toBe('position')
    expect(view.assigneeId).toBeNull()
  })

  it('returns the empty view without parameters', () => {
    expect(readViewFromUrl()).toEqual(emptyView())
  })
})

describe('viewToParams', () => {
  it('omits defaults so the URL stays clean', () => {
    expect(viewToParams(emptyView()).toString()).toBe('')
  })

  it('round-trips through the query string', () => {
    const view = {
      text: 'карточка',
      labelIds: [1, 7],
      assigneeId: 3,
      priority: 2,
      due: 'today',
      mine: true,
      sortMode: 'priority',
    }
    const params = viewToParams(view)
    setSearch(`?${params.toString()}`)
    expect(readViewFromUrl()).toEqual(view)
  })

  it('trims the search text', () => {
    const params = viewToParams({ ...emptyView(), text: '  docker  ' })
    expect(params.get('q')).toBe('docker')
  })
})

describe('writeViewToUrl', () => {
  it('replaces the query without adding history entries', () => {
    const before = window.history.length
    writeViewToUrl({ ...emptyView(), text: 'x' })
    expect(window.location.search).toBe('?q=x')
    expect(window.history.length).toBe(before)
  })

  it('clears the query when the view is empty', () => {
    writeViewToUrl({ ...emptyView(), text: 'x' })
    writeViewToUrl(emptyView())
    expect(window.location.search).toBe('')
  })
})

describe('hasActiveView', () => {
  it('treats each filter as active', () => {
    const cases = [
      { text: 'a' },
      { labelIds: [1] },
      { assigneeId: 2 },
      { priority: 1 },
      { due: 'overdue' },
      { mine: true },
    ]
    for (const patch of cases) {
      expect(hasActiveView({ ...emptyView(), ...patch })).toBe(true)
    }
  })

  it('ignores whitespace-only search text', () => {
    expect(hasActiveView({ ...emptyView(), text: '   ' })).toBe(false)
  })
})
