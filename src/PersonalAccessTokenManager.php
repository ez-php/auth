<?php

declare(strict_types=1);

namespace EzPhp\Auth;

use DateTimeImmutable;
use EzPhp\Contracts\DatabaseInterface;

/**
 * Class PersonalAccessTokenManager
 *
 * Manages personal access tokens: generation, lookup, rotation, and revocation.
 *
 * Tokens are random 40-byte hex strings (80 characters). Only the SHA-256 hash
 * is stored in the database; the raw token is returned at creation time and
 * is never recoverable afterwards.
 *
 * Database table: `personal_access_tokens` — created by the bundled migration.
 *
 * @package EzPhp\Auth
 */
final class PersonalAccessTokenManager
{
    private const string TABLE = 'personal_access_tokens';

    /**
     * Name prefix marking tokens from issueOneTime(); consume() accepts only these.
     */
    public const string ONE_TIME_PREFIX = 'one-time:';

    /**
     * PersonalAccessTokenManager Constructor
     *
     * @param DatabaseInterface $db
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Create a new personal access token for the given user.
     *
     * Returns a two-element array: [rawToken, PersonalAccessToken].
     * The raw token is shown once and must be stored by the caller.
     *
     * @param int|string   $userId      The owning user's ID.
     * @param string       $name        Human-readable label.
     * @param string[]     $abilities   Granted abilities (use ['*'] for all).
     * @param int|null     $expiresIn   Seconds until expiry, or null for no expiry.
     *
     * @return array{0: string, 1: PersonalAccessToken}
     */
    public function create(
        int|string $userId,
        string $name,
        array $abilities = ['*'],
        ?int $expiresIn = null,
    ): array {
        $rawToken = bin2hex(random_bytes(40));
        $hash = hash('sha256', $rawToken);

        $expiresAt = $expiresIn !== null
            ? (new DateTimeImmutable())->modify("+{$expiresIn} seconds")
            : null;

        $createdAt = new DateTimeImmutable();

        $this->db->execute(
            'INSERT INTO ' . self::TABLE . ' (user_id, name, token, abilities, expires_at, created_at)
             VALUES (:user_id, :name, :token, :abilities, :expires_at, :created_at)',
            [
                'user_id' => $userId,
                'name' => $name,
                'token' => $hash,
                'abilities' => implode(',', $abilities),
                'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
            ],
        );

        $id = (int) $this->db->getPdo()->lastInsertId();

        $token = new PersonalAccessToken(
            id: $id,
            userId: $userId,
            name: $name,
            tokenHash: $hash,
            abilities: $abilities,
            lastUsedAt: null,
            expiresAt: $expiresAt,
            createdAt: $createdAt,
        );

        return [$rawToken, $token];
    }

    /**
     * Find a token record by its raw (unhashed) value.
     *
     * Returns null when the token does not exist, has expired, or is a one-time
     * token from issueOneTime().
     *
     * @param string $rawToken
     *
     * @return PersonalAccessToken|null
     */
    public function find(string $rawToken): ?PersonalAccessToken
    {
        $hash = hash('sha256', $rawToken);

        $rows = $this->db->query(
            'SELECT * FROM ' . self::TABLE . ' WHERE token = :token LIMIT 1',
            ['token' => $hash],
        );

        if ($rows === []) {
            return null;
        }

        $token = $this->hydrate($rows[0]);

        // One-time tokens (password reset, e-mail verification) are redeemed via
        // consume() only — they must never work as a Bearer token.
        if ($token->isExpired() || str_starts_with($token->name, self::ONE_TIME_PREFIX)) {
            return null;
        }

        $rawId = $rows[0]['id'];
        $this->touchLastUsed(is_int($rawId) ? $rawId : (is_string($rawId) ? (int) $rawId : 0));

        return $token;
    }

    /**
     * Revoke (delete) a token by its database ID.
     *
     * @param int|string $id
     *
     * @return void
     */
    public function revoke(int|string $id): void
    {
        $this->db->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * Rotate a token: revoke the old one and create a new one with identical metadata.
     *
     * Returns a two-element array: [rawToken, PersonalAccessToken].
     *
     * @param int|string $id The ID of the token to rotate.
     *
     * @return array{0: string, 1: PersonalAccessToken}|null Null when the old token is not found.
     */
    public function rotate(int|string $id): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1',
            ['id' => $id],
        );

        if ($rows === []) {
            return null;
        }

        $old = $this->hydrate($rows[0]);
        $expiresIn = $old->expiresAt !== null
            ? max(0, (int) (new DateTimeImmutable())->diff($old->expiresAt)->s
                + ((int) (new DateTimeImmutable())->diff($old->expiresAt)->i) * 60
                + ((int) (new DateTimeImmutable())->diff($old->expiresAt)->h) * 3600
                + ((int) (new DateTimeImmutable())->diff($old->expiresAt)->days) * 86400)
            : null;

