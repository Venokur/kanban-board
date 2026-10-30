import { test, expect } from '@playwright/test'
import { DEMO, login } from './helpers'

test.describe('authentication', () => {
  test('the board requires a session and the demo login works', async ({ page }) => {
    await page.goto('/')
    await expect(page).toHaveURL(/\/login$/)
    await expect(page.getByRole('button', { name: /Войти как demo/i })).toBeVisible()

    await login(page)
    await expect(page.getByRole('button', { name: /Выйти|Выход/i }).first()).toBeVisible()
  })

  test('wrong credentials show an error and keep the visitor on /login', async ({ page }) => {
    await page.goto('/login')
    await page.getByRole('textbox', { name: /Email|Почта/i }).fill(DEMO.email)
    // `getByLabel(/Пароль/)` would also match the "Показать пароль" button aria-label.
    await page.locator('#login-password').fill('definitely-wrong')
    await page.getByRole('button', { name: /^Войти$/ }).click()
    await expect(page).toHaveURL(/\/login$/)
    await expect(page.getByRole('alert')).toBeVisible()
  })

  test('logout clears the session', async ({ page }) => {
    await login(page)
    await page.getByRole('button', { name: /Выйти|Выход/i }).first().click()
    await expect(page).toHaveURL(/\/login$/)
    await page.goto('/')
    await expect(page).toHaveURL(/\/login$/)
  })
})
