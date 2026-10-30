<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Lib\LabelRepo;
use PHPUnit\Framework\TestCase;

final class LabelRepoTest extends TestCase
{
    public function testAcceptsEveryPaletteKey(): void
    {
        foreach (LabelRepo::COLORS as $color) {
            self::assertTrue(LabelRepo::isColor($color), $color);
        }
    }

    public function testRejectsHexAndUnknownKeys(): void
    {
        // The column stores a palette key, not a colour value.
        self::assertFalse(LabelRepo::isColor('#ff0000'));
        self::assertFalse(LabelRepo::isColor('violet-500'));
        self::assertFalse(LabelRepo::isColor(''));
    }
}
