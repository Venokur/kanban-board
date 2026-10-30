# AGENTS.md — Kanban Board

Инструкции и карта проекта для AI-агентов и разработчиков. Читать перед любыми изменениями.

## 1. Что это

Trello-подобная канбан-доска в реальном времени. **Одна общая доска** на всех авторизованных
пользователей (без владельцев, ролей и прав). Колонки → карточки → комментарии, drag & drop,
синхронизация между вкладками/клиентами через WebSocket.

Карточка полноценная: исполнитель, срок, приоритет, цветные метки, чек-лист с прогрессом, оценка
в часах, цветная полоса-обложка, история изменений, архив с восстановлением. Колонка умеет
WIP-лимит и цветной акцент. Есть поиск/фильтры (текст, метки, исполнитель, приоритет, срок,
«только мои»), сортировка внутри колонки, командная палитра, горячие клавиши, тёмная тема и
переименование доски.

Демо-пользователь: `demo@kanban.local` / `demo1234` (кнопка «Войти как demo» на `/login`).

## 2. Стек

| Слой | Технологии |
|---|---|
| Frontend | Vue 3.4 (`<script setup>`), Vite 5, Pinia 2, Vue Router 4, `vuedraggable`, **без TypeScript и без CSS-фреймворка** |
| Backend | PHP 8.3, корутинный сервер: **OpenSwoole 26.2** по умолчанию либо Swoole (`SWOOLE_VARIANT`) — HTTP + WebSocket + static в одном процессе, собственный автолоад и роутер |
| БД | PostgreSQL 16, доступ через корутинный клиент `\Coroutine\PostgreSQL` |
| Инфра | Docker Compose, два режима: prod (всё на `:9501`) и dev (Vite + HMR на `:5173`) |
| Тесты | PHPUnit 12 (юнит, без расширения), Vitest + jsdom (утилиты стора/UI), Playwright (браузерный e2e) |
| Тулчейн | PHPStan level 5, PHP-CS-Fixer (PSR-12), GitHub Actions |

Рантайм-зависимостей PHP по-прежнему нет: `composer.json` содержит только dev-инструменты,
классы грузятся из `backend/src` по префиксу `App\`. Lock-файлы коммитятся: `frontend/package-lock.json`
для сборки образа и `package-lock.json` для e2e-раннера, чтобы прогоны были воспроизводимы.

OpenSwoole 26 требует **PHP ≥ 8.3** (в 26.x выпилена поддержка 8.2) и собирается из исходников
тега `v26.2.0` с флагами `--enable-openssl --with-postgres`.

### Переключение рантайма (Swoole ↔ OpenSwoole)

Код не знает, какой форк загружен: `backend/src/rt.php` один раз выбирает пространство имён
и публикует алиасы `App\Rt\*` (`WsServer`, `Request`, `Response`, `Channel`, `PostgreSQL`,
`Coroutine`, `Table`, `Timer`, `Frame`). Всё приложение работает только через `App\Rt\*`.

```bash
SWOOLE_VARIANT=swoole docker compose up --build -d      # собрать образ с Swoole
SWOOLE_VARIANT=openswoole docker compose up --build -d  # вернуться на OpenSwoole (по умолчанию)
```

Переменная читается дважды: на этапе сборки (какое расширение компилировать) и в рантайме
(какие алиасы создавать). Менять только код — бессмысленно, нужен пересбор образа.

## 3. Быстрый старт

```bash
# prod: собранный фронтенд раздаёт сервер
docker compose up --build -d          # http://localhost:9501

# dev: Vite + HMR, проксирует /api и /ws в app:9501
docker compose -f docker-compose.yml -f docker-compose.dev.yml up --build -d   # http://localhost:5173

docker compose down                   # стоп
docker compose down -v                # стоп + полный сброс БД (init.sql выполнится заново)
```

Порты: `APP_PORT=9501`, `DEV_PORT=5173`, `POSTGRES_PORT=5432` (см. `.env.example`).
Сервер работает только на Linux → приложение всегда запускается в контейнере.
Локальный PHP/Node под Windows годится для `php -l`, статического анализа и юнит-тестов.

### Миграции

`init.sql` выполняется **только на пустом volume**. Для уже существующей БД есть раннер —
он сам применяет всё недостающее и пишет историю в `schema_migrations`:

```bash
docker compose exec -T app php src/migrate.php        # идемпотентно, повторный запуск безопасен
```

Прямой `psql` тоже работает (файлы идемпотентны), но раннер предпочтительнее: он не даёт
применить один файл дважды и печатает список пропущенных.

```bash
# Linux / macOS / Git Bash
docker compose exec -T db psql -U kanban -d kanban < migrations/001_card_fields.sql
```

> **Windows PowerShell — только так.** Редирект `<` в PowerShell не работает, а пайп
> (`Get-Content … | docker compose exec -T … psql`) перекодирует файл в cp866:
> кириллица в SQL превращается в `?`, и данные (имена меток, доски) портятся молча.
> Копировать файл в контейнер и запускать его через `-f`:
>
> ```powershell
> docker cp migrations/001_card_fields.sql kanban-board-db-1:/tmp/001.sql
> docker compose exec -T db psql -U kanban -d kanban -v ON_ERROR_STOP=1 -f /tmp/001.sql
> ```
>
> Если данные уже побились — `UPDATE` с правильными строками тем же способом
> (`docker cp` + `psql -f`), а не через пайп.

### Тесты

```bash
# PHP: юнит-тесты (загружают backend/stubs/rt.stub.php, расширение не нужно)
php vendor/bin/phpunit

