<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Config;
use App\Lib\Db;
use App\Lib\Http;
use App\Lib\HttpException;
use App\Lib\RateLimit;
use App\Rt\Request;
use App\Rt\Response;

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

        self::throttle('register|' . self::clientIp($request) . '|' . $email);

        $existing = Db::queryOne('SELECT id FROM users WHERE email = ?', [$email]);
        if ($existing !== null) {
            throw new HttpException(409, 'Пользователь с таким email уже существует');
        }

        $user = Db::queryOne(
            'INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)
             RETURNING id, email, name',
            [$email, password_hash($password, PASSWORD_DEFAULT), $name],
        );
        if ($user === null) {
            throw new HttpException(500, 'Не удалось создать пользователя');
        }

        return self::startSession($response, $user);
    }

    public static function login(Request $request, Response $response, array $params): array
    {
        $body = Http::body($request);
        $email = strtolower(Http::requireString($body, 'email', 254));
        $password = Http::requireString($body, 'password', 128, false);

        $key = 'login|' . self::clientIp($request) . '|' . $email;
        if (RateLimit::tooMany($key)) {
            throw new HttpException(429, 'Слишком много попыток. Повторите через '
                . RateLimit::retryAfter($key) . ' с.');
        }

        $user = Db::queryOne(
            'SELECT id, email, name, password_hash FROM users WHERE email = ?',
            [$email],
        );
        if ($user === null || !password_verify($password, (string)$user['password_hash'])) {
            RateLimit::hit($key);
            throw new HttpException(401, 'Неверный email или пароль');
        }

        RateLimit::clear($key);
        unset($user['password_hash']);
        return self::startSession($response, $user);
    }

    public static function logout(Request $request, Response $response, array $params): array
    {
        $token = Auth::token($request);
        Auth::destroy($token);
        self::clearCookie($response);
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
        self::setCookie($response, $token);

        $payload = [
            'csrf' => Auth::csrfToken($token),
            'user' => [
                'id' => $userId,
                'email' => (string)$user['email'],
                'name' => (string)$user['name'],
            ],
        ];

        // The session cookie is HttpOnly; the raw token is only echoed for
        // non-browser clients (curl, integration tests). Browsers never need it.
        if (Config::bool('APP_LEGACY_TOKEN', false)) {
            $payload['token'] = $token;
        }
        return $payload;
    }

    private static function setCookie(Response $response, string $token): void
    {
        $expires = time() + Auth::SESSION_TTL;
        // OpenSwoole's cookie() is positional (key, value, expire, path, domain,
        // secure, httpOnly, sameSite) — it does not accept setcookie()'s array.
        self::writeCookie($response, Auth::COOKIE, $token, $expires, true);
        self::writeCookie($response, Auth::CSRF_COOKIE, Auth::csrfToken($token), $expires, false);
    }

    private static function clearCookie(Response $response): void
    {
        $expires = time() - 3600;
        self::writeCookie($response, Auth::COOKIE, '', $expires, true);
        self::writeCookie($response, Auth::CSRF_COOKIE, '', $expires, false);
    }

    private static function writeCookie(
        Response $response,
        string $name,
        string $value,
        int $expires,
        bool $httpOnly,
    ): void {
        $response->cookie($name, $value, $expires, '/', '', self::isHttps(), $httpOnly, 'Lax');
    }

    private static function isHttps(): bool
    {
        $forwarded = getenv('APP_HTTPS');
        return $forwarded !== false && in_array(strtolower((string)$forwarded), ['1', 'true', 'yes', 'on'], true);
    }

    private static function clientIp(Request $request): string
    {
        $forwarded = $request->header['x-forwarded-for'] ?? null;
        if (is_string($forwarded) && $forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }
        $server = $request->server['remote_addr'] ?? '';
        return is_string($server) && $server !== '' ? $server : 'unknown';
    }

    private static function throttle(string $key): void
    {
        if (RateLimit::tooMany($key)) {
            throw new HttpException(429, 'Слишком много попыток. Повторите позже.');
        }
        RateLimit::hit($key);
    }
}
