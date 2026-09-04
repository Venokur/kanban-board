<?php

declare(strict_types=1);

namespace App\Lib;

final class Config
{
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
