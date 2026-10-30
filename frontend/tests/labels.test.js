import { describe, it, expect } from 'vitest'
import {
  LABEL_COLORS,
  LABEL_COLOR_KEYS,
  LABEL_COLOR_NAMES,
  labelColor,
  PRIORITIES,
  priorityLabel,
} from '../src/utils/labels'

describe('label palette', () => {
  it('exposes exactly the keys the backend accepts', () => {
    // LabelRepo::COLORS on the backend must stay in sync with this list.
    expect(LABEL_COLOR_KEYS).toEqual(['violet', 'sky', 'mint', 'amber', 'rose', 'slate'])
  })

  it('maps every key to a CSS custom property', () => {
    for (const key of LABEL_COLOR_KEYS) {
      expect(LABEL_COLORS[key]).toMatch(/^var\(--label-[a-z]+\)$/)
    }
  })

  it('has a Russian name for every key', () => {
    for (const key of LABEL_COLOR_KEYS) {
      expect(LABEL_COLOR_NAMES[key]).toBeTruthy()
    }
  })

  it('falls back to the first colour for unknown keys', () => {
    expect(labelColor('nope')).toBe(LABEL_COLORS.violet)
    expect(labelColor(undefined)).toBe(LABEL_COLORS.violet)
    expect(labelColor('sky')).toBe(LABEL_COLORS.sky)
  })
})

describe('priorities', () => {
  it('covers the 0…3 range used by the API', () => {
    expect(PRIORITIES.map((p) => p.value)).toEqual([0, 1, 2, 3])
  })

  it('returns the short label for card badges', () => {
    expect(priorityLabel(0)).toBe('')
    expect(priorityLabel(2)).toBe('средний')
    expect(priorityLabel(99)).toBe('')
  })
})
