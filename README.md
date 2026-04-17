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
# Generate a personal access token for a user
php ez auth:token <user_id> <name> [--abilities=read,write] [--expires=3600]
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

## License

MIT — [Andreas Uretschnig](mailto:andreas.uretschnig@gmail.com)
