-- =============================================================
--  Migration 001 — card fields, labels, checklist
--
--  init.sql runs only on an empty data volume, so existing
--  installations must apply this file manually:
--
--    docker compose exec -T db psql -U "${POSTGRES_USER:-kanban}" \
--        -d "${POSTGRES_DB:-kanban}" < migrations/001_card_fields.sql
--
--  The script is idempotent and safe to run more than once.
-- =============================================================

BEGIN;

-- ---------- cards ----------
ALTER TABLE cards ADD COLUMN IF NOT EXISTS assignee_id BIGINT REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE cards ADD COLUMN IF NOT EXISTS due_date    DATE;
ALTER TABLE cards ADD COLUMN IF NOT EXISTS priority    SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE cards ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS idx_cards_assignee ON cards(assignee_id);
CREATE INDEX IF NOT EXISTS idx_cards_archived ON cards(archived_at);

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

-- ---------- default labels for an existing board ----------
INSERT INTO labels (board_id, name, color, position)
SELECT b.id, v.name, v.color, v.position
  FROM (SELECT id FROM board ORDER BY id LIMIT 1) b
 CROSS JOIN (VALUES
    ('Дизайн', 'violet', 1),
    ('Бэкенд', 'sky',    2),
    ('Баг',    'rose',   3),
    ('Срочно', 'amber',  4)
 ) AS v(name, color, position)
 WHERE NOT EXISTS (SELECT 1 FROM labels);

COMMIT;
