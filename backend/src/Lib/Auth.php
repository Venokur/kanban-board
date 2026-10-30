<?php

declare(strict_types=1);

namespace App\Lib;

use App\Rt\Request;

final class Auth
{
    public const COOKIE = 'kb_session';
    public const CSRF_COOKIE = 'kb_csrf';
    public const SESSION_TTL = 2592000; // 30 days

    /**
     * Double-submit CSRF token: readable cookie + `X-CSRF-Token` header, both
     * derived from the session token so no extra state is stored.
     */
    public static function csrfToken(string $sessionToken): string
    {
        return hash_hmac('sha256', $sessionToken, Config::get('APP_SECRET', 'kanban-csrf-secret'));
    }

    /**
     * Mutating requests authenticated *by cookie* must echo the CSRF token.
     * Clients using the `X-Auth-Token` header are exempt (no ambient credentials).
     */
    public static function requireCsrf(Request $request): void
    {
        $cookieToken = $request->cookie[self::COOKIE] ?? null;
        $headerToken = $request->header['x-auth-token'] ?? null;
        if (!is_string($cookieToken) || $cookieToken === '' || $headerToken !== null) {
            return;
        }
        $expected = self::csrfToken($cookieToken);
        $provided = $request->header['x-csrf-token'] ?? null;
        if (!is_string($provided) || !hash_equals($expected, $provided)) {
            // 403, not 419: OpenSwoole's status table has no 419 reason phrase,
            // which sends an empty status line back to the client.
            throw new HttpException(403, 'Истекла CSRF-защита, обновите страницу');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function currentUser(Request $request): array
    {
        $token = self::token($request);
        if ($token === null) {
            throw new HttpException(401, 'Не авторизован');
        }
        $user = self::resolve($token);
        if ($user === null) {
            throw new HttpException(401, 'Сессия истекла, войдите снова');
        }
        return $user;
    }

    /**
     * Resolve a user from a session token.
     *
     * @return array<string,mixed>|null
     */
    public static function resolve(string $token): ?array
    {
        return Db::queryOne(
            'SELECT u.id, u.email, u.name
               FROM sessions s
               JOIN users u ON u.id = s.user_id
              WHERE s.token = ?
                AND s.expires_at > now()
              LIMIT 1',
            [$token],
        );
    }

    public static function userId(Request $request): int
    {
        return (int)self::currentUser($request)['id'];
    }

    public static function token(Request $request): ?string
    {
        $cookie = $request->cookie[self::COOKIE] ?? null;
        if (is_string($cookie) && $cookie !== '') {
            return $cookie;
        }
        $header = $request->header['x-auth-token'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }
        return null;
    }

    /**
     * Token from a WebSocket upgrade request. OpenSwoole parses the Cookie
     * header into `$request->cookie` before `on('Open')` runs and drops it from
     * `$request->header`, so the parsed map is the primary source and the raw
     * header is kept as a fallback for other runtimes/proxies.
     */
    public static function tokenFromHandshake(Request $request): ?string
    {
        $cookie = $request->cookie[self::COOKIE] ?? null;
        if (is_string($cookie) && $cookie !== '') {
            return $cookie;
        }
        $raw = $request->header['cookie'] ?? null;
        return self::tokenFromCookieHeader(is_string($raw) ? $raw : null);
    }

    /**
     * Token from a raw WebSocket handshake. Browsers send cookies on the
     * upgrade request, so no token has to leak into the URL or the proxy logs.
     */
    public static function tokenFromCookieHeader(?string $cookieHeader): ?string
    {
        if (!is_string($cookieHeader) || $cookieHeader === '') {
            return null;
        }
        foreach (explode(';', $cookieHeader) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2 && $pair[0] === self::COOKIE && $pair[1] !== '') {
                return urldecode($pair[1]);
            }
        }
        return null;
    }

    public static function issue(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        Db::exec(
            "INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, now() + interval '30 days')",
            [$token, $userId],
        );
        return $token;
    }

    public static function destroy(?string $token): void
    {
        if ($token !== null && $token !== '') {
            Db::exec('DELETE FROM sessions WHERE token = ?', [$token]);
        }
    }

    public static function destroyOtherSessions(int $userId, string $keepToken): void
    {
        Db::exec('DELETE FROM sessions WHERE user_id = ? AND token <> ?', [$userId, $keepToken]);
    }

    /** Housekeeping: drop expired rows (called from a server timer). */
    public static function pruneExpired(): int
    {
        $row = Db::queryOne(
            'WITH removed AS (DELETE FROM sessions WHERE expires_at <= now() RETURNING 1)
             SELECT count(*)::int AS n FROM removed',
        );
        return (int)($row['n'] ?? 0);
    }
}
