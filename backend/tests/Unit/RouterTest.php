<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Lib\HttpException;
use App\Lib\Router;
use App\Rt\Request;
use App\Rt\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->add('GET', '/api/board', static fn (): string => 'board');
        $router->add('PUT', '/api/cards/{id}', static fn ($req, $res, $p): array => $p);
        $router->add('POST', '/api/cards/order', static fn (): string => 'order');

        return $router;
    }

    public function testDispatchMatchesStaticPath(): void
    {
        $result = $this->router()->dispatch('GET', '/api/board', new Request(), new Response());
        self::assertSame('board', $result);
    }

    public function testDispatchExtractsNumericParameters(): void
    {
        $result = $this->router()->dispatch('PUT', '/api/cards/42', new Request(), new Response());
        self::assertSame(['id' => 42], $result);
    }

    public function testNonNumericParameterDoesNotMatch(): void
    {
        $this->expectException(HttpException::class);
        $this->router()->dispatch('PUT', '/api/cards/abc', new Request(), new Response());
    }

    public function testLiteralRouteWinsOverParameterisedSibling(): void
    {
        // /api/cards/order must never be parsed as card id "order".
        $result = $this->router()->dispatch('POST', '/api/cards/order', new Request(), new Response());
        self::assertSame('order', $result);
    }

    public function testHeadIsServedByGetRoute(): void
    {
        $result = $this->router()->dispatch('HEAD', '/api/board', new Request(), new Response());
        self::assertSame('board', $result);
    }

    public function testWrongMethodOnKnownPathIs405(): void
    {
        try {
            $this->router()->dispatch('DELETE', '/api/board', new Request(), new Response());
            self::fail('expected HttpException');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status());
        }
    }

    public function testUnknownPathIs404(): void
    {
        try {
            $this->router()->dispatch('GET', '/api/unknown', new Request(), new Response());
            self::fail('expected HttpException');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status());
        }
    }

    /**
     * @return array<string,array{0:string,1:bool}>
     */
    public static function pathProvider(): array
    {
        return [
            'trailing slash' => ['/api/board/', false],
            'prefix only' => ['/api', false],
            'suffix' => ['/api/board/extra', false],
            'exact' => ['/api/board', true],
        ];
    }

    #[DataProvider('pathProvider')]
    public function testPathsAreMatchedAnchored(string $path, bool $shouldMatch): void
    {
        try {
            $this->router()->dispatch('GET', $path, new Request(), new Response());
            self::assertTrue($shouldMatch, 'path unexpectedly matched');
        } catch (HttpException $e) {
            self::assertFalse($shouldMatch, 'path unexpectedly rejected');
            self::assertSame(404, $e->status());
        }
    }
}
