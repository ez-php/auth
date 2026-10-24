# ez-php/auth

Authentication module for the [ez-php framework](https://github.com/ez-php/framework) — session, Bearer token, JWT, and personal access token authentication with a flexible user provider interface.

[![CI](https://github.com/ez-php/auth/actions/workflows/ci.yml/badge.svg)](https://github.com/ez-php/auth/actions/workflows/ci.yml)

## Requirements

- PHP 8.5+
- ez-php/framework 0.*

## Installation

```bash
composer require ez-php/auth
```

## Setup

Register the service provider in your application:

```php
$app->register(\EzPhp\Auth\AuthServiceProvider::class);

// Optional — register JWT support:
$app->register(\EzPhp\Auth\JwtServiceProvider::class);
```

Implement `UserProviderInterface` to connect your user storage:

```php
use EzPhp\Auth\UserInterface;
use EzPhp\Auth\UserProviderInterface;

class UserProvider implements UserProviderInterface
{
    // Session restoration (the `auth_id` stored by Auth::login()).
    public function findById(int|string $id): ?UserInterface { ... }

    // AuthMiddleware provider mode: the raw Bearer token from the request.
    public function findByToken(string $token): ?UserInterface { ... }

    // Auth::attempt(): look the user up by login name / e-mail before password_verify().
    public function findByCredentials(string $identifier): ?UserInterface { ... }

    // Auth::attemptRemember(): receives the RAW token from the cookie — look it up by
    // hash('sha256', $token), never store or compare the raw value (see "Remember me").
    public function findByRememberToken(string $token): ?UserInterface { ... }
}
```

Your user class implements `UserInterface`: `getAuthId()` (the identifier stored in the
session) and `getAuthPassword()` (the `password_hash()` hash `Auth::attempt()` verifies against).

Bind it before `AuthServiceProvider`:

```php
$this->app->bind(UserProviderInterface::class, UserProvider::class);
```

## Usage

### Session / Bearer token authentication

```php
use EzPhp\Auth\Auth;

// Authenticate
Auth::login($user);
$user = Auth::user();
Auth::logout();

// Protect routes with middleware
$router->get('/dashboard', $handler)->middleware(\EzPhp\Auth\Middleware\AuthMiddleware::class);
```

`AuthMiddleware` checks `Authorization: Bearer <token>` against the bound `UserProviderInterface`
(`findByToken()`), or against a static token list when constructed as
`new AuthMiddleware(['api-key-1', 'api-key-2'])`. With neither — e.g. registered by class name
while no `UserProviderInterface` is bound — it rejects every request with 401 instead of
letting any token through.

### Remember me

`Auth::loginWithRemember($user)` logs the user in and returns a random raw token. The module
does not persist it for you — the application stores it, and `findByRememberToken()` reads it back:

```php
$token = Auth::loginWithRemember($user);

// Persist only the hash (e.g. a `remember_token` column on the users table) …
$users->updateRememberToken($user->getAuthId(), hash('sha256', $token));

// … and hand the raw token to the browser.
$response = $response->withCookie('remember', $token, 60 * 60 * 24 * 30, secure: true, httpOnly: true, sameSite: 'Lax');

// On a later request without a session:
$raw = $request->cookie('remember');
if (is_string($raw) && $raw !== '') {
    Auth::attemptRemember($raw, $provider); // provider: WHERE remember_token = hash('sha256', $raw)
}
```

Store and compare only `hash('sha256', $token)` — a leaked database then yields no usable
cookie values. `findByRememberToken()` receives the raw cookie value precisely so the provider
does the hashing at the lookup. Rotate the stored hash on every remember-login and clear it on
logout.

The hash `loginWithRemember()` also writes to `$_SESSION['auth_remember_token']` is a
same-session check only: `Auth::verifyRememberToken($token)` confirms that a presented cookie
was issued in *this* session (e.g. before a sensitive action). It cannot restore a login after
the session has ended — that is what the persisted hash and `attemptRemember()` are for.

### JWT authentication

```dotenv
JWT_SECRET=  # at least 32 bytes, e.g. php -r 'echo bin2hex(random_bytes(32));'
JWT_TTL=3600
```

`JWT_SECRET` is required: resolving `JwtManager` throws when it is unset or shorter than
32 bytes (`JwtManager::MIN_SECRET_BYTES`, the HS256 minimum from RFC 7518), instead of
signing with an empty key that would let anyone forge tokens.

```php
$jwt = $app->make(\EzPhp\Auth\Jwt\JwtManager::class);

$token    = $jwt->issue($user->getAuthId());
$claims   = $jwt->validate($token);
$response = ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $jwt->ttl()];

// Protect routes
$router->get('/api/me', $handler)->middleware(\EzPhp\Auth\Middleware\JwtMiddleware::class);
```

### Rate-limited login attempts

For per-route throttling,
compose `ez-php/rate-limiter`'s `ThrottleMiddleware` in front of your login route instead,
passing the route's limit as middleware parameters (`maxAttempts,decaySeconds[,bucket]`):

```php
use EzPhp\RateLimiter\Middleware\ThrottleMiddleware;

$app->middlewareAlias('throttle', ThrottleMiddleware::class); // before bootstrap

// 5 attempts per 10 minutes in the 'login' bucket, keyed per client IP — independent
// of the throttle guarding any other route.
$router->post('/login', $handler)
    ->middleware('throttle:5,600,login') // runs first — throttled requests never reach AuthMiddleware
    ->middleware(AuthMiddleware::class);
```

`Auth`'s static-façade design is untouched by this — the throttle lives entirely in the
middleware chain in front of it.

To throttle *failed* logins per user (not per route) and tell the client why a login failed,
use `LoginThrottle` inside the controller:

```php
use EzPhp\Auth\LoginThrottle;

$throttle = new LoginThrottle($limiter, maxAttempts: 5, lockoutSeconds: 300, failureDelayMs: 250);
$result = $throttle->attempt($username, $password, $provider, throttleKey: strtolower($username) . '|' . $ip);

if ($result->isLockedOut()) {
    return (new Response('Too many attempts', 429))->withHeader('Retry-After', (string) $result->availableIn);
}

if (!$result->succeeded()) {
    return new Response('Invalid credentials', 401); // $result->remainingAttempts left
}
```

A success logs the user in and resets the counter; the failed-attempt lockout is
independent of `Auth::attempt()`, which keeps returning a plain `bool`.

### Session guard for routes

`SessionAuthMiddleware` lets a request through only when a user is logged in via the session
(`Auth::check()`), otherwise it answers `401` — JSON (`{"error":{"code":401,...}}`) for clients
sending `Accept: application/json`. Pass a closure to build the response yourself:

```php
use EzPhp\Auth\Middleware\SessionAuthMiddleware;

$this->app->bind(SessionAuthMiddleware::class, fn () => new SessionAuthMiddleware(
    static fn (RequestInterface $request) => (new Response('', 302))->withHeader('Location', '/login'),
));

$router->get('/dashboard', $handler)->middleware(SessionAuthMiddleware::class);
```

### Example login/register scaffold

`auth:scaffold` writes a starting-point login/register/logout controller and routes file:

```bash
php ez auth:scaffold
```

Creates:

- `app/Controllers/AuthController.php` — `login()`/`register()`/`logout()` built on `Auth::login()`/`hashPassword()`/`verifyPassword()`
- `routes/auth.php` — the matching `POST /login`, `POST /register`, `POST /logout` routes

Require the routes file from `routes/web.php` to activate it:

```php
require __DIR__ . '/auth.php';
```

Refuses to run if `AuthController.php` already exists. Not a complete user-management system —
`findUserByEmail()`/`createUser()` in the generated controller throw `RuntimeException` until
you replace them with your application's actual user lookup/persistence; `ez-php/auth` has no
user model or schema to generate those against.

Register the command before bootstrap, same as `auth:token`:

```php
$app->registerCommand(\EzPhp\Auth\Console\AuthScaffoldCommand::class);
```

### Personal access tokens

```php
$manager = $app->make(\EzPhp\Auth\PersonalAccessTokenManager::class);

[$rawToken, $token] = $manager->create($userId, 'my-token', ['read', 'write']);
$token = $manager->find($rawToken);
$manager->revoke($token->id);
```

One-time tokens for e-mail verification or password reset live in the same table but are
bound to one purpose, work once, and never authenticate as a Bearer token:

```php
$raw = $manager->issueOneTime($userId, 'password-reset', ttl: 3600); // revokes earlier reset links
// … send $raw in the reset link …

$token = $manager->consume($rawFromLink, 'password-reset');          // null if invalid, expired, used or wrong purpose
if ($token !== null) {
    // reset the password of $token->userId
}

$manager->revokeFor($userId, 'password-reset');                      // e.g. after a successful reset
```

To revoke every regular token of a user with a given name — e.g. all rotating refresh
tokens after a password reset — use `revokeNamed()`. It never touches one-time tokens:

```php
$revoked = $manager->revokeNamed($userId, 'refresh'); // number of deleted tokens
```

Register the bundled migration before migrating:

```
database/migrations/2024_01_01_000000_create_personal_access_tokens_table.php
```

### Console command

```bash
# Generate a personal access token for a user (the raw token is printed once)
php ez auth:token <user_id> <name> [--abilities=read,write] [--expires=3600]
```

`--abilities` defaults to `*` (all abilities); omit `--expires` for a token that never expires.

The command is opt-in — `AuthServiceProvider` does not register it, since issuing tokens from
the CLI should be a deliberate choice. Register it before bootstrap:

```php
$app->registerCommand(\EzPhp\Auth\Console\TokenCommand::class);
```

## Classes

| Class | Description |
|---|---|
| `Auth` | Static façade — `login()`, `logout()`, `user()`, `check()`, `id()`, `hashPassword()`, `verifyPassword()`, `dummyPasswordHash()` (verify an unknown user's password against it so the timing doesn't reveal which accounts exist) |
| `AuthServiceProvider` | Registers `Auth` singleton; optionally injects `UserProviderInterface` |
| `UserInterface` | Contract for authenticated user objects — `getAuthId()`, `getAuthPassword()` |
| `UserProviderInterface` | Contract for user lookup — `findById()`, `findByToken()`, `findByCredentials()`, `findByRememberToken()` |
| `AuthorizableInterface` | Optional contract for authorization checks on user objects |
| `PersonalAccessToken` | Immutable value object — `isExpired()`, `can()` |
| `PersonalAccessTokenManager` | Token CRUD — `create()`, `find()`, `revoke()`, `rotate()`, `pruneExpired()`; `revokeNamed()`; one-time tokens — `issueOneTime()`, `consume()`, `revokeFor()` |
| `AuthMiddleware` | Bearer token middleware (static list or provider mode) |
| `SessionAuthMiddleware` | Session guard — 401 (plain or JSON) unless `Auth::check()`, optional response closure |
| `LoginThrottle` / `LoginResult` | Throttled login returning success / invalid / locked out (`availableIn`) with a caller-supplied key |
| `JwtMiddleware` | JWT Bearer token middleware with optional blacklist and user resolution |
| `JwtManager` | Issues and validates HMAC-HS256 JWTs |
| `JwtBlacklist` | Cache-backed token blacklist (SHA-256 keyed) |
| `JwtServiceProvider` | Registers `JwtManager` and `JwtBlacklist` |
| `Console\TokenCommand` | `auth:token` CLI command |
| `Console\AuthScaffoldCommand` | `auth:scaffold` CLI command — example login/register controller + routes |

## License

MIT — [Andreas Uretschnig](mailto:andreas.uretschnig@gmail.com)
