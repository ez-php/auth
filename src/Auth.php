<?php

declare(strict_types=1);

namespace EzPhp\Auth;

/**
 * Class Auth
 *
 * Static facade for the authentication layer.
 *
 * Supports two authentication strategies:
 *   - Token-based: AuthMiddleware resolves the user via UserProviderInterface
 *     and calls Auth::login($user); the user is held in memory for the request.
 *   - Session-based: Auth::login($user) stores the user ID in $_SESSION;
 *     on subsequent requests Auth::user() restores the user via the provider.
 *
 * Usage:
 *   Auth::check()                        // bool
 *   Auth::user()                         // ?UserInterface
 *   Auth::id()                           // int|string|null
 *   Auth::login($user)                   // void
 *   Auth::logout()                       // void
 *   Auth::hashPassword($plain)           // string
 *   Auth::verifyPassword($plain, $hash)  // bool
 *
 * @package EzPhp\Auth
 */
final class Auth
{
    private static ?self $instance = null;

    private ?UserInterface $currentUser = null;

    /**
     * Auth Constructor
     *
     * @param UserProviderInterface|null $provider
     */
    public function __construct(private readonly ?UserProviderInterface $provider = null)
    {
    }

    // ─── Static instance management ──────────────────────────────────────────

    /**
     * @param Auth $instance
     *
     * @return void
     */
    public static function setInstance(self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Reset the static instance (useful in tests).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    // ─── Static facade ────────────────────────────────────────────────────────

    /**
     * Return the currently authenticated user, or null.
     */
    public static function user(): ?UserInterface
    {
        return self::getInstance()->resolveUser();
    }

    /**
     * Return true if a user is authenticated.
     */
    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * Return the authenticated user's ID, or null.
     *
     * @return int|string|null
     */
    public static function id(): int|string|null
    {
        return self::user()?->getAuthId();
    }

    /**
     * Authenticate the given user for this request.
     * If a session is active the user ID is persisted so it survives the request.
     */
    public static function login(UserInterface $user): void
    {
        self::getInstance()->loginUser($user);
    }

    /**
     * De-authenticate the current user and clear any session data.
     */
    public static function logout(): void
    {
        self::getInstance()->logoutUser();
    }

    /**
     * Hash a plain-text password using PHP's default hashing algorithm (bcrypt).
     */
    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    /**
     * Verify a plain-text password against a stored hash.
     */
    public static function verifyPassword(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    // ─── Instance logic ───────────────────────────────────────────────────────

    /**
     * @return UserInterface|null
     */
    private function resolveUser(): ?UserInterface
    {
        if ($this->currentUser !== null) {
            return $this->currentUser;
        }

        // Attempt session restoration when a provider is available.
        if ($this->provider !== null && session_status() === PHP_SESSION_ACTIVE) {
            $id = $_SESSION['auth_id'] ?? null;

            if (is_int($id) || is_string($id)) {
                $this->currentUser = $this->provider->findById($id);
            }
        }

        return $this->currentUser;
    }

    /**
     * @param UserInterface $user
     *
     * @return void
     */
    private function loginUser(UserInterface $user): void
    {
        $this->currentUser = $user;

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['auth_id'] = $user->getAuthId();
        }
    }

    /**
     * @return void
     */
    private function logoutUser(): void
    {
        $this->currentUser = null;

        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['auth_id']);
        }
    }
}
