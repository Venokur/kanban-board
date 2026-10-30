import { test, expect } from '@playwright/test'
import { column, confirmAccept, createCard, createColumn, deleteColumnByTitle, login, unique } from './helpers'

test.describe('columns and cards', () => {
  test('creates, renames and deletes a column', async ({ page }) => {
    await login(page)
    const title = unique('Колонка')
    const renamed = `${title} (изм.)`

    await createColumn(page, title)
    const col = column(page, title)
    await expect(col).toBeVisible()

    await col.getByRole('button', { name: 'Переименовать колонку' }).click()
    // While editing, the h3 is replaced by the input, so the section no longer
    // contains the old title: locate the field page-wide instead.
    const input = page.getByLabel('Название колонки')
    await input.fill(renamed)
    await input.press('Enter')
    await expect(column(page, renamed)).toBeVisible()

    await column(page, renamed).getByRole('button', { name: 'Удалить колонку' }).click()
    // Deletion asks for confirmation (no native confirm()).
    await confirmAccept(page, 'Удалить колонку')
    await expect(column(page, renamed)).toHaveCount(0)
  })

  test('creates a card and edits its properties', async ({ page }) => {
    await login(page)
    const colTitle = unique('Колонка')
    const cardTitle = unique('Карточка')

    await createColumn(page, colTitle)
    await createCard(page, cardTitle, colTitle)

    await page.getByText(cardTitle, { exact: true }).first().click()
    const panel = page.getByRole('dialog', { name: 'Карточка' })
    await expect(panel).toBeVisible()

    const renamed = `${cardTitle} (изм.)`
    const titleField = panel.getByLabel('Заголовок карточки')
    await titleField.fill(renamed)
    await titleField.blur()
    await expect(page.getByText(renamed, { exact: true }).first()).toBeVisible()

    await panel.getByRole('button', { name: 'Высокий' }).click()
    await expect(panel.getByRole('button', { name: 'Высокий' })).toHaveAttribute('aria-pressed', 'true')

    const estimate = panel.getByLabel('Оценка карточки')
    await estimate.fill('3')
    await estimate.blur()
    await expect(estimate).toHaveValue('3')

    // The card panel is a side sheet: Esc closes it.
    await page.keyboard.press('Escape')
    await expect(panel).toHaveCount(0)

    await deleteColumnByTitle(page, colTitle)
  })

  test('adds checklist items and comments', async ({ page }) => {
    await login(page)
    const colTitle = unique('Колонка')
    const cardTitle = unique('Карточка')

    await createColumn(page, colTitle)
    await createCard(page, cardTitle, colTitle)
    await page.getByText(cardTitle, { exact: true }).first().click()

    const panel = page.getByRole('dialog', { name: 'Карточка' })
    const checklist = panel.getByLabel('Новый пункт чек-листа')
    await checklist.fill('Первый пункт')
    await checklist.press('Enter')
    await expect(panel.getByText('Первый пункт', { exact: true })).toBeVisible()

    await panel.getByRole('checkbox').first().click()
    await expect(panel.getByRole('checkbox').first()).toHaveAttribute('aria-checked', 'true')

    const comment = panel.getByLabel('Новый комментарий')
    await comment.fill('Проверочный комментарий')
    // Scope to the comment composer: the checklist also has a «Добавить» button.
    await panel.locator('.new-comment').getByRole('button', { name: 'Добавить' }).click()
    await expect(panel.getByText('Проверочный комментарий', { exact: true })).toBeVisible()

    await page.keyboard.press('Escape')
    await deleteColumnByTitle(page, colTitle)
  })
})
