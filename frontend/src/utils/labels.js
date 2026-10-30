// Palette keys must stay in sync with LabelRepo::COLORS on the backend
// and the --label-* custom properties in styles/base.css.

export const LABEL_COLORS = {
  violet: 'var(--label-violet)',
  sky: 'var(--label-sky)',
  mint: 'var(--label-mint)',
  amber: 'var(--label-amber)',
  rose: 'var(--label-rose)',
  slate: 'var(--label-slate)',
}

export const LABEL_COLOR_KEYS = Object.keys(LABEL_COLORS)

export const LABEL_COLOR_NAMES = {
  violet: 'Фиолетовый',
  sky: 'Голубой',
  mint: 'Мятный',
  amber: 'Жёлтый',
  rose: 'Красный',
  slate: 'Серый',
}

export function labelColor(key) {
  return LABEL_COLORS[key] || LABEL_COLORS.violet
}

export const PRIORITIES = [
  { value: 0, label: 'Нет', short: '' },
  { value: 1, label: 'Низкий', short: 'низкий' },
  { value: 2, label: 'Средний', short: 'средний' },
  { value: 3, label: 'Высокий', short: 'высокий' },
]

export function priorityLabel(value) {
  const found = PRIORITIES.find((p) => p.value === value)
  return found ? found.short : ''
}
