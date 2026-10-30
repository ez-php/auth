<?php

declare(strict_types=1);

namespace EzPhp\Auth;

use EzPhp\Auth\Jwt\JwtBlacklist;
use EzPhp\Auth\Jwt\JwtManager;
use EzPhp\Cache\CacheInterface;
use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\ServiceProvider;

/**
 * Class JwtServiceProvider
 *
 * Registers JWT authentication services in the container.
 *
 * Bindings registered:
 *   - JwtManager   — reads auth.jwt.secret / auth.jwt.ttl from config, falling back to the
 *                    JWT_SECRET / JWT_TTL environment variables when a key is unset or empty
 *                    (applications created before config/auth.php existed).
 *   - JwtBlacklist — wraps CacheInterface; resolving it requires CacheInterface to be bound.
 *
 * Environment variables (the template's config/auth.php reads the same ones):
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
        $this->app->bind(JwtManager::class, function (ContainerInterface $app): JwtManager {
            $config = $app->has(ConfigInterface::class) ? $app->make(ConfigInterface::class) : null;
            $secret = self::configured($config?->get('auth.jwt.secret'), getenv('JWT_SECRET'));
            $ttl = (int) (self::configured($config?->get('auth.jwt.ttl'), getenv('JWT_TTL')) ?: 3600);

            if (strlen($secret) < JwtManager::MIN_SECRET_BYTES) {
                throw new \InvalidArgumentException(
                    'auth.jwt.secret (JWT_SECRET) must be set to at least ' . JwtManager::MIN_SECRET_BYTES . ' bytes '
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

    /**
     * The config value when it is a non-empty string or a positive int, else the
     * environment value (getenv() returns false when unset).
     */
    private static function configured(mixed $value, string|false $env): string
    {
        if ((is_string($value) && $value !== '') || (is_int($value) && $value > 0)) {
            return (string) $value;
        }

        return $env === false ? '' : $env;
    }
}
