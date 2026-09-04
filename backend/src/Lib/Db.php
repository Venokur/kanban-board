<?php

declare(strict_types=1);

namespace App\Lib;

use RuntimeException;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\PostgreSQL;

final class Db
{
    private const MAX_CONNECTIONS = 10;

    /** @var array{host:string,port:string,dbname:string,user:string,password:string} */
    private static array $config = [];
    private static bool $poolReady = false;
    private static ?Channel $pool = null;
    private static ?Channel $slots = null;

    /**
     * Must be called from within a coroutine (request/ws handler).
     * Swoole only allows Channel creation inside a coroutine context.
     */
    public static function ensurePool(): void
    {
        if (self::$poolReady) {
            return;
        }
        self::$config = Config::db();
        self::$pool = new Channel(self::MAX_CONNECTIONS);
        self::$slots = new Channel(self::MAX_CONNECTIONS);

        // Pre-fill capacity "tokens". acquire() pops a token, guaranteeing that
        // at most MAX_CONNECTIONS connections exist at any point in time.
        for ($i = 0; $i < self::MAX_CONNECTIONS; $i++) {
            self::$slots->push(true);
        }
        self::$poolReady = true;
    }

    /**
     * Run a callback inside a transaction on a dedicated connection.
     * The callback receives the connection and may use Db::connQuery()/Db::connExec().
     */
    public static function transaction(callable $callback): mixed
    {
        $conn = self::acquire();
        try {
            self::connExec($conn, 'BEGIN');
            $result = $callback($conn);
            self::connExec($conn, 'COMMIT');
            return $result;
        } catch (\Throwable $e) {
            try {
                self::connExec($conn, 'ROLLBACK');
            } catch (\Throwable $ignored) {
                // connection is likely broken; drop it
            }
            throw $e;
        } finally {
            self::release($conn);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function query(string $sql): array
    {
        $conn = self::acquire();
        try {
            $result = self::connQuery($conn, $sql);
            return $result === null ? [] : $result->fetchAll();
        } finally {
            self::release($conn);
        }
    }

    public static function exec(string $sql): void
    {
        $conn = self::acquire();
        try {
            self::connQuery($conn, $sql);
        } finally {
            self::release($conn);
        }
    }

    /** @return array<string,mixed>|null */
    public static function queryOne(string $sql): ?array
    {
        $rows = self::query($sql);
        return $rows[0] ?? null;
    }

    /** Execute on an already-acquired connection (used inside transaction()). */
    public static function connExec(PostgreSQL $conn, string $sql): void
    {
        self::connQuery($conn, $sql);
    }

    /** @return array<string,mixed>|null */
    public static function connQueryOne(PostgreSQL $conn, string $sql): ?array
    {
        $result = self::connQuery($conn, $sql);
        if ($result === null) {
            return null;
        }
        $rows = $result->fetchAll();
        return $rows[0] ?? null;
    }

    public static function connQuery(PostgreSQL $conn, string $sql): mixed
    {
        $result = $conn->query($sql);
        if ($result === false) {
            throw new RuntimeException('DB error: ' . (string)($conn->error ?? 'unknown'));
        }

        $status = (int)$conn->resultStatus;
        if (self::isErrorStatus($status)) {
            throw new RuntimeException('SQL error: ' . (string)($conn->error ?? 'unknown') . ' | ' . $sql);
        }
        return $result;
    }

    private static function isErrorStatus(int $status): bool
    {
        // libpq PGRES_* constants (fallback to numeric values when unavailable)
        $constants = [
            'PGRES_BAD_RESPONSE'   => 5,
            'PGRES_NONFATAL_ERROR' => 6,
            'PGRES_FATAL_ERROR'    => 7,
        ];
        foreach ($constants as $name => $value) {
            $const = defined('Swoole\\Constant::' . $name)
                ? constant('Swoole\\Constant::' . $name)
                : $value;
            if ($status === $const) {
                return true;
            }
        }
        return false;
    }

    private static function acquire(): PostgreSQL
    {
        self::ensurePool();

        if (self::$slots->pop(5) === false) {
            throw new RuntimeException('DB pool timeout');
        }

        $conn = self::$pool->pop(0.001);
        if ($conn !== false && $conn instanceof PostgreSQL) {
            return $conn;
        }

        $conn = self::connect();
        if ($conn === null) {
            self::$slots->push(true); // return the token
            throw new RuntimeException('Cannot connect to database');
        }
        return $conn;
    }

    private static function release(PostgreSQL $conn): void
    {
        self::$pool->push($conn);
        self::$slots->push(true);
    }

    private static function connect(): ?PostgreSQL
    {
        try {
            $pg = new PostgreSQL();
            $dsn = sprintf(
                'host=%s;port=%s;dbname=%s;user=%s;password=%s',
                self::$config['host'],
                self::$config['port'],
                self::$config['dbname'],
                self::$config['user'],
                self::$config['password'],
            );
            $ok = $pg->connect($dsn);
            if (!$ok) {
                error_log('[db] connect failed: ' . (string)($pg->error ?? ''));
                return null;
            }
            return $pg;
        } catch (\Throwable $e) {
            error_log('[db] connect exception: ' . $e->getMessage());
            return null;
        }
    }

    public static function quote(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }

    /** Safely interpolate an identifier (column/table name). */
    public static function ident(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
