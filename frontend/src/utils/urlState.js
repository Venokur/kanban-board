/**
 * Board view state (filters + sort) lives in the URL query string, so a view
 * survives a reload and can be shared as a link. Uses `history.replaceState`
 * directly: no router navigation, no history spam.
 */

const DUE_VALUES = ['', 'overdue', 'today', 'week', 'none']
const SORT_VALUES = ['position', 'due', 'priority']

export const emptyView = () => ({
  text: '',
  labelIds: [],
  assigneeId: null,
  priority: 0,
  due: '',
  mine: false,
  sortMode: 'position',
})

export function readViewFromUrl() {
  const view = emptyView()
  if (typeof window === 'undefined') return view
  const params = new URLSearchParams(window.location.search)

  view.text = params.get('q') || ''
  const labels = params.get('labels')
  if (labels) {
    view.labelIds = labels
      .split(',')
      .map((id) => Number(id))
      .filter((id) => Number.isInteger(id) && id > 0)
  }
  const assignee = params.get('assignee')
  if (assignee === 'none') view.assigneeId = 'none'
  else if (assignee && Number.isInteger(Number(assignee))) view.assigneeId = Number(assignee)

  const priority = params.get('priority')
  if (priority === 'none') view.priority = 'none'
  else if (Number(priority) >= 1 && Number(priority) <= 3) view.priority = Number(priority)

  const due = params.get('due') || ''
  view.due = DUE_VALUES.includes(due) ? due : ''
  view.mine = params.get('mine') === '1'

  const sort = params.get('sort') || 'position'
  view.sortMode = SORT_VALUES.includes(sort) ? sort : 'position'

  return view
}

export function viewToParams(view) {
  const params = new URLSearchParams()
  if (view.text && view.text.trim()) params.set('q', view.text.trim())
  if (view.labelIds && view.labelIds.length) params.set('labels', view.labelIds.join(','))
  if (view.assigneeId === 'none') params.set('assignee', 'none')
  else if (view.assigneeId !== null && view.assigneeId !== undefined) {
    params.set('assignee', String(view.assigneeId))
  }
  if (view.priority) params.set('priority', String(view.priority))
  if (view.due) params.set('due', view.due)
  if (view.mine) params.set('mine', '1')
  if (view.sortMode && view.sortMode !== 'position') params.set('sort', view.sortMode)
  return params
}

export function writeViewToUrl(view) {
  if (typeof window === 'undefined') return
  const params = viewToParams(view)
  const query = params.toString()
  const next = `${window.location.pathname}${query ? `?${query}` : ''}`
  if (next === `${window.location.pathname}${window.location.search}`) return
  window.history.replaceState(window.history.state, '', next)
}

export function hasActiveView(view) {
  return (
    !!view.text.trim() ||
    view.labelIds.length > 0 ||
    view.assigneeId !== null ||
    !!view.priority ||
    !!view.due ||
    view.mine
  )
}
