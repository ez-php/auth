<?php

declare(strict_types=1);

namespace Tests\Auth;

use EzPhp\Auth\Auth;
use EzPhp\Auth\AuthServiceProvider;
use EzPhp\Auth\UserInterface;
use EzPhp\Auth\UserProviderInterface;
use EzPhp\Contracts\ContainerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class AuthServiceProviderTest
 *
 * @package Tests\Auth
 */
#[CoversClass(AuthServiceProvider::class)]
#[UsesClass(Auth::class)]
final class AuthServiceProviderTest extends TestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Auth::resetInstance();
        parent::tearDown();
    }

    /**
     * Build a minimal container stub and register the provider against it.
     */
    private function makeBootedContainer(): ContainerInterface
    {
        $container = new class () implements ContainerInterface {
            /** @var array<string, callable> */
            private array $bindings = [];

            /** @var array<string, object> */
            private array $instances = [];

            public function bind(string $abstract, string|callable|null $factory = null): void
            {
                if (is_callable($factory)) {
                    $this->bindings[$abstract] = $factory;
                    unset($this->instances[$abstract]);
                }
            }

            public function instance(string $abstract, object $instance): void
            {
                $this->instances[$abstract] = $instance;
            }

            /**
             * @template T of object
             * @param class-string<T> $abstract
             * @return T
             */
            public function make(string $abstract): mixed
            {
                if (isset($this->instances[$abstract])) {
                    /** @var T */
                    return $this->instances[$abstract];
                }

                if (isset($this->bindings[$abstract])) {
                    /** @var T */
                    return $this->instances[$abstract] = ($this->bindings[$abstract])($this);
                }

                throw new \RuntimeException("No binding registered for {$abstract}.");
            }
        };

        $provider = new AuthServiceProvider($container);
        $provider->register();
        $provider->boot();

        return $container;
    }

    /**
     * @return void
     */
    public function test_auth_is_bound_in_container(): void
    {
        $container = $this->makeBootedContainer();

        $this->assertInstanceOf(Auth::class, $container->make(Auth::class));
    }

    /**
     * @return void
     */
    public function test_auth_check_returns_false_by_default(): void
    {
        $this->makeBootedContainer();

        $this->assertFalse(Auth::check());
    }

    /**
     * @return void
     */
    public function test_auth_static_methods_work_after_bootstrap(): void
    {
        $this->makeBootedContainer();

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

        $container = $this->makeBootedContainer();

        // Bind UserProviderInterface after register/boot, then resolve Auth.
        $container->bind(UserProviderInterface::class, static fn (): UserProviderInterface => $provider);
        $container->make(Auth::class);

        Auth::login($user);
        $this->assertSame($user, Auth::user());
    }
}
