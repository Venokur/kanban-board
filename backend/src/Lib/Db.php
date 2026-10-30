<?php

declare(strict_types=1);

namespace App\Lib;

use App\Rt\Channel;
use App\Rt\PostgreSQL;
use RuntimeException;

/**
 * Coroutine connection pool with always-parameterised queries.
 *
 * Application code passes positional placeholders (`?`) and an argument array;
 * Db rewrites them to server-side `$1..$n` and runs the statement through
 * prepare/execute. If the extension build has no `prepare()` (older builds), the
 * values are escaped as literals instead — never interpolated raw.
 */
final class Db
{
    private const MAX_CONNECTIONS = 10;
    private const ACQUIRE_TIMEOUT = 5.0;

    /** @var array{host:string,port:string,dbname:string,user:string,password:string} */
    private static array $config = [
        'host' => 'db',
        'port' => '5432',
        'dbname' => 'kanban',
        'user' => 'kanban',
        'password' => 'kanban',
    ];
    private static bool $poolReady = false;
    private static ?Channel $pool = null;
    private static ?Channel $slots = null;

    /**
     * Must be called from within a coroutine (request/ws handler).
     * Channel creation is not allowed in the global scope.
     */
    public static function ensurePool(): void
    {
        if (self::$poolReady) {
            return;
        }
        self::$config = Config::db();
        self::$pool = new Channel(self::MAX_CONNECTIONS);
        self::$slots = new Channel(self::MAX_CONNECTIONS);

        // Pre-fill capacity "tokens": acquire() pops a token, so at most
        // MAX_CONNECTIONS connections can exist at any point in time.
        for ($i = 0; $i < self::MAX_CONNECTIONS; $i++) {
            self::$slots->push(true);
        }
        self::$poolReady = true;
    }

