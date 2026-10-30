<?php

declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\BoardRepo;
use App\Lib\Config;
use App\Lib\Db;
use App\Lib\HttpException;
use App\Lib\Hub;
use App\Lib\Runtime;
use App\Rt\Frame;
use App\Rt\Request;
use App\Rt\Response;
use App\Rt\WsServer;

require __DIR__ . '/rt.php';
require __DIR__ . '/autoload.php';

// ---------------------------------------------------------------- helpers

function sendJson(Response $response, int $status, mixed $data): void
{
    $response->header('Content-Type', 'application/json; charset=utf-8');
    $response->status($status);
    $response->end(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function sendError(Response $response, int $status, string $message): void
{
    sendJson($response, $status, ['error' => $message, 'code' => $status]);
}

/**
 * Security headers for every response (static assets included).
 */
function applySecurityHeaders(Response $response): void
{
    $response->header('X-Content-Type-Options', 'nosniff');
    $response->header('X-Frame-Options', 'DENY');
    $response->header('Referrer-Policy', 'same-origin');
    $response->header('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
    $response->header(
        'Content-Security-Policy',
        "default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; "
        . "script-src 'self'; connect-src 'self' ws: wss:; font-src 'self' data:; "
        . "object-src 'none'; base-uri 'self'; frame-ancestors 'none'",
    );
}

function indexHtml(): string
{
    static $html = null;
    if ($html === null) {
        $file = '/var/www/html/index.html';
        $html = is_file($file) ? (string)file_get_contents($file) : 'Frontend not built.';
    }
    return $html;
}

function isStaticAsset(string $path): bool
{
    return str_starts_with($path, '/assets/');
}

function handleHttp(WsServer $server, Request $request, Response $response, callable $router): void
{
    $method = strtoupper((string)($request->server['request_method'] ?? 'GET'));
    $uri = (string)($request->server['request_uri'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }

    applySecurityHeaders($response);

    try {
        if ($path === '/api/health') {
            Db::query('SELECT 1');
            sendJson($response, 200, ['ok' => true, 'runtime' => Runtime::variant()]);
            return;
        }

        if (str_starts_with($path, '/api/')) {
            if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
                Auth::requireCsrf($request);
            }
            $data = $router($method, $path, $request, $response);
            sendJson($response, 200, $data);
            return;
        }

        // Hashed Vite assets are immutable; a missing one must 404, not return the SPA.
        if (isStaticAsset($path)) {
            $response->header('Cache-Control', 'public, max-age=31536000, immutable');
            $response->status(404);
            $response->end('Not found');
            return;
        }

        // Everything else is the SPA shell — never cached, so deploys pick up.
        $response->header('Content-Type', 'text/html; charset=utf-8');
        $response->header('Cache-Control', 'no-cache');
        $response->end(indexHtml());
    } catch (HttpException $e) {
        sendError($response, $e->status(), $e->getMessage());
    } catch (\Throwable $e) {
        if (Config::bool('APP_DEBUG', false)) {
            error_log((string)$e);
        } else {
            error_log('[http] ' . $e->getMessage());
        }
        sendError($response, 500, 'Внутренняя ошибка сервера');
    }
}

function handleWsMessage(WsServer $server, Frame $frame): void
{
    $fd = (int)$frame->fd;
    if (!Hub::isOnline($fd)) {
        return;
    }
    $payload = json_decode((string)$frame->data, true);
    if (!is_array($payload)) {
        return;
    }
    $type = $payload['type'] ?? '';

    if ($type === 'ping') {
        Hub::push($fd, 'pong', ['t' => time()]);
        return;
    }
    if ($type === 'board:get') {
        Hub::push($fd, 'board:sync', BoardRepo::snapshot());
        return;
    }
    if ($type === 'presence:watch') {
        $cardId = $payload['cardId'] ?? null;
        Hub::touch($fd, is_int($cardId) ? $cardId : null);
        Hub::broadcastPresence();
    }
}

// ---------------------------------------------------------------- server

$host = Config::get('APP_HOST', '0.0.0.0');
$port = (int)Config::get('APP_PORT', '9501');

$server = new WsServer($host, $port);

$server->set([
    // Single worker is load-bearing: Hub::$sessions and the Db pool live in this
    // worker's memory. See AGENTS.md before raising it.
    'worker_num' => 1,
    'document_root' => '/var/www/html',
    'enable_static_handler' => true,
    'http_compression' => true,
    'heartbeat_idle_time' => 600,
    'heartbeat_check_interval' => 60,
    'max_request' => 0,
    'package_max_length' => 2 * 1048576,
]);

Hub::setServer($server);

$routes = (require __DIR__ . '/routes.php')();
$router = static function (string $method, string $path, Request $request, Response $response) use ($routes) {
    return $routes->dispatch($method, $path, $request, $response);
};

$server->on('Start', static function (WsServer $server) use ($host, $port): void {
    echo sprintf(
        "Kanban board: http://%s:%d (pid %d, runtime %s)\n",
        $host,
        $port,
        $server->master_pid,
        Runtime::variant(),
    );
});

$server->on('WorkerStart', static function (WsServer $server): void {
    // Housekeeping: expired sessions must not accumulate forever.
    $server->tick(3600 * 1000, static function (): void {
        Runtime::spawn(static function (): void {
            try {
                $removed = Auth::pruneExpired();
                if ($removed > 0) {
                    error_log('[auth] pruned ' . $removed . ' expired sessions');
                }
            } catch (\Throwable $e) {
                error_log('[auth] prune failed: ' . $e->getMessage());
            }
        });
    });
});

$server->on('Open', static function (WsServer $server, Request $request): void {
    $fd = (int)$request->fd;

    // Browsers send the HttpOnly session cookie on the upgrade request, so the
    // token never has to appear in the URL (or in proxy access logs).
    // OpenSwoole parses Cookie into $request->cookie during the handshake and
    // does not keep it in $request->header, so both sources are checked.
    $token = Auth::tokenFromHandshake($request);
    if ($token === null && Config::bool('APP_WS_QUERY_TOKEN', false)) {
        $query = $request->get['token'] ?? null;
        $token = is_string($query) && $query !== '' ? $query : null;
    }

    if ($token === null) {
        $server->disconnect($fd, 1008, 'No session');
        return;
    }

    try {
        $user = Auth::resolve($token);
    } catch (\Throwable $e) {
        $user = null;
    }
    if ($user === null) {
        $server->disconnect($fd, 1008, 'Unauthorized');
        return;
    }

    Hub::bind($fd, (int)$user['id']);
    Hub::broadcastPresence();
    try {
        Hub::push($fd, 'board:sync', BoardRepo::snapshot());
    } catch (\Throwable $e) {
        error_log('[open] snapshot failed: ' . $e->getMessage());
    }
});

$server->on('Message', static function (WsServer $server, Frame $frame): void {
    handleWsMessage($server, $frame);
});

$server->on('Close', static function (WsServer $server, int $fd): void {
    Hub::unbind($fd);
});

$server->on('Disconnect', static function (WsServer $server, int $fd): void {
    Hub::unbind($fd);
});

$server->on('Request', static function (Request $request, Response $response) use ($server, $router): void {
    handleHttp($server, $request, $response, $router);
});

echo "Starting coroutine workers (runtime: " . Runtime::variant() . ")...\n";
$server->start();
