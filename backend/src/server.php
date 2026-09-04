<?php

declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\BoardRepo;
use App\Lib\Config;
use App\Lib\Db;
use App\Lib\Hub;
use App\Lib\HttpException;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WsServer;

require __DIR__ . '/autoload.php';

// ---------------------------------------------------------------- helpers

function sendJson(Response $response, int $status, mixed $data): void
{
    $response->status($status);
    $response->header('Content-Type', 'application/json; charset=utf-8');
    $response->end(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function sendError(Response $response, int $status, string $message): void
{
    sendJson($response, $status, ['error' => $message, 'code' => $status]);
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

function handleHttp(WsServer $server, Request $request, Response $response, callable $router): void
{
    $method = strtoupper((string)($request->server['request_method'] ?? 'GET'));
    $uri = (string)($request->server['request_uri'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }

    try {
        if ($path === '/api/health') {
            Db::query('SELECT 1');
            sendJson($response, 200, ['ok' => true]);
            return;
        }

        if (str_starts_with($path, '/api/')) {
            $data = $router($method, $path, $request, $response);
            sendJson($response, 200, $data);
            return;
        }

        // Everything else is the SPA (static assets are served by Swoole itself).
        $response->header('Content-Type', 'text/html; charset=utf-8');
        $response->end(indexHtml());
    } catch (HttpException $e) {
        sendError($response, $e->status(), $e->getMessage());
    } catch (Throwable $e) {
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
    $fd = $frame->fd;
    if (!Hub::isOnline($fd)) {
        return;
    }
    $data = json_decode((string)$frame->data, true);
    if (!is_array($data)) {
        return;
    }
    $type = $data['type'] ?? '';
    if ($type === 'ping') {
        Hub::push($fd, 'pong', ['t' => time()]);
    } elseif ($type === 'board:get') {
        Hub::push($fd, 'board:sync', BoardRepo::snapshot());
    }
}

// ---------------------------------------------------------------- server

$host = Config::get('APP_HOST', '0.0.0.0');
$port = (int)Config::get('APP_PORT', '9501');
$debug = Config::bool('APP_DEBUG', false);

$server = new WsServer($host, $port);

$server->set([
    'worker_num' => 1,
    'document_root' => '/var/www/html',
    'enable_static_handler' => true,
    'http_compression' => true,
    'heartbeat_idle_time' => 600,
    'heartbeat_check_interval' => 60,
]);

Hub::setServer($server);

$routes = (require __DIR__ . '/routes.php')();
$router = static function (string $method, string $path, Request $request, Response $response) use ($routes) {
    return $routes->dispatch($method, $path, $request, $response);
};

$server->on('Start', static function (WsServer $server) use ($host, $port): void {
    echo "Kanban board: http://{$host}:{$port} (pid {$server->master_pid})\n";
});

$server->on('Open', static function (WsServer $server, Request $request): void {
    $fd = (int)$request->fd;
    $token = $request->get['token'] ?? null;
    if (!is_string($token) || $token === '') {
        $server->disconnect($fd, 1008, 'No token');
        return;
    }
    try {
        $user = Auth::resolve($token);
    } catch (Throwable $e) {
        $user = null;
    }
    if ($user === null) {
        $server->disconnect($fd, 1008, 'Unauthorized');
        return;
    }
    Hub::bind($fd, (int)$user['id']);
    try {
        Hub::push($fd, 'board:sync', BoardRepo::snapshot());
    } catch (Throwable $e) {
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

echo "Starting swoole workers...\n";
$server->start();
