<?php

declare(strict_types=1);

namespace EzPhp\Auth\Jwt;

use EzPhp\Cache\CacheInterface;

/**
 * Class JwtBlacklist
 *
 * Cache-backed token blacklist for JWT logout support.
 *
 * When a user logs out, the token is added to the blacklist with a TTL equal
 * to the token's remaining lifetime. Subsequent requests carrying the same
 * token are rejected even though the signature is valid.
 *
 * Key format: `<prefix><sha256(token)>` — the raw token is never stored.
 *
 * @package EzPhp\Auth\Jwt
 */
final class JwtBlacklist
{
    /**
     * JwtBlacklist Constructor
     *
     * @param CacheInterface $cache     Cache driver (Redis recommended for distributed systems).
     * @param string         $keyPrefix Cache key prefix; change if multiple apps share a cache.
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $keyPrefix = 'jwt_bl:',
    ) {
    }

    /**
     * Add a token to the blacklist.
     *
     * The entry is stored with TTL = remaining lifetime so the cache entry
     * self-expires when the token would have expired anyway.
     *
     * @param string $token     The raw JWT string to blacklist.
     * @param int    $expiresAt Unix timestamp when the token expires (the `exp` claim value).
     *
     * @return void
     */
    public function add(string $token, int $expiresAt): void
    {
        $ttl = max(1, $expiresAt - time());
        $this->cache->set($this->key($token), true, $ttl);
    }

    /**
     * Return true when the token has been blacklisted.
     *
     * @param string $token The raw JWT string to check.
     *
     * @return bool
     */
    public function isBlacklisted(string $token): bool
    {
        return $this->cache->has($this->key($token));
    }

    /**
     * Build the cache key for the given token.
     *
     * @param string $token
     *
     * @return string
     */
    private function key(string $token): string
    {
        return $this->keyPrefix . hash('sha256', $token);
    }
}
