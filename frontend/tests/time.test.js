import { describe, it, expect } from 'vitest'
import { startOfDay, parseDate, dayDiff, plural, timeAgo, dueMeta } from '../src/utils/time'

describe('parseDate', () => {
  it('parses YYYY-MM-DD as a local date', () => {
    const d = parseDate('2026-03-15')
    expect(d.getFullYear()).toBe(2026)
    expect(d.getMonth()).toBe(2)
    expect(d.getDate()).toBe(15)
    expect(d.getHours()).toBe(0)
  })

  it('returns null for empty or invalid input', () => {
    expect(parseDate('')).toBeNull()
    expect(parseDate(null)).toBeNull()
    expect(parseDate('не дата')).toBeNull()
  })
})

describe('startOfDay', () => {
  it('drops the time part', () => {
    const d = startOfDay(new Date(2026, 4, 9, 17, 42, 13, 500))
    expect(d.getHours()).toBe(0)
    expect(d.getMinutes()).toBe(0)
    expect(d.getSeconds()).toBe(0)
    expect(d.getMilliseconds()).toBe(0)
    expect(d.getDate()).toBe(9)
  })

  it('defaults to today', () => {
    expect(startOfDay().getDate()).toBe(new Date().getDate())
  })
})

describe('dayDiff', () => {
  it('counts whole calendar days', () => {
    expect(dayDiff(new Date(2026, 0, 10), new Date(2026, 0, 9))).toBe(1)
    expect(dayDiff(new Date(2026, 0, 9), new Date(2026, 0, 10))).toBe(-1)
    expect(dayDiff(new Date(2026, 0, 9), new Date(2026, 0, 9))).toBe(0)
  })

  it('ignores the time of day', () => {
    expect(dayDiff(new Date(2026, 0, 10, 23, 59), new Date(2026, 0, 10, 0, 1))).toBe(0)
  })

  it('does not slip across a DST boundary', () => {
    // 23- and 25-hour days must still count as one calendar day.
    expect(dayDiff(new Date(2026, 2, 30), new Date(2026, 2, 29))).toBe(1)
    expect(dayDiff(new Date(2026, 9, 26), new Date(2026, 9, 25))).toBe(1)
  })

  it('returns 0 when an argument is missing', () => {
    expect(dayDiff(null, new Date())).toBe(0)
    expect(dayDiff(new Date(), null)).toBe(0)
  })
})

describe('plural', () => {
  it('picks the Russian form for a count', () => {
    expect(plural(1, 'день', 'дня', 'дней')).toBe('1 день')
    expect(plural(3, 'день', 'дня', 'дней')).toBe('3 дня')
    expect(plural(5, 'день', 'дня', 'дней')).toBe('5 дней')
    expect(plural(11, 'день', 'дня', 'дней')).toBe('11 дней')
    expect(plural(21, 'день', 'дня', 'дней')).toBe('21 день')
    expect(plural(0, 'день', 'дня', 'дней')).toBe('0 дней')
  })
})

describe('timeAgo', () => {
  it('says "только что" for the last minute', () => {
    expect(timeAgo(Date.now())).toBe('только что')
  })

  it('counts minutes, hours and days', () => {
    expect(timeAgo(Date.now() - 5 * 60000)).toBe('5 минут назад')
    expect(timeAgo(Date.now() - 3 * 3600000)).toBe('3 часа назад')
    expect(timeAgo(Date.now() - 2 * 86400000)).toBe('2 дня назад')
  })

  it('returns an empty string without a timestamp', () => {
    expect(timeAgo(0)).toBe('')
    expect(timeAgo(null)).toBe('')
  })
})

describe('dueMeta', () => {
  const iso = (offsetDays) => {
    const d = startOfDay()
    d.setDate(d.getDate() + offsetDays)
    const month = String(d.getMonth() + 1).padStart(2, '0')
    const day = String(d.getDate()).padStart(2, '0')
    return `${d.getFullYear()}-${month}-${day}`
  }

  it('labels today, tomorrow and yesterday', () => {
    expect(dueMeta(iso(0))).toMatchObject({ label: 'сегодня', tone: 'today', diff: 0 })
    expect(dueMeta(iso(1))).toMatchObject({ label: 'завтра', tone: 'soon', diff: 1 })
    expect(dueMeta(iso(-1))).toMatchObject({ label: 'вчера', tone: 'overdue', diff: -1 })
  })

  it('marks dates within three days as soon', () => {
    expect(dueMeta(iso(3)).tone).toBe('soon')
    expect(dueMeta(iso(9)).tone).toBe('normal')
  })

  it('formats a distant date instead of a relative label', () => {
    const meta = dueMeta(iso(40))
    expect(meta.tone).toBe('normal')
    expect(meta.label).not.toMatch(/сегодня|завтра|вчера/)
  })

  it('returns null without a date', () => {
    expect(dueMeta('')).toBeNull()
    expect(dueMeta(null)).toBeNull()
  })
})
