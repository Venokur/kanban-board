<?php

declare(strict_types=1);

namespace App\Lib;

use App\Rt\Timer;
use App\Rt\WsServer;

/**
 * Registry of live WebSocket connections (fd → user) plus broadcast helpers.
 *
 * All state lives in the single worker's memory, which is why `worker_num` must
 * stay 1 (see AGENTS.md). Broadcasts are coalesced: a burst of mutations (drag &
 * drop, ticking checklist items) produces one snapshot per COALESCE_MS instead of
 * one per request.
 */
final class Hub
{
    private const COALESCE_MS = 60;

    private static ?WsServer $server = null;

    /** @var array<int,int> fd => user_id */
    private static array $sessions = [];

    /** @var array<int,array{cardId:int|null,at:int}> fd => last activity */
    private static array $activity = [];

    private static bool $flushScheduled = false;
    private static bool $flushDirty = false;

    public static function setServer(WsServer $server): void
    {
        self::$server = $server;
    }

    public static function bind(int $fd, int $userId): void
    {
        self::$sessions[$fd] = $userId;
        self::$activity[$fd] = ['cardId' => null, 'at' => time()];
    }

    public static function unbind(int $fd): void
    {
        unset(self::$sessions[$fd], self::$activity[$fd]);
        self::broadcastPresence();
    }

    public static function userIdFor(int $fd): ?int
    {
        return self::$sessions[$fd] ?? null;
    }

    public static function isOnline(int $fd): bool
    {
        return isset(self::$sessions[$fd]);
    }

    public static function onlineCount(): int
    {
        return count(self::$sessions);
    }

    /**
     * Distinct users currently connected.
     *
     * @return array<int,int> user ids
     */
    public static function onlineUsers(): array
    {
        return array_values(array_unique(array_values(self::$sessions)));
    }

    /**
     * Remember which card a connection is looking at (drives "Петя смотрит
     * эту карточку" in the card panel).
     */
    public static function touch(int $fd, ?int $cardId): void
    {
        if (!isset(self::$sessions[$fd])) {
            return;
        }
        self::$activity[$fd] = ['cardId' => $cardId, 'at' => time()];
    }

    /** @return array<int,int> card_id => count of viewers */
    public static function watching(): array
    {
        $map = [];
        foreach (self::$activity as $entry) {
            if ($entry['cardId'] !== null) {
                $map[$entry['cardId']] = ($map[$entry['cardId']] ?? 0) + 1;
            }
        }
        return $map;
    }

    /** @return array<int,int> user ids currently watching a specific card */
    public static function watchersOf(int $cardId): array
    {
        $users = [];
        foreach (self::$activity as $fd => $entry) {
            if ($entry['cardId'] === $cardId) {
                $users[] = self::$sessions[$fd];
            }
        }
        return array_values(array_unique($users));
    }

    public static function push(int $fd, string $type, mixed $data): void
    {
        if (self::$server === null || !isset(self::$sessions[$fd])) {
            return;
        }
        self::pushRaw($fd, $type, $data);
    }

    /** Push to every connection of one user. */
    public static function pushToUser(int $userId, string $type, mixed $data): void
    {
        foreach (self::$sessions as $fd => $sessionUser) {
            if ($sessionUser === $userId) {
                self::pushRaw($fd, $type, $data);
            }
        }
    }

    public static function broadcast(string $type, mixed $data): void
    {
        foreach (array_keys(self::$sessions) as $fd) {
            self::pushRaw($fd, $type, $data);
        }
    }

    /** Immediate broadcast of a fresh snapshot (ignores coalescing). */
    public static function broadcastBoard(): void
    {
        self::broadcast('board:sync', BoardRepo::snapshot());
    }

    /**
     * Coalesced snapshot broadcast: at most one per COALESCE_MS, always with the
     * freshest state.
     */
    public static function scheduleBoardSync(): void
    {
        self::$flushDirty = true;
        if (self::$flushScheduled) {
            return;
        }
        self::$flushScheduled = true;

        Timer::after(self::COALESCE_MS, static function (): void {
            self::$flushScheduled = false;
            if (!self::$flushDirty) {
                return;
            }
            self::$flushDirty = false;
            Runtime::spawn(static function (): void {
                if (self::$sessions === []) {
                    return;
                }
                self::broadcastBoard();
            });
        });
    }

    public static function broadcastPresence(): void
    {
        if (self::$sessions === []) {
            return;
        }
        Runtime::spawn(static function (): void {
            self::broadcast('presence:sync', [
                'users' => self::onlineUsers(),
                'watching' => self::watching(),
            ]);
        });
    }

    private static function pushRaw(int $fd, string $type, mixed $data): void
    {
        if (self::$server === null) {
            return;
        }
        // NOTE: OpenSwoole 26 renamed `exist()` to `exists()`; do not change back.
        if (self::$server->exists($fd) && self::$server->isEstablished($fd)) {
            self::$server->push($fd, json_encode(
                ['type' => $type, 'data' => $data],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        }
    }
}
