<?php

declare(strict_types=1);

namespace Tests\Middleware;

use EzPhp\Auth\Auth;
use EzPhp\Auth\Middleware\AuthMiddleware;
use EzPhp\Auth\UserInterface;
use EzPhp\Auth\UserProviderInterface;
use EzPhp\Http\Request;
use EzPhp\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class AuthMiddlewareTest
 *
 * @package Tests\Middleware
 */
#[CoversClass(AuthMiddleware::class)]
#[UsesClass(Auth::class)]
final class AuthMiddlewareTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Auth::resetInstance();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Auth::resetInstance();
        parent::tearDown();
    }

    // ─── Static token list (existing behaviour) ───────────────────────────────

    /**
     * @return void
     */
    public function test_missing_authorization_header_returns_401(): void
    {
        $middleware = new AuthMiddleware();

        $response = $middleware->handle(
            new Request('GET', '/'),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(401, $response->status());
        $this->assertSame('Unauthorized', $response->body());
    }

    /**
     * @return void
     */
    public function test_non_bearer_scheme_returns_401(): void
    {
        $middleware = new AuthMiddleware();

        $response = $middleware->handle(
            new Request('GET', '/', headers: ['authorization' => 'Basic dXNlcjpwYXNz']),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(401, $response->status());
    }

    /**
     * @return void
     */
    public function test_valid_token_calls_next(): void
    {
        $middleware = new AuthMiddleware(['secret-token']);

        $response = $middleware->handle(
            new Request('GET', '/', headers: ['authorization' => 'Bearer secret-token']),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(200, $response->status());
        $this->assertSame('ok', $response->body());
    }

    /**
     * @return void
     */
    public function test_invalid_token_returns_401(): void
    {
        $middleware = new AuthMiddleware(['secret-token']);

        $response = $middleware->handle(
            new Request('GET', '/', headers: ['authorization' => 'Bearer wrong-token']),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(401, $response->status());
    }

    /**
     * @return void
     */
    public function test_empty_valid_tokens_accepts_any_bearer_token(): void
    {
        $middleware = new AuthMiddleware([]);

        $response = $middleware->handle(
            new Request('GET', '/', headers: ['authorization' => 'Bearer any-token']),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(200, $response->status());
    }

    /**
     * @return void
     */
    public function test_next_is_not_called_when_unauthorized(): void
    {
        $middleware = new AuthMiddleware(['valid']);
        $called = false;

        $middleware->handle(
            new Request('GET', '/'),
            function () use (&$called): Response {
                $called = true;
                return new Response('ok');
            },
        );

        $this->assertFalse($called);
    }

    // ─── UserProvider mode ────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_provider_returns_user_calls_auth_login_and_next(): void
    {
        $user = new class () implements UserInterface {
            /**
             * @return int
             */
            public function getAuthId(): int
            {
                return 1;
            }

            /**
             * @return string
             */
            public function getAuthPassword(): string
            {
                return '';
            }
        };
        $provider = new readonly class ($user) implements UserProviderInterface {
            /**
             * Constructor
             *
             * @param UserInterface $u
             */
            public function __construct(private UserInterface $u)
            {
            }

            /**
             * @param int|string $id
             *
             * @return UserInterface|null
             */
            public function findById(int|string $id): ?UserInterface
            {
                return null;
            }

            /**
             * @param string $token
             *
             * @return UserInterface|null
             */
            public function findByToken(string $token): ?UserInterface
            {
                return $token === 'valid-token' ? $this->u : null;
            }

            /**
             * @param string $identifier
             *
             * @return UserInterface|null
             */
            public function findByCredentials(string $identifier): ?UserInterface
            {
                return null;
            }

            /**
             * @param string $token
             *
             * @return UserInterface|null
             */
            public function findByRememberToken(string $token): ?UserInterface
            {
                return null;
            }
        };

        $middleware = new AuthMiddleware(userProvider: $provider);

        $response = $middleware->handle(
            new Request('GET', '/', headers: ['authorization' => 'Bearer valid-token']),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(200, $response->status());
        $this->assertTrue(Auth::check());
        $this->assertSame($user, Auth::user());
    }

    /**
     * @return void
     */
    public function test_provider_returns_null_gives_401(): void
    {
        $provider = new class () implements UserProviderInterface {
            /**
             * @param int|string $id
             *
             * @return UserInterface|null
             */
            public function findById(int|string $id): ?UserInterface
            {
                return null;
            }

            /**
             * @param string $token
             *
             * @return UserInterface|null
             */
            public function findByToken(string $token): ?UserInterface
            {
                return null;
            }

            /**
             * @param string $identifier
             *
             * @return UserInterface|null
             */
            public function findByCredentials(string $identifier): ?UserInterface
            {
                return null;
            }

            /**
             * @param string $token
             *
             * @return UserInterface|null
             */
            public function findByRememberToken(string $token): ?UserInterface
            {
                return null;
            }
        };

        $middleware = new AuthMiddleware(userProvider: $provider);

        $response = $middleware->handle(
            new Request('GET', '/', headers: ['authorization' => 'Bearer unknown-token']),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(401, $response->status());
        $this->assertFalse(Auth::check());
    }
}
