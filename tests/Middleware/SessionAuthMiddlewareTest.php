<?php

declare(strict_types=1);

namespace Tests\Middleware;

use EzPhp\Auth\Auth;
use EzPhp\Auth\Middleware\SessionAuthMiddleware;
use EzPhp\Auth\UserInterface;
use EzPhp\Http\Request;
use EzPhp\Http\RequestInterface;
use EzPhp\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * @package Tests\Middleware
 */
#[CoversClass(SessionAuthMiddleware::class)]
#[UsesClass(Auth::class)]
final class SessionAuthMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Auth::resetInstance();
        Auth::setInstance(new Auth(null));
    }

    protected function tearDown(): void
    {
        Auth::resetInstance();
        parent::tearDown();
    }

    private function user(): UserInterface
    {
        return new class () implements UserInterface {
            public function getAuthId(): int
            {
                return 7;
            }

            public function getAuthPassword(): string
            {
                return '';
            }
        };
    }

    public function test_logged_in_user_passes_through(): void
    {
        Auth::login($this->user());

        $response = (new SessionAuthMiddleware())->handle(new Request('GET', '/me'), static fn (): Response => new Response('me'));

        $this->assertSame(200, $response->status());
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('me', $response->body());
    }

    public function test_guest_gets_plain_401_and_next_is_not_called(): void
    {
        $called = false;

        $response = (new SessionAuthMiddleware())->handle(
            new Request('GET', '/me'),
            static function () use (&$called): Response {
                $called = true;

                return new Response('me');
            },
        );

        $this->assertFalse($called);
        $this->assertSame(401, $response->status());
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('Unauthenticated.', $response->body());
    }

    public function test_json_client_gets_the_error_envelope(): void
    {
        $request = new Request('GET', '/api/me', headers: ['Accept' => 'application/json']);

        $response = (new SessionAuthMiddleware())->handle($request, static fn (): Response => new Response('me'));

        $this->assertSame(401, $response->status());
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('{"error":{"code":401,"message":"Unauthenticated."}}', $response->body());
        $this->assertSame('application/json', $response->headers()['Content-Type'] ?? null);
    }

    public function test_custom_unauthenticated_response(): void
    {
        $middleware = new SessionAuthMiddleware(
            static fn (RequestInterface $r): Response => (new Response('', 302))->withHeader('Location', '/login?next=' . $r->uri()),
        );

        $response = $middleware->handle(new Request('GET', '/dashboard'), static fn (): Response => new Response('x'));

        $this->assertSame(302, $response->status());
        $this->assertSame('/login?next=/dashboard', $response->headers()['Location'] ?? null);
    }
}
