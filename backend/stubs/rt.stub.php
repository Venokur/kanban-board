<?php

/**
 * Static-analysis stubs for the coroutine runtime.
 *
 * At runtime `src/rt.php` aliases the real OpenSwoole (or Swoole) classes to
 * these names, so no file here is ever autoloaded by the application. PHPStan
 * gets the shapes from this file, which keeps the analysis independent of
 * whether the extension is installed on the machine running it.
 *
 * @phpstan-type HeaderMap array<string,string>
 */

declare(strict_types=1);

namespace App\Rt;

// Not final: unit tests subclass Request/Response to feed payloads in without
// the extension. The real classes are final, but nothing here is autoloaded by
// the application.
class Request
{
    /** @var array<string,string> */
    public array $header = [];

    /** @var array<string,string> */
    public array $server = [];

    /** @var array<string,string> */
    public array $get = [];

    /** @var array<string,string> */
    public array $post = [];

    /** @var array<string,string> */
    public array $cookie = [];

    public int $fd = 0;

    public function rawContent(): string
    {
        return '';
    }
}

class Response
{
    public function header(string $key, string $value, bool $format = true): bool
    {
        return true;
    }

    public function status(int $status, string $reason = ''): bool
    {
        return true;
    }

    public function end(?string $data = null): bool
    {
        return true;
    }

    public function write(string $data): bool
    {
        return true;
    }

    public function cookie(
        string $key,
        ?string $value = null,
        int $expire = 0,
        string $path = '',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = false,
        string $sameSite = '',
        string $priority = '',
    ): bool {
        return true;
    }
}

final class Frame
{
    public int $fd = 0;

    public string $data = '';
}

final class WsServer
{
    public int $master_pid = 0;

    public function __construct(string $host = '0.0.0.0', int $port = 0, int $mode = 0, int $sockType = 0) {}

    /**
     * @param array<string,mixed> $settings
     */
    public function set(array $settings): bool
    {
        return true;
    }

    public function on(string $event, callable $callback): bool
    {
        return true;
    }

    public function start(): bool
    {
        return true;
    }

    public function tick(int $ms, callable $callback): int
    {
        return 0;
    }

    public function push(int $fd, string $data, int $opcode = 1, int $flags = 1): bool
    {
        return true;
    }

    public function exists(int $fd): bool
    {
        return true;
    }

    public function isEstablished(int $fd): bool
    {
        return true;
    }

    public function disconnect(int $fd, int $code = 1000, string $reason = ''): bool
    {
        return true;
    }
}

final class Timer
{
    public static function after(int $ms, callable $callback): int
    {
        return 0;
    }

    public static function tick(int $ms, callable $callback): int
    {
        return 0;
    }

    public static function clear(int $id): bool
    {
        return true;
    }
}

final class Coroutine
{
    public static function getCid(): int
    {
        return 0;
    }

    public static function create(callable $callback): int
    {
        return 0;
    }

    /** Runs the coroutine scheduler in a plain CLI process. */
    public static function run(callable $callback): ?bool
    {
        return true;
    }
}

final class Channel
{
    public function __construct(int $capacity = 1) {}

    public function push(mixed $data, float $timeout = -1): bool
    {
        return true;
    }

    public function pop(float $timeout = -1): mixed
    {
        return null;
    }

    public function isEmpty(): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }
}

final class Table
{
    public const TYPE_INT = 1;

    public const TYPE_STRING = 2;

    public const TYPE_FLOAT = 3;

    public function __construct(int $size, float $conflictProportion = 1.0) {}

    public function column(string $name, int $type, int $size = 0): bool
    {
        return true;
    }

    public function create(): bool
    {
        return true;
    }

    /**
     * @param array<string,int|float|string> $value
     */
    public function set(string $key, array $value): bool
    {
        return true;
    }

    /**
     * @return array<string,int|float|string>|string|int|float|bool
     */
    public function get(string $key, string $column = ''): array|string|int|float|bool
    {
        return false;
    }

    public function del(string $key): bool
    {
        return true;
    }

    public function incr(string $key, string $column, int $incrBy = 1): int
    {
        return 0;
    }
}

final class PostgreSQLStatement
{
    public ?string $error = null;

    public int $resultStatus = 0;

    /**
     * @param array<int|string,mixed> $params
     */
    public function execute(array $params = []): bool
    {
        return true;
    }

    /**
     * @return array<int,array<string,mixed>>|false
     */
    public function fetchAll(int $resultType = 1): array|false
    {
        return false;
    }
}

final class PostgreSQL
{
    public ?string $error = null;

    public int $resultStatus = 0;

    public function connect(string $conninfo, float $timeout = 2): bool
    {
        return true;
    }

    public function query(string $query): PostgreSQLStatement|false
    {
        return false;
    }

    public function prepare(string $query): PostgreSQLStatement|false
    {
        return false;
    }

    public function escape(string $string): string|false
    {
        return false;
    }

    public function status(): int|false
    {
        return 0;
    }

    public function reset(float $timeout = 0): bool
    {
        return true;
    }
}
