<?php

declare(strict_types=1);

namespace Tests\Auth;

use EzPhp\Auth\Auth;
use EzPhp\Auth\UserInterface;
use EzPhp\Auth\UserProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
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
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Auth::resetInstance();
        parent::tearDown();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param int|string $id
     *
     * @return UserInterface
     */
    private function makeUser(int|string $id = 1): UserInterface
    {
        return new readonly class ($id) implements UserInterface {
            /**
             * Constructor
             *
             * @param int|string $id
             */
            public function __construct(private int|string $id)
            {
            }

            /**
             * @return int|string
             */
            public function getAuthId(): int|string
            {
                return $this->id;
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
        $provider = new readonly class ($user) implements UserProviderInterface {
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
        };

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
}
