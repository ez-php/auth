<?php

declare(strict_types=1);

namespace EzPhp\Auth;

use EzPhp\RateLimiter\RateLimiterInterface;

/**
 * Class LoginThrottle
 *
 * Throttled credential check with a result object — what applications
 * otherwise rebuild around Auth::verifyPassword():
 *
 * - The limiter key is caller-supplied (default: the identifier), so a login
 *   can be keyed on "username|ip" instead of the identifier alone.
 * - `$maxAttempts` failures lock the key for `$lockoutSeconds` (the limiter
 *   window), independent of Auth::attempt()'s `$decaySeconds` default.
 * - A success resets the counter and logs the user in via Auth::login().
 * - Optional constant delay after a failure (`$failureDelayMs`).
 * - The result says *why* it failed, with `availableIn` for a 429 Retry-After.
 *
 * Auth::attempt() and its bool return are unchanged. No CAPTCHA, and the
 * lockout lives in the rate limiter's store, not in the users table.
 *
 * @package EzPhp\Auth
 */
final readonly class LoginThrottle
{
    /**
     * @param RateLimiterInterface $limiter        Counts failed attempts per key.
     * @param int                  $maxAttempts    Failures allowed before the lockout.
     * @param int                  $lockoutSeconds Lockout / counting window in seconds.
     * @param int                  $failureDelayMs Constant delay after a failed attempt (0 = none).
     */
    public function __construct(
        private RateLimiterInterface $limiter,
        private int $maxAttempts = 5,
        private int $lockoutSeconds = 300,
        private int $failureDelayMs = 0,
    ) {
    }

    /**
     * Check the credentials under the throttle and log the user in on success.
     *
     * @param string                $identifier    Login identifier (e.g. email or username).
     * @param string                $plainPassword Plain-text password to verify.
     * @param UserProviderInterface $provider      Looks the user up by identifier.
     * @param string|null           $throttleKey   Limiter key; null = the identifier.
     *
     * @return LoginResult
     */
    public function attempt(
        string $identifier,
        string $plainPassword,
        UserProviderInterface $provider,
        ?string $throttleKey = null,
    ): LoginResult {
        $key = 'login:' . ($throttleKey ?? $identifier);

        if ($this->limiter->tooManyAttempts($key, $this->maxAttempts)) {
            return LoginResult::lockedOut($this->limiter->availableIn($key));
        }

        $user = $provider->findByCredentials($identifier);
        // An unknown identifier still pays one password_verify() (Auth::dummyPasswordHash()),
        // so the response time does not reveal which identifiers exist.
        $verified = Auth::verifyPassword($plainPassword, $user?->getAuthPassword() ?? Auth::dummyPasswordHash());

        if ($user === null || !$verified) {
            $this->limiter->attempt($key, $this->maxAttempts, $this->lockoutSeconds);

            if ($this->failureDelayMs > 0) {
                usleep($this->failureDelayMs * 1000);
            }

            $remaining = $this->limiter->remainingAttempts($key, $this->maxAttempts);

            return $remaining > 0
                ? LoginResult::invalid($remaining)
                : LoginResult::lockedOut($this->limiter->availableIn($key));
        }

        $this->limiter->resetAttempts($key);
        Auth::login($user);

        return LoginResult::success($user);
    }

    /**
     * Clear the failure counter for a key (e.g. after a password reset).
     *
     * @param string $throttleKey The same key passed to attempt() (or the identifier).
     *
     * @return void
     */
    public function clear(string $throttleKey): void
    {
        $this->limiter->resetAttempts('login:' . $throttleKey);
    }
}
