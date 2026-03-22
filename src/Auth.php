<?php

declare(strict_types=1);

namespace EzPhp\Auth;

use EzPhp\RateLimiter\RateLimiterInterface;
use RuntimeException;

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
 *   Auth::check()                                                  // bool
 *   Auth::user()                                                   // ?UserInterface
 *   Auth::id()                                                     // int|string|null
 *   Auth::login($user)                                             // void
 *   Auth::logout()                                                 // void
 *   Auth::attempt($id, $pass, $provider)                          // bool
 *   Auth::loginWithRemember($user)                                 // string
 *   Auth::verifyRememberToken($token)                              // bool
 *   Auth::attemptRemember($token, $provider)                       // bool
 *   Auth::can($permission)                                         // bool
 *   Auth::hasRole($role)                                           // bool
 *   Auth::guard('web')                                             // Auth
 *   Auth::hashPassword($plain)                                     // string
 *   Auth::verifyPassword($plain, $hash)                            // bool
 *
 * @package EzPhp\Auth
 */
final class Auth
{
    private static ?self $instance = null;

    /** @var array<string, self> */
    private static array $guards = [];

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

    // ─── Multi-guard support ──────────────────────────────────────────────────

    /**
     * Return (or lazily create) a named Auth guard instance.
     *
     * Guards are independent — each holds its own authenticated user and
     * session key. Calling Auth::guard('api')->login($user) only affects
     * the 'api' guard; Auth::check() (default guard) is unaffected.
     *
     * @param string $name
     *
     * @return self
     */
    public static function guard(string $name): self
    {
        if (!isset(self::$guards[$name])) {
            self::$guards[$name] = new self();
        }

        return self::$guards[$name];
    }

    /**
     * Clear the named-guard registry (useful in tests).
     *
     * @return void
     */
    public static function resetGuards(): void
    {
        self::$guards = [];
    }

    // ─── Static facade ────────────────────────────────────────────────────────

    /**
     * Return the currently authenticated user, or null when no user is authenticated.
     *
     * Missing resource convention: returns null — never throws. Use Auth::check() to
     * distinguish "not authenticated" from other states before accessing the user object.
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
     * Attempt to authenticate a user by identifier and plain-text password.
     *
     * If a RateLimiterInterface is provided the identifier is used as the key.
     * The method returns false immediately when the limit is exceeded; on a
     * failed attempt (user not found or wrong password) a hit is recorded.
     * On success the limiter counter is cleared and the user is logged in.
     *
     * @param string                   $identifier    Login identifier (e.g. email or username).
     * @param string                   $plainPassword Plain-text password to verify.
     * @param UserProviderInterface    $provider      Provider used to look up the user.
     * @param RateLimiterInterface|null $limiter       Optional rate limiter.
     * @param int                      $maxAttempts   Maximum allowed attempts per window.
     * @param int                      $decaySeconds  Limiter window length in seconds.
     *
     * @return bool
     */
    public static function attempt(
        string $identifier,
        string $plainPassword,
        UserProviderInterface $provider,
        ?RateLimiterInterface $limiter = null,
        int $maxAttempts = 5,
        int $decaySeconds = 60,
    ): bool {
        return self::getInstance()->attemptLogin(
            $identifier,
            $plainPassword,
            $provider,
            $limiter,
            $maxAttempts,
            $decaySeconds,
        );
    }

    /**
     * Log in the user and generate a remember-me token.
     *
     * The raw token is returned to the caller so the application can set it
     * as a cookie. A SHA-256 hash of the token is stored in the session under
     * 'auth_remember_token' for later verification without re-hashing.
     *
     * Requires an active PHP session.
     *
     * @param UserInterface $user
     *
     * @return string  Raw (un-hashed) remember token — store this in the cookie.
     */
    public static function loginWithRemember(UserInterface $user): string
    {
        return self::getInstance()->loginUserWithRemember($user);
    }

    /**
     * Verify a raw remember token against the hash stored in the session.
     *
     * @param string $token  Raw token from the cookie.
     *
     * @return bool
     */
    public static function verifyRememberToken(string $token): bool
    {
        return self::getInstance()->checkRememberToken($token);
    }

    /**
     * Attempt to authenticate a user via a remember-me token.
     *
     * Calls $provider->findByRememberToken($token). If a user is found the
     * user is logged in and true is returned.
     *
     * @param string                $token    Raw remember token from the cookie.
     * @param UserProviderInterface $provider Provider used to look up the user.
     *
     * @return bool
     */
    public static function attemptRemember(string $token, UserProviderInterface $provider): bool
    {
        return self::getInstance()->attemptRememberLogin($token, $provider);
    }

