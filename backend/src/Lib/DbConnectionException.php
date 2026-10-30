<?php

declare(strict_types=1);

namespace App\Lib;

use RuntimeException;

/**
 * The connection itself is unusable (socket closed, server restarted, …).
 * Such a connection is never returned to the pool and the statement is retried
 * once on a fresh one.
 */
final class DbConnectionException extends RuntimeException
{
}
