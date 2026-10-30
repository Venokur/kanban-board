<?php

declare(strict_types=1);

/**
 * Coroutine runtime compatibility layer.
 *
 * The application only uses API that exists both in OpenSwoole (26.x) and in
 * Swoole 5/6 (HTTP + WebSocket server, coroutine channels, coroutine PostgreSQL
 * client, Table). Instead of hard-coding one fork everywhere, this file picks a
 * runtime once (SWOOLE_VARIANT=swoole|openswoole, default openswoole) and exposes
 * it under the `App\Rt\*` aliases.
 *
 * Switching forks therefore means flipping one environment variable and using
 * the matching extension build — no changes in application code.
 */

$variant = strtolower(trim((string)getenv('SWOOLE_VARIANT')));
$candidates = $variant === 'swoole'
    ? ['Swoole', 'OpenSwoole']
    : ['OpenSwoole', 'Swoole'];

$runtime = null;
foreach ($candidates as $namespace) {
    if (class_exists($namespace . '\\WebSocket\\Server', false)) {
        $runtime = $namespace;
        break;
    }
}

if ($runtime === null) {
    throw new RuntimeException(
        'Ни OpenSwoole, ни Swoole не загружены. Приложение должно запускаться в контейнере '
        . 'с расширением openswoole (по умолчанию) или swoole.'
    );
}

/** @var array<string,string> $aliases source class (без префикса) => App\Rt\* суффикс */
$aliases = [
    'WebSocket\\Server'     => 'WsServer',
    'WebSocket\\Frame'      => 'Frame',
    'Http\\Request'         => 'Request',
    'Http\\Response'        => 'Response',
    'Coroutine\\Channel'    => 'Channel',
    'Coroutine\\PostgreSQL' => 'PostgreSQL',
    'Coroutine'             => 'Coroutine',
    'Table'                 => 'Table',
    'Timer'                 => 'Timer',
];

foreach ($aliases as $source => $alias) {
    $class = $runtime . '\\' . $source;
    if (!class_exists('App\\Rt\\' . $alias, false) && class_exists($class, false)) {
        class_alias($class, 'App\\Rt\\' . $alias);
    }
}

// libpq result statuses, resolved once so Db does not have to guess.
$statuses = [
    'PGRES_BAD_RESPONSE'   => 5,
    'PGRES_NONFATAL_ERROR' => 6,
    'PGRES_FATAL_ERROR'    => 7,
];
foreach ($statuses as $name => $fallback) {
    $constant = $runtime . '\\Constant::' . $name;
    define('APP_RT_' . $name, defined($constant) ? constant($constant) : $fallback);
}
define('APP_RT_VARIANT', $runtime);

unset($variant, $candidates, $namespace, $aliases, $statuses, $name, $fallback, $constant);
