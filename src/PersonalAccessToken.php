<?php

declare(strict_types=1);

namespace EzPhp\Auth;

/**
 * Class PersonalAccessToken
 *
 * Immutable value object representing a personal access token record as
 * stored in the database. The raw token is never stored — only its SHA-256
 * hash is persisted. The raw token is available only at creation time.
 *
 * @package EzPhp\Auth
 */
final class PersonalAccessToken
{
    /**
     * PersonalAccessToken Constructor
     *
     * @param int|string         $id          Primary key from the database.
     * @param int|string         $userId      The owning user's ID.
     * @param string             $name        Human-readable label (e.g. "CI/CD pipeline").
     * @param string             $tokenHash   SHA-256 hash of the raw token.
     * @param string[]           $abilities   List of granted ability strings.
     * @param \DateTimeImmutable|null $lastUsedAt  Timestamp of last use, or null if never used.
     * @param \DateTimeImmutable|null $expiresAt   Expiry timestamp, or null for no expiry.
     * @param \DateTimeImmutable      $createdAt   Creation timestamp.
     */
    public function __construct(
        public readonly int|string $id,
        public readonly int|string $userId,
        public readonly string $name,
        public readonly string $tokenHash,
        /** @var string[] */
        public readonly array $abilities,
        public readonly ?\DateTimeImmutable $lastUsedAt,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * Return true if this token has expired.
     *
     * Tokens with no expiry (`expiresAt === null`) never expire.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new \DateTimeImmutable();
    }

    /**
     * Return true if the token has the given ability.
     *
     * A token with the `'*'` ability has all abilities.
     *
     * @param string $ability
     *
     * @return bool
     */
    public function can(string $ability): bool
    {
        return in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true);
    }
}