# PHP: стиль и статика
php vendor/bin/php-cs-fixer fix --dry-run --diff
php vendor/bin/phpstan analyse --no-progress

# Frontend: утилиты (time, urlState, labels)
cd frontend && npm test

# Браузерный e2e — против запущенного стека
npx playwright test
BASE_URL=http://localhost:5173 npx playwright test   # против dev-режима

# Контракт в виде Postman-коллекции (сборка + прогон)
node scripts/gen-postman-collection.mjs
npx newman run docs/postman/kanban.postman_collection.json

# Ручной сценарий §13 целиком: две вкладки, realtime, персистентность
node scripts/manual-scenario.mjs
```

`playwright.config.js` ходит на `http://localhost:9501`, поэтому перед e2e нужен
`docker compose up -d`. Спеки сами создают и удаляют свои колонки через API, так что прогон
не ломается на непустой доске и не оставляет мусора.

`scripts/manual-scenario.mjs` — тот же ручной сценарий из раздела 13, но исполняемый: он сам
создаёт данные, проверяет синхронизацию между двумя вкладками, фильтры, архив, горячие клавиши
и тему, а в конце убирает за собой. Печатает `PASS`/`FAIL` по каждому пункту и завершается
ненулевым кодом, если что-то не сошлось.

## 4. Архитектура

```
браузер ──HTTP /api/*──┐
        ──WS   /ws───┐│
                      ▼▼
        backend/src/server.php  (один процесс, OpenSwoole или Swoole)
              ├── Router → Controllers → Lib\BoardRepo → Lib\Db → PostgreSQL
              ├── Lib\Hub  (реестр fd → user_id, presence, coalesced broadcast)
              └── static handler (/var/www/html) + SPA fallback
```

Ключевые решения:

- **Один воркер.** `worker_num => 1` в `server.php` — несущий инвариант: и `Hub::$sessions`,
  и пул соединений `Db` живут в памяти процесса. При `worker_num > 1` broadcast увидит только
  клиентов своего воркера, а пул БД размножится. Масштабирование требует `Table`/Redis
  для реестра fd + `$server->sendMessage()` между воркерами.
- **Пул БД.** `Lib\Db` — до 10 соединений на воркер (`Channel`-очередь + токены), ленивая
  инициализация внутри корутины (`ensurePool()`), таймаут захвата 5 с, переподключение при
  обрыве соединения.
- **Модель синхронизации — «полный снапшот».** Любая мутация → `BoardRepo::snapshot()` →
  `Hub::scheduleBoardSync()` → ответ HTTP тем же снапшотом. Клиент не мержит изменения,
  а заменяет состояние целиком (`stores/board.js::applySnapshot`). Конфликтов нет, но каждое
  действие читает всю доску.
- **Ревизия отсекает гонки.** Снапшот несёт `revision` (монотонный счётчик `board.revision`).
  HTTP-ответ и broadcast приходят в произвольном порядке, поэтому клиент применяет снапшот,
  только если `revision` больше текущего.
- **Broadcast склеивается.** `Hub::scheduleBoardSync()` шлёт не чаще одного снапшота за
  `COALESCE_MS` (60 мс): серия мутаций (drag & drop, отметка чек-листа) даёт одну рассылку
  с самым свежим состоянием.
- **Presence.** `Hub` помнит `fd → user_id` и `fd → cardId` (клиент присылает
  `presence:watch`), снапшот содержит `presence.users` и `presence.watching`, при изменении
  рассылается `presence:sync`.
- **WS-авторизация — по cookie.** Браузер присылает HttpOnly `kb_session` в handshake,
  `on('Open')` проверяет её и при ошибке закрывает соединение кодом 1008. Токен в query
  (`/ws?token=…`) принимается только при `APP_WS_QUERY_TOKEN=1` (совместимость).
- **Безопасность HTTP.** HttpOnly + SameSite=Lax + Secure (при `APP_HTTPS=1`) cookie сессии,
  double-submit CSRF (`kb_csrf` cookie + `X-CSRF-Token`), rate limit логина, CSP и прочие
  security-заголовки на каждый ответ, очистка истёкших сессий по таймеру.
