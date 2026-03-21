<?php

declare(strict_types=1);

namespace EzPhp\Auth;

/**
 * Interface UserInterface
 *
 * Contract for authenticated user objects.
 *
 * @package EzPhp\Auth
 */
interface UserInterface
{
    /**
     * Return the unique identifier for this user.
     *
     * @return int|string
     */
    public function getAuthId(): int|string;

    /**
     * Return the hashed password for this user.
     *
     * Used by Auth::attempt() to verify the supplied plain-text password.
     *
     * @return string
     */
    public function getAuthPassword(): string;
}
