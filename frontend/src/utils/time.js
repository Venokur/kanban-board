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

function plural(n, one, few, many) {
  const abs = Math.abs(n) % 100
  const last = abs % 10
  if (abs > 10 && abs < 20) return `${n} ${many}`
  if (last > 1 && last < 5) return `${n} ${few}`
  if (last === 1) return `${n} ${one}`
  return `${n} ${many}`
}