- **Клиентская навигация.** Фильтры/сортировка живут в query-строке (`utils/urlState.js`),
  поэтому вид переживает перезагрузку и шарится ссылкой. Когда активен фильтр или сортировка
  ≠ `position`, drag & drop выключен (`store.dragDisabled`), иначе порядок в БД разъехался бы
  с тем, что видит пользователь.

## 5. Карта файлов

```
init.sql                     схема + сид (выполняется только на пустом volume)
migrations/                  идемпотентные миграции схемы для существующих БД
  001_card_fields.sql        поля карточки, labels, card_labels, checklist_items
  002_stability.sql          revision, card_events, wip_limit/accent, cover_color, estimate
  003_dense_positions.sql    перенумерация дыр в position (колонки, карточки, метки, чек-лист)
docker-compose.yml           db + app
docker-compose.dev.yml       + frontend (Vite)
.env.example                 все переменные окружения
phpunit.xml                  PHPUnit (bootstrap — backend/tests/bootstrap.php)
phpstan.neon, .php-cs-fixer.php   статический анализ и стиль
playwright.config.js         браузерный e2e (testDir ./e2e)
docs/openapi.yaml            OpenAPI 3.1 — контракт API
docs/postman/                Postman-коллекция (32 запроса, генерируется скриптом)
scripts/                     ручной сценарий §13 и генератор Postman-коллекции
.github/workflows/ci.yml     CI: статика, юнит-тесты, сборка, smoke, Playwright
backend/Dockerfile           3 стадии: fe (vite build) → extension → runtime
backend/stubs/rt.stub.php    заглушки App\Rt\* для PHPStan и юнит-тестов
backend/tests/               PHPUnit: bootstrap + Unit/*
backend/src/
  rt.php                     выбор рантайма (OpenSwoole/Swoole) и алиасы App\Rt\*
  server.php                 bootstrap: настройки сервера, HTTP-хендлер, WS-события
  migrate.php                раннер миграций (schema_migrations, Coroutine::run)
  routes.php                 таблица маршрутов
  autoload.php               автолоад App\ → src/
  Lib/Config.php             env-конфиг (get/bool/db)
  Lib/Runtime.php            вариант рантайма, spawn() для не-корутинных контекстов
  Lib/Db.php                 корутинный пул, query/exec/transaction, conn*-методы, bind/quote/ident
  Lib/DbConnectionException.php  обрыв соединения → переподключение
  Lib/Auth.php               сессии, CSRF, токен из cookie/handshake, pruneExpired
  Lib/RateLimit.php          fixed-window лимит на вход/регистрацию (Table)
  Lib/Http.php               разбор JSON-тела и валидация строк
  Lib/HttpException.php      исключение со статусом (→ JSON `{error, code}`)
  Lib/Router.php             regex-роутер, параметры только числовые
  Lib/BoardRepo.php          доска, колонки, карточки, комментарии, архив + сборка снапшота
  Lib/LabelRepo.php          метки доски (create/update/delete/order)
  Lib/ChecklistRepo.php      пункты чек-листа (add/update/delete/order)
  Lib/EventRepo.php          история изменений карточки
  Lib/Revision.php           монотонная ревизия доски
  Lib/Hub.php                реестр подключений, presence, coalesced broadcast
  Controllers/AuthController.php   register/login/logout/me
  Controllers/BoardController.php  board/columns/cards/labels/checklist/comments; мутация → sync()
frontend/src/
  api/http.js                fetch-обёртка, CSRF-заголовок, разбор ошибок
  api/ws.js                  WS-клиент: reconnect с backoff, ping каждые 20 с, шина событий
  stores/auth.js             сессия, bootstrap, login/register/logout
  stores/board.js            снапшот, фильтры, сортировка, архив + все экшены (паттерн run())
  stores/theme.js            светлая/тёмная тема (localStorage + data-theme)
  stores/ui.js               глобальное состояние UI (открытые панели и т.п.)
  router/index.js            guard: / требует авторизации, /login — только для гостя
  views/LoginView.vue        вход/регистрация + демо-вход
  views/BoardView.vue        каркас доски, архив, палитра, горячие клавиши
  components/SvgIcon.vue     иконки из src/icons/index.js
  components/Avatar.vue      инициалы + детерминированный цвет
  components/SelectMenu.vue  выпадающий список (Teleport + fixed, клавиатура, ARIA listbox)
  components/DatePicker.vue  календарь (сетка 6×7, навигация по месяцам, клавиатура)
  components/ConfirmDialog.vue  модалка подтверждения (см. composables/useConfirm.js)
  components/ToastHost.vue   стек тостов, в т.ч. «Вернуть» после архивации
  composables/useConfirm.js  confirmAction({...}) → Promise<boolean>, очередь диалогов
  composables/useToast.js    pushToast/toastError/toastUndo
  composables/useStableStatus.js  дебаунс меняющегося статуса (WS) перед показом в UI
  directives/tooltip.js      v-tooltip — своя подсказка вместо атрибута title
  components/board/TopBar.vue        единая шапка: бренд + название доски, статус WS, метки/архив, тема, пользователь
  components/board/BoardFilters.vue  поиск, фильтры (метки/исполнитель/приоритет/срок/мои), сортировка
  components/board/Column.vue        колонка: две строки шапки (кнопки сверху, название снизу), WIP-лимит, акцент, удаление, draggable
  components/board/CardItem.vue      карточка в списке: метки, срок, приоритет, чек-лист, обложка
  components/board/CardModal.vue     боковая панель: свойства, метки, чек-лист, история, комментарии
  components/board/ArchivePanel.vue  модалка архива: восстановление и удаление
  components/board/LabelManager.vue  создание/переименование/удаление меток доски
  components/board/CommandPalette.vue  Ctrl+K: действия, карточки, создание
  components/board/ShortcutsDialog.vue  «?» — шпаргалка по горячим клавишам
  components/board/AddCard.vue       инлайн-добавление карточки
  components/board/AddColumn.vue     инлайн-добавление колонки
  styles/base.css            CSS-переменные (палитра меток, темы), .btn/.input/.textarea/.icon-btn
  utils/time.js              timeAgo(), parseDate(), startOfDay(), dueMeta()
  utils/labels.js            палитра меток и приоритеты (LABEL_COLORS, PRIORITIES)
  utils/urlState.js          фильтры/сортировка ↔ query-строка
  icons/index.js             словарь SVG-путей
frontend/public/theme-boot.js   тема до первой отрисовки (внешний файл из-за CSP)
frontend/tests/              Vitest: time, urlState, labels, useStableStatus
e2e/                         Playwright: auth, board, filters, keyboard, appearance, header, drag-preview
```

