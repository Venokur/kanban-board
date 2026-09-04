<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Db;
use App\Lib\Http;
use App\Lib\HttpException;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class AuthController
{
    public static function register(Request $request, Response $response, array $params): array
    {
        $body = Http::body($request);
        $name = Http::requireString($body, 'name', 80);
        $email = strtolower(Http::requireString($body, 'email', 254));
        $password = Http::requireString($body, 'password', 128, false);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'Некорректный email');
        }
        if (Http::length($password) < 6) {
            throw new HttpException(422, 'Пароль должен быть не короче 6 символов');
        }

        $existing = Db::queryOne('SELECT id FROM users WHERE email = ' . Db::quote($email));
        if ($existing !== null) {
            throw new HttpException(409, 'Пользователь с таким email уже существует');
        }

        Db::exec(
            'INSERT INTO users (email, password_hash, name)
             VALUES (' . Db::quote($email) . ', ' . Db::quote(password_hash($password, PASSWORD_DEFAULT))
            . ', ' . Db::quote($name) . ')'
        );
        $user = Db::queryOne('SELECT id, email, name FROM users WHERE email = ' . Db::quote($email));

        return self::startSession($response, $user);
    }

    public static function login(Request $request, Response $response, array $params): array
    {
        $body = Http::body($request);
        $email = strtolower(Http::requireString($body, 'email', 254));
        $password = Http::requireString($body, 'password', 128, false);

        $user = Db::queryOne('SELECT id, email, name, password_hash FROM users WHERE email = ' . Db::quote($email));
        if ($user === null || !password_verify($password, (string)$user['password_hash'])) {
            throw new HttpException(401, 'Неверный email или пароль');
        }

        unset($user['password_hash']);
        return self::startSession($response, $user);
    }

    public static function logout(Request $request, Response $response, array $params): array
    {
        $token = Auth::token($request);
        Auth::destroy($token);
        $response->cookie(Auth::COOKIE, '', time() - 3600, '/');
        return ['ok' => true];
    }

    public static function me(Request $request, Response $response, array $params): array
    {
        return ['user' => Auth::currentUser($request)];
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private static function startSession(Response $response, array $user): array
    {
        $userId = (int)$user['id'];
        $token = Auth::issue($userId);
        $response->cookie(Auth::COOKIE, $token, time() + 30 * 86400, '/');

        return [
            'token' => $token,
            'user' => [
                'id' => $userId,
                'email' => (string)$user['email'],
                'name' => (string)$user['name'],
            ],
        ];
    }
}
