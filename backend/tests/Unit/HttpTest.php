<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Lib\Http;
use App\Lib\HttpException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    public function testRequireStringTrimsAndReturnsValue(): void
    {
        self::assertSame('колонка', Http::requireString(['title' => '  колонка  '], 'title'));
    }

    public function testRequireStringRejectsMissing(): void
    {
        $this->expectException(HttpException::class);
        Http::requireString([], 'title');
    }

    public function testRequireStringRejectsEmpty(): void
    {
        $this->expectException(HttpException::class);
        Http::requireString(['title' => '   '], 'title');
    }

    public function testRequireStringRejectsNonString(): void
    {
        $this->expectException(HttpException::class);
        Http::requireString(['title' => 42], 'title');
    }

    public function testRequireStringCountsCharactersNotBytes(): void
    {
        // Five Cyrillic characters are 10 bytes; the limit is in characters.
        self::assertSame('привет', Http::requireString(['title' => 'привет'], 'title', 6));
    }

    public function testRequireStringRejectsOverlongValue(): void
    {
        $this->expectException(HttpException::class);
        Http::requireString(['title' => 'привет'], 'title', 5);
    }

    public function testOptionalStringDefaultsToEmpty(): void
    {
        self::assertSame('', Http::optionalString([], 'description'));
    }

    public function testOptionalStringKeepsNewlines(): void
    {
        self::assertSame("a\nb", Http::optionalString(['d' => "a\nb"], 'd'));
    }

    public function testOptionalStringRejectsWrongType(): void
    {
        $this->expectException(HttpException::class);
        Http::optionalString(['d' => []], 'd');
    }

    /**
     * @return array<string,array{0:mixed,1:bool}>
     */
    public static function intProvider(): array
    {
        return [
            'int' => [3, true],
            'numeric string' => ['3', true],
            'zero' => [0, true],
            'float' => [3.5, false],
            'word' => ['3a', false],
            'bool' => [true, false],
            'null' => [null, false],
            'out of range' => [9, false],
        ];
    }

    #[DataProvider('intProvider')]
    public function testRequireIntValidates(mixed $value, bool $valid): void
    {
        try {
            $result = Http::requireInt(['priority' => $value], 'priority', 0, 3);
            self::assertTrue($valid, 'unexpected success');
            self::assertSame((int)$value, $result);
        } catch (HttpException $e) {
            self::assertFalse($valid, 'unexpected rejection');
            self::assertSame(422, $e->status());
        }
    }

    public function testOptionalIntTreatsNullAndEmptyAsAbsent(): void
    {
        self::assertNull(Http::optionalInt(['wip' => null], 'wip', 1, 99));
        self::assertNull(Http::optionalInt(['wip' => ''], 'wip', 1, 99));
        self::assertSame(5, Http::optionalInt(['wip' => 5], 'wip', 1, 99));
    }

    public function testRequireBoolIsStrict(): void
    {
        self::assertTrue(Http::requireBool(['done' => true], 'done'));
        $this->expectException(HttpException::class);
        Http::requireBool(['done' => 1], 'done');
    }

    public function testRequireIntListDeduplicatesAndCasts(): void
    {
        $result = Http::requireIntList(['labelIds' => [3, '3', 5]], 'labelIds');
        self::assertSame([3, 5], array_values($result));
    }

    public function testRequireIntListRejectsNonNumericItems(): void
    {
        $this->expectException(HttpException::class);
        Http::requireIntList(['labelIds' => [1, 'x']], 'labelIds');
    }

    public function testRequireIntListRejectsScalar(): void
    {
        $this->expectException(HttpException::class);
        Http::requireIntList(['labelIds' => 1], 'labelIds');
    }
}
