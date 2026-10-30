<?php

declare(strict_types=1);

namespace App\Lib;

/**
 * Activity trail. Every mutation that touches a card records a row, which the
 * card panel renders as a timeline ("Петя изменил срок", "Аня добавила метку").
 */
final class EventRepo
{
    public const KINDS = [
        'card.created',
        'card.updated',
        'card.moved',
        'card.archived',
        'card.restored',
        'card.deleted',
        'comment.added',
        'comment.deleted',
        'checklist.added',
        'checklist.updated',
        'checklist.deleted',
        'label.attached',
        'label.detached',
        'attachment.added',
        'attachment.removed',
    ];

    /**
     * @param array<string,mixed> $payload
     */
    public static function log(int $cardId, ?int $userId, string $kind, array $payload = []): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            return;
        }
        Db::exec(
            'INSERT INTO card_events (card_id, user_id, kind, payload) VALUES (?, ?, ?, ?::jsonb)',
            [$cardId, $userId, $kind, json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}'],
        );
    }

    /**
     * Card activity, newest first.
     *
     * Cards that predate the event log (the seed data, and anything created
     * before `card_events` existed) have no `card.created` row, so the panel
     * showed "История появится после первого изменения" even though the card
     * obviously had a creation date. When that row is missing we synthesise it
     * from `cards.created_at` so the timeline always starts with the creation.
     * Synthetic entries carry id 0, which no real row can have.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function timeline(int $cardId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));
        $rows = Db::query(
            "SELECT e.id, e.kind, e.payload,
                    e.user_id, u.name AS user_name,
                    round(extract(epoch FROM e.created_at)::numeric * 1000)::bigint AS created_at_ms
               FROM card_events e
          LEFT JOIN users u ON u.id = e.user_id
              WHERE e.card_id = ?
              ORDER BY e.created_at DESC, e.id DESC
              LIMIT ?",
            [$cardId, $limit],
        );

        $events = array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'kind' => (string)$row['kind'],
            'payload' => json_decode((string)($row['payload'] ?? '{}'), true) ?: [],
            'user' => $row['user_id'] === null ? null : [
                'id' => (int)$row['user_id'],
                'name' => (string)$row['user_name'],
            ],
            'createdAt' => isset($row['created_at_ms']) ? (int)$row['created_at_ms'] : null,
        ], $rows);

        $hasCreated = false;
        foreach ($events as $event) {
            if ($event['kind'] === 'card.created') {
                $hasCreated = true;
                break;
            }
        }
        if ($hasCreated) {
            return $events;
        }

        $card = Db::queryOne(
            'SELECT c.author_id, u.name AS author_name,
                    round(extract(epoch FROM c.created_at)::numeric * 1000)::bigint AS created_at_ms
               FROM cards c
          LEFT JOIN users u ON u.id = c.author_id
              WHERE c.id = ?',
            [$cardId],
        );
        if ($card === null) {
            return $events;
        }

        $events[] = [
            'id' => 0,
            'kind' => 'card.created',
            'payload' => [],
            'user' => $card['author_id'] === null ? null : [
                'id' => (int)$card['author_id'],
                'name' => (string)$card['author_name'],
            ],
            'createdAt' => isset($card['created_at_ms']) ? (int)$card['created_at_ms'] : null,
        ];
        // The synthetic entry is the oldest, so dropping the tail keeps the
        // newest `$limit` real events intact.
        if (count($events) > $limit) {
            array_pop($events);
        }

        return $events;
    }
}
