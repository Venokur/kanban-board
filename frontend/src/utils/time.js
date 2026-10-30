/** Local midnight for the given date (or today). */
export function startOfDay(date = new Date()) {
  const d = new Date(date)
  d.setHours(0, 0, 0, 0)
  return d
}

/** Parse a `YYYY-MM-DD` string as a local date. */
export function parseDate(iso) {
  if (!iso) return null
  const d = new Date(`${iso}T00:00:00`)
  return Number.isNaN(d.getTime()) ? null : d
}

/**
 * Whole calendar days between two dates, independent of time zone and DST.
 * Both values are reduced to their local calendar date and compared in UTC,
 * so a 23- or 25-hour day can never shift the result.
 */
export function dayDiff(a, b) {
  if (!a || !b) return 0
  const left = Date.UTC(a.getFullYear(), a.getMonth(), a.getDate())
  const right = Date.UTC(b.getFullYear(), b.getMonth(), b.getDate())
  return Math.round((left - right) / 86400000)
}

export function plural(n, one, few, many) {
  const abs = Math.abs(n) % 100
  const last = abs % 10
  if (abs > 10 && abs < 20) return `${n} ${many}`
  if (last > 1 && last < 5) return `${n} ${few}`
  if (last === 1) return `${n} ${one}`
  return `${n} ${many}`
}

export function timeAgo(ms) {
  if (!ms) return ''
  const diff = Date.now() - ms
  if (diff < 45000) return 'только что'
  const mins = Math.round(diff / 60000)
  if (mins < 60) return plural(mins, 'минуту', 'минуты', 'минут') + ' назад'
  const hours = Math.round(mins / 60)
  if (hours < 24) return plural(hours, 'час', 'часа', 'часов') + ' назад'
  const days = Math.round(hours / 24)
  if (days < 7) return plural(days, 'день', 'дня', 'дней') + ' назад'
  return new Date(ms).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })
}

/**
 * Human friendly due date info.
 * @returns {{ label: string, tone: 'overdue'|'today'|'soon'|'normal', diff: number }|null}
 */
export function dueMeta(iso) {
  const date = parseDate(iso)
  if (!date) return null

  const today = startOfDay()
  const diff = dayDiff(date, today)

  let label
  if (diff === 0) label = 'сегодня'
  else if (diff === 1) label = 'завтра'
  else if (diff === -1) label = 'вчера'
  else {
    const sameYear = date.getFullYear() === today.getFullYear()
    label = date.toLocaleDateString('ru-RU', {
      day: 'numeric',
      month: 'short',
      ...(sameYear ? {} : { year: 'numeric' }),
    })
  }

  let tone = 'normal'
  if (diff < 0) tone = 'overdue'
  else if (diff === 0) tone = 'today'
  else if (diff <= 3) tone = 'soon'

  return { label, tone, diff }
}
