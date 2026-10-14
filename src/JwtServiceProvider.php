<?php

declare(strict_types=1);

namespace EzPhp\Auth;

use EzPhp\Auth\Jwt\JwtBlacklist;
use EzPhp\Auth\Jwt\JwtManager;
use EzPhp\Cache\CacheInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\ServiceProvider;

/**
 * Class JwtServiceProvider
 *
 * Registers JWT authentication services in the container.
 *
 * Bindings registered:
 *   - JwtManager   — reads JWT_SECRET and JWT_TTL from the environment.
 *   - JwtBlacklist — wraps CacheInterface; resolving it requires CacheInterface to be bound.
 *
 * Environment variables:
 *   JWT_SECRET  — HMAC-HS256 signing secret (required, at least 32 bytes; no default —
 *                 resolving JwtManager throws when it is missing or shorter).
 *   JWT_TTL     — Token lifetime in seconds (optional; default: 3600).
 *
 * Register CacheServiceProvider before this provider — JwtBlacklist depends on
 * CacheInterface directly (no fallback), so it is only actually constructed when
 * something resolves JwtBlacklist::class (e.g. wiring it into JwtMiddleware).
 * Applications that never use logout blacklisting never trigger this dependency.
 *
 * @package EzPhp\Auth
 */
final class JwtServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(JwtManager::class, function (): JwtManager {
            $secret = (string) (getenv('JWT_SECRET') ?: '');
            $ttl = (int) (getenv('JWT_TTL') ?: 3600);

            if (strlen($secret) < JwtManager::MIN_SECRET_BYTES) {
                throw new \InvalidArgumentException(
                    'JWT_SECRET must be set to at least ' . JwtManager::MIN_SECRET_BYTES . ' bytes '
                    . '(e.g. `bin2hex(random_bytes(32))`); refusing to sign tokens with a short or empty key.'
                );
            }

            return new JwtManager(secret: $secret, ttl: $ttl);
        });

        $this->app->bind(
            JwtBlacklist::class,
            fn (ContainerInterface $app): JwtBlacklist => new JwtBlacklist($app->make(CacheInterface::class)),
        );
    }

    /**
     * @return void
     */
    public function boot(): void
    {
        // JWT services are resolved lazily — nothing to boot.
    }
}
