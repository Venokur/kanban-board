import { test, expect } from '@playwright/test'
import { column, login, unique } from './helpers'

test.describe('keyboard and command palette', () => {
  test('? opens the shortcut cheat sheet and Esc closes it', async ({ page }) => {
    await login(page)
    // Do not click the page: the click could land on a card and open its panel.
    await page.evaluate(() => document.activeElement?.blur())
    await page.keyboard.press('?')

    const sheet = page.getByRole('dialog', { name: /Горячие клавиши/i })
    await expect(sheet).toBeVisible()

    await page.keyboard.press('Escape')
    await expect(sheet).toHaveCount(0)
  })

  test('Ctrl+K searches cards and opens one', async ({ page }) => {
    await login(page)
    const colTitle = unique('Колонка')
    const cardTitle = unique('Палитра')

    // One deterministic card to target.
    await page.getByRole('button', { name: /Добавить колонку/i }).first().click()
    const input = page.locator('input[placeholder*="олонк"]').first()
    await input.fill(colTitle)
    await input.press('Enter')
    await expect(column(page, colTitle)).toBeVisible()

    const target = column(page, colTitle)
    await target.getByRole('button', { name: /Добавить карточку/i }).first().click()
    await target.locator('textarea').first().fill(cardTitle)
    await page.keyboard.press('Enter')
    await expect(page.getByText(cardTitle, { exact: true })).toBeVisible()

    await page.keyboard.press('Control+k')
    const palette = page.getByRole('dialog', { name: /Командная палитра/i })
    await expect(palette).toBeVisible()

    await page.keyboard.type(cardTitle)
    await expect(palette.getByText(cardTitle, { exact: true })).toBeVisible()
    await page.keyboard.press('Enter')

    await expect(page.getByRole('dialog', { name: 'Карточка' })).toBeVisible()
    await page.keyboard.press('Escape')

    await page.evaluate(async (title) => {
      const csrf = document.cookie
        .split(';')
        .map((c) => c.trim())
        .find((c) => c.startsWith('kb_csrf='))
      const headers = { 'Content-Type': 'application/json' }
      if (csrf) headers['X-CSRF-Token'] = decodeURIComponent(csrf.split('=')[1])
      const board = await (await fetch('/api/board', { headers })).json()
      const found = (board.columns || []).find((c) => c.title === title)
      if (found) await fetch(`/api/columns/${found.id}`, { method: 'DELETE', headers })
    }, colTitle)
  })

  test('N creates a card in the first column', async ({ page }) => {
    await login(page)
    const title = unique('Быстрая')
    const firstColumn = page.locator('section.column').first()

    await firstColumn.click({ position: { x: 10, y: 10 } })
    await page.keyboard.press('n')

    const area = firstColumn.locator('textarea').first()
    await expect(area).toBeFocused()
    await area.fill(title)
    await page.keyboard.press('Enter')
    await expect(page.getByText(title, { exact: true })).toBeVisible()

    // Remove through the card panel so the board stays clean.
    await page.getByText(title, { exact: true }).first().click()
    const panel = page.getByRole('dialog', { name: 'Карточка' })
    await panel.getByRole('button', { name: 'В архив' }).click()
    await expect(page.getByText(title, { exact: true })).toHaveCount(0)

    // Archiving only hides the card: delete it for good, otherwise every run
    // leaves another row behind in the archive.
    await page.evaluate(async (cardTitle) => {
      const csrf = document.cookie
        .split(';')
        .map((c) => c.trim())
        .find((c) => c.startsWith('kb_csrf='))
      const headers = { 'Content-Type': 'application/json' }
      if (csrf) headers['X-CSRF-Token'] = decodeURIComponent(csrf.split('=')[1])
      const archived = await (await fetch('/api/board/archive', { headers })).json()
      const card = (archived.cards || []).find((c) => c.title === cardTitle)
      if (card) await fetch(`/api/cards/${card.id}`, { method: 'DELETE', headers })
    }, title)
  })
})
