-- =============================================================
--  Kanban board — PostgreSQL schema + seed
--  Runs only once, on an empty data volume.
-- =============================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- ---------- users ----------
CREATE TABLE IF NOT EXISTS users (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email         TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    name          TEXT NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------- sessions ----------
CREATE TABLE IF NOT EXISTS sessions (
    token      TEXT PRIMARY KEY,
    user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_sessions_user  ON sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);

-- ---------- board ----------
CREATE TABLE IF NOT EXISTS board (
    id         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name       TEXT NOT NULL DEFAULT 'Демо доска',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------- columns ----------
CREATE TABLE IF NOT EXISTS columns (
    id         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    board_id   BIGINT NOT NULL REFERENCES board(id) ON DELETE CASCADE,
    title      TEXT NOT NULL,
    position   INT  NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_columns_board ON columns(board_id, position);

-- ---------- cards ----------
CREATE TABLE IF NOT EXISTS cards (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    column_id   BIGINT NOT NULL REFERENCES columns(id) ON DELETE CASCADE,
    title       TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    position    INT  NOT NULL DEFAULT 0,
    author_id   BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_cards_column ON cards(column_id, position);

-- ---------- comments ----------
CREATE TABLE IF NOT EXISTS comments (
    id         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    card_id    BIGINT NOT NULL REFERENCES cards(id) ON DELETE CASCADE,
    author_id  BIGINT REFERENCES users(id) ON DELETE SET NULL,
    body       TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_comments_card ON comments(card_id, created_at);

-- =============================================================
--  Seed data
-- =============================================================

-- Demo user: demo@kanban.local / demo1234
INSERT INTO users (email, password_hash, name)
VALUES ('demo@kanban.local', crypt('demo1234', gen_salt('bf', 10)), 'Demo User')
ON CONFLICT (email) DO NOTHING;

-- Single shared board
INSERT INTO board (name)
VALUES ('Демо доска');

-- Default columns with a few starter cards (authored by the demo user)
WITH b AS (
    SELECT id FROM board ORDER BY id LIMIT 1
), cols AS (
    INSERT INTO columns (board_id, title, position)
    SELECT b.id, v.title, v.position
    FROM b
    CROSS JOIN (VALUES
        ('Нужно сделать', 1),
        ('В работе',      2),
        ('Готово',        3)
    ) AS v(title, position)
    RETURNING id, title
)
INSERT INTO cards (column_id, title, description, position, author_id)
SELECT
    c.id,
    v.title,
    v.description,
    v.position,
    d.id
FROM (VALUES
    ('Настроить проект в Docker', 'docker-compose + init.sql + сборка swoole с поддержкой postgres.', 'Нужно сделать', 1),
    ('Сверстать страницу входа', 'Форма логина в стиле Trello, SVG-иконки.',                        'Нужно сделать', 2),
    ('Drag & drop карточек', 'Перемещение карточек между колонками через vuedraggable.',           'В работе',      1),
    ('WebSocket + board:sync', 'Рассылка снимка доски всем подключённым клиентам.',                 'В работе',      2),
    ('Схема базы данных', 'users, sessions, board, columns, cards, comments.',                      'Готово',        1)
) AS v(title, description, col_title, position)
JOIN cols c ON c.title = v.col_title
CROSS JOIN (SELECT id FROM users WHERE email = 'demo@kanban.local') d;
