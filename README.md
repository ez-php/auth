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
use EzPhp\Auth\UserProviderInterface;

class UserProvider implements UserProviderInterface
{
    public function findById(int|string $id): ?UserInterface { ... }
    public function findByToken(string $token): ?UserInterface { ... }
}
```

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

### JWT authentication

```dotenv
JWT_SECRET=your-secret-key
JWT_TTL=3600
```

```php
$jwt = $app->make(\EzPhp\Auth\Jwt\JwtManager::class);

$token    = $jwt->issue($user->getAuthId());
$claims   = $jwt->validate($token);

// Protect routes
$router->get('/api/me', $handler)->middleware(\EzPhp\Auth\Middleware\JwtMiddleware::class);
```

### Rate-limited login attempts

Login-attempt throttling is not part of this module (see "What Does NOT Belong Here") —
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
| `Auth` | Static façade — `login()`, `logout()`, `user()`, `check()`, `id()`, `hashPassword()`, `verifyPassword()` |
| `AuthServiceProvider` | Registers `Auth` singleton; optionally injects `UserProviderInterface` |
| `UserInterface` | Contract for authenticated user objects — `getAuthId()` |
| `UserProviderInterface` | Contract for user lookup — `findById()`, `findByToken()` |
| `AuthorizableInterface` | Optional contract for authorization checks on user objects |
| `PersonalAccessToken` | Immutable value object — `isExpired()`, `can()` |
| `PersonalAccessTokenManager` | Token CRUD — `create()`, `find()`, `revoke()`, `rotate()`, `pruneExpired()` |
| `AuthMiddleware` | Bearer token middleware (static list or provider mode) |
| `JwtMiddleware` | JWT Bearer token middleware with optional blacklist and user resolution |
| `JwtManager` | Issues and validates HMAC-HS256 JWTs |
| `JwtBlacklist` | Cache-backed token blacklist (SHA-256 keyed) |
| `JwtServiceProvider` | Registers `JwtManager` and `JwtBlacklist` |
| `Console\TokenCommand` | `auth:token` CLI command |
| `Console\AuthScaffoldCommand` | `auth:scaffold` CLI command — example login/register controller + routes |

## License

MIT — [Andreas Uretschnig](mailto:andreas.uretschnig@gmail.com)