## 6. API и WebSocket

Все ответы — JSON, ошибки — `{ "error": "текст", "code": <http status> }`.
Авторизация: HttpOnly cookie `kb_session` **или** заголовок `X-Auth-Token`.
Мутации, авторизованные cookie, обязаны прислать `X-CSRF-Token` со значением cookie `kb_csrf`
(иначе **403**; 419 не используется — в таблице статусов рантайма нет такой reason phrase).
Мутирующие запросы возвращают свежий снапшот доски (исключения: `/api/board/archive`,
`/api/cards/{id}/events`, `GET /api/cards/{cardId}/comments`).

| Метод | Путь | Тело / примечание |
|---|---|---|
| POST | `/api/register` | `{name, email, password}` → `{csrf, user}`, cookie `kb_session` + `kb_csrf` |
| POST | `/api/login` | `{email, password}` → `{csrf, user}` |
| POST | `/api/logout` | удаляет сессию, чистит cookie |
| GET | `/api/me` | → `{user}` |
| GET | `/api/health` | `SELECT 1` → `{ok:true, runtime:"OpenSwoole"}` (healthcheck) |
| GET | `/api/board` | полный снапшот |
| PUT | `/api/board` | `{name}` — переименование доски |
| GET | `/api/board/archive` | `{cards:[{id,title,columnId,columnTitle,archivedAt}]}` |
| POST | `/api/columns` | `{title}` |
| PUT | `/api/columns/{id}` | `{title?, wipLimit? (int\|null), accent? (ключ палитры\|null)}` |
| DELETE | `/api/columns/{id}` | каскадно удаляет карточки, комментарии, метки карточек, чек-лист |
| POST | `/api/columns/order` | `{columnIds:[id,…]}` |
| POST | `/api/cards` | `{columnId, title, description?}` |
| PUT | `/api/cards/{id}` | подмножество: `title`, `description`, `assigneeId` (int\|null), `dueDate` (`YYYY-MM-DD`\|null), `priority` (0…3), `labelIds` (int[]), `coverColor` (ключ\|null), `estimate` (число\|null) |
| DELETE | `/api/cards/{id}` | |
| POST | `/api/cards/order` | `{columns:[{id, cards:[id,…]}, …]}` — после drag & drop; смена колонки пишет `card.moved` |
| POST | `/api/cards/{id}/archive` | в архив (позиции в колонке уплотняются) |
| POST | `/api/cards/{id}/restore` | из архива в конец колонки |
| GET | `/api/cards/{id}/events` | `{events:[{id, kind, payload, user, createdAt}]}`, новые сверху; у карточек без `card.created` создание синтезируется из `cards.created_at` (`id: 0`) |
| POST | `/api/labels` | `{name, color}` → снапшот + `createdLabelId` |
| PUT | `/api/labels/{id}` | `{name?, color?}` |
| DELETE | `/api/labels/{id}` | снимается со всех карточек |
| POST | `/api/labels/order` | `{labelIds:[id,…]}` |
| POST | `/api/cards/{cardId}/checklist` | `{text}` |
| POST | `/api/cards/{cardId}/checklist/order` | `{itemIds:[id,…]}` |
| PUT | `/api/checklist/{id}` | `{text?, done?}` |
| DELETE | `/api/checklist/{id}` | |
| GET | `/api/cards/{cardId}/comments` | `{comments:[…]}` |
| POST | `/api/cards/{cardId}/comments` | `{body}` |
| DELETE | `/api/comments/{id}` | |

