<?php

declare(strict_types=1);

namespace EzPhp\Auth;

/**
 * Class LoginResult
 *
 * Outcome of LoginThrottle::attempt(): success (with the logged-in user),
 * invalid credentials, or locked out — with the seconds until the lockout
 * ends, for a 429 `Retry-After`. Unlike Auth::attempt()'s bool, a caller can
 * tell "wrong password" from "too many attempts".
 *
 * @package EzPhp\Auth
 */
final readonly class LoginResult
{
    public const string SUCCESS = 'success';

    public const string INVALID = 'invalid';

    public const string LOCKED_OUT = 'locked_out';

    /**
     * @param self::SUCCESS|self::INVALID|self::LOCKED_OUT $status
     * @param UserInterface|null                           $user              The logged-in user on success.
     * @param int                                          $availableIn       Seconds until the lockout ends (LOCKED_OUT only, else 0).
     * @param int                                          $remainingAttempts Failed attempts left before a lockout (INVALID only, else 0).
     */
    private function __construct(
        public string $status,
        public ?UserInterface $user = null,
        public int $availableIn = 0,
        public int $remainingAttempts = 0,
    ) {
    }

    /**
     * @param UserInterface $user
     *
     * @return self
     */
    public static function success(UserInterface $user): self
    {
        return new self(self::SUCCESS, $user);
    }

    /**
     * @param int $remainingAttempts
     *
     * @return self
     */
    public static function invalid(int $remainingAttempts): self
    {
        return new self(self::INVALID, remainingAttempts: max(0, $remainingAttempts));
    }

    /**
     * @param int $availableIn
     *
     * @return self
     */
    public static function lockedOut(int $availableIn): self
    {
        return new self(self::LOCKED_OUT, availableIn: max(0, $availableIn));
    }

    /**
     * @return bool
     */
    public function succeeded(): bool
    {
        return $this->status === self::SUCCESS;
    }

    /**
     * @return bool
     */
    public function isLockedOut(): bool
    {
        return $this->status === self::LOCKED_OUT;
    }
}
