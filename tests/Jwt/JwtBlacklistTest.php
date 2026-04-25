<?php

declare(strict_types=1);

namespace Tests\Jwt;

use EzPhp\Auth\Jwt\JwtBlacklist;
use EzPhp\Cache\ArrayDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class JwtBlacklistTest
 *
 * @package Tests\Jwt
 */
#[CoversClass(JwtBlacklist::class)]
final class JwtBlacklistTest extends TestCase
{
    private JwtBlacklist $blacklist;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->blacklist = new JwtBlacklist(new ArrayDriver());
    }

    /**
     * @return void
     */
    public function test_unknown_token_is_not_blacklisted(): void
    {
        $this->assertFalse($this->blacklist->isBlacklisted('unknown-token'));
    }

    /**
     * @return void
     */
    public function test_add_and_check_blacklisted_token(): void
    {
        $token = 'some.jwt.token';
        $expiresAt = time() + 3600;

        $this->blacklist->add($token, $expiresAt);

        $this->assertTrue($this->blacklist->isBlacklisted($token));
    }

    /**
     * @return void
     */
    public function test_blacklist_uses_sha256_hash_not_raw_token(): void
    {
        $cache = new ArrayDriver();
        $blacklist = new JwtBlacklist($cache, 'jwt_bl:');
        $token = 'header.payload.sig';
        $expiresAt = time() + 3600;

        $blacklist->add($token, $expiresAt);

        // The raw token must not be stored as a cache key.
        $this->assertNull($cache->get('jwt_bl:' . $token));

        // The SHA-256 hash must be stored.
        $hashedKey = 'jwt_bl:' . hash('sha256', $token);
        $this->assertTrue($cache->has($hashedKey));
    }

    /**
     * @return void
     */
    public function test_different_tokens_are_tracked_independently(): void
    {
        $this->blacklist->add('token-a', time() + 3600);

        $this->assertTrue($this->blacklist->isBlacklisted('token-a'));
        $this->assertFalse($this->blacklist->isBlacklisted('token-b'));
    }

    /**
     * @return void
     */
    public function test_already_expired_token_uses_minimum_ttl_of_one(): void
    {
        // expiresAt in the past — TTL clamped to 1 so the entry is still stored (briefly).
        $this->blacklist->add('past-token', time() - 100);

        // The entry is set (even if it will expire in ~1s). Under ArrayDriver the
        // entry is stored with TTL=1; it is still readable immediately after storing.
        $this->assertTrue($this->blacklist->isBlacklisted('past-token'));
    }

    /**
     * @return void
     */
    public function test_custom_key_prefix(): void
    {
        $cache = new ArrayDriver();
        $blacklist = new JwtBlacklist($cache, 'app:bl:');
        $token = 'test.jwt';
        $expiresAt = time() + 60;

        $blacklist->add($token, $expiresAt);

        $this->assertTrue($cache->has('app:bl:' . hash('sha256', $token)));
    }
}
