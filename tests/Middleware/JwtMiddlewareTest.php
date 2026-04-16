<?php

declare(strict_types=1);

namespace Tests\Middleware;

use EzPhp\Auth\Auth;
use EzPhp\Auth\Jwt\JwtBlacklist;
use EzPhp\Auth\Jwt\JwtManager;
use EzPhp\Auth\Middleware\JwtMiddleware;
use EzPhp\Auth\UserInterface;
use EzPhp\Auth\UserProviderInterface;
use EzPhp\Cache\ArrayDriver;
use EzPhp\Http\Request;
use EzPhp\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class JwtMiddlewareTest
 *
 * @package Tests\Middleware
 */
#[CoversClass(JwtMiddleware::class)]
final class JwtMiddlewareTest extends TestCase
{
    private JwtManager $jwt;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Auth::resetInstance();
        $this->jwt = new JwtManager(secret: 'test-secret', ttl: 3600);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Auth::resetInstance();
        parent::tearDown();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param int|string $id
     *
     * @return UserInterface
     */
    private function makeUser(int|string $id): UserInterface
    {
        return new class ($id) implements UserInterface {
            /**
             * @param int|string $id
             */
            public function __construct(private readonly int|string $id)
            {
            }

            /**
             * @return int|string
             */
            public function getAuthId(): int|string
            {
                return $this->id;
            }

            /**
             * @return string
             */
            public function getAuthPassword(): string
            {
                return '';
            }
        };
    }

    /**
     * @param UserInterface|null $user  User returned by findById(), or null.
     *
     * @return UserProviderInterface
     */
    private function makeProvider(?UserInterface $user): UserProviderInterface
    {
        return new readonly class ($user) implements UserProviderInterface {
            /**
             * @param UserInterface|null $user
             */
            public function __construct(private ?UserInterface $user)
            {
            }

            /**
             * @param int|string $id
             *
             * @return UserInterface|null
             */
            public function findById(int|string $id): ?UserInterface
            {
                return $this->user;
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
    }

    /**
     * @param string $token
     *
     * @return Request
     */
    private function requestWithBearer(string $token): Request
    {
        return new Request('GET', '/', headers: ['authorization' => 'Bearer ' . $token]);
    }

    // ─── Missing / malformed Authorization header ──────────────────────────────

    /**
     * @return void
     */
    public function test_missing_authorization_header_returns_401(): void
    {
        $middleware = new JwtMiddleware($this->jwt);

        $response = $middleware->handle(
            new Request('GET', '/'),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(401, $response->status());
    }

    /**
     * @return void
     */
    public function test_non_bearer_scheme_returns_401(): void
    {
        $middleware = new JwtMiddleware($this->jwt);

        $response = $middleware->handle(
            new Request('GET', '/', headers: ['authorization' => 'Basic dXNlcjpwYXNz']),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(401, $response->status());
    }

    // ─── Invalid / expired token ───────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_invalid_token_returns_401(): void
    {
        $middleware = new JwtMiddleware($this->jwt);

        $response = $middleware->handle(
            $this->requestWithBearer('not.a.valid.jwt'),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(401, $response->status());
    }

    /**
     * @return void
     */
    public function test_expired_token_returns_401(): void
    {
        $expiredJwt = new JwtManager(secret: 'test-secret', ttl: -1);
        $token = $expiredJwt->issue(1);
        $middleware = new JwtMiddleware($this->jwt);

        $response = $middleware->handle(
            $this->requestWithBearer($token),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(401, $response->status());
    }

    /**
     * @return void
     */
    public function test_token_signed_with_wrong_secret_returns_401(): void
    {
        $other = new JwtManager(secret: 'other-secret', ttl: 3600);
        $token = $other->issue(1);
        $middleware = new JwtMiddleware($this->jwt);

        $response = $middleware->handle(
            $this->requestWithBearer($token),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(401, $response->status());
    }

    // ─── Blacklisted token ─────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_blacklisted_token_returns_401(): void
    {
        $blacklist = new JwtBlacklist(new ArrayDriver());
        $token = $this->jwt->issue(1);
        $claims = $this->jwt->validate($token);

        $exp = $claims['exp'];
        assert(is_int($exp));
        $blacklist->add($token, $exp);

        $middleware = new JwtMiddleware($this->jwt, $blacklist);

        $response = $middleware->handle(
            $this->requestWithBearer($token),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(401, $response->status());
    }

    /**
     * @return void
     */
    public function test_valid_token_not_on_blacklist_passes(): void
    {
        $blacklist = new JwtBlacklist(new ArrayDriver());
        $token = $this->jwt->issue(1);
        $middleware = new JwtMiddleware($this->jwt, $blacklist);

        $response = $middleware->handle(
            $this->requestWithBearer($token),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(200, $response->status());
    }

    // ─── User provider ─────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_valid_token_without_user_provider_calls_next(): void
    {
        $token = $this->jwt->issue(42);
        $middleware = new JwtMiddleware($this->jwt);

        $response = $middleware->handle(
            $this->requestWithBearer($token),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(200, $response->status());
        $this->assertFalse(Auth::check());
    }

    /**
     * @return void
     */
    public function test_valid_token_with_provider_logs_in_user(): void
    {
        $user = $this->makeUser(7);
        $provider = $this->makeProvider($user);
        $token = $this->jwt->issue(7);
        $middleware = new JwtMiddleware($this->jwt, userProvider: $provider);

        $response = $middleware->handle(
            $this->requestWithBearer($token),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(200, $response->status());
        $this->assertTrue(Auth::check());
        $this->assertSame($user, Auth::user());
    }

    /**
     * @return void
     */
    public function test_valid_token_provider_returns_null_gives_401(): void
    {
        $provider = $this->makeProvider(null);
        $token = $this->jwt->issue(99);
        $middleware = new JwtMiddleware($this->jwt, userProvider: $provider);

        $response = $middleware->handle(
            $this->requestWithBearer($token),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(401, $response->status());
        $this->assertFalse(Auth::check());
    }

    /**
     * @return void
     */
    public function test_next_is_not_called_when_unauthorized(): void
    {
        $middleware = new JwtMiddleware($this->jwt);
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
}
