<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Lib\Db;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Only the pure helpers are covered here: the query path needs a live
 * PostgreSQL connection and is exercised by the end-to-end suite instead.
 */
final class DbValueTest extends TestCase
{
    /**
     * @return array<string,array{0:mixed,1:string}>
     */
    public static function quoteProvider(): array
    {
        return [
            'null' => [null, 'NULL'],
            'true' => [true, 'true'],
            'false' => [false, 'false'],
            'int' => [42, '42'],
            'negative' => [-7, '-7'],
            'float' => [1.5, '1.5'],
            'string' => ['abc', "'abc'"],
            'quotes escaped' => ["O'Brien", "'O''Brien'"],
            'cyrillic' => ['доска', "'доска'"],
            'injection attempt' => ["'; DROP TABLE cards; --", "'''; DROP TABLE cards; --'"],
        ];
    }

    #[DataProvider('quoteProvider')]
    public function testQuote(mixed $value, string $expected): void
    {
        self::assertSame($expected, Db::quote($value));
    }

    public function testIdentQuotesAndEscapesDoubleQuotes(): void
    {
        self::assertSame('"cards"', Db::ident('cards'));
        self::assertSame('"we""ird"', Db::ident('we"ird'));
    }

    public function testPlaceholdersBuildsListAndCollectsParams(): void
    {
        $params = [];
        self::assertSame('?, ?, ?', Db::placeholders([1, 2, 3], $params));
        self::assertSame([1, 2, 3], $params);
    }

    public function testPlaceholdersOnEmptyListYieldsNull(): void
    {
        $params = [];
        self::assertSame('NULL', Db::placeholders([], $params));
        self::assertSame([], $params);
    }

    /**
     * @return array<string,array{0:mixed,1:mixed}>
     */
    public static function paramProvider(): array
    {
        return [
            'true' => [true, 'true'],
            'false' => [false, 'false'],
            'int unchanged' => [42, 42],
            'zero unchanged' => [0, 0],
            'null unchanged' => [null, null],
            'string unchanged' => ['abc', 'abc'],
            'empty string unchanged' => ['', ''],
            'float unchanged' => [1.5, 1.5],
        ];
    }

    /**
     * The driver turns PHP `false` into an empty string, which is not a valid
     * boolean literal — un-ticking a checklist item used to fail with a 500.
     */
    #[DataProvider('paramProvider')]
    public function testParamNormalisesBooleansOnly(mixed $value, mixed $expected): void
    {
        self::assertSame($expected, Db::param($value));
    }
}
