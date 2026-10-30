import { test, expect } from '@playwright/test'
import { createCard, createColumn, deleteColumnByTitle, login, unique } from './helpers'

/**
 * Regressions for three layout/behaviour complaints:
 *
 * 1. The column header had the title and five 30px action buttons on one row.
 *    The buttons took ~154px even while invisible, so a 280px column left the
 *    title ~52px and it wrapped one letter per line.
 * 2. The sync pill changed width between states and a second "сохранение"
 *    badge was inserted mid-row, shifting «Метки»/«Архив» on every save.
 * 3. A cross-column move was never written to the card's activity trail.
 */
test.describe('column header and sync status', () => {
  test('the column title keeps a usable width and the actions sit on their own row', async ({ page }) => {
    await login(page)

    const colTitle = unique('Колонка')
    await createColumn(page, colTitle)

    try {
      const col = page.locator('section.column').filter({ hasText: colTitle }).first()
      const title = col.locator('.column-title')
      const actions = col.locator('.column-head-actions')
      const grip = col.locator('.column-grip')

      // Actions must never be hidden: they no longer compete with the title.
      await expect(actions).toBeVisible()
      await expect(grip).toBeVisible()

      const box = await title.boundingBox()
      const columnBox = await col.boundingBox()
      // The old layout left ~52px; the fixed one gives the full row (~230px+).
      expect(box.width).toBeGreaterThan(150)
      expect(box.height).toBeLessThan(30) // one line, not one letter per line

      // Actions are above the title, not beside it.
      const actionsBox = await actions.boundingBox()
      expect(actionsBox.y + actionsBox.height).toBeLessThanOrEqual(box.y + 1)

      // The title is not wrapped letter-by-letter: it ends before the column
      // edge and the element is wider than it is tall by a wide margin.
      expect(box.x + box.width).toBeLessThanOrEqual(columnBox.x + columnBox.width)
      expect(box.width).toBeGreaterThan(box.height * 4)
    } finally {
      await deleteColumnByTitle(page, colTitle)
    }
  })

  test('the status pill and the board buttons do not shift when a save starts', async ({ page }) => {
    await login(page)

    const pill = page.locator('header.topbar .badge').first()
    await expect(pill).toBeVisible()

    const before = await page.evaluate(() => {
      const badge = document.querySelector('header.topbar .badge')
      const labels = [...document.querySelectorAll('header.topbar button')]
        .filter((b) => /Метки|Архив/.test(b.textContent))
        .map((b) => ({ text: b.textContent.trim(), x: Math.round(b.getBoundingClientRect().left) }))
      return { badgeWidth: Math.round(badge.getBoundingClientRect().width), labels }
    })

    // The three status labels have different natural widths. Reserving the
    // widest one means swapping the text must not resize the pill.
    const widths = await page.evaluate(() => {
      const badge = document.querySelector('header.topbar .badge')
      const label = badge.lastChild
      const original = label.textContent
      const out = {}
      for (const state of ['синхронизировано', 'подключение…', 'переподключение…']) {
        label.textContent = ' ' + state
        out[state] = Math.round(badge.getBoundingClientRect().width)
      }
      label.textContent = original
      return out
    })

    const values = Object.values(widths)
    expect(Math.max(...values) - Math.min(...values)).toBe(0)

    // Only one pill lives in the header: "saving" is a state of it, so no
    // extra element can be inserted into the row and push the buttons aside.
    await expect(page.locator('header.topbar .badge')).toHaveCount(1)
    await expect(page.locator('header.topbar .badge--ghost')).toHaveCount(0)

    const after = await page.evaluate(() => {
      const badge = document.querySelector('header.topbar .badge')
      const labels = [...document.querySelectorAll('header.topbar button')]
        .filter((b) => /Метки|Архив/.test(b.textContent))
        .map((b) => ({ text: b.textContent.trim(), x: Math.round(b.getBoundingClientRect().left) }))
      return { badgeWidth: Math.round(badge.getBoundingClientRect().width), labels }
    })
    expect(after).toEqual(before)
  })

  test('a cross-column move is recorded in the card history', async ({ page }) => {
    await login(page)

    const fromTitle = unique('Откуда')
    const toTitle = unique('Куда')
    const cardTitle = unique('Перемещаемая')

    await createColumn(page, fromTitle)
    await createColumn(page, toTitle)

    try {
      await createCard(page, cardTitle, fromTitle)
      const cardId = await page.evaluate(
        async ({ title, targetTitle }) => {
          const csrf = document.cookie
            .split(';')
            .map((c) => c.trim())
            .find((c) => c.startsWith('kb_csrf='))
          const headers = { 'Content-Type': 'application/json' }
          if (csrf) headers['X-CSRF-Token'] = decodeURIComponent(csrf.split('=')[1])

          const board = await (await fetch('/api/board', { headers })).json()
          const card = board.columns.flatMap((c) => c.cards).find((c) => c.title === title)
          if (!card) return 0
          const from = board.columns.find((c) => c.cards.some((x) => x.id === card.id))
          const to = board.columns.find((c) => c.title === targetTitle)

          // Same endpoint drag & drop uses.
          const columns = board.columns.map((c) => ({
            id: c.id,
            cards:
              c.id === from.id
                ? c.cards.filter((x) => x.id !== card.id).map((x) => x.id)
                : c.id === to.id
                  ? [...c.cards.map((x) => x.id), card.id]
                  : c.cards.map((x) => x.id),
          }))
          await fetch('/api/cards/order', {
            method: 'POST',
            headers,
            body: JSON.stringify({ columns }),
          })
          return card.id
        },
        { title: cardTitle, targetTitle: toTitle },
      )

      expect(cardId).toBeGreaterThan(0)

      // Open the card through the UI and read the rendered timeline.
      await page.locator('.card').filter({ hasText: cardTitle }).first().click()
      const panel = page.getByRole('dialog', { name: 'Карточка' })
      await expect(panel).toBeVisible()

      const history = panel.locator('.block').filter({ hasText: 'История' }).first()
      await expect(history).toContainText('переместил(а) в')
      await expect(history).toContainText(toTitle)
      // The creation date is always present, even for cards with no event log.
      await expect(history).toContainText('создал(а) карточку')

      const events = await page.evaluate(async (id) => {
        const res = await fetch(`/api/cards/${id}/events`)
        return res.json()
      }, cardId)
      const moves = events.events.filter((e) => e.kind === 'card.moved')
      expect(moves).toHaveLength(1)
      expect(moves[0].payload.to).toBe(toTitle)
      expect(moves[0].payload.from).toBe(fromTitle)
    } finally {
      await deleteColumnByTitle(page, toTitle)
      await deleteColumnByTitle(page, fromTitle)
    }
  })

  test('the user chip keeps the avatar centred inside the pill', async ({ page }) => {
    await login(page)

    const chip = page.locator('header.topbar .user-chip')
    await expect(chip).toBeVisible()

    const metrics = await chip.evaluate((el) => {
      const avatar = el.querySelector('.avatar')
      const button = el.querySelector('button')
      const chipBox = el.getBoundingClientRect()
      const avatarBox = avatar.getBoundingClientRect()
      const buttonBox = button.getBoundingClientRect()

      // Where the initials really are, not just the element box.
      const range = document.createRange()
      range.selectNodeContents(avatar)
      const textBox = range.getBoundingClientRect()

      return {
        leftInset: Math.round(avatarBox.left - chipBox.left),
        rightInset: Math.round(chipBox.right - buttonBox.right),
        circleCenter: avatarBox.left + avatarBox.width / 2,
        textCenter: textBox.left + textBox.width / 2,
      }
    })

    // The glyphs stay centred in the circle.
    expect(Math.abs(metrics.textCenter - metrics.circleCenter)).toBeLessThan(1)

    // The complaint was a visibly bigger gap on the left of the pill than on
    // its right (6 vs 4). Now the difference must not be perceptible.
    expect(Math.abs(metrics.leftInset - metrics.rightInset)).toBeLessThanOrEqual(1)
  })
})
