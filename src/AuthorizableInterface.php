<?php

declare(strict_types=1);

namespace EzPhp\Auth;

/**
 * Interface AuthorizableInterface
 *
 * Optional RBAC contract for user objects that support role and permission checks.
 *
 * Implement this interface on your user model to enable Auth::can() and Auth::hasRole().
 * Users that do not implement this interface cannot be checked for roles or permissions,
 * and Auth::can() / Auth::hasRole() will throw a RuntimeException.
 *
 * @package EzPhp\Auth
 */
interface AuthorizableInterface
{
    /**
     * Return true if the user holds the given role.
     *
     * @param string $role
     *
     * @return bool
     */
    public function hasRole(string $role): bool;

    /**
     * Return true if the user has the given permission.
     *
     * @param string $permission
     *
     * @return bool
     */
    public function can(string $permission): bool;
}
