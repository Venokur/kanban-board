<?php

declare(strict_types=1);

namespace App\Lib;

use Swoole\Http\Request;

final class Auth
{
    public const COOKIE = 'kb_session';

    /**
     * Resolve the current user from the session cookie / token header.
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
     * @return array<string,mixed>|null
     */
    public static function resolve(string $token): ?array
    {
        return Db::queryOne(
            'SELECT u.id, u.email, u.name
               FROM sessions s
               JOIN users u ON u.id = s.user_id
              WHERE s.token = ' . Db::quote($token) . '
                AND s.expires_at > now()
              LIMIT 1'
        );
    }

    public static function userId(Request $request): int
    {
        return (int)self::currentUser($request)['id'];
    }

    public static function token(Request $request): ?string
    {
        $token = $request->cookie[self::COOKIE] ?? null;
        if (is_string($token) && $token !== '') {
            return $token;
        }
        $header = $request->header['x-auth-token'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }
        return null;
    }

    public static function issue(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        Db::exec(
            'INSERT INTO sessions (token, user_id, expires_at)
             VALUES (' . Db::quote($token) . ', ' . $userId . ", now() + interval '30 days')"
        );
        return $token;
    }

    public static function destroy(?string $token): void
    {
        if ($token !== null) {
            Db::exec('DELETE FROM sessions WHERE token = ' . Db::quote($token));
        }
    }
}