        $this->revoke($id);

        return $this->create($old->userId, $old->name, $old->abilities, $expiresIn);
    }

    /**
     * Issue a purpose-bound one-time token (e-mail verification, password reset, …).
     *
     * Earlier one-time tokens of the same user and ability are revoked first, so only
     * the latest link works. The token carries exactly `$ability` — never `*` — and
     * can only be redeemed through consume().
     *
     * @param int|string $userId
     * @param string     $ability Purpose, e.g. 'password-reset'.
     * @param int        $ttl     Seconds until the token expires.
     *
     * @return string The raw token (store only in the link you send).
     */
    public function issueOneTime(int|string $userId, string $ability, int $ttl): string
    {
        $this->revokeFor($userId, $ability);

        [$rawToken] = $this->create($userId, self::ONE_TIME_PREFIX . $ability, [$ability], $ttl);

        return $rawToken;
    }

    /**
     * Redeem a one-time token for `$ability`: it must exist, have been issued by
     * issueOneTime() for that ability, and not be expired. It is deleted in the same
     * step — the delete succeeding is what makes the redemption count, so of two
     * concurrent calls only one gets the token. A token for another ability is left
     * intact; an expired one is removed. `last_used_at` is not touched.
     *
     * @param string $rawToken
     * @param string $ability
     *
     * @return PersonalAccessToken|null The redeemed token, or null.
     */
    public function consume(string $rawToken, string $ability): ?PersonalAccessToken
    {
        $hash = hash('sha256', $rawToken);

        $rows = $this->db->query(
            'SELECT * FROM ' . self::TABLE . ' WHERE token = :token AND name = :name LIMIT 1',
            ['token' => $hash, 'name' => self::ONE_TIME_PREFIX . $ability],
        );

        if ($rows === []) {
            return null;
        }

        $token = $this->hydrate($rows[0]);

        $deleted = $this->db->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE token = :token',
            ['token' => $hash],
        );

        if ($deleted !== 1 || $token->isExpired() || $token->abilities !== [$ability]) {
            return null;
        }

        return $token;
    }

    /**
     * Revoke all one-time tokens of `$userId` for `$ability` (regular tokens are untouched).
     *
     * @param int|string $userId
     * @param string     $ability
     *
     * @return int Number of revoked tokens.
     */
    public function revokeFor(int|string $userId, string $ability): int
    {
        return $this->db->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE user_id = :user_id AND name = :name',
            ['user_id' => $userId, 'name' => self::ONE_TIME_PREFIX . $ability],
        );
    }

    /**
     * Delete all expired tokens from the table.
     *
     * @return int Number of deleted rows.
     */
    public function pruneExpired(): int
    {
        return $this->db->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE expires_at IS NOT NULL AND expires_at < :now',
            ['now' => (new DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Update the last_used_at timestamp for the given token ID.
     *
     * @param int $id
     *
     * @return void
     */
    private function touchLastUsed(int $id): void
    {
        $this->db->execute(
            'UPDATE ' . self::TABLE . ' SET last_used_at = :now WHERE id = :id',
            ['now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    /**
     * Hydrate a PersonalAccessToken from a database row.
     *
     * @param array<string, mixed> $row
     *
     * @return PersonalAccessToken
     */
    private function hydrate(array $row): PersonalAccessToken
    {
        $abilitiesRaw = isset($row['abilities']) && is_string($row['abilities']) ? $row['abilities'] : '*';
        $abilities = array_filter(explode(',', $abilitiesRaw), static fn (string $a): bool => $a !== '');

        $lastUsedAt = isset($row['last_used_at']) && is_string($row['last_used_at'])
            ? new DateTimeImmutable($row['last_used_at'])
            : null;

        $expiresAt = isset($row['expires_at']) && is_string($row['expires_at'])
            ? new DateTimeImmutable($row['expires_at'])
            : null;

        $createdAt = isset($row['created_at']) && is_string($row['created_at'])
            ? new DateTimeImmutable($row['created_at'])
            : new DateTimeImmutable();

        $id = isset($row['id']) && (is_int($row['id']) || is_string($row['id'])) ? $row['id'] : 0;
        $userId = isset($row['user_id']) && (is_int($row['user_id']) || is_string($row['user_id'])) ? $row['user_id'] : 0;
        $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : '';
        $tokenHash = isset($row['token']) && is_string($row['token']) ? $row['token'] : '';

        return new PersonalAccessToken(
            id: $id,
            userId: $userId,
            name: $name,
            tokenHash: $tokenHash,
            abilities: array_values($abilities),
            lastUsedAt: $lastUsedAt,
            expiresAt: $expiresAt,
            createdAt: $createdAt,
        );
    }
}