    /**
     * Return true if the currently authenticated user has the given permission.
     *
     * Returns false when no user is authenticated.
     * Delegates to UserInterface::can() when the user implements AuthorizableInterface.
     * Throws RuntimeException when the user does not implement AuthorizableInterface.
     *
     * @param string $permission
     *
     * @return bool
     *
     * @throws RuntimeException
     */
    public static function can(string $permission): bool
    {
        $user = self::user();

        if ($user === null) {
            return false;
        }

        if ($user instanceof AuthorizableInterface) {
            return $user->can($permission);
        }

        throw new RuntimeException(
            sprintf(
                'User class "%s" does not implement AuthorizableInterface.',
                $user::class,
            ),
        );
    }

    /**
     * Return true if the currently authenticated user holds the given role.
     *
     * Returns false when no user is authenticated.
     * Delegates to UserInterface::hasRole() when the user implements AuthorizableInterface.
     * Throws RuntimeException when the user does not implement AuthorizableInterface.
     *
     * @param string $role
     *
     * @return bool
     *
     * @throws RuntimeException
     */
    public static function hasRole(string $role): bool
    {
        $user = self::user();

        if ($user === null) {
            return false;
        }

        if ($user instanceof AuthorizableInterface) {
            return $user->hasRole($role);
        }

        throw new RuntimeException(
            sprintf(
                'User class "%s" does not implement AuthorizableInterface.',
                $user::class,
            ),
        );
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

    // ─── Instance methods (called on named guards) ────────────────────────────

    /**
     * Return the currently authenticated user for this guard instance.
     *
     * @return UserInterface|null
     */
    public function resolveCurrentUser(): ?UserInterface
    {
        return $this->resolveUser();
    }

    /**
     * Authenticate the given user on this guard instance.
     *
     * @param UserInterface $user
     *
     * @return void
     */
    public function loginUser(UserInterface $user): void
    {
        $this->currentUser = $user;

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['auth_id'] = $user->getAuthId();
        }
    }

    /**
     * De-authenticate the current user on this guard instance.
     *
     * @return void
     */
    public function logoutUser(): void
    {
        $this->currentUser = null;

        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['auth_id']);
        }
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
     * @param string                    $identifier
     * @param string                    $plainPassword
     * @param UserProviderInterface     $provider
     * @param RateLimiterInterface|null $limiter
     * @param int                       $maxAttempts
     * @param int                       $decaySeconds
     *
     * @return bool
     */
    private function attemptLogin(
        string $identifier,
        string $plainPassword,
        UserProviderInterface $provider,
        ?RateLimiterInterface $limiter,
        int $maxAttempts,
        int $decaySeconds,
    ): bool {
        if ($limiter !== null && $limiter->tooManyAttempts($identifier, $maxAttempts)) {
            return false;
        }

        $user = $provider->findByCredentials($identifier);

        if ($user === null) {
            if ($limiter !== null) {
                $limiter->attempt($identifier, $maxAttempts, $decaySeconds);
            }

            return false;
        }

        if (!self::verifyPassword($plainPassword, $user->getAuthPassword())) {
            if ($limiter !== null) {
                $limiter->attempt($identifier, $maxAttempts, $decaySeconds);
            }

            return false;
        }

        if ($limiter !== null) {
            $limiter->resetAttempts($identifier);
        }

        $this->loginUser($user);

        return true;
    }

    /**
     * @param UserInterface $user
     *
     * @return string
     */
    private function loginUserWithRemember(UserInterface $user): string
    {
        $this->loginUser($user);

        $token = bin2hex(random_bytes(32));

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['auth_remember_token'] = hash('sha256', $token);
        }

        return $token;
    }

    /**
     * @param string $token
     *
     * @return bool
     */
    private function checkRememberToken(string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $stored = $_SESSION['auth_remember_token'] ?? '';

        if (!is_string($stored) || $stored === '') {
            return false;
        }

        return hash_equals($stored, hash('sha256', $token));
    }

    /**
     * @param string                $token
     * @param UserProviderInterface $provider
     *
     * @return bool
     */
    private function attemptRememberLogin(string $token, UserProviderInterface $provider): bool
    {
        $user = $provider->findByRememberToken($token);

        if ($user === null) {
            return false;
        }

        $this->loginUser($user);

        return true;
    }
}
