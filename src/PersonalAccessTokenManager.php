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
        $hash     = hash('sha256', $rawToken);

        $expiresAt = $expiresIn !== null
            ? (new DateTimeImmutable())->modify("+{$expiresIn} seconds")
            : null;

        $createdAt = new DateTimeImmutable();

        $this->db->execute(
            'INSERT INTO ' . self::TABLE . ' (user_id, name, token, abilities, expires_at, created_at)
             VALUES (:user_id, :name, :token, :abilities, :expires_at, :created_at)',
            [
                'user_id'    => $userId,
                'name'       => $name,
                'token'      => $hash,
                'abilities'  => implode(',', $abilities),
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
     * Returns null when the token does not exist or has expired.
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

        if ($rows === [] || !isset($rows[0])) {
            return null;
        }

        $token = $this->hydrate($rows[0]);

        if ($token->isExpired()) {
            return null;
        }

        $this->touchLastUsed((int) $rows[0]['id']);

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

        if ($rows === [] || !isset($rows[0])) {
            return null;
        }

        $old       = $this->hydrate($rows[0]);
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
     * Delete all expired tokens from the table.
     *
     * @return int Number of deleted rows.
     */
    public function pruneExpired(): int
    {
        $this->db->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE expires_at IS NOT NULL AND expires_at < :now',
            ['now' => (new DateTimeImmutable())->format('Y-m-d H:i:s')],
        );

        return $this->db->getPdo()->rowCount();
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
        $abilities    = array_filter(explode(',', $abilitiesRaw), static fn (string $a): bool => $a !== '');

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