`color`/`accent`/`coverColor` — **ключ палитры**, не hex: `violet|sky|mint|amber|rose|slate`.
`{id}` в путях — только цифры (`Router` подставляет `(?P<id>\d+)`), иначе 404. Поэтому
`/api/cards/{id}/archive` не конфликтует с `/api/cards/order`.

Полный контракт с телами, кодами ответов и схемами — `docs/openapi.yaml`.

WS-сообщения (`{"type": "...", "data": ...}`):

| Направление | type | data |
|---|---|---|
| клиент → сервер | `ping` | — |
| сервер → клиент | `pong` | `{t}` |
| клиент → сервер | `board:get` | — |
| клиент → сервер | `presence:watch` | `{cardId}` — какую карточку открыл клиент |
| сервер → клиент | `board:sync` | снапшот (при `Open` и после мутаций) |
| сервер → клиент | `presence:sync` | `{users, watching}` |

## 7. Форма снапшота (контракт front/back)

```jsonc
{
  "revision": 42,                                          // монотонный; старый снапшот игнорируется
  "board": { "id": 1, "name": "Демо доска" },
  "labels": [{ "id": 2, "name": "Бэкенд", "color": "sky", "position": 2 }],
  "users":  [{ "id": 1, "name": "Demo User" }],             // для выбора исполнителя
  "columns": [{
    "id": 1, "title": "Нужно сделать", "position": 1,
    "wipLimit": 5,                                          // WIP-лимит или null
    "accent": "sky",                                        // цветной акцент или null
    "cards": [{
      "id": 1, "title": "…", "description": "", "position": 1,
      "author":   { "id": 1, "name": "Demo User" },          // или null
      "assignee": { "id": 1, "name": "Demo User" },          // или null
      "dueDate": "2026-11-02",                               // YYYY-MM-DD или null
      "priority": 2,                                         // 0 нет, 1 низкий, 2 средний, 3 высокий
      "coverColor": "violet",                                // обложка карточки или null
      "estimate": 4,                                         // часы или null
      "labels": [{ "id": 2, "name": "Бэкенд", "color": "sky" }],
      "checklist": [{ "id": 1, "text": "…", "done": true, "position": 1 }],
      "createdAt": 1730000000000, "updatedAt": 1730000000000, // ms
      "comments": [{ "id": 1, "body": "…", "author": {...}, "createdAt": 1730000000000 }]
    }]
  }],
  "presence": { "users": [1], "watching": { "12": 2 } }    // watching: cardId → число клиентов
}
```

Архивные карточки в снапшот **не попадают** (`cards.archived_at IS NULL`).
Менять эту форму можно только синхронно в `BoardRepo` (backend) и `stores/board.js` +
`CardModal.vue::liveCard` / `CardItem.vue` (frontend). `CardModal` намеренно читает карточку
из стора по id, поэтому после `board:sync` открытая модалка обновляется сама.

## 8. База данных

`init.sql` создаётся один раз при пустом volume, поэтому **изменения схемы для существующих
установок нужно выносить в отдельную идемпотентную миграцию** в `migrations/` (см. раздел 3).

| Таблица | Ключевое |
|---|---|
| `users` | `email UNIQUE`, `password_hash` (bcrypt) |
| `sessions` | `token` PK, `expires_at` (+30 дней), каскад от user |
| `board` | одна строка, `name`, `revision` (монотонный счётчик) |
| `columns` | `board_id`, `position`, `wip_limit`, `accent` |
| `cards` | `column_id`, `position`, `author_id` (SET NULL), `assignee_id` (SET NULL), `due_date DATE`, `priority SMALLINT 0…3`, `cover_color`, `estimate NUMERIC`, `archived_at TIMESTAMPTZ`, `created_at/updated_at` |
| `comments` | `card_id`, `author_id`, `created_at` |
| `labels` | `board_id`, `name`, `color` (ключ палитры), `position` |
| `card_labels` | `(card_id, label_id)` PK, оба каскадные |
| `checklist_items` | `card_id`, `text`, `done`, `position`, каскад от карточки |
| `card_events` | `card_id`, `user_id`, `kind`, `payload JSONB`, `created_at` |
| `schema_migrations` | `name` PK, `applied_at` — история раннера `migrate.php` |

Инварианты:

- `position` — плотная нумерация от 1: у колонок в рамках доски, у карточек в рамках колонки
  (только среди неархивных), у меток в рамках доски, у пунктов чек-листа в рамках карточки.
  Порядок меняется только через `/api/*/order` (перезапись всех позиций в транзакции).
