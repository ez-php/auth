<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use EzPhp\Auth\PersonalAccessToken;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class PersonalAccessTokenTest
 *
 * @package Tests
 */
#[CoversClass(PersonalAccessToken::class)]
final class PersonalAccessTokenTest extends TestCase
{
    private function make(
        ?\DateTimeImmutable $expiresAt = null,
        string $abilities = '*',
    ): PersonalAccessToken {
        return new PersonalAccessToken(
            id: 1,
            userId: 42,
            name: 'test token',
            tokenHash: hash('sha256', 'secret'),
            abilities: [$abilities],
            lastUsedAt: null,
            expiresAt: $expiresAt,
            createdAt: new DateTimeImmutable(),
        );
    }

    public function testIsExpiredReturnsFalseWhenNoExpiry(): void
    {
        $token = $this->make(expiresAt: null);
        $this->assertFalse($token->isExpired());
    }

    public function testIsExpiredReturnsFalseForFutureExpiry(): void
    {
        $token = $this->make(expiresAt: new DateTimeImmutable('+1 hour'));
        $this->assertFalse($token->isExpired());
    }

    public function testIsExpiredReturnsTrueForPastExpiry(): void
    {
        $token = $this->make(expiresAt: new DateTimeImmutable('-1 second'));
        $this->assertTrue($token->isExpired());
    }

    public function testCanReturnsTrueForWildcard(): void
    {
        $token = $this->make(abilities: '*');
        $this->assertTrue($token->can('read'));
        $this->assertTrue($token->can('write'));
        $this->assertTrue($token->can('delete'));
    }

    public function testCanReturnsTrueForMatchingAbility(): void
    {
        $token = new PersonalAccessToken(
            id: 1,
            userId: 1,
            name: 'test',
            tokenHash: 'hash',
            abilities: ['read', 'write'],
            lastUsedAt: null,
            expiresAt: null,
            createdAt: new DateTimeImmutable(),
        );

        $this->assertTrue($token->can('read'));
        $this->assertTrue($token->can('write'));
        $this->assertFalse($token->can('delete'));
    }

    public function testCanReturnsFalseForUnknownAbility(): void
    {
        $token = new PersonalAccessToken(
            id: 1,
            userId: 1,
            name: 'test',
            tokenHash: 'hash',
            abilities: ['read'],
            lastUsedAt: null,
            expiresAt: null,
            createdAt: new DateTimeImmutable(),
        );

        $this->assertFalse($token->can('admin'));
    }
}
