import { test, expect } from '@playwright/test'
import { confirmAccept, login } from './helpers'

test.describe('appearance and archive', () => {
  test('the theme toggles and is applied before first paint after a reload', async ({ page }) => {
    await login(page)
    const toggle = page.getByRole('button', { name: /Тёмная тема|Светлая тема/ })
    const wasDark = await page.evaluate(() => document.documentElement.dataset.theme === 'dark')

    await toggle.click()
    await expect
      .poll(() => page.evaluate(() => document.documentElement.dataset.theme === 'dark'))
      .not.toBe(wasDark)

    const dark = await page.evaluate(() => document.documentElement.dataset.theme === 'dark')
    await page.reload()

    // theme-boot.js runs from <head>, so the attribute is set before Vue mounts.
    expect(await page.evaluate(() => document.documentElement.dataset.theme === 'dark')).toBe(dark)

    // Leave the board in the light theme for the other tests.
    if (dark) await page.getByRole('button', { name: /Тёмная тема|Светлая тема/ }).click()
  })

  test('archived cards can be restored from the archive dialog', async ({ page }) => {
    await login(page)

    const title = await page.evaluate(async () => {
      const csrf = document.cookie
        .split(';')
        .map((c) => c.trim())
        .find((c) => c.startsWith('kb_csrf='))
      const headers = { 'Content-Type': 'application/json' }
      if (csrf) headers['X-CSRF-Token'] = decodeURIComponent(csrf.split('=')[1])

      const board = await (await fetch('/api/board', { headers })).json()
      const column = (board.columns || [])[0]
      const name = `Архив ${Date.now().toString(36)}`
      const res = await fetch('/api/cards', {
        method: 'POST',
        headers,
        body: JSON.stringify({ columnId: column.id, title: name }),
      })
      const created = await res.json()
      const card = created.columns.flatMap((c) => c.cards).find((c) => c.title === name)
      await fetch(`/api/cards/${card.id}/archive`, { method: 'POST', headers })
      return name
    })

    await page.reload()
    await expect(page.getByText(title, { exact: true })).toHaveCount(0)

    await page.getByRole('button', { name: 'Архив' }).click()
    const dialog = page.getByRole('dialog', { name: 'Архив карточек' })
    await expect(dialog).toBeVisible()
    await expect(dialog.getByText(title, { exact: true })).toBeVisible()

    await dialog.getByRole('button', { name: 'Вернуть' }).first().click()
    await page.keyboard.press('Escape')
    await expect(page.getByText(title, { exact: true })).toBeVisible()

    // Clean up: delete it for good through the API.
    await page.evaluate(async (cardTitle) => {
      const csrf = document.cookie
        .split(';')
        .map((c) => c.trim())
        .find((c) => c.startsWith('kb_csrf='))
      const headers = { 'Content-Type': 'application/json' }
      if (csrf) headers['X-CSRF-Token'] = decodeURIComponent(csrf.split('=')[1])
      const board = await (await fetch('/api/board', { headers })).json()
      const card = board.columns.flatMap((c) => c.cards).find((c) => c.title === cardTitle)
      if (card) await fetch(`/api/cards/${card.id}`, { method: 'DELETE', headers })
    }, title)
  })

  test('the label manager creates a label and it appears in the filters', async ({ page }) => {
    await login(page)
    const name = `Метка ${Date.now().toString(36)}`

    await page.getByRole('button', { name: 'Метки' }).first().click()
    const dialog = page.getByRole('dialog', { name: 'Метки доски' })
    await expect(dialog).toBeVisible()

    await dialog.getByLabel('Название новой метки').fill(name)
    await dialog.getByRole('button', { name: 'Создать' }).click()
    await expect(dialog.getByText(name, { exact: true })).toBeVisible()

    await page.keyboard.press('Escape')
    await expect(page.getByRole('button', { name, exact: false }).first()).toBeVisible()

    // Delete the label again so repeated runs stay clean.
    await page.getByRole('button', { name: 'Метки' }).first().click()
    await page.getByRole('dialog', { name: 'Метки доски' }).getByRole('button', {
      name: `Удалить метку ${name}`,
    }).click()
    await confirmAccept(page, 'Удалить метку')
  })
})
