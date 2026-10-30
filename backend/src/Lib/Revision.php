<?php

declare(strict_types=1);

namespace App\Lib;

/**
 * Monotonic board revision.
 *
 * Every mutation bumps `board.revision`; the snapshot carries it and clients
 * ignore snapshots whose revision is not newer than what they already have.
 * That removes the race between an HTTP response and a broadcast that may
 * arrive in either order.
 */
final class Revision
{
    private static int $lastSeen = 0;

    public static function current(int $boardId): int
    {
        $row = Db::queryOne('SELECT revision FROM board WHERE id = ?', [$boardId]);
        return (int)($row['revision'] ?? 0);
    }

    public static function bump(int $boardId): int
    {
        $row = Db::queryOne(
            'UPDATE board SET revision = revision + 1 WHERE id = ? RETURNING revision',
            [$boardId],
        );
        $revision = (int)($row['revision'] ?? 0);
        if ($revision > self::$lastSeen) {
            self::$lastSeen = $revision;
        }
        return $revision;
    }
}
