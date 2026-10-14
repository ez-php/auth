<?php

declare(strict_types=1);

namespace Tests\Auth;

use EzPhp\Application\Application;
use EzPhp\Auth\Jwt\JwtBlacklist;
use EzPhp\Auth\Jwt\JwtManager;
use EzPhp\Auth\JwtServiceProvider;
use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\CacheInterface;
use EzPhp\Exceptions\ContainerException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\ApplicationTestCase;

/**
 * Smoke test: JwtServiceProvider registers and boots its bindings in a minimal
 * application context without error.
 *
 * @package Tests\Auth
 */
#[CoversClass(JwtServiceProvider::class)]
#[UsesClass(JwtManager::class)]
#[UsesClass(JwtBlacklist::class)]
final class JwtServiceProviderTest extends ApplicationTestCase
{
    private string|false $previousSecret = false;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->previousSecret = getenv('JWT_SECRET');
        putenv('JWT_SECRET=' . str_repeat('k', JwtManager::MIN_SECRET_BYTES));
        parent::setUp();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        putenv($this->previousSecret === false ? 'JWT_SECRET' : 'JWT_SECRET=' . $this->previousSecret);
        parent::tearDown();
    }

    /**
     * @param Application $app
     *
     * @return void
     */
    protected function configureApplication(Application $app): void
    {
        $app->register(JwtServiceProvider::class);
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function test_jwt_manager_is_bound(): void
    {
        $this->assertInstanceOf(JwtManager::class, $this->app()->make(JwtManager::class));
    }

    /**
     * Resolving JwtBlacklist without a CacheInterface binding throws — there is no
     * silent fallback to a concrete cache driver (that would couple ez-php/auth to
     * ez-php/cache internals instead of the CacheInterface contract).
     *
     * @return void
     * @throws \ReflectionException
     */
    public function test_jwt_blacklist_throws_without_cache_binding(): void
    {
        $this->expectException(ContainerException::class);

        $this->app()->make(JwtBlacklist::class);
    }

    /**
     * With CacheInterface bound, JwtBlacklist resolves normally.
     *
     * @return void
     * @throws \ReflectionException
     */
    public function test_jwt_blacklist_is_bound_when_cache_interface_is_available(): void
    {
        $this->app()->bind(CacheInterface::class, fn (): ArrayDriver => new ArrayDriver());

        $this->assertInstanceOf(JwtBlacklist::class, $this->app()->make(JwtBlacklist::class));
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function test_bindings_are_singletons(): void
    {
        $this->assertSame(
            $this->app()->make(JwtManager::class),
            $this->app()->make(JwtManager::class),
        );
    }

    /**
     * An unset JWT_SECRET must not silently sign with an empty key — anyone could forge tokens.
     *
     * @return void
     * @throws \ReflectionException
     */
    public function test_jwt_manager_refuses_to_resolve_without_a_secret(): void
    {
        putenv('JWT_SECRET');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT_SECRET');

        $this->app()->make(JwtManager::class);
    }
}
