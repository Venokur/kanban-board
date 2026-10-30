-- =============================================================
--  Migration 002 — stability
--
--  * board.revision — monotonic counter so clients can drop stale
--    snapshots that arrive out of order (HTTP response vs WS broadcast).
--  * card_events — activity timeline for a card.
--  * card fields/columns used by the product phase (kept in one place so
--    the schema is never ahead of the code).
--  * position uniqueness per scope, priority range check.
--
--  Idempotent: safe to run more than once.
-- =============================================================

BEGIN;

-- ---------- board ----------
ALTER TABLE board ADD COLUMN IF NOT EXISTS revision BIGINT NOT NULL DEFAULT 0;

-- ---------- columns ----------
ALTER TABLE columns ADD COLUMN IF NOT EXISTS wip_limit INT;
ALTER TABLE columns ADD COLUMN IF NOT EXISTS accent    TEXT;

-- ---------- cards ----------
ALTER TABLE cards ADD COLUMN IF NOT EXISTS cover_color TEXT;
ALTER TABLE cards ADD COLUMN IF NOT EXISTS estimate    NUMERIC(6,2);
ALTER TABLE cards ADD COLUMN IF NOT EXISTS deleted_at  TIMESTAMPTZ;

ALTER TABLE cards DROP CONSTRAINT IF EXISTS cards_priority_range;
ALTER TABLE cards ADD CONSTRAINT cards_priority_range CHECK (priority BETWEEN 0 AND 3);

-- ---------- card_events ----------
CREATE TABLE IF NOT EXISTS card_events (
    id         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    card_id    BIGINT NOT NULL REFERENCES cards(id) ON DELETE CASCADE,
    user_id    BIGINT REFERENCES users(id) ON DELETE SET NULL,
    kind       TEXT NOT NULL,
    payload    JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_card_events_card ON card_events(card_id, created_at DESC);

-- ---------- ordering indexes ----------
-- Plain (non-unique) indexes: reordering rewrites positions row by row, so a
-- UNIQUE constraint on (column_id, position) would fire mid-update. The repos
-- use a two-phase renumber (temporary offset, then final values) instead.
CREATE INDEX IF NOT EXISTS idx_columns_board_position ON columns(board_id, position);
CREATE INDEX IF NOT EXISTS idx_labels_board_position  ON labels(board_id, position);
CREATE INDEX IF NOT EXISTS idx_cards_live_position    ON cards(column_id, position)
    WHERE archived_at IS NULL AND deleted_at IS NULL;

COMMIT;
