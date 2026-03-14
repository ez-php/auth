# ezphp/auth

Authentication module for the [ez-php framework](https://github.com/ezphp/framework) — session and token-based auth with a flexible user provider interface.

[![CI](https://github.com/ezphp/auth/actions/workflows/ci.yml/badge.svg)](https://github.com/ezphp/auth/actions/workflows/ci.yml)

## Requirements

- PHP 8.5+
- ezphp/framework ^1.0

## Installation

```bash
composer require ezphp/auth
```

## Setup

Register the service provider in your application:

```php
$app->register(\EzPhp\Auth\AuthServiceProvider::class);
```

Implement `UserProviderInterface` to connect your user storage:

```php
use EzPhp\Auth\UserProviderInterface;

class UserProvider implements UserProviderInterface
{
    public function findById(int|string $id): ?object { ... }
    public function findByCredentials(array $credentials): ?object { ... }
    public function validateCredentials(object $user, array $credentials): bool { ... }
}
```

Then bind your provider in a service provider:

```php
$this->app->bind(UserProviderInterface::class, UserProvider::class);
```

## Usage

```php
$auth = $app->make(\EzPhp\Auth\Auth::class);

// Authenticate
if ($auth->attempt(['email' => $email, 'password' => $password])) {
    $user = $auth->user();
}

// Protect routes with middleware
$router->get('/dashboard', $handler)->middleware(\EzPhp\Auth\Middleware\AuthMiddleware::class);
```

## License

MIT — [Andreas Uretschnig](mailto:andreas.uretschnig@gmail.com)
