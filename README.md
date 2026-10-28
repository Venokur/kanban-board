# Kanban Board

Trello-подобная канбан-доска в реальном времени. Одна общая доска, доступная после входа.

- **Frontend:** Vue 3 + Vite, без TypeScript. Pinia, Vue Router, `vuedraggable` (drag & drop карточек), собственный набор SVG-иконок.
- **Backend:** PHP 8.2 + **Swoole** (WebSocket-сервер, он же HTTP). REST API + WebSocket + раздача собранного фронтенда одним процессом. Доступ к БД через корутинный клиент `Swoole\Coroutine\PostgreSQL`.
- **Database:** PostgreSQL 16, схема в `init.sql`.

## Демо-пользователь

| | |
|---|---|
| email | `demo@kanban.local` |
| пароль | `demo1234` |

На странице входа есть кнопка «Войти как demo».

## Запуск

Нужен Docker Desktop (Linux-контейнеры). Из корня проекта:

```bash
# производственный режим (собранный фронтенд раздаёт Swoole на :9501)
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
При первом запуске `init.sql` автоматически создаёт таблицы и наполняет доску демо-данными (выполняется один раз на пустом volume).

## Функциональность

- Регистрация / вход / выход (сессии в БД, HttpOnly-cookie; токен дополнительно отдаётся в теле ответа для WebSocket).
- Колонки: создание, переименование (двойной клик или карандаш), удаление, перемещение влево/вправо.
- Карточки: создание, drag & drop между колонками и внутри, редактирование названия и описания, удаление.
- Комментарии к карточкам (Enter — отправить, Shift+Enter — новая строка).
- Реальное время: после любого изменения сервер рассылает подключённым клиентам полный снимок доски (`board:sync`) — никаких клиентских merge-конфликтов.
- SVG-иконки (`src/components/SvgIcon.vue` + `src/icons`), современный интерфейс в стиле Trello.

## Структура

```
init.sql                    # схема БД + сид (пользователь, колонки, карточки)
docker-compose.yml          # db + app
docker-compose.dev.yml      # + frontend (Vite dev)
backend/
  Dockerfile                # php:8.2 + swoole v5.1.4 собранный с --enable-swoole-pgsql
  src/
    server.php              # Swoole WebSocket/HTTP-сервер
    routes.php
    Lib/  Config, Db (корутинный пул), Auth, Router, BoardRepo, Hub (broadcast)
    Controllers/ AuthController, BoardController
frontend/
  src/
    api/     http.js, ws.js (reconnect + heartbeat)
    stores/  auth.js, board.js
    router/  guard на авторизацию
    views/   LoginView, BoardView
    components/ SvgIcon, Avatar, board/{TopBar,Column,CardItem,AddCard,AddColumn,CardModal}
    icons/   встроенные SVG
```

## API (основное)

| Метод | Путь | Описание |
|---|---|---|
| POST | `/api/register`, `/api/login`, `/api/logout`, `GET /api/me` | авторизация |
| GET | `/api/board` | полный снимок доски |
| POST | `/api/columns` | создать колонку |
| PUT/DELETE | `/api/columns/{id}` | переименовать / удалить |
| POST | `/api/columns/order` | порядок колонок |
| POST | `/api/cards` | создать карточку |
| PUT/DELETE | `/api/cards/{id}` | изменить / удалить |
| POST | `/api/cards/order` | порядок и колонки карточек (drag&drop) |
| POST | `/api/cards/{cardId}/comments` | комментарий |
| DELETE | `/api/comments/{id}` | удалить комментарий |

Мутирующие запросы возвращают свежий снимок доски и рассылают его всем по WebSocket.

## Заметки

- Swoole работает только в Linux, поэтому приложение всегда запускается в контейнере.
- Сборка Swoole из исходников выполняется один раз и кэшируется Docker'ом (слой `php`).
- В корпоративном деплое перед Swoole можно поставить Nginx; сейчас всё раздаёт один процесс на `9501`.