- **Удаление обязано уплотнять последовательность.** `deleteColumn`, `deleteCard` и
  `LabelRepo::delete` сначала читают `position`, потом `DELETE`, потом сдвигают всех
  последователей на −1 в той же транзакции. Раньше они этого не делали, и после первого же
  удаления доска навсегда начиналась с позиции 2, а новые строки дописывались за N.
  `migrations/003_dense_positions.sql` перенумеровывает уже накопленные дыры (идемпотентно).
- Удаление **архивной** карточки последовательность не трогает: у архивной строки позиция
  осталась с момента архивации и в живой нумерации не участвует.
- Архивирование карточки сдвигает `position` следующих на −1, восстановление ставит в конец.
- Удаление колонки каскадно удаляет карточки и комментарии; удаление карточки — комментарии,
  метки карточек, чек-лист.
- **Уникальных индексов на `position` нет намеренно**: переупорядочивание — это серия
  `UPDATE` внутри транзакции, и UNIQUE ломал бы её на промежуточных состояниях.
- `color` метки — **ключ палитры**, не hex: `violet|sky|mint|amber|rose|slate`
  (`LabelRepo::COLORS` ↔ `utils/labels.js::LABEL_COLORS` ↔ `--label-*` в `base.css`).
- Все временные метки в API — миллисекунды (`round(extract(epoch …)*1000)`).

## 9. Соглашения кода

PHP:

- `declare(strict_types=1);` в каждом файле, `final class`, статические методы-сервисы.
- Контроллеры возвращают `array` → `server.php` сериализует; ошибки — только `HttpException`.
- Пользовательские тексты ошибок — на русском.
- SQL — параметризованный: `Db::query(..., [$param])`; `Db::quote()`/`Db::ident()` только для
  идентификаторов и динамически собираемых фрагментов (`IN (…)`, `SET col = …`).
- Работа с БД только через `Lib\Db`; внутри `transaction()` — только `conn*`-методы
  (`connQueryOne`/`connExec`), иначе запрос уйдёт в другое соединение вне транзакции.
- Классы рантайма — только через `App\Rt\*` (`Swoole\*` в OpenSwoole 26 отсутствует,
  `OpenSwoole\*` отсутствует в Swoole). Прямой `use OpenSwoole\…` — ошибка.
- В не-корутинном контексте (таймеры, колбэки) любую работу с БД оборачивать в `Runtime::spawn()`.
- Новый код должен проходить PHPStan level 5 и PHP-CS-Fixer без правок.

Frontend:

- Composition API + `<script setup>`, опции в стиле Pinia options-store.
- События компонентов в kebab-case (`@open-card`), props — объектами.
- Стили scoped, цвета/радиусы/тени — только из переменных `styles/base.css`.
- Все сетевые вызовы — через `stores/*` + `http`/`ws`, не напрямую из компонентов.
- Интерфейс и сообщения — на русском, без i18n.
- Новые иконки добавлять в `src/icons/index.js` (24×24, stroke, `currentColor`).
- Нативные `<select>` не используем: любое выпадающее меню — `components/SelectMenu.vue`
  (`:options="[{ value, label, color?, hint? }]"`, `v-model` или `:model-value` + `@change`,
  `size="sm"` для панелей/фильтров, `size="md"` + `block` для форм внутри `.prop`).
  Цветной кружок в опции задаётся полем `color` (hex), а не отдельным слотом.
- Нативные браузерные виджеты запрещены, у каждого свой компонент:
  `<input type="date">` → `DatePicker` (`:model-value="'YYYY-MM-DD'"`, `@change` отдаёт строку
  или `''`); `alert`/`confirm`/`prompt` → `await confirmAction({ title, message, confirmText, tone })`;
  `title="…"` → `v-tooltip="'…'"` (+ `aria-label` на иконочных кнопках);
  `<input type="search">` → `type="text" role="searchbox"` со своей кнопкой очистки;
  «глазок» пароля — своя кнопка (`eye`/`eye-off`), нативные `::-ms-reveal` скрыты в `base.css`.
- Форма входа помечена `novalidate`: браузерные пузыри валидации не показываем, проверяем сами
  (`EMAIL_RE` в `LoginView`). Ошибка входа — `role="alert"`, у полей есть `for`/`id`.
- Новые цвета — сначала CSS-переменная в `base.css` (для светлой и тёмной темы), потом использование.
- Инлайн-CSP запрещает inline-скрипты: тема поднимается внешним `public/theme-boot.js`.

## 10. Как добавить фичу

