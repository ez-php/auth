<?php

declare(strict_types=1);

namespace Tests\Auth;

use EzPhp\Application\Application;
use EzPhp\Auth\Auth;
use EzPhp\Auth\AuthServiceProvider;
use EzPhp\Auth\UserInterface;
use EzPhp\Auth\UserProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\ApplicationTestCase;

/**
 * Class AuthServiceProviderTest
 *
 * @package Tests\Auth
 */
#[CoversClass(AuthServiceProvider::class)]
#[UsesClass(Auth::class)]
final class AuthServiceProviderTest extends ApplicationTestCase
{
    /**
     * @return void
     */
    protected function configureApplication(Application $app): void
    {
        $app->register(AuthServiceProvider::class);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Auth::resetInstance();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function test_auth_is_bound_in_container(): void
    {
        $this->assertInstanceOf(Auth::class, $this->app()->make(Auth::class));
    }

    /**
     * @return void
     */
    public function test_auth_check_returns_false_by_default(): void
    {
        $this->app()->make(Auth::class);

        $this->assertFalse(Auth::check());
    }

    /**
     * @return void
     */
    public function test_auth_static_methods_work_after_bootstrap(): void
    {
        $this->app()->make(Auth::class);

        $user = new class () implements UserInterface {
            /**
             * @return int
             */
            public function getAuthId(): int
            {
                return 1;
            }
        };

        Auth::login($user);

        $this->assertTrue(Auth::check());
        $this->assertSame($user, Auth::user());
        $this->assertSame(1, Auth::id());

        Auth::logout();

        $this->assertFalse(Auth::check());
    }

    /**
     * Binds UserProviderInterface before the first make(Auth::class) call so
     * the lazy factory in AuthServiceProvider::register() resolves it and
     * injects it into the Auth instance.
     *
     * @return void
     */
    public function test_auth_uses_user_provider_when_bound(): void
    {
        $user = new class () implements UserInterface {
            /**
             * @return int
             */
            public function getAuthId(): int
            {
                return 42;
            }
        };

        $provider = new class ($user) implements UserProviderInterface {
            /**
             * @param UserInterface $u
             */
            public function __construct(private readonly UserInterface $u)
            {
            }

            /**
             * @param int|string $id
             *
             * @return UserInterface|null
             */
            public function findById(int|string $id): ?UserInterface
            {
                return $this->u->getAuthId() === $id ? $this->u : null;
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
        };

        // Bind before the first make(Auth::class); the binding factory resolves
        // UserProviderInterface lazily, so this ordering works even after bootstrap.
        $this->app()->bind(UserProviderInterface::class, static fn (): UserProviderInterface => $provider);
        $this->app()->make(Auth::class);

        Auth::login($user);
        $this->assertSame($user, Auth::user());
    }
}
