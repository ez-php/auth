<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Auth\PersonalAccessToken;
use EzPhp\Auth\PersonalAccessTokenManager;
use EzPhp\Database\Database;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Class PersonalAccessTokenManagerTest
 *
 * Uses an in-memory SQLite database (via ez-php/framework's Database, pulled
 * in transitively through ez-php/testing-application) rather than the full
 * Application bootstrap — this module has no direct dependency on
 * ez-php/framework, so the lighter-weight construction mirrors the schema
 * from modules/auth/database/migrations/ directly.
 *
 * @package Tests
 */
#[CoversClass(PersonalAccessTokenManager::class)]
#[UsesClass(PersonalAccessToken::class)]
#[UsesClass(Database::class)]
final class PersonalAccessTokenManagerTest extends TestCase
{
    private Database $db;

    private PersonalAccessTokenManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Database('sqlite::memory:', '', '');
        $this->db->getPdo()->exec(
            'CREATE TABLE personal_access_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                abilities TEXT NOT NULL DEFAULT "*",
                last_used_at TIMESTAMP NULL,
                expires_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL
            )'
        );

        $this->manager = new PersonalAccessTokenManager($this->db);
    }

    public function test_create_returns_raw_token_and_record(): void
    {
        [$rawToken, $token] = $this->manager->create(userId: 1, name: 'CI token');

        $this->assertSame(80, strlen($rawToken), '40 random bytes hex-encoded = 80 characters');
        $this->assertSame(1, $token->userId);
        $this->assertSame('CI token', $token->name);
        $this->assertSame(['*'], $token->abilities);
        $this->assertNull($token->expiresAt);
        $this->assertNull($token->lastUsedAt);
        $this->assertSame(hash('sha256', $rawToken), $token->tokenHash);
    }

    public function test_create_stores_only_the_hash_never_the_raw_token(): void
    {
        [$rawToken] = $this->manager->create(userId: 1, name: 'Secret');

        $rows = $this->db->query('SELECT token FROM personal_access_tokens');

        $this->assertCount(1, $rows);
        $this->assertNotSame($rawToken, $rows[0]['token']);
        $this->assertSame(hash('sha256', $rawToken), $rows[0]['token']);
    }

    public function test_create_with_custom_abilities(): void
    {
        [, $token] = $this->manager->create(userId: 5, name: 'Scoped', abilities: ['read', 'write']);

        $this->assertSame(['read', 'write'], $token->abilities);
    }

    public function test_create_with_expiry_sets_expires_at(): void
    {
        [, $token] = $this->manager->create(userId: 1, name: 'Expiring', expiresIn: 3600);

        $this->assertNotNull($token->expiresAt);
        $this->assertFalse($token->isExpired());
    }

    public function test_find_returns_matching_token(): void
    {
        [$rawToken, $created] = $this->manager->create(userId: 42, name: 'Findable');

        $found = $this->manager->find($rawToken);

        $this->assertNotNull($found);
        $this->assertSame($created->id, $found->id);
        // user_id is a VARCHAR column, so it round-trips as a string even
        // though 42 (an int) was passed in at creation time.
        $this->assertSame('42', $found->userId);
    }

    public function test_find_returns_null_for_unknown_token(): void
    {
        $this->assertNull($this->manager->find('does-not-exist'));
    }

    public function test_find_returns_null_and_rejects_expired_token(): void
    {
        [$rawToken] = $this->manager->create(userId: 1, name: 'AlreadyExpired', expiresIn: -3600);

        $this->assertNull($this->manager->find($rawToken));
    }

    public function test_find_updates_last_used_at(): void
    {
        [$rawToken, $created] = $this->manager->create(userId: 1, name: 'Touchable');
        $this->assertNull($created->lastUsedAt);

        $this->manager->find($rawToken);

        $rows = $this->db->query('SELECT last_used_at FROM personal_access_tokens WHERE id = ?', [$created->id]);
        $this->assertNotNull($rows[0]['last_used_at']);
    }

    public function test_revoke_deletes_the_token(): void
    {
        [$rawToken, $token] = $this->manager->create(userId: 1, name: 'ToRevoke');

        $this->manager->revoke($token->id);

        $this->assertNull($this->manager->find($rawToken));
        $this->assertSame([], $this->db->query('SELECT * FROM personal_access_tokens'));
    }

    public function test_revoke_is_a_no_op_for_unknown_id(): void
    {
        // Must not throw.
        $this->manager->revoke(999999);
        $this->addToAssertionCount(1);
    }

    public function test_rotate_revokes_old_and_creates_new_with_same_metadata(): void
    {
        [$oldRaw, $old] = $this->manager->create(userId: 7, name: 'Rotatable', abilities: ['read']);

        $result = $this->manager->rotate($old->id);

        $this->assertNotNull($result);
        [$newRaw, $new] = $result;

        $this->assertNotSame($oldRaw, $newRaw);
        $this->assertNotSame($old->id, $new->id);
        // $old->userId is 7 (int, as passed to create()); $new->userId comes
        // from rotate() re-reading the row via hydrate(), where the VARCHAR
        // column round-trips it as a string.
        $this->assertSame((string) $old->userId, (string) $new->userId);
        $this->assertSame($old->name, $new->name);
        $this->assertSame($old->abilities, $new->abilities);

        // The old token must no longer resolve; the new one must.
        $this->assertNull($this->manager->find($oldRaw));
        $this->assertNotNull($this->manager->find($newRaw));
    }

    public function test_rotate_preserves_remaining_ttl_on_expiring_token(): void
    {
        [, $old] = $this->manager->create(userId: 1, name: 'Expiring', expiresIn: 3600);

        $result = $this->manager->rotate($old->id);

        $this->assertNotNull($result);
        [, $new] = $result;

        $this->assertNotNull($new->expiresAt);
        $this->assertFalse($new->isExpired());
    }

    public function test_rotate_returns_null_for_unknown_id(): void
    {
        $this->assertNull($this->manager->rotate(999999));
    }

    public function test_prune_expired_deletes_only_expired_tokens(): void
    {
        [, $expired] = $this->manager->create(userId: 1, name: 'Old', expiresIn: -3600);
        [$activeRaw] = $this->manager->create(userId: 1, name: 'Fresh', expiresIn: 3600);
        [$foreverRaw] = $this->manager->create(userId: 1, name: 'Forever');

        $deleted = $this->manager->pruneExpired();

        $this->assertSame(1, $deleted);
        $this->assertNotNull($this->manager->find($activeRaw));
        $this->assertNotNull($this->manager->find($foreverRaw));

        $rows = $this->db->query('SELECT id FROM personal_access_tokens WHERE id = ?', [$expired->id]);
        $this->assertSame([], $rows);
    }

    public function test_prune_expired_returns_zero_when_nothing_to_prune(): void
    {
        $this->manager->create(userId: 1, name: 'Fresh');

        $this->assertSame(0, $this->manager->pruneExpired());
    }
}
