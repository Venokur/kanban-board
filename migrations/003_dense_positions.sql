-- =============================================================
--  Migration 003 — dense positions
--
--  Positions are supposed to be a dense 1…N sequence inside their
--  scope (columns per board, cards per column, labels per board and
--  checklist items per card). Older builds removed a row without
--  shifting its successors, which left permanent gaps: the board
--  could start at position 2 and new rows were appended past N.
--
--  This migration renumbers every scope from scratch. It is
--  idempotent: running it on an already dense database is a no-op.
-- =============================================================

BEGIN;

-- ---------- columns ----------
WITH ranked AS (
    SELECT id, ROW_NUMBER() OVER (PARTITION BY board_id ORDER BY position, id) AS rn
      FROM columns
)
UPDATE columns AS c
   SET position = ranked.rn
  FROM ranked
 WHERE c.id = ranked.id
   AND c.position IS DISTINCT FROM ranked.rn;

-- ---------- cards (live rows only) ----------
WITH ranked AS (
    SELECT id, ROW_NUMBER() OVER (PARTITION BY column_id ORDER BY position, id) AS rn
      FROM cards
     WHERE archived_at IS NULL
)
UPDATE cards AS c
   SET position = ranked.rn
  FROM ranked
 WHERE c.id = ranked.id
   AND c.position IS DISTINCT FROM ranked.rn;

-- ---------- labels ----------
WITH ranked AS (
    SELECT id, ROW_NUMBER() OVER (PARTITION BY board_id ORDER BY position, id) AS rn
      FROM labels
)
UPDATE labels AS l
   SET position = ranked.rn
  FROM ranked
 WHERE l.id = ranked.id
   AND l.position IS DISTINCT FROM ranked.rn;

-- ---------- checklist items ----------
WITH ranked AS (
    SELECT id, ROW_NUMBER() OVER (PARTITION BY card_id ORDER BY position, id) AS rn
      FROM checklist_items
)
UPDATE checklist_items AS i
   SET position = ranked.rn
  FROM ranked
 WHERE i.id = ranked.id
   AND i.position IS DISTINCT FROM ranked.rn;

COMMIT;
