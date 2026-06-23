<?php

declare(strict_types=1);

namespace Tests\Auth;

use EzPhp\Application\Application;
use EzPhp\Auth\Jwt\JwtBlacklist;
use EzPhp\Auth\Jwt\JwtManager;
use EzPhp\Auth\JwtServiceProvider;
use EzPhp\Cache\ArrayDriver;
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
#[UsesClass(ArrayDriver::class)]
final class JwtServiceProviderTest extends ApplicationTestCase
{
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
     * Without a CacheInterface binding, JwtBlacklist falls back to an ArrayDriver
     * rather than throwing.
     *
     * @return void
     * @throws \ReflectionException
     */
    public function test_jwt_blacklist_is_bound_with_array_fallback(): void
    {
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
}
