<?php

declare(strict_types=1);

namespace App\Lib;

use App\Rt\Request;

final class Http
{
    /** Hard cap on request bodies so a single client cannot exhaust memory. */
    public const MAX_BODY = 1048576; // 1 MiB

    /**
     * @return array<string,mixed>
     */
    public static function body(Request $request): array
    {
        $raw = (string)$request->rawContent();
        if ($raw === '') {
            return [];
        }
        if (strlen($raw) > self::MAX_BODY) {
            throw new HttpException(413, 'Слишком большой запрос');
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

    public static function requireInt(array $body, string $key, int $min, int $max): int
    {
        $value = $body[$key] ?? null;
        if (!is_int($value) && !(is_string($value) && preg_match('~^-?\d+$~', $value) === 1)) {
            throw new HttpException(422, 'Поле "' . $key . '" должно быть целым числом');
        }
        $int = (int)$value;
        if ($int < $min || $int > $max) {
            throw new HttpException(422, 'Поле "' . $key . '" должно быть в диапазоне ' . $min . '…' . $max);
        }
        return $int;
    }

    public static function optionalInt(array $body, string $key, int $min, int $max): ?int
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        return self::requireInt($body, $key, $min, $max);
    }

    public static function requireBool(array $body, string $key): bool
    {
        $value = $body[$key] ?? null;
        if (!is_bool($value)) {
            throw new HttpException(422, 'Поле "' . $key . '" должно быть boolean');
        }
        return $value;
    }

    /** @return array<int,int> */
    public static function requireIntList(array $body, string $key): array
    {
        $value = $body[$key] ?? null;
        if (!is_array($value)) {
            throw new HttpException(422, 'Поле "' . $key . '" должно быть массивом');
        }
        foreach ($value as $item) {
            if (!is_int($item) && !(is_string($item) && ctype_digit($item))) {
                throw new HttpException(422, 'Поле "' . $key . '" должно содержать числа');
            }
        }
        return array_values(array_unique(array_map('intval', $value)));
    }

    public static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
