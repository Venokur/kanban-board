# Kanban Board

Trello-подобная канбан-доска в реальном времени. Одна общая доска, доступная после входа.

- **Frontend:** Vue 3 + Vite, без TypeScript. Pinia, Vue Router, `vuedraggable` (drag & drop карточек), собственный набор SVG-иконок, светлая и тёмная тема.
- **Backend:** PHP 8.3 + корутинный сервер (**OpenSwoole 26.2** по умолчанию, Swoole — одной переменной окружения). REST API + WebSocket + раздача собранного фронтенда одним процессом. Доступ к БД через корутинный клиент.
- **Database:** PostgreSQL 16, схема в `init.sql`, миграции в `migrations/`.
- **Тесты:** PHPUnit, Vitest, Playwright. **CI:** GitHub Actions.

## Демо-пользователь

| | |
|---|---|
| email | `demo@kanban.local` |
| пароль | `demo1234` |

На странице входа есть кнопка «Войти как demo».

## Запуск

Нужен Docker Desktop (Linux-контейнеры). Из корня проекта:

```bash
# производственный режим (собранный фронтенд раздаёт сервер на :9501)
docker compose up --build -d
# открыть http://localhost:9501

# режим разработки (Vite dev + HMR на :5173, проксирует /api и /ws в бэкенд)
docker compose -f docker-compose.yml -f docker-compose.dev.yml up --build -d
# открыть http://localhost:5173
```

Остановка (теми же файлами, какими запускали):

```bash
docker compose down                                   # производственный режим
docker compose -f docker-compose.yml -f docker-compose.dev.yml down   # режим разработки
```

Полный сброс данных (пересоздание схемы и сидов): та же команда + `-v`.

## Конфигурация

Все параметры в `.env` (пример — `.env.example`): креды PostgreSQL, порты `APP_PORT`, `DEV_PORT`.
При первом запуске `init.sql` автоматически создаёт таблицы и наполняет доску демо-данными
(выполняется один раз на пустом volume).

| Переменная | По умолчанию | Назначение |
|---|---|---|
| `POSTGRES_*` | `kanban` | база, пользователь, пароль, порт |
| `APP_PORT` | `9501` | порт приложения |
| `DEV_PORT` | `5173` | порт Vite в dev-режиме |
| `APP_SECRET` | `kanban-csrf-secret` | подпись CSRF-токена — **сменить в продакшене** |
| `APP_HTTPS` | `0` | `1` — ставить `Secure` на cookie сессии |
| `APP_LEGACY_TOKEN` | `0` | `1` — возвращать сырой токен из login/register (для curl/скриптов) |
| `APP_WS_QUERY_TOKEN` | `0` | `1` — принимать `/ws?token=…`, если cookie недоступна |
| `APP_DEBUG` | `0` | `1` — подробный лог исключений |
| `SWOOLE_VARIANT` | `openswoole` | `swoole` — собрать и запустить на Swoole вместо OpenSwoole |

### Переключение рантайма

Весь код обращается к серверу через алиасы `App\Rt\*` (`backend/src/rt.php`), поэтому смена форка —
это одна переменная и пересборка образа (расширение компилируется на этапе сборки):

```bash
SWOOLE_VARIANT=swoole docker compose up --build -d
SWOOLE_VARIANT=openswoole docker compose up --build -d   # назад, по умолчанию
```

### Миграции для существующей БД

`init.sql` отрабатывает только на пустом volume. Для уже созданной базы есть раннер — он
применяет недостающие файлы и ведёт историю в `schema_migrations`:

```bash
docker compose exec -T app php src/migrate.php
```

Команда идемпотентна: повторный запуск ничего не сделает и перечислит уже применённые миграции.

```bash
# Linux / macOS / Git Bash — прямой psql (файлы тоже идемпотентны)
docker compose exec -T db psql -U kanban -d kanban < migrations/001_card_fields.sql
```

> **Windows PowerShell:** редирект `<` не работает, а пайп ломает UTF-8 (кириллица → `?`).
> Копировать файл в контейнер и выполнять через `-f`:
>
> ```powershell
> docker cp migrations/001_card_fields.sql kanban-board-db-1:/tmp/001.sql
> docker compose exec -T db psql -U kanban -d kanban -v ON_ERROR_STOP=1 -f /tmp/001.sql
> ```

