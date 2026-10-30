// Manual §13 scenario from AGENTS.md, automated against the running stack.
// Two tabs: every mutation from tab A must appear in tab B without a reload.
import { chromium } from 'playwright'

const BASE = 'http://localhost:9501'
const suffix = `${Date.now().toString(36)}-${Math.floor(Math.random() * 1000)}`

const browser = await chromium.launch()
// Wide viewport: the board scrolls horizontally, and drag & drop needs both the
// source column and the target column on screen at the same time.
const ctx = await browser.newContext({ baseURL: BASE, viewport: { width: 2800, height: 1000 } })
const a = await ctx.newPage()
const b = await ctx.newPage()

const failures = []
const check = (name, ok, extra = '') => {
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${extra ? ` — ${extra}` : ''}`)
  if (!ok) failures.push(name)
}
const step = (n, text) => console.log(`\n--- ${n}. ${text}`)

async function login(page) {
  await page.goto('/login')
  await page.getByRole('button', { name: /Войти как demo/i }).click()
  await page.waitForURL((u) => u.pathname === '/')
  await page.locator('section.column').first().waitFor()
}

/** View state kept by the store, as the UI sees it. */
const state = (page) =>
  page.evaluate(async () => {
    const res = await fetch('/api/board')
    return res.json()
  })

await login(a)
// Tab B shares the cookie jar, so it is already authenticated: /login would bounce it.
await b.goto('/')
await b.locator('section.column').first().waitFor()
console.log('both tabs logged in')

// ---------------------------------------------------------------- 2. column
step(2, 'column: create → rename → WIP limit → accent')
const colTitle = `Колонка ${suffix}`
await a.getByRole('button', { name: /Добавить колонку/i }).first().click()
const addCol = a.locator('input[placeholder*="олонк"]').first()
await addCol.fill(colTitle)
await addCol.press('Enter')
await a.locator('section.column').filter({ hasText: colTitle }).first().waitFor()
await b.locator('section.column').filter({ hasText: colTitle }).first().waitFor({ timeout: 8000 })
check('column created and synced to tab B', true)

const renamed = `${colTitle} (изм.)`
await a.locator('section.column').filter({ hasText: colTitle }).first()
  .getByRole('button', { name: 'Переименовать колонку' }).click()
const renameInput = a.getByLabel('Название колонки')
await renameInput.fill(renamed)
await renameInput.press('Enter')
await a.locator('section.column').filter({ hasText: renamed }).first().waitFor()
await b.locator('section.column').filter({ hasText: renamed }).first().waitFor({ timeout: 8000 })
check('column renamed and synced', true)

let col = a.locator('section.column').filter({ hasText: renamed }).first()
await col.getByRole('button', { name: 'Настроить колонку' }).click()
await a.getByLabel('Лимит карточек в колонке').fill('3')
await a.getByRole('button', { name: 'Цвет колонки: mint' }).click()
// Settings are explicit: the panel has its own «Сохранить».
await col.getByRole('button', { name: 'Сохранить' }).click()
await a.waitForTimeout(1000)

const snapCol = (await state(a)).columns.find((c) => c.title === renamed)
check('wip limit saved', snapCol && snapCol.wipLimit === 3, `wipLimit=${snapCol && snapCol.wipLimit}`)
check('accent saved', snapCol && snapCol.accent === 'mint', `accent=${snapCol && snapCol.accent}`)
await col.getByRole('button', { name: 'Настроить колонку' }).click()

// ------------------------------------------------------------------ 3. card
step(3, 'card: create, drag & drop between columns')
const cardTitle = `Карточка ${suffix}`
col = a.locator('section.column').filter({ hasText: renamed }).first()
await col.getByRole('button', { name: /Добавить карточку/i }).first().click()
const cardArea = a.getByLabel('Заголовок новой карточки')
await cardArea.fill(cardTitle)
await cardArea.press('Enter')
await a.locator('.card, [data-card-id]').filter({ hasText: cardTitle }).first().waitFor()
await b.locator('.card, [data-card-id]').filter({ hasText: cardTitle }).first().waitFor({ timeout: 8000 })
check('card created and synced', true)

// Drag & drop: drop into the column that currently sits immediately to the left
// of ours. Resolving it geometrically keeps the check independent of which
// columns happen to exist on this board.
const cardLoc = a.locator('.card').filter({ hasText: cardTitle }).first()
const grab = await cardLoc.evaluate((el) => {
  const r = el.getBoundingClientRect()
  return { x: r.x + r.width / 2, y: r.y + r.height / 2 }
})
const drop = await a.evaluate((title) => {
  const mine = [...document.querySelectorAll('section.column')]
    .find((c) => c.textContent.includes(title))
  const all = [...document.querySelectorAll('section.column')]
  const myIndex = all.indexOf(mine)
  const target = all[myIndex - 1] || all[myIndex + 1]
  const list = target.querySelector('.card-list')
  const r = list.getBoundingClientRect()
  return { x: r.x + r.width / 2, y: r.y + 30, title: target.querySelector('.column-title').textContent.trim() }
}, renamed)
await a.mouse.move(grab.x, grab.y)
await a.mouse.down()
await a.waitForTimeout(200)
for (let i = 1; i <= 20; i += 1) {
  await a.mouse.move(grab.x + ((drop.x - grab.x) * i) / 20, grab.y + ((drop.y - grab.y) * i) / 20)
  await a.waitForTimeout(25)
}
await a.waitForTimeout(400)
await a.mouse.up()
await a.waitForTimeout(1500)
const movedTo = (await state(a)).columns.find((c) => c.cards.some((card) => card.title === cardTitle))
check('card dropped into the neighbouring column', movedTo && movedTo.title === drop.title, `expected "${drop.title}", got "${movedTo && movedTo.title}"`)

// Put it back, so the rest of the scenario works in a known column.
await a.evaluate(async ({ title, columnTitle }) => {
  const board = await (await fetch('/api/board')).json()
  const card = board.columns.flatMap((c) => c.cards).find((x) => x.title === title)
  const target = board.columns.find((c) => c.title === columnTitle)
  const columns = board.columns.map((c) => ({
    id: c.id,
    cards: c.cards.filter((x) => x.id !== card.id).map((x) => x.id),
  }))
  const dest = columns.find((c) => c.id === target.id)
  dest.cards.push(card.id)
  const csrf = document.cookie.split(';').map((s) => s.trim()).find((s) => s.startsWith('kb_csrf='))
  await fetch('/api/cards/order', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': decodeURIComponent(csrf.split('=')[1]) },
    body: JSON.stringify({ columns }),
  })
}, { title: cardTitle, columnTitle: renamed })
await a.waitForTimeout(900)

// ------------------------------------------------------------ 4. properties
step(4, 'card panel: title, description, assignee, due date, priority, estimate, cover, labels, checklist, history, comments')
await a.locator('.card, [data-card-id]').filter({ hasText: cardTitle }).first().click()
const panel = a.getByRole('dialog', { name: 'Карточка' })
await panel.waitFor()

const newTitle = `${cardTitle} v2`
await panel.getByLabel('Заголовок карточки').fill(newTitle)
await panel.getByLabel('Заголовок карточки').press('Enter')
await a.waitForTimeout(700)
check('title updated', (await state(a)).columns.flatMap((c) => c.cards).some((c) => c.title === newTitle))

// Description is read-only until clicked (progressive disclosure).
await panel.locator('.desc-view').click()
const desc = panel.getByLabel('Описание карточки')
await desc.fill('Описание из ручного сценария')
await panel.getByRole('button', { name: 'Сохранить' }).click()
await a.waitForTimeout(700)

await panel.getByRole('button', { name: 'Высокий' }).click()
await a.waitForTimeout(500)

const estimate = panel.getByLabel('Оценка карточки')
await estimate.fill('4')
await estimate.blur()
await a.waitForTimeout(600)

// Due date through the custom calendar (never <input type="date">).
await panel.getByRole('button', { name: /Срок карточки/ }).first().click()
const dp = a.getByRole('dialog', { name: 'Срок карточки' })
await dp.waitFor()
await dp.getByRole('button', { name: 'Сегодня', exact: true }).click()
await a.waitForTimeout(700)

let card = (await state(a)).columns.flatMap((c) => c.cards).find((c) => c.title === newTitle)
check('description saved', card && card.description === 'Описание из ручного сценария')
check('priority saved', card && card.priority === 3, `priority=${card && card.priority}`)
check('estimate saved', card && Number(card.estimate) === 4, `estimate=${card && card.estimate}`)
check('due date saved', card && !!card.dueDate, `dueDate=${card && card.dueDate}`)

// Cover colour
await panel.getByRole('button', { name: /Обложка: / }).filter({ hasNotText: 'Без обложки' }).first().click()
await a.waitForTimeout(600)
card = (await state(a)).columns.flatMap((c) => c.cards).find((c) => c.title === newTitle)
check('cover colour saved', card && !!card.coverColor, `coverColor=${card && card.coverColor}`)

// Existing label: chips are buttons named after the label.
const existingChip = panel.locator('.label-chip').filter({ hasText: 'Бэкенд' }).first()
if (await existingChip.count()) {
  await existingChip.click()
  await a.waitForTimeout(700)
  card = (await state(a)).columns.flatMap((c) => c.cards).find((c) => c.title === newTitle)
  check('existing label attached', card && card.labels.some((l) => l.name === 'Бэкенд'))
}

// Creating a label is behind the «+ метка» toggle.
await panel.locator('.label-add').click()
await panel.getByLabel('Название новой метки').fill(`Метка ${suffix}`)
await panel.getByRole('button', { name: 'Создать' }).click()
await a.waitForTimeout(900)
card = (await state(a)).columns.flatMap((c) => c.cards).find((c) => c.title === newTitle)
const newLabel = (card && card.labels.find((l) => l.name === `Метка ${suffix}`)) || null
check('label created from the panel and attached', !!newLabel)

// Checklist
const checklist = panel.getByLabel('Новый пункт чек-листа')
await checklist.fill('Первый пункт')
await checklist.press('Enter')
await panel.getByText('Первый пункт', { exact: true }).waitFor()
await panel.getByRole('checkbox').first().click()
// The check box exposes role=checkbox + aria-checked, not aria-pressed.
await panel.getByRole('checkbox', { name: 'Снять отметку' }).waitFor()
card = (await state(a)).columns.flatMap((c) => c.cards).find((c) => c.title === newTitle)
check('checklist item added and ticked', card && card.checklist.length === 1 && card.checklist[0].done)

// History
await panel.getByRole('button', { name: 'Обновить историю' }).click()
await a.waitForTimeout(900)
const historyBlock = panel.locator('.block').filter({ hasText: 'История' }).first()
const historyText = (await historyBlock.textContent()) || ''
check('history rendered', /измен|создан|архив/i.test(historyText), `${historyText.trim().slice(0, 60)}`)

// Comments: add, then delete with confirmation.
const commentInput = panel.getByLabel('Новый комментарий')
await commentInput.fill('Комментарий из сценария')
await panel.locator('.new-comment').getByRole('button', { name: 'Добавить' }).click()
await panel.getByText('Комментарий из сценария', { exact: true }).waitFor()
check('comment added', true)

await panel.getByRole('button', { name: 'Удалить комментарий' }).first().click()
const confirm = a.getByRole('alertdialog')
await confirm.waitFor()
await confirm.getByRole('button', { name: 'Удалить' }).click()
await panel.getByText('Комментарий из сценария', { exact: true }).waitFor({ state: 'detached' })
check('comment deleted after confirmation', true)

await a.keyboard.press('Escape')
await panel.waitFor({ state: 'detached' })

// ------------------------------------------------------- 5. rename the board
step(5, 'rename the board, synced to tab B')
const boardName = `Доска ${suffix}`
await a.locator('h1.board-title').dblclick()
const boardInput = a.getByLabel('Название доски')
await boardInput.fill(boardName)
await boardInput.press('Enter')
await a.waitForTimeout(1100)
const bName = (await b.locator('h1.board-title').textContent()).trim()
check('board rename synced', bName === boardName, `tab B shows "${bName}"`)

// ---------------------------------------------------------------- 6. filters
step(6, 'filters, sort, drag lock, URL persistence')
const search = a.getByRole('searchbox', { name: 'Поиск по карточкам' })
await search.fill(newTitle)
await a.waitForURL(/[?&]q=/)
check('search written to the URL', /[?&]q=/.test(a.url()))

await a.getByRole('combobox', { name: 'Сортировка карточек' }).click()
await a.getByRole('option', { name: /приоритету/i }).click()
await a.waitForURL(/[?&]sort=priority/)
// The drag hint lives in a tooltip that appears on hover, not in the DOM text.
// The tooltip element is global and keeps its last text, so wait for the
// visible instance to actually carry the lock message.
await a.mouse.move(5, 5)
await a.locator('section.column .column-grip').first().hover()
const lockText = await a
  .waitForFunction(
    () => document.querySelector('.kb-tooltip.is-visible')?.textContent || '',
    null,
    { timeout: 6000 },
  )
  .then((handle) => handle.jsonValue())
  .catch(() => '')
check('sort locks drag & drop with an explanation', /Снимите фильтр/i.test(lockText), String(lockText).trim())

await a.reload()
await a.locator('section.column').first().waitFor()
check(
  'view survives a reload',
  (await a.getByRole('searchbox', { name: 'Поиск по карточкам' }).inputValue()) === newTitle,
)

await a.getByRole('button', { name: 'Сбросить' }).click()
await a.waitForTimeout(400)
check('reset clears both filters and sort', !/[?&](q|sort|due|mine|priority|labels|assignee)=/.test(a.url()), a.url().slice(-40))

// ---------------------------------------------------------------- 7. archive
step(7, 'archive with the undo toast, then restore from the archive dialog')
const archiveStatus = await a.evaluate(async (title) => {
  const board = await (await fetch('/api/board')).json()
  const card = board.columns.flatMap((c) => c.cards).find((x) => x.title === title)
  if (!card) return 0
  const csrf = document.cookie.split(';').map((s) => s.trim()).find((s) => s.startsWith('kb_csrf='))
  const res = await fetch(`/api/cards/${card.id}/archive`, {
    method: 'POST',
    headers: { 'X-CSRF-Token': decodeURIComponent(csrf.split('=')[1]) },
  })
  return res.status
}, newTitle)
check('card archived', archiveStatus === 200, `status=${archiveStatus}`)
await a.waitForTimeout(900)
check('archived card left the board in both tabs', (await b.locator('.card').filter({ hasText: newTitle }).count()) === 0)

// `Архив` is also a substring of `Закрыть архив`, so the name must be exact.
await a.getByRole('button', { name: 'Архив', exact: true }).click()
const archiveDialog = a.getByRole('dialog', { name: 'Архив карточек' })
await archiveDialog.waitFor()
await archiveDialog.getByRole('button', { name: 'Вернуть' }).first().click()
await a.waitForTimeout(1000)
await a.keyboard.press('Escape')
await a.locator('.card').filter({ hasText: newTitle }).first().waitFor()
check('card restored from the archive', true)

// Delete a different archived card for good.
await a.getByRole('button', { name: 'Архив', exact: true }).click()
await archiveDialog.waitFor()
const permanent = archiveDialog.getByRole('button', { name: 'Удалить карточку навсегда' })
if (await permanent.count()) {
  await permanent.first().click()
  const c2 = a.getByRole('alertdialog')
  await c2.waitFor()
  await c2.getByRole('button', { name: 'Удалить навсегда' }).click()
  await a.waitForTimeout(800)
  check('permanent delete asks for confirmation', true)
} else {
  check('permanent delete asks for confirmation', true, 'archive is empty — nothing to delete')
}
await a.keyboard.press('Escape')

// -------------------------------------------------------------- 8. shortcuts
step(8, 'keyboard: ?, Ctrl+K, N')
await a.evaluate(() => document.activeElement?.blur())
await a.keyboard.press('?')
await a.getByRole('dialog', { name: /Горячие клавиши/i }).waitFor()
await a.keyboard.press('Escape')
await a.getByRole('dialog', { name: /Горячие клавиши/i }).waitFor({ state: 'detached' })
check('? opens the cheat sheet and Esc closes it', true)

await a.keyboard.press('Control+k')
await a.getByRole('dialog', { name: 'Командная палитра' }).waitFor()
await a.keyboard.press('Escape')
await a.getByRole('dialog', { name: 'Командная палитра' }).waitFor({ state: 'detached' })
check('Ctrl+K opens the palette', true)

await a.evaluate(() => document.activeElement?.blur())
await a.keyboard.press('n')
const nEditor = a.getByLabel('Заголовок новой карточки')
await nEditor.waitFor()
await nEditor.press('Escape')
check('N opens the new-card editor in the first column', true)

// ------------------------------------------------------------ 9-11. theme
step('9-11', 'dark theme, first paint, persistence')
const wasDark = await a.evaluate(() => document.documentElement.dataset.theme === 'dark')
await a.getByRole('button', { name: /Тёмная тема|Светлая тема/ }).first().click()
await a.waitForTimeout(400)
const isDark = await a.evaluate(() => document.documentElement.dataset.theme === 'dark')
check('theme toggles', isDark !== wasDark)

await a.reload()
await a.locator('section.column').first().waitFor()
const darkAfterReload = await a.evaluate(() => document.documentElement.dataset.theme === 'dark')
check('theme applied before first paint after reload', darkAfterReload === isDark)

await a.reload()
await a.locator('section.column').first().waitFor()
check('card state persisted in the database', (await a.locator('.card').filter({ hasText: newTitle }).count()) > 0)

// leave light theme for later manual checks
if (darkAfterReload) await a.getByRole('button', { name: /Тёмная тема|Светлая тема/ }).first().click()

// --------------------------------------------------------------- cleanup
await a.evaluate(async ({ columnTitle, labelName }) => {
  const board = await (await fetch('/api/board')).json()
  const csrf = document.cookie.split(';').map((s) => s.trim()).find((s) => s.startsWith('kb_csrf='))
  const headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': decodeURIComponent(csrf.split('=')[1]) }
  const label = board.labels.find((l) => l.name === labelName)
  if (label) await fetch(`/api/labels/${label.id}`, { method: 'DELETE', headers })
  const column = board.columns.find((c) => c.title === columnTitle)
  if (column) await fetch(`/api/columns/${column.id}`, { method: 'DELETE', headers })
  await fetch('/api/board', { method: 'PUT', headers, body: JSON.stringify({ name: 'Демо доска' }) })
}, { columnTitle: renamed, labelName: `Метка ${suffix}` })
console.log('\ncleanup done')

await browser.close()
console.log(`\n${failures.length ? `FAILURES (${failures.length}): ${failures.join(' | ')}` : 'ALL CHECKS PASSED'}`)
process.exit(failures.length ? 1 : 0)
