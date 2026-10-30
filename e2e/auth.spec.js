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

  test('the login page warns that the demo account is still enabled', async ({ page }) => {
    await page.goto('/login')

    // The banner is driven by GET /api/config, so the request must have happened.
    const notices = page.locator('.notice')
    await expect(notices.filter({ hasText: 'APP_DEMO=0' })).toBeVisible()
    await expect(notices.filter({ hasText: 'Демо-доступ включён' })).toHaveCount(1)

    // The credentials block and the quick-login button belong to the same flag.
    await expect(page.getByRole('button', { name: /Войти как demo/i })).toBeVisible()
    await expect(page.locator('.demo-creds')).toContainText('demo@kanban.local')
  })

  test('deployment banners are not alert roles', async ({ page }) => {
    await page.goto('/login')
    await expect(page.locator('.notice').first()).toBeVisible()

    // e2e/auth.spec.js asserts the login error through getByRole('alert'). A
    // permanent banner with that role would make the assertion meaningless.
    await expect(page.getByRole('alert')).toHaveCount(0)

    // The APP_SECRET notice is development-only: this suite runs against the
    // built bundle (import.meta.env.DEV === false), so it must be absent even
    // though the shipped secret is still the placeholder.
    await expect(page.locator('.notice').filter({ hasText: 'APP_SECRET' })).toHaveCount(0)
  })

  test('/api/config exposes flags but never the secret', async ({ page }) => {
    await page.goto('/login')
    const res = await page.request.get('/api/config')
    expect(res.status()).toBe(200)

    const body = await res.json()
    expect(typeof body.demo).toBe('boolean')
    expect(typeof body.defaultSecret).toBe('boolean')
    expect(body.secret).toBeUndefined()
    expect(JSON.stringify(body)).not.toContain('kanban-csrf-secret')
  })
})
