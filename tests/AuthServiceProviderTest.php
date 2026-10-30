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
     * @param Application $app
     *
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
     * @throws \ReflectionException
     */
    public function test_auth_is_bound_in_container(): void
    {
        $this->assertInstanceOf(Auth::class, $this->app()->make(Auth::class));
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function test_auth_check_returns_false_by_default(): void
    {
        $this->app()->make(Auth::class);

        $this->assertFalse(Auth::check());
    }

    /**
     * @return void
     * @throws \ReflectionException
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

            /**
             * @return string
             */
            public function getAuthPassword(): string
            {
                return '';
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
     * @throws \ReflectionException
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

        // Bind before the first make(Auth::class); the binding factory resolves
        // UserProviderInterface lazily, so this ordering works even after bootstrap.
        $this->app()->bind(UserProviderInterface::class, static fn (): UserProviderInterface => $provider);
        $this->app()->make(Auth::class);

        Auth::login($user);
        $this->assertSame($user, Auth::user());
    }

    /**
     * A bound provider that fails to construct must not be taken for "not bound":
     * a provider-less Auth would answer every guarded route with 401 and log nothing.
     *
     * @return void
     */
    public function test_a_bound_but_failing_user_provider_surfaces_its_error(): void
    {
        $basePath = sys_get_temp_dir() . '/ez-php-auth-provider-' . uniqid('', true);
        mkdir($basePath . '/config', 0o777, true);

        try {
            $app = new Application($basePath);
            $app->register(AuthServiceProviderFailingUserProvider::class);
            $app->register(AuthServiceProvider::class);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('users database unreachable');

            // boot() resolves Auth for the façade, which resolves the provider.
            $app->bootstrap();
        } finally {
            @rmdir($basePath . '/config');
            @rmdir($basePath);
        }
    }
}

/**
 * Binds a UserProviderInterface whose construction fails, like one whose
 * database connection is down.
 */
final class AuthServiceProviderFailingUserProvider extends \EzPhp\Contracts\ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(UserProviderInterface::class, static function (): UserProviderInterface {
            throw new \RuntimeException('users database unreachable');
        });
    }
}
