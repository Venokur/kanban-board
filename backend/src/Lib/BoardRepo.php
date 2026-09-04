<?php

declare(strict_types=1);

namespace App\Lib;

final class BoardRepo
{
    public static function boardId(): int
    {
        $row = Db::queryOne('SELECT id FROM board ORDER BY id LIMIT 1');
        if ($row === null) {
            throw new HttpException(500, 'Доска не инициализирована');
        }
        return (int)$row['id'];
    }

    /**
     * Full board snapshot: columns with nested cards and comments.
     * @return array<string,mixed>
     */
    public static function snapshot(): array
    {
        $boardId = self::boardId();
        $board = Db::queryOne('SELECT id, name FROM board WHERE id = ' . $boardId);

        $columnRows = Db::query(
            'SELECT id, title, position FROM columns
              WHERE board_id = ' . $boardId . '
              ORDER BY position, id'
        );

        $cardRows = Db::query(
            'SELECT c.id, c.column_id, c.title, c.description, c.position,
                    c.author_id,
                    u.name AS author_name,
                    round(extract(epoch FROM c.created_at)::numeric * 1000)::bigint AS created_at_ms,
                    round(extract(epoch FROM c.updated_at)::numeric * 1000)::bigint AS updated_at_ms
               FROM cards c
               JOIN columns col ON col.id = c.column_id
          LEFT JOIN users u ON u.id = c.author_id
              WHERE col.board_id = ' . $boardId . '
              ORDER BY col.position, c.position, c.id'
        );

        $commentRows = Db::query(
            'SELECT cm.id, cm.card_id, cm.body,
                    cm.author_id,
                    u.name AS author_name,
                    round(extract(epoch FROM cm.created_at)::numeric * 1000)::bigint AS created_at_ms
               FROM comments cm
               JOIN cards c ON c.id = cm.card_id
               JOIN columns col ON col.id = c.column_id
          LEFT JOIN users u ON u.id = cm.author_id
              WHERE col.board_id = ' . $boardId . '
              ORDER BY cm.created_at, cm.id'
        );

        // Group comments by card
        $commentsByCard = [];
        foreach ($commentRows as $row) {
            $commentsByCard[(int)$row['card_id']][] = self::commentShape($row);
        }

        // Group cards by column
        $cardsByColumn = [];
        foreach ($cardRows as $row) {
            $cardsByColumn[(int)$row['column_id']][] = self::cardShape($row, $commentsByCard);
        }

        $columns = [];
        foreach ($columnRows as $row) {
            $id = (int)$row['id'];
            $columns[] = [
                'id' => $id,
                'title' => (string)$row['title'],
                'position' => (int)$row['position'],
                'cards' => $cardsByColumn[$id] ?? [],
            ];
        }

        return [
            'board' => [
                'id' => (int)$board['id'],
                'name' => (string)$board['name'],
            ],
            'columns' => $columns,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cardShape(array $row, array $commentsByCard): array
    {
        $id = (int)$row['id'];
        return [
            'id' => $id,
            'title' => (string)$row['title'],
            'description' => (string)($row['description'] ?? ''),
            'position' => (int)$row['position'],
            'author' => self::authorShape($row['author_id'] ?? null, $row['author_name'] ?? null),
            'createdAt' => isset($row['created_at_ms']) ? (int)$row['created_at_ms'] : null,
            'updatedAt' => isset($row['updated_at_ms']) ? (int)$row['updated_at_ms'] : null,
            'comments' => $commentsByCard[$id] ?? [],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function commentShape(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'body' => (string)$row['body'],
            'author' => self::authorShape($row['author_id'] ?? null, $row['author_name'] ?? null),
            'createdAt' => isset($row['created_at_ms']) ? (int)$row['created_at_ms'] : null,
        ];
    }

    /**
     * @return array{id:int,name:string}|null
     */
    private static function authorShape(mixed $id, mixed $name): ?array
    {
        if ($id === null) {
            return null;
        }
        return [
            'id' => (int)$id,
            'name' => (string)$name,
        ];
    }

    public static function addColumn(string $title): int
    {
        $boardId = self::boardId();
        Db::transaction(static function ($conn) use ($title, $boardId): void {
            $row = Db::connQueryOne(
                $conn,
                'SELECT COALESCE(MAX(position), 0) + 1 AS next
                   FROM columns WHERE board_id = ' . $boardId
            );
            $pos = (int)($row['next'] ?? 1);
            Db::connExec(
                $conn,
                'INSERT INTO columns (board_id, title, position)
                 VALUES (' . $boardId . ', ' . Db::quote($title) . ', ' . $pos . ')'
            );
        });
        return 0;
    }

    public static function renameColumn(int $id, string $title): void
    {
        $boardId = self::boardId();
        Db::transaction(static function ($conn) use ($id, $title, $boardId): void {
            $exists = Db::queryOne(
                'SELECT id FROM columns WHERE id = ' . $id . ' AND board_id = ' . $boardId
            );
            if ($exists === null) {
                throw new HttpException(404, 'Колонка не найдена');
            }
            Db::connExec(
                $conn,
                'UPDATE columns SET title = ' . Db::quote($title) . ' WHERE id = ' . $id
            );
        });
    }

    public static function deleteColumn(int $id): void
    {
        $boardId = self::boardId();
        Db::transaction(static function ($conn) use ($id, $boardId): void {
            $exists = Db::queryOne(
                'SELECT id FROM columns WHERE id = ' . $id . ' AND board_id = ' . $boardId
            );
            if ($exists === null) {
                throw new HttpException(404, 'Колонка не найдена');
            }
            Db::connExec($conn, 'DELETE FROM columns WHERE id = ' . $id);
        });
    }

    /** @param array<int> $ids */
    public static function orderColumns(array $ids): void
    {
        $boardId = self::boardId();
        Db::transaction(static function ($conn) use ($ids, $boardId): void {
            foreach (array_values($ids) as $index => $id) {
                Db::connExec(
                    $conn,
                    'UPDATE columns SET position = ' . ($index + 1) . '
                      WHERE id = ' . (int)$id . ' AND board_id = ' . $boardId
                );
            }
        });
    }

    public static function addCard(int $columnId, string $title, string $description, int $authorId): void
    {
        $boardId = self::boardId();
        Db::transaction(static function ($conn) use ($columnId, $title, $description, $authorId, $boardId): void {
            $column = Db::connQueryOne(
                $conn,
                'SELECT id FROM columns WHERE id = ' . $columnId . ' AND board_id = ' . $boardId
            );
            if ($column === null) {
                throw new HttpException(404, 'Колонка не найдена');
            }
            $row = Db::connQueryOne(
                $conn,
                'SELECT COALESCE(MAX(position), 0) + 1 AS next FROM cards WHERE column_id = ' . $columnId
            );
            $pos = (int)($row['next'] ?? 1);
            Db::connExec(
                $conn,
                'INSERT INTO cards (column_id, title, description, position, author_id)
                 VALUES (' . $columnId . ', ' . Db::quote($title) . ', ' . Db::quote($description)
                . ', ' . $pos . ', ' . $authorId . ')'
            );
        });
    }

    public static function updateCard(int $id, ?string $title, ?string $description): void
    {
        $boardId = self::boardId();
        $sets = [];
        if ($title !== null) {
            $sets[] = 'title = ' . Db::quote($title);
        }
        if ($description !== null) {
            $sets[] = 'description = ' . Db::quote($description);
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at = now()';
        Db::transaction(static function ($conn) use ($id, $boardId, $sets): void {
            Db::connExec(
                $conn,
                'UPDATE cards SET ' . implode(', ', $sets) . '
                  WHERE id = ' . $id . '
                    AND EXISTS (SELECT 1 FROM columns col
                                 WHERE col.id = cards.column_id AND col.board_id = ' . $boardId . ')'
            );
        });
    }

    public static function deleteCard(int $id): void
    {
        $boardId = self::boardId();
        Db::transaction(static function ($conn) use ($id, $boardId): void {
            $exists = Db::queryOne(
                'SELECT c.id FROM cards c
                   JOIN columns col ON col.id = c.column_id
                  WHERE c.id = ' . $id . ' AND col.board_id = ' . $boardId
            );
            if ($exists === null) {
                throw new HttpException(404, 'Карточка не найдена');
            }
            Db::connExec($conn, 'DELETE FROM cards WHERE id = ' . $id);
        });
    }

    /**
     * Persist full card ordering (after drag & drop).
     * @param array<int,array{id:int,cards:array<int>}> $columns
     */
    public static function orderCards(array $columns): void
    {
        $boardId = self::boardId();
        Db::transaction(static function ($conn) use ($columns, $boardId): void {
            foreach ($columns as $item) {
                $columnId = (int)($item['id'] ?? 0);
                $column = Db::queryOne(
                    'SELECT id FROM columns WHERE id = ' . $columnId . ' AND board_id = ' . $boardId
                );
                if ($column === null) {
                    throw new HttpException(404, 'Колонка не найдена');
                }
                $cardIds = array_values(array_map('intval', $item['cards'] ?? []));
                foreach ($cardIds as $index => $cardId) {
                    Db::connExec(
                        $conn,
                        'UPDATE cards
                            SET position = ' . ($index + 1) . ', column_id = ' . $columnId . ', updated_at = now()
                          WHERE id = ' . $cardId
                    );
                }
            }
        });
    }

    public static function addComment(int $cardId, string $body, int $authorId): void
    {
        $boardId = self::boardId();
        Db::transaction(static function ($conn) use ($cardId, $body, $authorId, $boardId): void {
            $card = Db::queryOne(
                'SELECT c.id FROM cards c
                   JOIN columns col ON col.id = c.column_id
                  WHERE c.id = ' . $cardId . ' AND col.board_id = ' . $boardId
            );
            if ($card === null) {
                throw new HttpException(404, 'Карточка не найдена');
            }
            Db::connExec(
                $conn,
                'INSERT INTO comments (card_id, author_id, body)
                 VALUES (' . $cardId . ', ' . $authorId . ', ' . Db::quote($body) . ')'
            );
            Db::connExec(
                $conn,
                'UPDATE cards SET updated_at = now() WHERE id = ' . $cardId
            );
        });
    }

    public static function deleteComment(int $id): void
    {
        $boardId = self::boardId();
        Db::transaction(static function ($conn) use ($id, $boardId): void {
            $exists = Db::queryOne(
                'SELECT cm.id FROM comments cm
                   JOIN cards c ON c.id = cm.card_id
                   JOIN columns col ON col.id = c.column_id
                  WHERE cm.id = ' . $id . ' AND col.board_id = ' . $boardId
            );
            if ($exists === null) {
                throw new HttpException(404, 'Комментарий не найден');
            }
            Db::connExec($conn, 'DELETE FROM comments WHERE id = ' . $id);
        });
    }
}
