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
    revision   BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------- columns ----------
CREATE TABLE IF NOT EXISTS columns (
    id         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    board_id   BIGINT NOT NULL REFERENCES board(id) ON DELETE CASCADE,
    title      TEXT NOT NULL,
    position   INT  NOT NULL DEFAULT 0,
    wip_limit  INT,
    accent     TEXT,
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
    assignee_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    due_date    DATE,
    priority    SMALLINT NOT NULL DEFAULT 0,
    cover_color TEXT,
    estimate    NUMERIC(6,2),
    archived_at TIMESTAMPTZ,
    deleted_at  TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT cards_priority_range CHECK (priority BETWEEN 0 AND 3)
);

CREATE INDEX IF NOT EXISTS idx_cards_column ON cards(column_id, position);
CREATE INDEX IF NOT EXISTS idx_cards_assignee ON cards(assignee_id);
CREATE INDEX IF NOT EXISTS idx_cards_archived ON cards(archived_at);

-- ---------- card_events (activity timeline) ----------
CREATE TABLE IF NOT EXISTS card_events (
    id         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    card_id    BIGINT NOT NULL REFERENCES cards(id) ON DELETE CASCADE,
    user_id    BIGINT REFERENCES users(id) ON DELETE SET NULL,
    kind       TEXT NOT NULL,
    payload    JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_card_events_card ON card_events(card_id, created_at DESC);

-- ---------- labels ----------
CREATE TABLE IF NOT EXISTS labels (
    id         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    board_id   BIGINT NOT NULL REFERENCES board(id) ON DELETE CASCADE,
    name       TEXT NOT NULL,
    color      TEXT NOT NULL DEFAULT 'violet',
    position   INT  NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_labels_board ON labels(board_id, position);

-- ---------- card_labels ----------
CREATE TABLE IF NOT EXISTS card_labels (
    card_id  BIGINT NOT NULL REFERENCES cards(id) ON DELETE CASCADE,
    label_id BIGINT NOT NULL REFERENCES labels(id) ON DELETE CASCADE,
    PRIMARY KEY (card_id, label_id)
);

CREATE INDEX IF NOT EXISTS idx_card_labels_label ON card_labels(label_id);

-- ---------- checklist_items ----------
CREATE TABLE IF NOT EXISTS checklist_items (
    id         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    card_id    BIGINT NOT NULL REFERENCES cards(id) ON DELETE CASCADE,
    text       TEXT NOT NULL,
    done       BOOLEAN NOT NULL DEFAULT false,
    position   INT  NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_checklist_card ON checklist_items(card_id, position);

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
SELECT 'Демо доска'
WHERE NOT EXISTS (SELECT 1 FROM board);

-- Default columns with a few starter cards (authored by the demo user).
-- Guarded so the file can be re-applied to an existing board without duplicates.
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
    WHERE NOT EXISTS (
        SELECT 1 FROM columns c WHERE c.board_id = b.id AND c.title = v.title
    )
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
CROSS JOIN (SELECT id FROM users WHERE email = 'demo@kanban.local') d
WHERE NOT EXISTS (SELECT 1 FROM cards existing WHERE existing.title = v.title);

-- Labels available on the board
INSERT INTO labels (board_id, name, color, position)
SELECT b.id, v.name, v.color, v.position
FROM (SELECT id FROM board ORDER BY id LIMIT 1) b
CROSS JOIN (VALUES
    ('Дизайн', 'violet', 1),
    ('Бэкенд', 'sky',    2),
    ('Баг',    'rose',   3),
    ('Срочно', 'amber',  4)
) AS v(name, color, position)
WHERE NOT EXISTS (
    SELECT 1 FROM labels l WHERE l.board_id = b.id AND l.name = v.name
);

-- Attach labels to a couple of demo cards
INSERT INTO card_labels (card_id, label_id)
SELECT c.id, l.id
FROM cards c
JOIN labels l ON l.name = 'Бэкенд'
WHERE c.title = 'Настроить проект в Docker'
ON CONFLICT DO NOTHING;

INSERT INTO card_labels (card_id, label_id)
SELECT c.id, l.id
FROM cards c
JOIN labels l ON l.name = 'Срочно'
WHERE c.title = 'WebSocket + board:sync'
ON CONFLICT DO NOTHING;

-- Assignee / due date / priority demo values
UPDATE cards
   SET assignee_id = (SELECT id FROM users WHERE email = 'demo@kanban.local'),
       due_date    = CURRENT_DATE + 3,
       priority    = 2
 WHERE title = 'WebSocket + board:sync';

UPDATE cards
   SET priority = 3
 WHERE title = 'Настроить проект в Docker';

-- Checklist demo items
INSERT INTO checklist_items (card_id, text, done, position)
SELECT c.id, v.text, v.done, v.position
FROM cards c
CROSS JOIN (VALUES
    ('Поднять postgres в docker-compose', true,  1),
    ('Собрать OpenSwoole с --with-postgres', false, 2),
    ('Проверить /api/health', false, 3)
) AS v(text, done, position)
WHERE c.title = 'Настроить проект в Docker'
  AND NOT EXISTS (
      SELECT 1 FROM checklist_items ci WHERE ci.card_id = c.id AND ci.text = v.text
  );

-- ---------- ordering indexes (mirrors migrations/002) ----------
CREATE INDEX IF NOT EXISTS idx_labels_board_position ON labels(board_id, position);
CREATE INDEX IF NOT EXISTS idx_cards_live_position ON cards(column_id, position)
    WHERE archived_at IS NULL AND deleted_at IS NULL;
