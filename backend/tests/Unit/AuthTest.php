<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Lib\Auth;
use App\Lib\HttpException;
use App\Rt\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    private string|false $previousSecret;

    protected function setUp(): void
    {
        $this->previousSecret = getenv('APP_SECRET');
        putenv('APP_SECRET=test-secret');
    }

    protected function tearDown(): void
    {
        putenv($this->previousSecret === false ? 'APP_SECRET' : 'APP_SECRET=' . $this->previousSecret);
    }

    private static function request(array $cookie = [], array $header = []): Request
    {
        $request = new Request();
        $request->cookie = $cookie;
        $request->header = $header;

        return $request;
    }

    public function testCsrfTokenIsDeterministicAndBoundToTheSession(): void
    {
        $first = Auth::csrfToken('session-a');
        self::assertSame($first, Auth::csrfToken('session-a'));
        self::assertNotSame($first, Auth::csrfToken('session-b'));
        self::assertSame(64, strlen($first));
    }

    public function testCsrfTokenDependsOnTheSecret(): void
    {
        $before = Auth::csrfToken('session-a');
        putenv('APP_SECRET=other-secret');
        self::assertNotSame($before, Auth::csrfToken('session-a'));
    }

    public function testRequireCsrfAcceptsMatchingHeader(): void
    {
        $token = 'session-a';
        Auth::requireCsrf(self::request(['kb_session' => $token], ['x-csrf-token' => Auth::csrfToken($token)]));
        self::assertTrue(true);
    }

    public function testRequireCsrfRejectsMissingHeader(): void
    {
        try {
            Auth::requireCsrf(self::request(['kb_session' => 'session-a']));
            self::fail('expected a rejection');
        } catch (HttpException $e) {
            // 403 and not 419: OpenSwoole has no 419 reason phrase and would
            // send an empty status line.
            self::assertSame(403, $e->status());
        }
    }

    public function testRequireCsrfRejectsWrongHeader(): void
    {
        $this->expectException(HttpException::class);
        Auth::requireCsrf(self::request(['kb_session' => 'session-a'], ['x-csrf-token' => 'nope']));
    }

    public function testRequireCsrfRejectsTokenOfAnotherSession(): void
    {
        $this->expectException(HttpException::class);
        Auth::requireCsrf(
            self::request(['kb_session' => 'session-a'], ['x-csrf-token' => Auth::csrfToken('session-b')]),
        );
    }

    public function testRequireCsrfSkipsAnonymousRequests(): void
    {
        // No session cookie: nothing ambient to protect, so the auth layer
        // answers 401 later instead.
        Auth::requireCsrf(self::request());
        self::assertTrue(true);
    }

    public function testRequireCsrfSkipsHeaderAuthenticatedClients(): void
    {
        // X-Auth-Token is not an ambient credential, so CSRF does not apply.
        Auth::requireCsrf(self::request([], ['x-auth-token' => 'raw-token']));
        self::assertTrue(true);
    }

    /**
     * @return array<string,array{0:?string,1:?string}>
     */
    public static function cookieProvider(): array
    {
        return [
            'single' => ['kb_session=abc', 'abc'],
            'with others' => ['theme=dark; kb_session=abc; kb_csrf=xyz', 'abc'],
            'url encoded' => ['kb_session=a%2Fb', 'a/b'],
            'missing' => ['kb_csrf=xyz', null],
            'empty value' => ['kb_session=', null],
            'no header' => [null, null],
            'empty header' => ['', null],
        ];
    }

    #[DataProvider('cookieProvider')]
    public function testTokenFromCookieHeader(?string $header, ?string $expected): void
    {
        self::assertSame($expected, Auth::tokenFromCookieHeader($header));
    }

    public function testTokenFromHandshakePrefersParsedCookie(): void
    {
        // OpenSwoole parses Cookie into $request->cookie and drops it from the
        // header map during the upgrade; both paths must resolve to a token.
        $parsed = self::request(['kb_session' => 'from-cookie']);
        self::assertSame('from-cookie', Auth::tokenFromHandshake($parsed));

        $raw = self::request([], ['cookie' => 'kb_session=from-header']);
        self::assertSame('from-header', Auth::tokenFromHandshake($raw));
    }

    public function testTokenFromHandshakeWithoutCookie(): void
    {
        self::assertNull(Auth::tokenFromHandshake(self::request()));
    }
}
