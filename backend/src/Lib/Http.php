<?php

declare(strict_types=1);

namespace App\Lib;

use Swoole\Http\Request;

final class Http
{
    /**
     * Decode a JSON request body into an associative array.
     * @return array<string,mixed>
     */
    public static function body(Request $request): array
    {
        $raw = (string)$request->rawContent();
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new HttpException(400, 'Некорректный JSON');
        }
        return $data;
    }

    public static function requireString(array $body, string $key, int $maxLen = 1000, bool $trim = true): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new HttpException(422, 'Поле "' . $key . '" обязательно');
        }
        if ($trim) {
            $value = trim($value);
        }
        if ($value === '') {
            throw new HttpException(422, 'Поле "' . $key . '" не может быть пустым');
        }
        if (self::length($value) > $maxLen) {
            throw new HttpException(422, 'Поле "' . $key . '" слишком длинное');
        }
        return $value;
    }

    public static function optionalString(array $body, string $key, int $maxLen = 1000): string
    {
        $value = $body[$key] ?? null;
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new HttpException(422, 'Поле "' . $key . '" должно быть строкой');
        }
        if (self::length($value) > $maxLen) {
            throw new HttpException(422, 'Поле "' . $key . '" слишком длинное');
        }
        return trim($value);
    }

    public static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
