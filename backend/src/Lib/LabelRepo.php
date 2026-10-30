<?php

declare(strict_types=1);

namespace App\Lib;

final class LabelRepo
{
    /** Palette keys understood by the frontend (see styles/base.css). */
    public const COLORS = ['violet', 'sky', 'mint', 'amber', 'rose', 'slate'];

    public static function isColor(string $color): bool
    {
        return in_array($color, self::COLORS, true);
    }

    public static function create(string $name, string $color): int
    {
        $boardId = BoardRepo::boardId();
        return Db::transaction(static function ($conn) use ($name, $color, $boardId): int {
            $row = Db::connQueryOne(
                $conn,
                'SELECT COALESCE(MAX(position), 0) + 1 AS next FROM labels WHERE board_id = ?',
                [$boardId],
            );
            $created = Db::connQueryOne(
                $conn,
                'INSERT INTO labels (board_id, name, color, position) VALUES (?, ?, ?, ?) RETURNING id',
                [$boardId, $name, $color, (int)($row['next'] ?? 1)],
            );
            return (int)($created['id'] ?? 0);
        });
    }

    public static function update(int $id, ?string $name, ?string $color): void
    {
        $sets = [];
        $params = [];
        if ($name !== null) {
            $sets[] = 'name = ?';
            $params[] = $name;
        }
        if ($color !== null) {
            $sets[] = 'color = ?';
            $params[] = $color;
        }
        if ($sets === []) {
            return;
        }

        $boardId = BoardRepo::boardId();
        $row = Db::queryOne('SELECT id FROM labels WHERE id = ? AND board_id = ?', [$id, $boardId]);
        if ($row === null) {
            throw new HttpException(404, 'Метка не найдена');
        }
        $params[] = $id;
        Db::exec('UPDATE labels SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public static function delete(int $id): void
    {
        $boardId = BoardRepo::boardId();
        Db::transaction(static function ($conn) use ($id, $boardId): void {
            $row = Db::connQueryOne(
                $conn,
                'SELECT id, position FROM labels WHERE id = ? AND board_id = ?',
                [$id, $boardId],
            );
            if ($row === null) {
                throw new HttpException(404, 'Метка не найдена');
            }
            $position = (int)$row['position'];
            Db::connExec($conn, 'DELETE FROM labels WHERE id = ?', [$id]);
            // Keep the 1…N label order dense, like columns and cards.
            Db::connExec(
                $conn,
                'UPDATE labels SET position = position - 1 WHERE board_id = ? AND position > ?',
                [$boardId, $position],
            );
        });
    }

    /** @param array<int,int> $ids */
    public static function order(array $ids): void
    {
        $boardId = BoardRepo::boardId();
        Db::transaction(static function ($conn) use ($ids, $boardId): void {
            foreach (array_values($ids) as $index => $id) {
                Db::connExec(
                    $conn,
                    'UPDATE labels SET position = ? WHERE id = ? AND board_id = ?',
                    [$index + 1, (int)$id, $boardId],
                );
            }
        });
    }
}
