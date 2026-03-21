<?php

declare(strict_types=1);

namespace Tests\Auth;

use EzPhp\Auth\Auth;
use EzPhp\Auth\AuthorizableInterface;
use EzPhp\Auth\UserInterface;
use EzPhp\Auth\UserProviderInterface;
use EzPhp\RateLimiter\RateLimiterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class AuthTest
 *
 * @package Tests\Auth
 */
#[CoversClass(Auth::class)]
final class AuthTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Auth::resetInstance();
        Auth::resetGuards();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Auth::resetInstance();
        Auth::resetGuards();
        parent::tearDown();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param int|string $id
     * @param string     $password  Plain-text password (will be hashed via Auth::hashPassword).
     *
     * @return UserInterface
     */
    private function makeUser(int|string $id = 1, string $password = 'secret'): UserInterface
    {
        $hash = Auth::hashPassword($password);

        return new readonly class ($id, $hash) implements UserInterface {
            /**
             * Constructor
             *
             * @param int|string $id
             * @param string     $hash
             */
            public function __construct(
                private int|string $id,
                private string $hash,
            ) {
            }

            /**
             * @return int|string
             */
            public function getAuthId(): int|string
            {
                return $this->id;
            }

            /**
             * @return string
             */
            public function getAuthPassword(): string
            {
                return $this->hash;
            }
        };
    }

    /**
     * @param UserInterface $user
     *
     * @return UserProviderInterface
     */
    private function makeProvider(UserInterface $user): UserProviderInterface
    {
        return new readonly class ($user) implements UserProviderInterface {
            /**
             * Constructor
             *
             * @param UserInterface $user
             */
            public function __construct(private UserInterface $user)
            {
            }

            /**
             * @param int|string $id
             *
             * @return UserInterface|null
             */
            public function findById(int|string $id): ?UserInterface
            {
                return $this->user->getAuthId() === $id ? $this->user : null;
            }

            /**
             * @param string $token
             *
             * @return UserInterface|null
             */
            public function findByToken(string $token): ?UserInterface
            {
                return null;
            }

            /**
             * @param string $identifier
             *
             * @return UserInterface|null
             */
            public function findByCredentials(string $identifier): ?UserInterface
            {
                return $identifier === (string) $this->user->getAuthId() ? $this->user : null;
            }

            /**
             * @param string $token
             *
             * @return UserInterface|null
             */
            public function findByRememberToken(string $token): ?UserInterface
            {
                return null;
            }
        };
    }

    // ─── Default state ────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_check_returns_false_when_no_user_authenticated(): void
    {
        $this->assertFalse(Auth::check());
    }

    /**
     * @return void
     */
    public function test_user_returns_null_when_no_user_authenticated(): void
    {
        $this->assertNull(Auth::user());
    }

    /**
     * @return void
     */
    public function test_id_returns_null_when_no_user_authenticated(): void
    {
        $this->assertNull(Auth::id());
    }

    // ─── Login / logout ───────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_login_sets_authenticated_user(): void
    {
        $user = $this->makeUser(42);

        Auth::login($user);

        $this->assertTrue(Auth::check());
        $this->assertSame($user, Auth::user());
    }

    /**
     * @return void
     */
    public function test_id_returns_user_id_after_login(): void
    {
        Auth::login($this->makeUser(99));

        $this->assertSame(99, Auth::id());
    }

    /**
     * @return void
     */
    public function test_id_returns_string_id(): void
    {
        Auth::login($this->makeUser('uuid-abc'));

        $this->assertSame('uuid-abc', Auth::id());
    }

    /**
     * @return void
     */
    public function test_logout_clears_authenticated_user(): void
    {
        Auth::login($this->makeUser());
        Auth::logout();

        $this->assertFalse(Auth::check());
        $this->assertNull(Auth::user());
    }

    // ─── Instance management ──────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_get_instance_creates_instance_lazily(): void
    {
        $instance = Auth::getInstance();

        $this->assertInstanceOf(Auth::class, $instance);
    }

    /**
     * @return void
     */
    public function test_set_instance_replaces_static_instance(): void
    {
        $custom = new Auth();
        Auth::setInstance($custom);

        $this->assertSame($custom, Auth::getInstance());
    }

    /**
     * @return void
     */
    public function test_reset_instance_clears_static_instance(): void
    {
        Auth::login($this->makeUser());
        Auth::resetInstance();

        // After reset a fresh instance is created — no user is set.
        $this->assertFalse(Auth::check());
    }

    // ─── Password hashing ─────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_hash_password_returns_bcrypt_hash(): void
    {
        $hash = Auth::hashPassword('secret');

        $this->assertStringStartsWith('$2y$', $hash);
    }

    /**
     * @return void
     */
    public function test_verify_password_returns_true_for_correct_password(): void
    {
        $hash = Auth::hashPassword('secret');

        $this->assertTrue(Auth::verifyPassword('secret', $hash));
    }

    /**
     * @return void
     */
    public function test_verify_password_returns_false_for_wrong_password(): void
    {
        $hash = Auth::hashPassword('secret');

        $this->assertFalse(Auth::verifyPassword('wrong', $hash));
    }

    /**
     * @return void
     */
    public function test_hash_password_produces_unique_hashes(): void
    {
        $hash1 = Auth::hashPassword('secret');
        $hash2 = Auth::hashPassword('secret');

        $this->assertNotSame($hash1, $hash2);
    }

    // ─── Session restoration ──────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_user_restored_from_session_via_provider(): void
    {
        $user = $this->makeUser(7);
        $provider = $this->makeProvider($user);

        $auth = new Auth($provider);
        Auth::setInstance($auth);

        // Simulate a session that was set in a previous request.
        session_start();
        $_SESSION['auth_id'] = 7;

        $resolved = Auth::user();

        session_destroy();
        unset($_SESSION['auth_id']);

        $this->assertSame($user, $resolved);
    }

    /**
     * @return void
     */
    public function test_login_persists_id_to_session(): void
    {
        $auth = new Auth();
        Auth::setInstance($auth);

        session_start();

        Auth::login($this->makeUser(5));

        $storedId = $_SESSION['auth_id'] ?? null;

        session_destroy();

        $this->assertSame(5, $storedId);
    }

    /**
     * @return void
     */
    public function test_logout_removes_id_from_session(): void
    {
        $auth = new Auth();
        Auth::setInstance($auth);

        session_start();
        $_SESSION['auth_id'] = 5;

        Auth::login($this->makeUser(5));
        Auth::logout();

        $this->assertArrayNotHasKey('auth_id', $_SESSION);

        session_destroy();
    }

    // ─── Login rate limiting ──────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_attempt_returns_true_on_valid_credentials(): void
    {
        $user = $this->makeUser(1, 'correct');
        $provider = $this->makeProvider($user);

        $result = Auth::attempt((string) $user->getAuthId(), 'correct', $provider);

        $this->assertTrue($result);
        $this->assertTrue(Auth::check());
        $this->assertSame($user, Auth::user());
    }

    /**
     * @return void
     */
    public function test_attempt_returns_false_for_wrong_password(): void
    {
        $user = $this->makeUser(1, 'correct');
        $provider = $this->makeProvider($user);

        $result = Auth::attempt((string) $user->getAuthId(), 'wrong', $provider);

        $this->assertFalse($result);
        $this->assertFalse(Auth::check());
    }

    /**
     * @return void
     */
    public function test_attempt_returns_false_for_unknown_user(): void
    {
        $user = $this->makeUser(1);
        $provider = $this->makeProvider($user);

        $result = Auth::attempt('unknown', 'secret', $provider);

        $this->assertFalse($result);
        $this->assertFalse(Auth::check());
    }

    /**
     * @return void
     */
    public function test_attempt_blocks_when_rate_limited(): void
    {
        $user = $this->makeUser(1, 'correct');
        $provider = $this->makeProvider($user);

        $limiter = new class () implements RateLimiterInterface {
            /**
             * @param string $key
             * @param int    $maxAttempts
             * @param int    $decaySeconds
             *
             * @return bool
             */
            public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
            {
                return false;
            }

            /**
             * @param string $key
             * @param int    $maxAttempts
             *
             * @return bool
             */
            public function tooManyAttempts(string $key, int $maxAttempts): bool
            {
                return true; // always throttled
            }

            /**
             * @param string $key
             * @param int    $maxAttempts
             *
             * @return int
             */
            public function remainingAttempts(string $key, int $maxAttempts): int
            {
                return 0;
            }

            /**
             * @param string $key
             *
             * @return void
             */
            public function resetAttempts(string $key): void
            {
            }
        };

        $result = Auth::attempt((string) $user->getAuthId(), 'correct', $provider, $limiter);

        $this->assertFalse($result);
        $this->assertFalse(Auth::check());
    }

    /**
     * @return void
     */
    public function test_attempt_records_hit_on_wrong_password(): void
    {
        $user = $this->makeUser(1, 'correct');
        $provider = $this->makeProvider($user);
        $hits = 0;

        $limiter = new class ($hits) implements RateLimiterInterface {
            /**
             * @param int $hits
             */
            public function __construct(private int &$hits)
            {
            }

            /**
             * @param string $key
             * @param int    $maxAttempts
             * @param int    $decaySeconds
             *
             * @return bool
             */
            public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
            {
                $this->hits++;

                return true;
            }

            /**
             * @param string $key
             * @param int    $maxAttempts
             *
             * @return bool
             */
            public function tooManyAttempts(string $key, int $maxAttempts): bool
            {
                return false;
            }

            /**
             * @param string $key
             * @param int    $maxAttempts
             *
             * @return int
             */
            public function remainingAttempts(string $key, int $maxAttempts): int
            {
                return $maxAttempts - $this->hits;
            }

            /**
             * @param string $key
             *
             * @return void
             */
            public function resetAttempts(string $key): void
            {
                $this->hits = 0;
            }
        };

        Auth::attempt((string) $user->getAuthId(), 'wrong', $provider, $limiter);

        $this->assertSame(1, $hits);
    }

    /**
     * @return void
     */
    public function test_attempt_clears_limiter_on_success(): void
    {
        $user = $this->makeUser(1, 'correct');
        $provider = $this->makeProvider($user);
        $hits = 2;
        $resetCalled = false;

        $limiter = new class ($hits) implements RateLimiterInterface {
            private bool $resetCalled = false;

            /**
             * @param int $hits
             */
            public function __construct(private int &$hits)
            {
            }

            /**
             * @return bool
             */
            public function wasReset(): bool
            {
                return $this->resetCalled;
            }

            /**
             * @param string $key
             * @param int    $maxAttempts
             * @param int    $decaySeconds
             *
             * @return bool
             */
            public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
            {
                return true;
            }

            /**
             * @param string $key
             * @param int    $maxAttempts
             *
             * @return bool
             */
            public function tooManyAttempts(string $key, int $maxAttempts): bool
            {
                return false;
            }

            /**
             * @param string $key
             * @param int    $maxAttempts
             *
             * @return int
             */
            public function remainingAttempts(string $key, int $maxAttempts): int
            {
                return $maxAttempts - $this->hits;
            }

            /**
             * @param string $key
             *
             * @return void
             */
            public function resetAttempts(string $key): void
            {
                $this->hits = 0;
                $this->resetCalled = true;
            }
        };

        $result = Auth::attempt((string) $user->getAuthId(), 'correct', $provider, $limiter);

        $this->assertTrue($result);
        $this->assertTrue($limiter->wasReset());
    }

    /**
     * @return void
     */
    public function test_attempt_without_limiter_works(): void
    {
        $user = $this->makeUser(1, 'password');
        $provider = $this->makeProvider($user);

        $result = Auth::attempt((string) $user->getAuthId(), 'password', $provider);

        $this->assertTrue($result);
    }

    // ─── Multi-guard support ──────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_guard_returns_separate_instance(): void
    {
        $web = Auth::guard('web');
        $api = Auth::guard('api');

        $this->assertNotSame($web, $api);
        $this->assertInstanceOf(Auth::class, $web);
        $this->assertInstanceOf(Auth::class, $api);
    }

    /**
     * @return void
     */
    public function test_guard_returns_same_instance_on_repeated_calls(): void
    {
        $first = Auth::guard('web');
        $second = Auth::guard('web');

        $this->assertSame($first, $second);
    }

    /**
     * @return void
     */
    public function test_guard_login_is_isolated_from_default(): void
    {
        $user = $this->makeUser(1);
        Auth::guard('web')->loginUser($user);

        // Default guard must not be affected.
        $this->assertFalse(Auth::check());
        $this->assertNull(Auth::user());
    }

    /**
     * @return void
     */
    public function test_guard_login_is_isolated_from_other_guards(): void
    {
        $webUser = $this->makeUser(1);
        $apiUser = $this->makeUser(2);

        Auth::guard('web')->loginUser($webUser);
        Auth::guard('api')->loginUser($apiUser);

        $this->assertSame($webUser, Auth::guard('web')->resolveCurrentUser());
        $this->assertSame($apiUser, Auth::guard('api')->resolveCurrentUser());
    }

    /**
     * @return void
     */
    public function test_reset_guards_clears_registry(): void
    {
        $original = Auth::guard('web');
        Auth::resetGuards();
        $fresh = Auth::guard('web');

        $this->assertNotSame($original, $fresh);
    }

    /**
     * @return void
     */
    public function test_guard_logout_clears_only_that_guard(): void
    {
        $webUser = $this->makeUser(1);
        $apiUser = $this->makeUser(2);

        Auth::guard('web')->loginUser($webUser);
        Auth::guard('api')->loginUser($apiUser);

        Auth::guard('web')->logoutUser();

        $this->assertNull(Auth::guard('web')->resolveCurrentUser());
        $this->assertSame($apiUser, Auth::guard('api')->resolveCurrentUser());
    }

    // ─── Role and permission helpers ──────────────────────────────────────────

    /**
     * @return void
     */
    public function test_can_returns_false_when_no_user(): void
    {
        $this->assertFalse(Auth::can('edit-posts'));
    }

    /**
     * @return void
     */
    public function test_has_role_returns_false_when_no_user(): void
    {
        $this->assertFalse(Auth::hasRole('admin'));
    }

    /**
     * @return void
     */
    public function test_can_returns_true_when_user_has_permission(): void
    {
        $user = $this->makeAuthorizableUser(['edit-posts'], ['admin']);
        Auth::login($user);

        $this->assertTrue(Auth::can('edit-posts'));
    }

    /**
     * @return void
     */
    public function test_can_returns_false_when_user_lacks_permission(): void
    {
        $user = $this->makeAuthorizableUser([], []);
        Auth::login($user);

        $this->assertFalse(Auth::can('edit-posts'));
    }

    /**
     * @return void
     */
    public function test_has_role_returns_true_when_user_has_role(): void
    {
        $user = $this->makeAuthorizableUser([], ['admin']);
        Auth::login($user);

        $this->assertTrue(Auth::hasRole('admin'));
    }

    /**
     * @return void
     */
    public function test_has_role_returns_false_when_user_lacks_role(): void
    {
        $user = $this->makeAuthorizableUser([], []);
        Auth::login($user);

        $this->assertFalse(Auth::hasRole('admin'));
    }

    /**
     * @return void
     */
    public function test_can_throws_when_user_does_not_implement_authorizable(): void
    {
        Auth::login($this->makeUser());

        $this->expectException(\RuntimeException::class);
        Auth::can('edit-posts');
    }

    /**
     * @return void
     */
    public function test_has_role_throws_when_user_does_not_implement_authorizable(): void
    {
        Auth::login($this->makeUser());

        $this->expectException(\RuntimeException::class);
        Auth::hasRole('admin');
    }

    // ─── Remember-me tokens ───────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_login_with_remember_logs_in_and_returns_token(): void
    {
        $auth = new Auth();
        Auth::setInstance($auth);

        session_start();

        $user = $this->makeUser(1);
        $token = Auth::loginWithRemember($user);

        $this->assertTrue(Auth::check());
        $this->assertSame($user, Auth::user());
        $this->assertNotEmpty($token);

        session_destroy();
    }

    /**
     * @return void
     */
    public function test_login_with_remember_stores_hash_in_session(): void
    {
        $auth = new Auth();
        Auth::setInstance($auth);

        session_start();

        $token = Auth::loginWithRemember($this->makeUser(1));

        $stored = $_SESSION['auth_remember_token'] ?? null;

        session_destroy();

        $this->assertSame(hash('sha256', $token), $stored);
    }

    /**
     * @return void
     */
    public function test_verify_remember_token_returns_true_for_valid_token(): void
    {
        $auth = new Auth();
        Auth::setInstance($auth);

        session_start();

        $token = Auth::loginWithRemember($this->makeUser(1));

        $valid = Auth::verifyRememberToken($token);

        session_destroy();

        $this->assertTrue($valid);
    }

    /**
     * @return void
     */
    public function test_verify_remember_token_returns_false_for_wrong_token(): void
    {
        $auth = new Auth();
        Auth::setInstance($auth);

        session_start();

        Auth::loginWithRemember($this->makeUser(1));

        $valid = Auth::verifyRememberToken('wrong-token');

        session_destroy();

        $this->assertFalse($valid);
    }

    /**
     * @return void
     */
    public function test_attempt_remember_returns_true_when_token_found(): void
    {
        $user = $this->makeUser(1);
        $rememberToken = 'valid-remember-token';

        $provider = new readonly class ($user, $rememberToken) implements UserProviderInterface {
            /**
             * @param UserInterface $user
             * @param string        $rememberToken
             */
            public function __construct(
                private UserInterface $user,
                private string $rememberToken,
            ) {
            }

            /**
             * @param int|string $id
             *
             * @return UserInterface|null
             */
            public function findById(int|string $id): ?UserInterface
            {
                return null;
            }

            /**
             * @param string $token
             *
             * @return UserInterface|null
             */
            public function findByToken(string $token): ?UserInterface
            {
                return null;
            }

            /**
             * @param string $identifier
             *
             * @return UserInterface|null
             */
            public function findByCredentials(string $identifier): ?UserInterface
            {
                return null;
            }

            /**
             * @param string $token
             *
             * @return UserInterface|null
             */
            public function findByRememberToken(string $token): ?UserInterface
            {
                return $token === $this->rememberToken ? $this->user : null;
            }
        };

        $result = Auth::attemptRemember($rememberToken, $provider);

        $this->assertTrue($result);
        $this->assertTrue(Auth::check());
        $this->assertSame($user, Auth::user());
    }

    /**
     * @return void
     */
    public function test_attempt_remember_returns_false_when_token_not_found(): void
    {
        $provider = new readonly class () implements UserProviderInterface {
            /**
             * @param int|string $id
             *
             * @return UserInterface|null
             */
            public function findById(int|string $id): ?UserInterface
            {
                return null;
            }

            /**
             * @param string $token
             *
             * @return UserInterface|null
             */
            public function findByToken(string $token): ?UserInterface
            {
                return null;
            }

            /**
             * @param string $identifier
             *
             * @return UserInterface|null
             */
            public function findByCredentials(string $identifier): ?UserInterface
            {
                return null;
            }

            /**
             * @param string $token
             *
             * @return UserInterface|null
             */
            public function findByRememberToken(string $token): ?UserInterface
            {
                return null;
            }
        };

        $result = Auth::attemptRemember('bad-token', $provider);

        $this->assertFalse($result);
        $this->assertFalse(Auth::check());
    }

    /**
     * @return void
     */
    public function test_verify_remember_token_returns_false_without_session(): void
    {
        // No session started — should return false gracefully.
        $result = Auth::verifyRememberToken('any-token');

        $this->assertFalse($result);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     *
     * @return UserInterface&AuthorizableInterface
     */
    private function makeAuthorizableUser(array $permissions, array $roles): UserInterface&AuthorizableInterface
    {
        $hash = Auth::hashPassword('secret');

        return new readonly class (1, $hash, $permissions, $roles) implements UserInterface, AuthorizableInterface {
            /**
             * @param int          $id
             * @param string       $hash
             * @param list<string> $permissions
             * @param list<string> $roles
             */
            public function __construct(
                private int $id,
                private string $hash,
                private array $permissions,
                private array $roles,
            ) {
            }

            /**
             * @return int
             */
            public function getAuthId(): int
            {
                return $this->id;
            }

            /**
             * @return string
             */
            public function getAuthPassword(): string
            {
                return $this->hash;
            }

            /**
             * @param string $role
             *
             * @return bool
             */
            public function hasRole(string $role): bool
            {
                return in_array($role, $this->roles, true);
            }

            /**
             * @param string $permission
             *
             * @return bool
             */
            public function can(string $permission): bool
            {
                return in_array($permission, $this->permissions, true);
            }
        };
    }
}
