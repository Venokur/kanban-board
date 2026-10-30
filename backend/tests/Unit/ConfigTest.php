<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Lib\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['TEST_STR', 'TEST_BOOL'] as $key) {
            $this->saved[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    public function testGetFallsBackToDefault(): void
    {
        putenv('TEST_STR');
        self::assertSame('fallback', Config::get('TEST_STR', 'fallback'));
        self::assertSame('', Config::get('TEST_STR'));
    }

    public function testGetReadsEnvironment(): void
    {
        putenv('TEST_STR=value');
        self::assertSame('value', Config::get('TEST_STR', 'fallback'));
    }

    public function testBoolAcceptsCommonTruthyValues(): void
    {
        foreach (['1', 'true', 'TRUE', 'yes', 'on'] as $value) {
            putenv('TEST_BOOL=' . $value);
            self::assertTrue(Config::bool('TEST_BOOL'), $value);
        }
    }

    public function testBoolTreatsEverythingElseAsFalse(): void
    {
        foreach (['0', 'false', 'no', 'off', 'random'] as $value) {
            putenv('TEST_BOOL=' . $value);
            self::assertFalse(Config::bool('TEST_BOOL'), $value);
        }
    }

    public function testBoolUsesDefaultForEmptyValue(): void
    {
        putenv('TEST_BOOL=');
        self::assertTrue(Config::bool('TEST_BOOL', true));
        self::assertFalse(Config::bool('TEST_BOOL'));
    }

    public function testDbHasAllConnectionKeys(): void
    {
        $db = Config::db();
        foreach (['host', 'port', 'dbname', 'user', 'password'] as $key) {
            self::assertArrayHasKey($key, $db);
            self::assertIsString($db[$key]);
        }
    }
}
