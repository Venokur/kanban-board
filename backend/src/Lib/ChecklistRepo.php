<?php

declare(strict_types=1);

namespace App\Lib;

final class ChecklistRepo
{
    public static function add(int $cardId, string $text): void
    {
        $boardId = BoardRepo::boardId();
        Db::transaction(static function ($conn) use ($cardId, $text, $boardId): void {
            $card = Db::connQueryOne(
                $conn,
                'SELECT c.id FROM cards c
                   JOIN columns col ON col.id = c.column_id
                  WHERE c.id = ? AND col.board_id = ?',
                [$cardId, $boardId],
            );
            if ($card === null) {
                throw new HttpException(404, 'Карточка не найдена');
            }
            $row = Db::connQueryOne(
                $conn,
                'SELECT COALESCE(MAX(position), 0) + 1 AS next FROM checklist_items WHERE card_id = ?',
                [$cardId],
            );
            Db::connExec(
                $conn,
                'INSERT INTO checklist_items (card_id, text, done, position) VALUES (?, ?, false, ?)',
                [$cardId, $text, (int)($row['next'] ?? 1)],
            );
            Db::connExec($conn, 'UPDATE cards SET updated_at = now() WHERE id = ?', [$cardId]);
        });
    }

    public static function update(int $id, ?string $text, ?bool $done): void
    {
        $sets = [];
        $params = [];
        if ($text !== null) {
            $sets[] = 'text = ?';
            $params[] = $text;
        }
        if ($done !== null) {
            $sets[] = 'done = ?';
            $params[] = $done;
        }
        if ($sets === []) {
            return;
        }

        $boardId = BoardRepo::boardId();
        Db::transaction(static function ($conn) use ($id, $boardId, $sets, $params): void {
            $item = Db::connQueryOne(
                $conn,
                'SELECT ci.id, ci.card_id FROM checklist_items ci
                   JOIN cards c ON c.id = ci.card_id
                   JOIN columns col ON col.id = c.column_id
                  WHERE ci.id = ? AND col.board_id = ?',
                [$id, $boardId],
            );
            if ($item === null) {
                throw new HttpException(404, 'Пункт чек-листа не найден');
            }
            $queryParams = array_merge($params, [$id]);
            Db::connExec($conn, 'UPDATE checklist_items SET ' . implode(', ', $sets) . ' WHERE id = ?', $queryParams);
            Db::connExec($conn, 'UPDATE cards SET updated_at = now() WHERE id = ?', [(int)$item['card_id']]);
        });
    }

    public static function delete(int $id): void
    {
        $boardId = BoardRepo::boardId();
        Db::transaction(static function ($conn) use ($id, $boardId): void {
            $item = Db::connQueryOne(
                $conn,
                'SELECT ci.id, ci.card_id, ci.position FROM checklist_items ci
                   JOIN cards c ON c.id = ci.card_id
                   JOIN columns col ON col.id = c.column_id
                  WHERE ci.id = ? AND col.board_id = ?',
                [$id, $boardId],
            );
            if ($item === null) {
                throw new HttpException(404, 'Пункт чек-листа не найден');
            }
            Db::connExec($conn, 'DELETE FROM checklist_items WHERE id = ?', [$id]);
            // Keep positions dense within the card.
            Db::connExec(
                $conn,
                'UPDATE checklist_items SET position = position - 1
                  WHERE card_id = ? AND position > ?',
                [(int)$item['card_id'], (int)$item['position']],
            );
        });
    }

    /**
     * Persist the order of a card's checklist.
     *
     * @param array<int,int> $ids
     */
    public static function order(int $cardId, array $ids): void
    {
        $boardId = BoardRepo::boardId();
        Db::transaction(static function ($conn) use ($cardId, $ids, $boardId): void {
            $card = Db::connQueryOne(
                $conn,
                'SELECT c.id FROM cards c
                   JOIN columns col ON col.id = c.column_id
                  WHERE c.id = ? AND col.board_id = ?',
                [$cardId, $boardId],
            );
            if ($card === null) {
                throw new HttpException(404, 'Карточка не найдена');
            }
            foreach (array_values($ids) as $index => $id) {
                Db::connExec(
                    $conn,
                    'UPDATE checklist_items SET position = ? WHERE id = ? AND card_id = ?',
                    [$index + 1, (int)$id, $cardId],
                );
            }
            Db::connExec($conn, 'UPDATE cards SET updated_at = now() WHERE id = ?', [$cardId]);
        });
    }
}
