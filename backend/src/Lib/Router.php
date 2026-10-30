<?php

declare(strict_types=1);

namespace App\Lib;

use App\Rt\Request;
use App\Rt\Response;

final class Router
{
    /** @var array<int,array{m:string,re:string,handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '~^' . preg_replace('~\{(\w+)\}~', '(?P<$1>\d+)', $pattern) . '$~D';
        $this->routes[] = [
            'm' => strtoupper($method),
            're' => $regex,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $path, Request $request, Response $response): mixed
    {
        $method = strtoupper($method);
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['re'], $path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['m'] !== $method && !($method === 'HEAD' && $route['m'] === 'GET')) {
                continue;
            }
            $params = [];
            foreach ($matches as $key => $value) {
                if (!is_int($key)) {
                    $params[$key] = (int)$value;
                }
            }
            return call_user_func($route['handler'], $request, $response, $params);
        }

        if ($pathMatched) {
            throw new HttpException(405, 'Метод не поддерживается');
        }
        throw new HttpException(404, 'Не найдено');
    }
}
