<?php

declare(strict_types=1);

namespace App\Lib;

use Swoole\WebSocket\Server as WsServer;

final class Hub
{
    private static ?WsServer $server = null;

    /** @var array<int,int> fd => user_id */
    private static array $sessions = [];

    public static function setServer(WsServer $server): void
    {
        self::$server = $server;
    }

    public static function bind(int $fd, int $userId): void
    {
        self::$sessions[$fd] = $userId;
    }

    public static function unbind(int $fd): void
    {
        unset(self::$sessions[$fd]);
    }

    public function userIdFor(int $fd): ?int
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

    public static function push(int $fd, string $type, mixed $data): void
    {
        if (self::$server === null) {
            return;
        }
        if (!isset(self::$sessions[$fd])) {
            return;
        }
        self::pushRaw($fd, $type, $data);
    }

    public static function broadcast(string $type, mixed $data): void
    {
        foreach (array_keys(self::$sessions) as $fd) {
            self::pushRaw($fd, $type, $data);
        }
    }

    public static function broadcastBoard(): void
    {
        $snapshot = BoardRepo::snapshot();
        self::broadcast('board:sync', $snapshot);
    }

    private static function pushRaw(int $fd, string $type, mixed $data): void
    {
        if (self::$server === null) {
            return;
        }
        if (self::$server->exist($fd) && self::$server->isEstablished($fd)) {
            self::$server->push($fd, json_encode(['type' => $type, 'data' => $data]));
        }
    }
}