| Файл | Что делает |
|---|---|
| `migrations/001_card_fields.sql` | поля карточки (`assignee_id`, `due_date`, `priority`, `archived_at`), таблицы `labels`, `card_labels`, `checklist_items`, метки по умолчанию |
| `migrations/002_stability.sql` | `board.revision`, `card_events`, `columns.wip_limit`/`accent`, `cards.cover_color`/`estimate` |
| `migrations/003_dense_positions.sql` | перенумерация `position` от 1 без дыр (колонки, карточки, метки, чек-лист) |

## Функциональность

- Регистрация / вход / выход. Сессия — HttpOnly cookie (`kb_session`), мутации защищены
  double-submit CSRF (`kb_csrf` + `X-CSRF-Token`), вход ограничен по частоте попыток.
- Колонки: создание, переименование (двойной клик или карандаш), удаление, перемещение
  влево/вправо, WIP-лимит и цветной акцент.
- Карточки: создание, drag & drop между колонками и внутри, редактирование названия и описания,
  удаление, архив с восстановлением.
- Свойства карточки: исполнитель, срок, приоритет (нет / низкий / средний / высокий), оценка
  в часах, цветная полоса-обложка, цветные метки с собственным набором на доске, чек-лист
  с прогрессом, история изменений, комментарии.
- Навигация: поиск по заголовку и описанию, фильтры по меткам, исполнителю, приоритету, сроку
  и «только мои», сортировка внутри колонки по сроку или приоритету, переименование доски.
  Фильтры и сортировка хранятся в query-строке — вид переживает перезагрузку и шарится ссылкой.
- Клавиатура и палитра: `Ctrl+K` — командная палитра (действия, поиск карточек, создание),
  `N` — новая карточка, `?` — шпаргалка по горячим клавишам, `/` — фокус в поиск.
- Тёмная тема: переключатель в шапке, выбор запоминается и применяется до первой отрисовки
  (внешний `theme-boot.js` — CSP запрещает инлайн-скрипты).
- Тосты вместо модальных ошибок: архивация показывает «Карточка в архиве» с кнопкой «Вернуть».
- Реальное время: после любого изменения сервер рассылает подключённым клиентам полный снимок
  доски (`board:sync`) — никаких клиентских merge-конфликтов. Снапшот несёт монотонную
  `revision`, поэтому устаревший ответ не перетирает свежий. В шапке видно, кто ещё на доске.
- Собственные компоненты вместо браузерных виджетов: `SelectMenu` (выпадающие списки),
  `DatePicker` (календарь), `ConfirmDialog` (подтверждения), `ToastHost` (уведомления),
  `v-tooltip` (подсказки), своя кнопка показа пароля и очистки поиска.

Пока активен фильтр или нестандартная сортировка, drag & drop отключён — иначе сохранённый порядок
разъехался бы с тем, что видно на экране.

## Тесты

```bash
# PHP: юнит-тесты (71 тест; расширение OpenSwoole не требуется — используется стаб)
php vendor/bin/phpunit

# PHP: стиль и статический анализ
php vendor/bin/php-cs-fixer fix --dry-run --diff
php vendor/bin/phpstan analyse --no-progress

# Frontend: юнит-тесты утилит (time, urlState, labels)
cd frontend && npm test && npm run build

# Браузерный e2e (нужен запущенный стек)
npx playwright test

# Постман-коллекция (нужен запущенный стек и npx)
npx newman run docs/postman/kanban.postman_collection.json

# Ручной сценарий §13 из AGENTS.md, автоматизированный (две вкладки, realtime)
node scripts/manual-scenario.mjs
```

E2E-спеки создают и удаляют свои колонки через API, поэтому прогон идемпотентен и не требует
пустой доски. CI (`.github/workflows/ci.yml`) гоняет всё перечисленное плюс сборку образов и
smoke-тест API с проверкой CSRF.

## Структура

