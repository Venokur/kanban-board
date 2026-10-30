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

    public static function renameBoard(string $name): void
    {
        $id = self::boardId();
        Db::exec('UPDATE board SET name = ? WHERE id = ?', [$name, $id]);
    }

    /**
     * Full board snapshot: columns with nested cards, comments, labels and checklist.
     * @return array<string,mixed>
     */
    public static function snapshot(): array
    {
        $boardId = self::boardId();
        $board = Db::queryOne('SELECT id, name, revision FROM board WHERE id = ?', [$boardId]);

        $labelRows = Db::query(
            'SELECT id, name, color, position FROM labels
              WHERE board_id = ?
              ORDER BY position, id',
            [$boardId],
        );

        $userRows = Db::query('SELECT id, name FROM users ORDER BY name, id');

        $columnRows = Db::query(
            'SELECT id, title, position, wip_limit, accent FROM columns
              WHERE board_id = ?
              ORDER BY position, id',
            [$boardId],
        );

        $cardRows = Db::query(
            "SELECT c.id, c.column_id, c.title, c.description, c.position,
                    c.cover_color, c.estimate,
                    c.author_id,
                    u.name AS author_name,
                    c.assignee_id,
                    a.name AS assignee_name,
                    to_char(c.due_date, 'YYYY-MM-DD') AS due_date,
                    c.priority,
                    round(extract(epoch FROM c.created_at)::numeric * 1000)::bigint AS created_at_ms,
                    round(extract(epoch FROM c.updated_at)::numeric * 1000)::bigint AS updated_at_ms
               FROM cards c
               JOIN columns col ON col.id = c.column_id
          LEFT JOIN users u ON u.id = c.author_id
          LEFT JOIN users a ON a.id = c.assignee_id
              WHERE col.board_id = ?
                AND c.archived_at IS NULL
                AND c.deleted_at IS NULL
              ORDER BY col.position, c.position, c.id",
            [$boardId],
        );

        $commentRows = Db::query(
            "SELECT cm.id, cm.card_id, cm.body,
                    cm.author_id,
                    u.name AS author_name,
                    round(extract(epoch FROM cm.created_at)::numeric * 1000)::bigint AS created_at_ms
               FROM comments cm
               JOIN cards c ON c.id = cm.card_id
               JOIN columns col ON col.id = c.column_id
          LEFT JOIN users u ON u.id = cm.author_id
              WHERE col.board_id = ?
                AND c.archived_at IS NULL
                AND c.deleted_at IS NULL
              ORDER BY cm.created_at, cm.id",
            [$boardId],
        );

        $cardLabelRows = Db::query(
            'SELECT cl.card_id, l.id, l.name, l.color, l.position
               FROM card_labels cl
               JOIN labels l ON l.id = cl.label_id
               JOIN cards c ON c.id = cl.card_id
               JOIN columns col ON col.id = c.column_id
              WHERE col.board_id = ?
                AND c.archived_at IS NULL
                AND c.deleted_at IS NULL
              ORDER BY l.position, l.id',
            [$boardId],
        );

        $checklistRows = Db::query(
            'SELECT ci.id, ci.card_id, ci.text, ci.done, ci.position
               FROM checklist_items ci
               JOIN cards c ON c.id = ci.card_id
               JOIN columns col ON col.id = c.column_id
              WHERE col.board_id = ?
                AND c.archived_at IS NULL
                AND c.deleted_at IS NULL
              ORDER BY ci.position, ci.id',
            [$boardId],
        );

        $commentsByCard = [];
        foreach ($commentRows as $row) {
            $commentsByCard[(int)$row['card_id']][] = self::commentShape($row);
        }

        $labelsByCard = [];
        foreach ($cardLabelRows as $row) {
            $labelsByCard[(int)$row['card_id']][] = self::labelBadgeShape($row);
        }

        $checklistByCard = [];
        foreach ($checklistRows as $row) {
            $checklistByCard[(int)$row['card_id']][] = self::checklistShape($row);
        }

        $cardsByColumn = [];
        foreach ($cardRows as $row) {
            $cardsByColumn[(int)$row['column_id']][] = self::cardShape(
                $row,
                $commentsByCard,
                $labelsByCard,
                $checklistByCard,
            );
        }

        $columns = [];
        foreach ($columnRows as $row) {
            $id = (int)$row['id'];
            $columns[] = [
                'id' => $id,
                'title' => (string)$row['title'],
                'position' => (int)$row['position'],
                'wipLimit' => isset($row['wip_limit']) ? (int)$row['wip_limit'] : null,
                'accent' => self::nullableString($row['accent'] ?? null),
                'cards' => $cardsByColumn[$id] ?? [],
            ];
        }

        return [
            'revision' => (int)($board['revision'] ?? 0),
            'board' => [
                'id' => (int)$board['id'],
                'name' => (string)$board['name'],
            ],
            'labels' => array_map(static fn(array $row): array => self::labelShape($row), $labelRows),
            'users' => array_map(static fn(array $row): array => self::userShape($row['id'], $row['name']), $userRows),
            'columns' => $columns,
            'presence' => [
                'users' => Hub::onlineUsers(),
                'watching' => (object)Hub::watching(),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,array<int,array<string,mixed>>> $commentsByCard
     * @param array<int,array<int,array<string,mixed>>> $labelsByCard
     * @param array<int,array<int,array<string,mixed>>> $checklistByCard
     * @return array<string,mixed>
     */
    private static function cardShape(
        array $row,
        array $commentsByCard,
        array $labelsByCard,
        array $checklistByCard,
    ): array {
        $id = (int)$row['id'];
        return [
            'id' => $id,
            'title' => (string)$row['title'],
            'description' => (string)($row['description'] ?? ''),
            'position' => (int)$row['position'],
            'coverColor' => self::nullableString($row['cover_color'] ?? null),
            'estimate' => isset($row['estimate']) ? (float)$row['estimate'] : null,
            'author' => self::userShape($row['author_id'] ?? null, $row['author_name'] ?? null),
            'assignee' => self::userShape($row['assignee_id'] ?? null, $row['assignee_name'] ?? null),
            'dueDate' => isset($row['due_date']) && $row['due_date'] !== '' ? (string)$row['due_date'] : null,
            'priority' => (int)($row['priority'] ?? 0),
            'labels' => $labelsByCard[$id] ?? [],
            'checklist' => $checklistByCard[$id] ?? [],
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
            'author' => self::userShape($row['author_id'] ?? null, $row['author_name'] ?? null),
            'createdAt' => isset($row['created_at_ms']) ? (int)$row['created_at_ms'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function labelShape(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'color' => (string)$row['color'],
            'position' => isset($row['position']) ? (int)$row['position'] : 0,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,name:string,color:string}
     */
    private static function labelBadgeShape(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'color' => (string)$row['color'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function checklistShape(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'text' => (string)$row['text'],
            'done' => (bool)$row['done'],
            'position' => (int)$row['position'],
        ];
    }

    /**
     * @return array{id:int,name:string}|null
     */
    private static function userShape(mixed $id, mixed $name): ?array
    {
        if ($id === null) {
            return null;
        }
        return [
            'id' => (int)$id,
            'name' => (string)$name,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (string)$value;
    }

    // ------------------------------------------------------------- columns

    public static function addColumn(string $title): void
    {
        $boardId = self::boardId();
        Db::transaction(static function ($conn) use ($title, $boardId): void {
            $row = Db::connQueryOne(
                $conn,
                'SELECT COALESCE(MAX(position), 0) + 1 AS next FROM columns WHERE board_id = ?',
                [$boardId],
            );
            Db::connExec(
                $conn,
                'INSERT INTO columns (board_id, title, position) VALUES (?, ?, ?)',
                [$boardId, $title, (int)($row['next'] ?? 1)],
            );
        });
    }

    public static function renameColumn(int $id, string $title): void
    {
        self::requireColumn($id);
        Db::exec('UPDATE columns SET title = ? WHERE id = ?', [$title, $id]);
    }

    /**
     * @param array{title?:string,wip_limit?:int|null,accent?:string|null} $fields
     */
    public static function updateColumn(int $id, array $fields): void
    {
        self::requireColumn($id);

        $sets = [];
        $params = [];
        if (array_key_exists('title', $fields)) {
            $sets[] = 'title = ?';
            $params[] = $fields['title'];
        }
        if (array_key_exists('wip_limit', $fields)) {
            $sets[] = 'wip_limit = ?';
            $params[] = $fields['wip_limit'];
        }
        if (array_key_exists('accent', $fields)) {
            $sets[] = 'accent = ?';
            $params[] = $fields['accent'];
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        Db::exec('UPDATE columns SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public static function deleteColumn(int $id): void
    {
        $boardId = self::boardId();
        Db::transaction(static function ($conn) use ($id, $boardId): void {
            $row = Db::connQueryOne(
                $conn,
                'SELECT id, position FROM columns WHERE id = ? AND board_id = ?',
                [$id, $boardId],
            );
            if ($row === null) {
                throw new HttpException(404, 'Колонка не найдена');
            }
            $position = (int)$row['position'];
            Db::connExec($conn, 'DELETE FROM columns WHERE id = ?', [$id]);
            // Keep positions dense: the invariant is 1…N with no gaps.
            Db::connExec(
                $conn,
                'UPDATE columns SET position = position - 1 WHERE board_id = ? AND position > ?',
                [$boardId, $position],
            );
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
                    'UPDATE columns SET position = ? WHERE id = ? AND board_id = ?',
                    [$index + 1, (int)$id, $boardId],
                );
            }
        });
    }

    private static function requireColumn(int $id): void
    {
        $row = Db::queryOne(
            'SELECT id FROM columns WHERE id = ? AND board_id = ?',
            [$id, self::boardId()],
        );
        if ($row === null) {
            throw new HttpException(404, 'Колонка не найдена');
        }
    }

    // --------------------------------------------------------------- cards

    public static function addCard(int $columnId, string $title, string $description, int $authorId): int
    {
        $boardId = self::boardId();
        $cardId = Db::transaction(static function ($conn) use ($columnId, $title, $description, $authorId, $boardId): int {
            $column = Db::connQueryOne(
                $conn,
                'SELECT id FROM columns WHERE id = ? AND board_id = ?',
                [$columnId, $boardId],
            );
            if ($column === null) {
                throw new HttpException(404, 'Колонка не найдена');
            }
            $row = Db::connQueryOne(
                $conn,
                'SELECT COALESCE(MAX(position), 0) + 1 AS next
                   FROM cards WHERE column_id = ? AND archived_at IS NULL AND deleted_at IS NULL',
                [$columnId],
            );
            $created = Db::connQueryOne(
                $conn,
                'INSERT INTO cards (column_id, title, description, position, author_id)
                 VALUES (?, ?, ?, ?, ?)
                 RETURNING id',
                [$columnId, $title, $description, (int)($row['next'] ?? 1), $authorId],
            );
            return (int)($created['id'] ?? 0);
        });

        return $cardId;
    }

    /**
     * Patch card fields. Recognised keys:
     * title, description, assignee_id (int|null), due_date (string|null),
     * priority (int), label_ids (array<int>), cover_color, estimate.
     *
     * @param array<string,mixed> $fields
     */
    public static function updateCard(int $id, array $fields): void
    {
        $boardId = self::boardId();
        Db::transaction(static function ($conn) use ($id, $boardId, $fields): void {
            self::requireCardOn($conn, $id, $boardId);

            $sets = [];
            $params = [];

            if (array_key_exists('title', $fields)) {
                $sets[] = 'title = ?';
                $params[] = $fields['title'];
            }
            if (array_key_exists('description', $fields)) {
                $sets[] = 'description = ?';
                $params[] = $fields['description'];
            }
            if (array_key_exists('assignee_id', $fields)) {
                $assignee = $fields['assignee_id'];
                if ($assignee !== null) {
                    $user = Db::connQueryOne($conn, 'SELECT id FROM users WHERE id = ?', [(int)$assignee]);
                    if ($user === null) {
                        throw new HttpException(422, 'Пользователь не найден');
                    }
                    $sets[] = 'assignee_id = ?';
                    $params[] = (int)$assignee;
                } else {
                    $sets[] = 'assignee_id = NULL';
                }
            }
            if (array_key_exists('due_date', $fields)) {
                $sets[] = 'due_date = ?';
                $params[] = $fields['due_date'];
            }
            if (array_key_exists('priority', $fields)) {
                $sets[] = 'priority = ?';
                $params[] = (int)$fields['priority'];
            }
            if (array_key_exists('cover_color', $fields)) {
                $sets[] = 'cover_color = ?';
                $params[] = $fields['cover_color'];
            }
            if (array_key_exists('estimate', $fields)) {
                $sets[] = 'estimate = ?';
                $params[] = $fields['estimate'];
            }

            if ($sets !== []) {
                $sets[] = 'updated_at = now()';
                $params[] = $id;
                Db::connExec(
                    $conn,
                    'UPDATE cards SET ' . implode(', ', $sets) . ' WHERE id = ?',
                    $params,
                );
            }

            if (array_key_exists('label_ids', $fields)) {
                /** @var array<int,int> $labelIds */
                $labelIds = array_values(array_map('intval', (array)$fields['label_ids']));
                if ($labelIds !== []) {
                    $params = [];
                    $list = Db::placeholders($labelIds, $params);
                    $params[] = $boardId;
                    $row = Db::connQueryOne(
                        $conn,
                        'SELECT count(*)::int AS n FROM labels WHERE id IN (' . $list . ') AND board_id = ?',
                        $params,
                    );
                    if ((int)($row['n'] ?? 0) !== count($labelIds)) {
                        throw new HttpException(422, 'Метка не найдена на этой доске');
                    }
                }
                Db::connExec($conn, 'DELETE FROM card_labels WHERE card_id = ?', [$id]);
                foreach ($labelIds as $labelId) {
                    Db::connExec(
                        $conn,
                        'INSERT INTO card_labels (card_id, label_id) VALUES (?, ?) ON CONFLICT DO NOTHING',
                        [$id, $labelId],
                    );
                }
            }
        });
    }

    /** Current label ids of a card, in label order. Used to diff label changes
     *  after an update so the activity trail can record attached/detached.
     *
     * @return array<int,int>
     */
    public static function cardLabelIds(int $id): array
    {
        $rows = Db::query(
            'SELECT cl.label_id
               FROM card_labels cl
               JOIN labels l ON l.id = cl.label_id
              WHERE cl.card_id = ?
              ORDER BY l.position, l.id',
            [$id],
        );

        return array_map(static fn(array $row): int => (int)$row['label_id'], $rows);
    }

    public static function deleteCard(int $id): void
    {
        $boardId = self::boardId();
        Db::transaction(static function ($conn) use ($id, $boardId): void {
            $card = Db::connQueryOne(
                $conn,
                'SELECT c.id, c.column_id, c.position, c.archived_at
                   FROM cards c
                   JOIN columns col ON col.id = c.column_id
                  WHERE c.id = ? AND col.board_id = ?',
                [$id, $boardId],
            );
            if ($card === null) {
                throw new HttpException(404, 'Карточка не найдена');
            }
            $columnId = (int)$card['column_id'];
            $position = (int)$card['position'];
            $archived = $card['archived_at'] !== null;
            Db::connExec($conn, 'DELETE FROM cards WHERE id = ?', [$id]);

            // Only a live card occupies a slot in the column's 1…N sequence.
            // An archived row keeps the position it had when it left, so
            // deleting it must not shift anything.
            if (!$archived) {
                Db::connExec(
                    $conn,
                    'UPDATE cards SET position = position - 1
                      WHERE column_id = ? AND position > ? AND archived_at IS NULL',
                    [$columnId, $position],
                );
            }
        });
    }

    public static function archiveCard(int $id): void
    {
        $boardId = self::boardId();
        Db::transaction(static function ($conn) use ($id, $boardId): void {
            $card = Db::connQueryOne(
                $conn,
                'SELECT c.id, c.column_id, c.position
                   FROM cards c
                   JOIN columns col ON col.id = c.column_id
                  WHERE c.id = ? AND col.board_id = ? AND c.archived_at IS NULL',
                [$id, $boardId],
            );
            if ($card === null) {
                throw new HttpException(404, 'Карточка не найдена');
            }
            $columnId = (int)$card['column_id'];
            $position = (int)$card['position'];
            Db::connExec(
                $conn,
                'UPDATE cards SET archived_at = now(), updated_at = now() WHERE id = ?',
                [$id],
            );
            Db::connExec(
                $conn,
                'UPDATE cards SET position = position - 1
                  WHERE column_id = ? AND position > ? AND archived_at IS NULL',
                [$columnId, $position],
            );
        });
    }

    public static function restoreCard(int $id): void
    {
        $boardId = self::boardId();
        Db::transaction(static function ($conn) use ($id, $boardId): void {
            $card = Db::connQueryOne(
                $conn,
                'SELECT c.id, c.column_id
                   FROM cards c
                   JOIN columns col ON col.id = c.column_id
                  WHERE c.id = ? AND col.board_id = ? AND c.archived_at IS NOT NULL',
                [$id, $boardId],
            );
            if ($card === null) {
                throw new HttpException(404, 'Карточка в архиве не найдена');
            }
            $columnId = (int)$card['column_id'];
            $row = Db::connQueryOne(
                $conn,
                'SELECT COALESCE(MAX(position), 0) + 1 AS next
                   FROM cards WHERE column_id = ? AND archived_at IS NULL AND deleted_at IS NULL',
                [$columnId],
            );
            Db::connExec(
                $conn,
                'UPDATE cards SET archived_at = NULL, position = ?, updated_at = now() WHERE id = ?',
                [(int)($row['next'] ?? 1), $id],
            );
        });
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function archivedCards(): array
    {
        $boardId = self::boardId();
        $rows = Db::query(
            "SELECT c.id, c.title, c.column_id, col.title AS column_title,
                    round(extract(epoch FROM c.archived_at)::numeric * 1000)::bigint AS archived_at_ms
               FROM cards c
               JOIN columns col ON col.id = c.column_id
              WHERE col.board_id = ? AND c.archived_at IS NOT NULL AND c.deleted_at IS NULL
              ORDER BY c.archived_at DESC, c.id DESC",
            [$boardId],
        );

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'columnId' => (int)$row['column_id'],
            'columnTitle' => (string)$row['column_title'],
            'archivedAt' => isset($row['archived_at_ms']) ? (int)$row['archived_at_ms'] : null,
        ], $rows);
    }

    /**
     * Persist full card ordering (after drag & drop).
     *
     * Returns one entry per card that actually changed column, so the
     * controller can record a `card.moved` event: reordering inside the same
     * column is not a move and must not show up in the activity trail.
     *
     * @param array<int,array{id:int,cards:array<int>}> $columns
     * @return array<int,array{cardId:int,fromId:int,fromTitle:string,toId:int,toTitle:string}>
     */
    public static function orderCards(array $columns): array
    {
        $boardId = self::boardId();
        /** @var array<int,array{cardId:int,fromId:int,fromTitle:string,toId:int,toTitle:string}> $moved */
        $moved = [];
        Db::transaction(static function ($conn) use ($columns, $boardId, &$moved): void {
            foreach ($columns as $item) {
                $columnId = (int)($item['id'] ?? 0);
                $column = Db::connQueryOne(
                    $conn,
                    'SELECT id, title FROM columns WHERE id = ? AND board_id = ?',
                    [$columnId, $boardId],
                );
                if ($column === null) {
                    throw new HttpException(404, 'Колонка не найдена');
                }
                $columnTitle = (string)($column['title'] ?? '');
                $cardIds = array_values(array_map('intval', $item['cards'] ?? []));
                foreach ($cardIds as $index => $cardId) {
                    // Read the current column first: that is the only way to
                    // tell a move from a reorder within one column.
                    $before = Db::connQueryOne(
                        $conn,
                        'SELECT c.column_id, col.title AS column_title
                           FROM cards c
                           JOIN columns col ON col.id = c.column_id
                          WHERE c.id = ? AND col.board_id = ?
                            AND c.archived_at IS NULL AND c.deleted_at IS NULL',
                        [$cardId, $boardId],
                    );
                    Db::connExec(
                        $conn,
                        'UPDATE cards
                            SET position = ?, column_id = ?, updated_at = now()
                          WHERE id = ? AND archived_at IS NULL AND deleted_at IS NULL',
                        [$index + 1, $columnId, $cardId],
                    );
                    if ($before !== null && (int)$before['column_id'] !== $columnId) {
                        $moved[] = [
                            'cardId' => $cardId,
                            'fromId' => (int)$before['column_id'],
                            'fromTitle' => (string)($before['column_title'] ?? ''),
                            'toId' => $columnId,
                            'toTitle' => $columnTitle,
                        ];
                    }
                }
            }
        });

        return $moved;
    }

    private static function requireCardOn($conn, int $id, int $boardId): void
    {
        $card = Db::connQueryOne(
            $conn,
            'SELECT c.id FROM cards c
               JOIN columns col ON col.id = c.column_id
              WHERE c.id = ? AND col.board_id = ?',
            [$id, $boardId],
        );
        if ($card === null) {
            throw new HttpException(404, 'Карточка не найдена');
        }
    }

    // ------------------------------------------------------------ comments

    public static function addComment(int $cardId, string $body, int $authorId): void
    {
        $boardId = self::boardId();
        Db::transaction(static function ($conn) use ($cardId, $body, $authorId, $boardId): void {
            self::requireCardOn($conn, $cardId, $boardId);
            Db::connExec(
                $conn,
                'INSERT INTO comments (card_id, author_id, body) VALUES (?, ?, ?)',
                [$cardId, $authorId, $body],
            );
            Db::connExec($conn, 'UPDATE cards SET updated_at = now() WHERE id = ?', [$cardId]);
        });
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function comments(int $cardId): array
    {
        $rows = Db::query(
            "SELECT cm.id, cm.card_id, cm.body, cm.author_id, u.name AS author_name,
                    round(extract(epoch FROM cm.created_at)::numeric * 1000)::bigint AS created_at_ms
               FROM comments cm
               JOIN cards c ON c.id = cm.card_id
               JOIN columns col ON col.id = c.column_id
          LEFT JOIN users u ON u.id = cm.author_id
              WHERE cm.card_id = ? AND col.board_id = ?
              ORDER BY cm.created_at, cm.id",
            [$cardId, self::boardId()],
        );

        return array_map(static fn(array $row): array => self::commentShape($row), $rows);
    }

    /**
     * @return int card id the comment belonged to
     */
    public static function deleteComment(int $id): int
    {
        $boardId = self::boardId();
        $cardId = 0;
        Db::transaction(static function ($conn) use ($id, $boardId, &$cardId): void {
            $exists = Db::connQueryOne(
                $conn,
                'SELECT cm.id, cm.card_id FROM comments cm
                   JOIN cards c ON c.id = cm.card_id
                   JOIN columns col ON col.id = c.column_id
                  WHERE cm.id = ? AND col.board_id = ?',
                [$id, $boardId],
            );
            if ($exists === null) {
                throw new HttpException(404, 'Комментарий не найден');
            }
            $cardId = (int)$exists['card_id'];
            Db::connExec($conn, 'DELETE FROM comments WHERE id = ?', [$id]);
        });

        return $cardId;
    }
}