    /**
     * Run a callback inside a transaction on a dedicated connection.
     * The callback receives the connection and must use the conn* methods.
     */
    public static function transaction(callable $callback): mixed
    {
        $conn = self::acquire();
        $healthy = true;
        try {
            self::connQuery($conn, 'BEGIN');
            $result = $callback($conn);
            self::connQuery($conn, 'COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $healthy = !($e instanceof DbConnectionException);
            try {
                self::connQuery($conn, 'ROLLBACK');
            } catch (\Throwable $ignored) {
                // Connection is gone: never put it back into the pool.
                $healthy = false;
            }
            throw $e;
        } finally {
            self::release($conn, $healthy);
        }
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function query(string $sql, array $params = []): array
    {
        return self::withConnection(static function (PostgreSQL $conn) use ($sql, $params): array {
            $result = self::connQuery($conn, $sql, $params);
            return $result === null ? [] : $result->fetchAll();
        });
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function queryOne(string $sql, array $params = []): ?array
    {
        $rows = self::query($sql, $params);
        return $rows[0] ?? null;
    }

    /** @param array<int|string,mixed> $params */
    public static function exec(string $sql, array $params = []): void
    {
        self::withConnection(static function (PostgreSQL $conn) use ($sql, $params): void {
            self::connQuery($conn, $sql, $params);
        });
    }

    /**
     * Execute on an already-acquired connection (only valid inside transaction()).
     *
     * @param array<int|string,mixed> $params
     */
    public static function connExec(PostgreSQL $conn, string $sql, array $params = []): void
    {
        self::connQuery($conn, $sql, $params);
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function connQueryOne(PostgreSQL $conn, string $sql, array $params = []): ?array
    {
        $result = self::connQuery($conn, $sql, $params);
        if ($result === null) {
            return null;
        }
        $rows = $result->fetchAll();
        return $rows[0] ?? null;
    }

    /**
     * @param array<int|string,mixed> $params
     */
    public static function connQuery(PostgreSQL $conn, string $sql, array $params = []): mixed
    {
        if ($params === [] || strpos($sql, '?') === false) {
            $result = $conn->query($sql);
            if ($result === false) {
                self::throwQueryError($conn, (string)($conn->error ?? ''), $sql);
            }
            if (self::isErrorStatus((int)$conn->resultStatus)) {
                self::throwQueryError($conn, (string)($conn->error ?? ''), $sql);
            }
            return $result;
        }

        [$prepared, $args] = self::bind($sql, $params);

        // OpenSwoole 26: prepare() takes only the SQL (the statement name is
        // generated by the extension) and execute() returns bool; rows are read
        // from the statement object.
        $stmt = $conn->prepare($prepared);
        if ($stmt !== false) {
            if ($stmt->execute($args)) {
                return $stmt;
            }
            self::throwQueryError(
                $conn,
                (string)($stmt->error ?? '') ?: (string)($conn->error ?? ''),
                $sql,
            );
        }

        // Build without a working prepared path: escape as literals instead.
        $result = $conn->query(self::inline($sql, $params));
        if ($result === false) {
            self::throwQueryError($conn, (string)($conn->error ?? ''), $sql);
        }
        return $result;
    }

    /**
     * Only a dead socket is retried (DbConnectionException); a rejected
     * statement is a plain RuntimeException so it is reported, not retried.
     */
    private static function throwQueryError(PostgreSQL $conn, string $error, string $sql): never
    {
        if (self::isConnectionError($conn, $error)) {
            throw new DbConnectionException('DB connection lost: ' . $error);
        }
        throw new RuntimeException(
            'SQL error: ' . ($error !== '' ? $error : 'unknown') . ' | ' . $sql,
        );
    }

    private static function isConnectionError(PostgreSQL $conn, string $error): bool
    {
        // libpq CONNECTION_OK is 0; any other value means the socket is unusable.
        $status = $conn->status();
        if (is_int($status) && $status !== 0) {
            return true;
        }
        if ($error === '') {
            return false;
        }
        foreach ([
            'server closed the connection',
            'connection already closed',
            'no connection to the server',
            'terminating connection',
            'could not connect',
        ] as $needle) {
            if (stripos($error, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Rewrite `?` placeholders to `$1..$n` and build the argument list.
     *
     * @param array<int|string,mixed> $params
     * @return array{0:string,1:array<int,mixed>|null} prepared SQL and args,
     *         or the SQL unchanged and null when there are no parameters.
     */
    private static function bind(string $sql, array $params): array
    {
        if ($params === [] || strpos($sql, '?') === false) {
            return [$sql, null];
        }
        $params = array_values($params);
        $index = 0;
        $out = '';
        $len = strlen($sql);
        $inString = false;
        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            if ($char === "'") {
                $inString = !$inString;
                $out .= $char;
                continue;
            }
            if ($char === '?' && !$inString) {
                $index++;
                if (!array_key_exists($index - 1, $params)) {
                    throw new RuntimeException('Недостаточно параметров для SQL-запроса');
                }
                $out .= '$' . $index;
                continue;
            }
            $out .= $char;
        }
        if ($index !== count($params)) {
            throw new RuntimeException('Количество параметров не совпадает с числом placeholder-ов');
        }
        return [$out, array_map([self::class, 'param'], $params)];
    }

    /**
     * Normalise a bound value for the prepared path.
     *
     * The OpenSwoole client stringifies every argument before sending it, and
     * PHP `false` becomes the empty string — which PostgreSQL rejects for a
     * boolean column (`invalid input syntax for type boolean: ""`). Only `true`
     * survived, so un-ticking a checklist item returned a 500 while ticking it
     * worked. `true`/`false` literals are valid input for booleans and harmless
     * for text/numeric columns, and they match what quote() already emits on
     * the inline path.
     */
    public static function param(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return $value;
    }

    /**
     * @param array<int|string,mixed> $params
     */
    private static function inline(string $sql, array $params): string
    {
        $params = array_values($params);
        $out = '';
        $index = 0;
        $inString = false;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            if ($char === "'") {
                $inString = !$inString;
                $out .= $char;
                continue;
            }
            if ($char === '?' && !$inString) {
                $out .= self::quote($params[$index] ?? null);
                $index++;
                continue;
            }
            $out .= $char;
        }
        return $out;
    }

    private static function isErrorStatus(int $status): bool
    {
        foreach (['APP_RT_PGRES_BAD_RESPONSE', 'APP_RT_PGRES_NONFATAL_ERROR', 'APP_RT_PGRES_FATAL_ERROR'] as $name) {
            if (defined($name) && $status === (int)constant($name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @template T
     * @param callable(PostgreSQL):T $callback
     * @return T
     */
    private static function withConnection(callable $callback): mixed
    {
        $attempts = 0;
        while (true) {
            $attempts++;
            $conn = self::acquire();
            $healthy = true;
            try {
                return $callback($conn);
            } catch (DbConnectionException $e) {
                $healthy = false;
                // A dropped connection is retried once with a fresh one.
                if ($attempts >= 2) {
                    throw $e;
                }
                continue;
            } finally {
                self::release($conn, $healthy);
            }
        }
    }

    private static function acquire(): PostgreSQL
    {
        self::ensurePool();

        if (self::$slots->pop(self::ACQUIRE_TIMEOUT) === false) {
            throw new RuntimeException('Пул соединений занят, попробуйте ещё раз');
        }

        $conn = self::$pool->pop(0.001);
        if ($conn !== false && $conn instanceof PostgreSQL) {
            return $conn;
        }

        $conn = self::connect();
        if ($conn === null) {
            self::$slots->push(true); // return the token
            throw new DbConnectionException('Не удалось подключиться к базе данных');
        }
        return $conn;
    }

    private static function release(PostgreSQL $conn, bool $healthy = true): void
    {
        if ($healthy) {
            self::$pool->push($conn);
        }
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

    /**
     * Build `($1,$2,…)`-style placeholder list for an IN clause.
     *
     * @param array<int,mixed> $values
     * @param array<int,mixed> $params receives the values
     */
    public static function placeholders(array $values, array &$params): string
    {
        $parts = [];
        foreach ($values as $value) {
            $params[] = $value;
            $parts[] = '?';
        }
        return $parts === [] ? 'NULL' : implode(', ', $parts);
    }
}
