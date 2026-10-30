<?php

declare(strict_types=1);

namespace App\Lib;

final class Config
{
    /**
     * Shipped placeholder for APP_SECRET. Signing CSRF tokens with a value that
     * is in the repository makes them forgeable, so the login page warns while
     * either of these is in use.
     */
    public const DEFAULT_SECRET = 'kanban-csrf-secret';
    private const PLACEHOLDER_SECRET = 'change-me-please';

    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === '') {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * The demo account can be switched off without touching the seeded board:
     * the account stays in the database, it just cannot be used to sign in.
     * Default is on so an existing install keeps working after an upgrade.
     */
    public static function demoEnabled(): bool
    {
        return self::bool('APP_DEMO', true);
    }

    /**
     * Single source of truth for the CSRF signing key, so `Auth::csrfToken()`
     * and the "secret was never changed" check can never disagree.
     */
    public static function appSecret(): string
    {
        $value = self::get('APP_SECRET');
        return $value === '' ? self::DEFAULT_SECRET : $value;
    }

    /**
     * True while APP_SECRET is unset or still one of the values shipped in the
     * repository (the compose fallback and the .env.example placeholder).
     */
    public static function appSecretIsDefault(): bool
    {
        $secret = self::appSecret();
        return $secret === self::DEFAULT_SECRET || $secret === self::PLACEHOLDER_SECRET;
    }

    /**
     * @return array{host:string,port:string,dbname:string,user:string,password:string}
     */
    public static function db(): array
    {
        return [
            'host'     => self::get('DB_HOST', 'db'),
            'port'     => self::get('DB_PORT', '5432'),
            'dbname'   => self::get('DB_NAME', 'kanban'),
            'user'     => self::get('DB_USER', 'kanban'),
            'password' => self::get('DB_PASSWORD', 'kanban'),
        ];
    }
}
