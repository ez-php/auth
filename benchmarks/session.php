<?php

declare(strict_types=1);

/**
 * Performance benchmark for EzPhp\Auth\Auth.
 *
 * Measures the overhead of the in-memory authentication operations:
 * login, check, user retrieval, permission check, and logout.
 * Uses a fake in-memory UserProvider — no database required.
 *
 * Exits with code 1 if the per-iteration time exceeds the defined threshold,
 * allowing CI to detect performance regressions automatically.
 *
 * Usage:
 *   php benchmarks/session.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use EzPhp\Auth\Auth;
use EzPhp\Auth\AuthorizableInterface;
use EzPhp\Auth\UserInterface;
use EzPhp\Auth\UserProviderInterface;

const ITERATIONS = 10000;
const OPS_PER_ITER = 5;
const THRESHOLD_MS = 0.5; // per-iteration upper bound in milliseconds

// ── Fake user and provider ────────────────────────────────────────────────────

final class BenchUser implements UserInterface, AuthorizableInterface
{
    public function getAuthId(): int|string
    {
        return 1;
    }

    public function getAuthPassword(): string
    {
        return password_hash('secret', PASSWORD_BCRYPT);
    }

    public function can(string $permission): bool
    {
        return $permission === 'view-dashboard';
    }

    public function hasRole(string $role): bool
    {
        return $role === 'admin';
    }
}

final class BenchUserProvider implements UserProviderInterface
{
    private readonly BenchUser $user;

    public function __construct()
    {
        $this->user = new BenchUser();
    }

    public function findById(int|string $id): ?UserInterface
    {
        return $id === 1 ? $this->user : null;
    }

    public function findByToken(string $token): ?UserInterface
    {
        return null;
    }

    public function findByCredentials(string $identifier): ?UserInterface
    {
        return null;
    }

    public function findByRememberToken(string $token): ?UserInterface
    {
        return null;
    }
}

// ── Setup ─────────────────────────────────────────────────────────────────────

$auth = new Auth(new BenchUserProvider());
Auth::setInstance($auth);

$user = new BenchUser();

// Warm-up
Auth::login($user);
Auth::check();
Auth::user();
Auth::logout();

// ── Benchmark ─────────────────────────────────────────────────────────────────

$start = hrtime(true);

for ($i = 0; $i < ITERATIONS; $i++) {
    Auth::login($user);
    Auth::check();
    Auth::user();
    Auth::id();
    Auth::logout();
}

$end = hrtime(true);

$totalMs = ($end - $start) / 1_000_000;
$perIter = $totalMs / ITERATIONS;

echo sprintf(
    "Auth Benchmark (in-memory)\n" .
    "  Operations per iter  : %d (login, check, user, id, logout)\n" .
    "  Iterations           : %d\n" .
    "  Total time           : %.2f ms\n" .
    "  Per iteration        : %.3f ms\n" .
    "  Threshold            : %.1f ms\n",
    OPS_PER_ITER,
    ITERATIONS,
    $totalMs,
    $perIter,
    THRESHOLD_MS,
);

if ($perIter > THRESHOLD_MS) {
    echo sprintf(
        "FAIL: %.3f ms exceeds threshold of %.1f ms\n",
        $perIter,
        THRESHOLD_MS,
    );
    exit(1);
}

echo "PASS\n";
exit(0);
