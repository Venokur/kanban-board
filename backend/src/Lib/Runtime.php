<?php

declare(strict_types=1);

namespace App\Lib;

use App\Rt\Coroutine;

/**
 * Small helpers around the selected coroutine runtime (see src/rt.php).
 */
final class Runtime
{
    /** "OpenSwoole" or "Swoole" — for logs and diagnostics. */
    public static function variant(): string
    {
        return defined('APP_RT_VARIANT') ? (string)constant('APP_RT_VARIANT') : 'unknown';
    }

    public static function inCoroutine(): bool
    {
        return class_exists(Coroutine::class, false) && Coroutine::getCid() > 0;
    }

    /**
     * Run a callback in a coroutine. Timer callbacks and other non-coroutine
     * contexts (where Channel creation and Db I/O are not allowed) use this.
     */
    public static function spawn(callable $callback): void
    {
        if (self::inCoroutine()) {
            $callback();
            return;
        }
        Coroutine::create(static function () use ($callback): void {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log('[coroutine] ' . $e->getMessage());
            }
        });
    }
}