```
init.sql                    # схема БД + сид (пользователь, колонки, карточки, метки, чек-лист)
migrations/                 # идемпотентные миграции схемы для существующих БД
docker-compose.yml          # db + app
docker-compose.dev.yml      # + frontend (Vite dev)
docs/openapi.yaml           # OpenAPI 3.1 — контракт API
docs/postman/               # Postman-коллекция (генерируется scripts/gen-postman-collection.mjs)
scripts/manual-scenario.mjs # автоматический прогон ручного сценария §13 в двух вкладках
e2e/                        # Playwright: auth, board, filters, keyboard, appearance
backend/
  Dockerfile                # php:8.3 + расширение, собранное с --with-postgres
  stubs/rt.stub.php         # заглушки App\Rt\* для PHPStan и юнит-тестов
  tests/                    # PHPUnit
  src/
    rt.php                  # выбор рантайма и алиасы App\Rt\*
    server.php              # HTTP/WebSocket-сервер
    migrate.php             # раннер миграций
    routes.php
    Lib/  Config, Runtime, Db (корутинный пул), Auth (сессии+CSRF), RateLimit,
          Router, BoardRepo, LabelRepo, ChecklistRepo, EventRepo, Revision, Hub
    Controllers/ AuthController, BoardController
frontend/
  public/theme-boot.js      # тема до первой отрисовки
  tests/                    # Vitest
  src/
    api/     http.js, ws.js (reconnect + heartbeat)
    stores/  auth.js, board.js (снапшот, фильтры, сортировка, архив), theme.js, ui.js
    router/  guard на авторизацию
    views/   LoginView, BoardView
    components/ SvgIcon, Avatar, SelectMenu, DatePicker, ConfirmDialog, ToastHost,
                board/{TopBar,BoardFilters,Column,CardItem,CardModal,ArchivePanel,
                       LabelManager,CommandPalette,ShortcutsDialog,AddCard,AddColumn}
    composables/ useConfirm.js — подтверждения через Promise, useToast.js — тосты
    directives/  tooltip.js — v-tooltip вместо title
    utils/   time.js, labels.js, urlState.js
    icons/   встроенные SVG
```

## API

Полный контракт с телами, кодами ответов и схемами — **[`docs/openapi.yaml`](docs/openapi.yaml)**.

| Метод | Путь | Описание |
|---|---|---|
| POST | `/api/register`, `/api/login`, `/api/logout`, `GET /api/me` | авторизация |
| GET | `/api/health` | проверка живости (`{ok, runtime}`) |
| GET | `/api/board` | полный снимок доски (доска, метки, пользователи, колонки с карточками, presence) |
| PUT | `/api/board` | переименовать доску |
| GET | `/api/board/archive` | список архивных карточек |
| POST | `/api/columns` | создать колонку |
| PUT/DELETE | `/api/columns/{id}` | изменить (название, WIP-лимит, акцент) / удалить |
| POST | `/api/columns/order` | порядок колонок |
| POST | `/api/cards` | создать карточку |
| PUT/DELETE | `/api/cards/{id}` | изменить / удалить |
| POST | `/api/cards/order` | порядок и колонки карточек (drag & drop) |
| POST | `/api/cards/{id}/archive`, `/api/cards/{id}/restore` | в архив / из архива |
| GET | `/api/cards/{id}/events` | история изменений карточки |
| POST | `/api/cards/{cardId}/checklist`, `/api/cards/{cardId}/checklist/order` | пункт чек-листа |
| PUT/DELETE | `/api/checklist/{id}` | изменить / удалить пункт |
| POST/PUT/DELETE | `/api/labels`, `/api/labels/{id}` | метки доски |
| POST | `/api/labels/order` | порядок меток |
| GET/POST | `/api/cards/{cardId}/comments` | комментарии карточки |
| DELETE | `/api/comments/{id}` | удалить комментарий |

`PUT /api/cards/{id}` принимает любое подмножество полей: `title`, `description`,
`assigneeId`, `dueDate` (`YYYY-MM-DD` или `null`), `priority` (0…3), `labelIds`,
`coverColor`, `estimate`.

Авторизация — cookie `kb_session` (HttpOnly) или заголовок `X-Auth-Token`. Мутации,
авторизованные cookie, обязаны прислать `X-CSRF-Token` со значением cookie `kb_csrf`
(иначе `403`). Мутирующие запросы возвращают свежий снимок доски и рассылают его всем
по WebSocket.

### WebSocket

`/ws` — авторизация по cookie из handshake (браузер отправляет её сам), при ошибке соединение
закрывается кодом `1008`. Сообщения: `ping` → `pong`, `board:get` → `board:sync`,
`presence:watch` (какую карточку смотрит клиент); сервер шлёт `board:sync` при подключении и
после мутаций (не чаще одного снапшота за 60 мс) и `presence:sync` при изменении состава.

## Заметки

- Корутинный сервер работает только в Linux, поэтому приложение всегда запускается в контейнере.
- Расширение собирается из исходников один раз и кэшируется Docker'ом (отдельная стадия), в рантайм-образ попадает только `.so`.
- В корпоративном деплое перед приложением можно поставить Nginx; сейчас всё раздаёт один процесс на `9501`.
- **Один воркер намеренно:** реестр WS-подключений и пул БД живут в памяти процесса. Масштабирование требует общего хранилища для реестра и `sendMessage()` между воркерами.
- В окружениях, где заблокирован исходящий HTTP (порт 80), Dockerfile переводит apt-источники на HTTPS перед установкой пакетов.
