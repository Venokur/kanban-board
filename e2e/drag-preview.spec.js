import { test, expect } from '@playwright/test'
import { createCard, createColumn, deleteColumnByTitle, login, unique } from './helpers'

/**
 * The drag preview must not be trapped inside a column.
 *
 * Sortable clones the dragged card in `force-fallback` mode. With
 * `fallback-on-body` the clone goes into <body>; without it the clone lands in
 * the column's `.card-list`, whose `backdrop-filter` makes the column a
 * containing block for `position: fixed`. The preview is then shifted by the
 * column's own offset and painted inside the column's stacking context, i.e.
 * under the neighbouring columns.
 */
test.describe('card drag preview', () => {
  test('the ghost is positioned from the viewport and paints above other columns', async ({ page }) => {
    await login(page)

    const colTitle = unique('Колонка')
    const cardTitle = unique('Карточка')
    const neighbourTitle = unique('Сосед')

    await createColumn(page, colTitle)
    await createCard(page, cardTitle, colTitle)
    await createColumn(page, neighbourTitle)

    try {
      const col = page.locator('section.column').filter({ hasText: colTitle }).first()
      const card = col.locator('.card').filter({ hasText: cardTitle }).first()
      const cardBox = await card.boundingBox()
      const cardCenter = {
        x: cardBox.x + cardBox.width / 2,
        y: cardBox.y + cardBox.height / 2,
      }

      await page.mouse.move(cardCenter.x, cardCenter.y)
      await page.mouse.down()
      // Nudge past the drag threshold; a real drag then follows below.
      await page.mouse.move(cardCenter.x + 4, cardCenter.y + 4, { steps: 4 })
      await page.waitForTimeout(150)

      const start = await page.evaluate(() => {
        const el = document.querySelector('.sortable-fallback')
        if (!el) return null
        const rect = el.getBoundingClientRect()
        return {
          inBody: el.parentElement === document.body,
          hasDragClass: el.classList.contains('card-dragging'),
          center: { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 },
        }
      })

      expect(start, 'a fallback clone should exist while dragging').not.toBeNull()
      // Appended to <body>, so no ancestor can shift its fixed positioning.
      expect(start.inBody).toBe(true)
      // The dragging style lives on the ghost, so it survives the move to <body>.
      expect(start.hasDragClass).toBe(true)
      // The preview sits on the card it was cloned from. Before the fix it was
      // pushed by the column's own offset (~23px left, ~233px down).
      expect(Math.abs(start.center.x - cardCenter.x)).toBeLessThan(10)
      expect(Math.abs(start.center.y - cardCenter.y)).toBeLessThan(10)

      // Drag across the neighbouring column and check the paint order at a
      // point where the ghost and that column overlap.
      const neighbourBox = await page.locator('section.column').last().boundingBox()
      await page.mouse.move(
        Math.min(neighbourBox.x + 60, 1200),
        neighbourBox.y + 100,
        { steps: 20 },
      )
      await page.waitForTimeout(200)

      const overlap = await page.evaluate(() => {
        const el = document.querySelector('.sortable-fallback')
        if (!el) return null
        const rect = el.getBoundingClientRect()
        const others = [...document.querySelectorAll('section.column')].map((c) => c.getBoundingClientRect())
        // The strongest intersection between the ghost and any column, so the
        // probe point is guaranteed to lie inside both.
        let best = null
        let bestArea = 0
        for (const r of others) {
          const left = Math.max(rect.left, r.left)
          const right = Math.min(rect.right, r.right)
          const top = Math.max(rect.top, r.top)
          const bottom = Math.min(rect.bottom, r.bottom)
          const area = (right - left) * (bottom - top)
          if (right - left > 10 && bottom - top > 10 && area > bestArea) {
            bestArea = area
            best = { x: (left + right) / 2, y: (top + bottom) / 2 }
          }
        }
        if (!best) return { overlapped: false }
        el.style.pointerEvents = 'auto'
        const topmost = document.elementFromPoint(best.x, best.y)
        el.style.pointerEvents = 'none'
        return {
          overlapped: true,
          topmostIsGhost: topmost === el || el.contains(topmost),
        }
      })

      expect(overlap.overlapped).toBe(true)
      expect(overlap.topmostIsGhost).toBe(true)

      // Release over the source column so the card returns where it was.
      await page.mouse.move(cardCenter.x, cardCenter.y, { steps: 10 })
      await page.mouse.up()
      await expect(page.locator('.sortable-fallback')).toHaveCount(0)
    } finally {
      await deleteColumnByTitle(page, neighbourTitle)
      await deleteColumnByTitle(page, colTitle)
    }
  })
})