1. **Схема** — новая колонка/таблица в `init.sql` **и** идемпотентная миграция в `migrations/`.
2. **Repo** — метод в `Lib\BoardRepo` (или отдельный `*Repo`, как `LabelRepo`), внутри `Db::transaction()`.
3. **Route** — `routes.php`, путь с `{id}` (только цифры).
4. **Controller** — валидация через `Lib\Http`, проверка `Auth::currentUser()`, вернуть `self::sync()`.
5. **Snapshot** — новое поле в `*Shape()` методов `BoardRepo` (+ зеркально в `stores/board.js`).
6. **Store** — экшен в `stores/board.js` через `run()`/`applySnapshot`.
7. **UI** — компонент, scoped-стили, иконка при необходимости.
8. **Контракт и тесты** — `docs/openapi.yaml`, при необходимости юнит-тест и e2e-спека.
9. **Проверка** — см. раздел 13.

Не забыть: любая мутация обязана закончиться `sync()`, иначе другие клиенты не увидят изменение;
новое серверное событие о карточке — добавить в `EventRepo::KINDS` и логировать через `EventRepo::log()`.
Событие должно нести данные в `payload`, а не только `kind`: панель строит текст из payload
(`card.moved` → `from`/`to`, `card.updated` → `fields`, метки → `labels`). Значение `kind` без
payload рендерится общим «изменил(а) карточку».

Удаляющие методы репозиториев (`BoardRepo::deleteComment`, `ChecklistRepo::update/delete`)
возвращают `card_id`: строка уже удалена, и без этого id залогировать событие не в ту карточку.

## 11. Известные ограничения и ловушки

| Ловушка | Детали |
|---|---|
| `worker_num => 1` | Реестр WS-подключений и пул БД — в памяти воркера. Не поднимать без переделки `Hub`. |
| `Hub::pushRaw()` использует `$server->exists()` | В OpenSwoole 26 метода `exist()` нет (алиас был в Swoole 5). Не откатывать на `exist()`. |
| CSRF отвечает **403**, а не 419 | В таблице статусов OpenSwoole нет reason phrase для 419 → клиент получает пустую строку статуса и видит 200. |
| Cookie сессии — только через позиционные аргументы | `Response::cookie()` в OpenSwoole принимает позиционные параметры; именованные молча не срабатывают. |
| Cookie в WS-handshake | `$request->cookie` уже разобран, в `$request->header` Cookie нет. Поэтому `Auth::tokenFromHandshake()` смотрит cookie-map, а сырой заголовок — как fallback. |
| Пул создаётся лениво | `Db::ensurePool()` обязан вызываться из корутины (HTTP/WS-хендлер). `Channel` нельзя создавать в глобальном скоупе. |
| `Coroutine::create` в CLI-скрипте | Одинокий `Coroutine::create` в `migrate.php` подвешивал процесс (нет планировщика) — нужен `Coroutine::run`. |
| `init.sql` не мигрирует | Выполняется только на пустом volume. Изменения схемы — миграцией + `php src/migrate.php`. |
| SQL-идентификаторы | `Db::quote()` — для значений, `Db::ident()` — для имён. Не склеивать пользовательский ввод в обход `Db::bind()`. |
| SPA fallback | Любой не-`/api` путь отдаёт `index.html`; `/assets/*` при промахе отдаёт 404, а не SPA. |
| Ошибки WS не ретраятся на сервере | Клиент переподключается с backoff 1.5 с → 30 с и заново получает снапшот при `Open`. |
| Drag & drop при фильтрах | При активном фильтре/сортировке dnd выключен намеренно: иначе `/api/cards/order` сохранит неполный порядок. |
| Ширина статус-пилюли | Текст статуса — часть одной пилюли в `TopBar`, а не отдельный бейдж: три подписи разной ширины (126/147/153 px) двигали «Метки»/«Архив». Держать `min-width` и не добавлять второй элемент в ряд. |
| `wsState` — сырой статус | Показывать в UI только через `useStableStatus`: `connecting → open` при загрузке и `closed → connecting` при реконнекте мигают. Логика reconnect/backoff читает сырой `wsState`. |
| Прав нет | Любой авторизованный может удалить любую колонку/карточку/комментарий/метку. |
| `APP_SECRET` | По умолчанию `kanban-csrf-secret`; менять в продакшене, иначе CSRF-подпись предсказуема. |
| apt в Docker-сборке | В части окружений заблокирован HTTP (порт 80): sources.list принудительно переводится на `https://` перед `apt-get update`. Не убирать этот шаг. |
| Сборка расширения | Только на Linux; в рантайм-образ попадает лишь `.so`, поэтому сборочная стадия кэшируется отдельно от кода. |
| `SWOOLE_VARIANT` | Влияет и на сборку, и на рантайм. Без пересборки образа переменная ничего не меняет. |
| PowerShell и UTF-8 | Пайп `Get-Content \| docker compose exec -T … psql` перекодирует SQL в cp866 → кириллица становится `?`. Только `docker cp` + `psql -f`. То же для JSON в теле HTTP: слать `[System.Text.Encoding]::UTF8.GetBytes(...)`. |
| `SelectMenu` телепортирует панель | Панель рендерится в `<body>` и позиционируется `fixed`, поэтому её не режут `overflow` родителей. Стилизовать её из родителя можно только через `:deep(.select-panel)`; `.select-trigger` — через `:deep(.select-trigger)`. То же у `DatePicker` (`.datepicker-panel`, `.datepicker-trigger`). |
| `ConfirmDialog` и Esc | Диалог слушает `keydown` на `window`, как и `CardModal`. Поэтому `CardModal::onKeydown` выходит при `confirmState.open`, иначе один Esc закрыл бы и подтверждение, и карточку. При добавлении новых глобальных Esc-обработчиков проверять `confirmState.open`. |
| Фокус при открытии карточки | Фокусируется сама панель (`.panel`, `tabindex="-1"`), а не заголовок: иначе карточка открывалась в «режиме редактирования» с курсором в названии. На `body` фокус оставлять нельзя — Tab-ловушка и `keydown`-обработчик панели перестают получать события. Из-за `outline: none` кольца фокуса нет. |
| Хоткеи и открытая карточка | `BoardView::onKeydown` выходит при `activeCard.value`: иначе `/` и `N` срабатывают за оверлеем, как только фокус оказался на кнопке внутри панели. |
| `v-tooltip` — глобальная директива | Регистрируется в `main.js` (`app.directive('tooltip', tooltip)`); в изолированном стенде/тесте её нужно регистрировать отдельно, иначе Vue выдаст предупреждение «Failed to resolve directive». |
| CSP `script-src 'self'` | Инлайн-скрипты запрещены. Тема и любые pre-paint скрипты — отдельным файлом в `frontend/public/`. |
| e2e против старого образа | `docker compose up -d` без пересборки после правок фронтенда оставляет в контейнере прежний `dist`. Перед e2e — `docker compose build app`. |

