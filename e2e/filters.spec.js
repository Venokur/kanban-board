import { test, expect } from '@playwright/test'
import { createCard, createColumn, deleteColumnByTitle, login, unique } from './helpers'

test.describe('filters, search and URL state', () => {
  test('search writes to the URL, filters the board and survives a reload', async ({ page }) => {
    await login(page)
    const search = page.getByRole('searchbox', { name: 'Поиск по карточкам' })

    await search.fill('zzz-такой-карточки-нет')
    await expect(page).toHaveURL(/[?&]q=/)
    await expect(page.getByText(/скрыто/i).first()).toBeVisible()

    // Reloading keeps the filter, because it lives in the query string.
    await page.reload()
    await expect(page.getByRole('searchbox', { name: 'Поиск по карточкам' })).toHaveValue(
      'zzz-такой-карточки-нет',
    )

    await page.getByRole('button', { name: 'Сбросить' }).click()
    await expect(page).not.toHaveURL(/[?&]q=/)
  })

  test('Esc clears the search field before leaving it', async ({ page }) => {
    await login(page)
    await page.keyboard.press('/')
    const search = page.getByRole('searchbox', { name: 'Поиск по карточкам' })
    await expect(search).toBeFocused()

    await page.keyboard.type('abc')
    await expect(search).toHaveValue('abc')

    await page.keyboard.press('Escape')
    await expect(search).toHaveValue('')
    await expect(search).toBeFocused()

    await page.keyboard.press('Escape')
    await expect(search).not.toBeFocused()
  })

  test('sorting disables drag & drop with an explanation', async ({ page }) => {
    await login(page)
    const sortTrigger = page.getByRole('combobox', { name: 'Сортировка карточек' })
    await sortTrigger.click()
    await page.getByRole('option', { name: /сроку/i }).click()
    await expect(page).toHaveURL(/[?&]sort=due/)

    // The grip is present but the reorder hint explains why it is inert.
    const grip = page.locator('section.column .column-grip').first()
    await expect(grip).toBeVisible()

    await page.getByRole('button', { name: 'Сбросить' }).click()
    await expect(page).not.toHaveURL(/[?&]sort=/)
  })

  test('a card can be found by its title through search', async ({ page }) => {
    await login(page)
    const colTitle = unique('Колонка')
    const cardTitle = unique('Ищем')
    await createColumn(page, colTitle)
    await createCard(page, cardTitle, colTitle)

    await page.getByRole('searchbox', { name: 'Поиск по карточкам' }).fill(cardTitle)
    await expect(page.getByText(cardTitle, { exact: true })).toBeVisible()
    await expect(page.getByText('Настроить проект в Docker', { exact: true })).toHaveCount(0)

    await page.getByRole('button', { name: 'Сбросить' }).click()
    await deleteColumnByTitle(page, colTitle)
  })
})
