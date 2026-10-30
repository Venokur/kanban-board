/**
 * Shared helpers for the end-to-end suite.
 *
 * Every test creates its own column/card with a unique suffix and removes it
 * afterwards, so the suite can run against a board that already has data.
 */
import { expect } from '@playwright/test'

export const DEMO = { email: 'demo@kanban.local', password: 'demo1234' }

export function unique(prefix) {
  return `${prefix} ${Date.now().toString(36)}-${Math.floor(Math.random() * 1000)}`
}

export async function login(page) {
  await page.goto('/login')
  await page.getByRole('button', { name: /Войти как demo/i }).click()
  await page.waitForURL((url) => url.pathname === '/')
  await expect(page.locator('section.column').first()).toBeVisible()
}

/** Column section locator by its visible title. */
export function column(page, title) {
  return page.locator('section.column').filter({ hasText: title }).first()
}

/**
 * The shared confirmation dialog (./ConfirmDialog.vue). Always scope the accept
 * button to it: several panels contain buttons with overlapping names.
 */
export function confirmDialog(page) {
  return page.getByRole('alertdialog')
}

/** Press the accept button of the open confirmation dialog. */
export async function confirmAccept(page, name) {
  const dialog = confirmDialog(page)
  await expect(dialog).toBeVisible()
  await dialog.getByRole('button', { name }).click()
  await expect(dialog).toHaveCount(0)
}

/** Press «Отмена» in the open confirmation dialog. */
export async function confirmCancel(page) {
  const dialog = confirmDialog(page)
  await expect(dialog).toBeVisible()
  await dialog.getByRole('button', { name: 'Отмена' }).click()
  await expect(dialog).toHaveCount(0)
}

export async function createColumn(page, title) {
  await page.getByRole('button', { name: /Добавить колонку|Новая колонка/i }).first().click()
  const input = page.locator('input[placeholder*="олонк"], textarea[placeholder*="олонк"]').first()
  await input.fill(title)
  await input.press('Enter')
  await expect(column(page, title)).toBeVisible()
}

export async function createCard(page, title, columnTitle) {
  const target = columnTitle ? column(page, columnTitle) : page.locator('section.column').first()
  await target.getByRole('button', { name: /Добавить карточку/i }).first().click()
  await target.locator('textarea').first().fill(title)
  await page.keyboard.press('Enter')
  await expect(page.getByText(title, { exact: true }).first()).toBeVisible()
}

/**
 * Delete a column through the API using the browser's session.
 *
 * @returns {Promise<number>} HTTP status
 */
export async function deleteColumnByTitle(page, title) {
  return page.evaluate(async (name) => {
    const csrf = document.cookie
      .split(';')
      .map((c) => c.trim())
      .find((c) => c.startsWith('kb_csrf='))
    const headers = { 'Content-Type': 'application/json' }
    if (csrf) headers['X-CSRF-Token'] = decodeURIComponent(csrf.split('=')[1])

    const board = await (await fetch('/api/board', { headers })).json()
    const target = (board.columns || []).find((c) => c.title === name)
    if (!target) return 0
    const res = await fetch(`/api/columns/${target.id}`, { method: 'DELETE', headers })
    return res.status
  }, title)
}
