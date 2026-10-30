<?php

declare(strict_types=1);

namespace App\Lib;

use App\Rt\Table;

/**
 * Fixed-window rate limiter backed by a shared memory table (per worker).
 * Used for login/registration so credential stuffing cannot hammer bcrypt.
 */
final class RateLimit
{
    private const LIMIT = 5;
    private const WINDOW = 60;

    private static ?Table $table = null;

    private static function table(): Table
    {
        if (self::$table === null) {
            // column size must be a multiple of 8 for atomic add
            $table = new Table(2048, 8);
            $table->column('hits', Table::TYPE_INT, 4);
            $table->column('reset_at', Table::TYPE_INT, 4);
            $table->create();
            self::$table = $table;
        }
        return self::$table;
    }

    /**
     * Register a failed attempt. Returns true when the caller is over the limit.
     */
    public static function hit(string $key): bool
    {
        $table = self::table();
        $id = self::key($key);
        $row = $table->get($id);
        $now = time();

        if ($row === false || (int)$row['reset_at'] <= $now) {
            $table->set($id, ['hits' => 1, 'reset_at' => $now + self::WINDOW]);
            // A fresh window always starts under the limit.
            return false;
        }

        $table->incr($id, 'hits');
        return (int)$table->get($id, 'hits') > self::LIMIT;
    }

    public static function tooMany(string $key): bool
    {
        $row = self::table()->get(self::key($key));
        if ($row === false) {
            return false;
        }
        if ((int)$row['reset_at'] <= time()) {
            return false;
        }
        return (int)$row['hits'] >= self::LIMIT;
    }

    public static function retryAfter(string $key): int
    {
        $row = self::table()->get(self::key($key));
        if ($row === false) {
            return 0;
        }
        return max(0, (int)$row['reset_at'] - time());
    }

    public static function clear(string $key): void
    {
        self::table()->del(self::key($key));
    }

    private static function key(string $key): string
    {
        return substr(md5($key), 0, 24);
    }
}