## 12. Бэклог (чего не хватает)

Фазы 1–3 (стабильность/безопасность, продукт/UX, дизайн/тёмная тема) и фаза 4 по тестам
(юнит, e2e, CI, OpenAPI) — **сделаны**.

- **Мульти-доски и права:** `boards` CRUD, участники и роли, WS-комнаты по `board_id`,
  проверка прав на каждой мутации.
- **Совместная работа:** точечные WS-события вместо полного снапшота, оптимистичные обновления,
  уведомления, курсоры/«кто смотрит» в реальном времени на уровне UI.
- **Качество:** prepared statements, ESLint/Prettier для Vue, e2e на нескольких браузерах,
  нагрузочный тест broadcast.
- **Мелочи:** массовые операции с архивом, шаблоны карточек, вложения.

## 13. Проверка перед сдачей

```bash
# 1. статика и юнит-тесты (можно локально, расширение не нужно)
php vendor/bin/php-cs-fixer fix --dry-run --diff
php vendor/bin/phpstan analyse --no-progress
php vendor/bin/phpunit
cd frontend && npm test && npm run build && cd ..

# 2. стек
docker compose build
docker compose up -d
curl -fsS http://localhost:9501/api/health          # {"ok":true,"runtime":"OpenSwoole"}
docker compose logs -f app                          # нет фатальных ошибок

# 3. миграции (для существующего volume)
docker compose exec -T app php src/migrate.php

# 4. браузерный e2e
npx playwright test
```

Ручной сценарий (обязателен для UI/бэкенд-изменений):

1. Войти демо-пользователем, открыть доску в **двух вкладках**.
2. Создать колонку → переименовать → задать WIP-лимит и акцент → сдвинуть влево/вправо.
3. Создать карточку, перетащить в другую колонку и внутри колонки.
4. Открыть карточку: изменить заголовок, описание, исполнителя, срок (календарь: выбор дня,
   «Сегодня», «Без срока», стрелки/PageUp/PageDown/Esc), приоритет, оценку, обложку,
   повесить и создать метку, добавить/отметить/удалить пункт чек-листа, проверить историю,
   добавить и удалить комментарий (подтверждение).
5. Переименовать доску (двойной клик по названию), проверить, что имя уехало во вторую вкладку.
6. Проверить фильтры: поиск, метки, исполнитель, приоритет, срок, «Мои», сортировку;
   drag & drop при активном фильтре должен быть выключен. Перезагрузить страницу — вид сохранился.
7. Отправить карточку в архив через тост «Вернуть», затем через «Архив» восстановить и
   удалить другую навсегда.
8. Проверить `?` (шпаргалка), `Ctrl+K` (палитра), `N` (новая карточка).
9. Переключить тёмную тему, перезагрузить — тема применилась до первой отрисовки.
10. Убедиться, что вторая вкладка обновляется без перезагрузки.
11. Обновить страницу — состояние сохранилось (проверка БД, а не только стора).

Перед коммитом: `php -l` по изменённым PHP-файлам, `npm run build` во фронтенде (ловит
синтаксис/импорты, которых не видит dev-сервер) и `docker compose build app` (иначе e2e
проверит старый `dist`).
