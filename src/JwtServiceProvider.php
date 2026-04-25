<?php

declare(strict_types=1);

namespace EzPhp\Auth;

use EzPhp\Auth\Jwt\JwtBlacklist;
use EzPhp\Auth\Jwt\JwtManager;
use EzPhp\Cache\CacheInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\ServiceProvider;
use Throwable;

/**
 * Class JwtServiceProvider
 *
 * Registers JWT authentication services in the container.
 *
 * Bindings registered:
 *   - JwtManager   — reads JWT_SECRET and JWT_TTL from the environment.
 *   - JwtBlacklist — wraps CacheInterface; skipped silently if CacheInterface is not bound.
 *
 * Environment variables:
 *   JWT_SECRET  — HMAC-HS256 signing secret (required; no default).
 *   JWT_TTL     — Token lifetime in seconds (optional; default: 3600).
 *
 * Register this provider after CacheServiceProvider when logout blacklisting is required.
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

            return new JwtManager(secret: $secret, ttl: $ttl);
        });

        $this->app->bind(JwtBlacklist::class, function (ContainerInterface $app): JwtBlacklist {
            $cache = null;

            try {
                $cache = $app->make(CacheInterface::class);
            } catch (Throwable) {
                // CacheInterface not bound — JwtBlacklist will still be constructed
                // but cannot actually blacklist tokens. Applications that require
                // logout support must register CacheServiceProvider before JwtServiceProvider.
            }

            if ($cache === null) {
                // Fallback: ArrayDriver with no-op TTL behaviour so injection does not
                // throw. Tokens added to this blacklist survive only for the current
                // process and are not shared across requests.
                $cache = new \EzPhp\Cache\ArrayDriver();
            }

            return new JwtBlacklist($cache);
        });
    }

    /**
     * @return void
     */
    public function boot(): void
    {
        // JWT services are resolved lazily — nothing to boot.
    }
}
