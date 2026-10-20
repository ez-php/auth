<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Auth\Auth;
use EzPhp\Auth\LoginResult;
use EzPhp\Auth\LoginThrottle;
use EzPhp\Auth\UserInterface;
use EzPhp\Auth\UserProviderInterface;
use EzPhp\RateLimiter\ArrayDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @package Tests
 */
#[CoversClass(LoginThrottle::class)]
#[CoversClass(LoginResult::class)]
#[UsesClass(Auth::class)]
final class LoginThrottleTest extends TestCase
{
    private UserProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Auth::resetInstance();
        Auth::setInstance(new Auth(null));

        $user = new class () implements UserInterface {
            public function getAuthId(): int
            {
                return 1;
            }

            public function getAuthPassword(): string
            {
                return password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);
            }
        };

        $this->provider = new class ($user) implements UserProviderInterface {
            public function __construct(private readonly UserInterface $user)
            {
            }

            public function findById(int|string $id): ?UserInterface
            {
                return null;
            }

            public function findByToken(string $token): ?UserInterface
            {
                return null;
            }

            public function findByCredentials(string $identifier): ?UserInterface
            {
                return $identifier === 'alice' ? $this->user : null;
            }

            public function findByRememberToken(string $token): ?UserInterface
            {
                return null;
            }
        };
    }

    protected function tearDown(): void
    {
        Auth::resetInstance();
        parent::tearDown();
    }

    public function test_success_logs_in_and_resets_the_counter(): void
    {
        $limiter = new ArrayDriver();
        $throttle = new LoginThrottle($limiter, maxAttempts: 3);

        $throttle->attempt('alice', 'wrong', $this->provider);
        $result = $throttle->attempt('alice', 'secret', $this->provider);

        $this->assertTrue($result->succeeded());
        $this->assertSame(1, $result->user?->getAuthId());
        $this->assertTrue(Auth::check());
        $this->assertSame(3, $limiter->remainingAttempts('login:alice', 3));
    }

    public function test_invalid_credentials_report_remaining_attempts(): void
    {
        $throttle = new LoginThrottle(new ArrayDriver(), maxAttempts: 3);

        $result = $throttle->attempt('alice', 'wrong', $this->provider);

        $this->assertSame(LoginResult::INVALID, $result->status);
        $this->assertSame(2, $result->remainingAttempts);
        $this->assertFalse(Auth::check());
        $this->assertSame(LoginResult::INVALID, $throttle->attempt('nobody', 'x', $this->provider)->status);
    }

    public function test_lockout_is_reported_with_available_in_and_blocks_correct_passwords(): void
    {
        $throttle = new LoginThrottle(new ArrayDriver(), maxAttempts: 2, lockoutSeconds: 300);

        $this->assertSame(LoginResult::INVALID, $throttle->attempt('alice', 'x', $this->provider)->status);
        $locking = $throttle->attempt('alice', 'x', $this->provider);
        $this->assertTrue($locking->isLockedOut(), 'the attempt that reaches the limit already reports the lockout');

        $blocked = $throttle->attempt('alice', 'secret', $this->provider);

        $this->assertTrue($blocked->isLockedOut());
        $this->assertGreaterThan(290, $blocked->availableIn);
        $this->assertLessThanOrEqual(300, $blocked->availableIn);
        $this->assertFalse(Auth::check());
    }

    public function test_caller_supplied_key_separates_counters(): void
    {
        $throttle = new LoginThrottle(new ArrayDriver(), maxAttempts: 1);

        $throttle->attempt('alice', 'x', $this->provider, 'alice|10.0.0.1');

        $this->assertTrue($throttle->attempt('alice', 'secret', $this->provider, 'alice|10.0.0.1')->isLockedOut());
        $this->assertTrue($throttle->attempt('alice', 'secret', $this->provider, 'alice|10.0.0.2')->succeeded());
    }

    public function test_unknown_identifier_costs_a_password_verification(): void
    {
        $throttle = new LoginThrottle(new ArrayDriver(), maxAttempts: 1000);
        Auth::dummyPasswordHash();

        $unknown = $this->fastestOf(5, fn () => $throttle->attempt('nobody', 'wrong', $this->provider));
        $wrong = $this->fastestOf(5, fn () => $throttle->attempt('alice', 'wrong', $this->provider));

        $this->assertGreaterThan($wrong * 0.3, $unknown, 'an unknown user must not answer measurably faster than a wrong password');
    }

    /**
     * @param int      $runs
     * @param callable $fn
     *
     * @return int Fastest run in nanoseconds.
     */
    private function fastestOf(int $runs, callable $fn): int
    {
        $fastest = PHP_INT_MAX;

        for ($i = 0; $i < $runs; $i++) {
            $start = hrtime(true);
            $fn();
            $fastest = min($fastest, hrtime(true) - $start);
        }

        return $fastest;
    }

    public function test_clear_lifts_a_lockout(): void
    {
        $throttle = new LoginThrottle(new ArrayDriver(), maxAttempts: 1);
        $throttle->attempt('alice', 'x', $this->provider);

        $throttle->clear('alice');

        $this->assertTrue($throttle->attempt('alice', 'secret', $this->provider)->succeeded());
    }
}
