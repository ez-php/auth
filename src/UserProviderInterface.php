<?php

declare(strict_types=1);

namespace EzPhp\Auth;

/**
 * Interface UserProviderInterface
 *
 * Resolves users from a backing store (database, cache, etc.).
 *
 * @package EzPhp\Auth
 */
interface UserProviderInterface
{
    /**
     * Find a user by their primary identifier.
     *
     * @param int|string $id
     *
     * @return UserInterface|null
     */
    public function findById(int|string $id): ?UserInterface;

    /**
     * Find a user by a Bearer API token.
     *
     * @param string $token
     *
     * @return UserInterface|null
     */
    public function findByToken(string $token): ?UserInterface;
}
