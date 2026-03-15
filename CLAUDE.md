# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
2. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
3. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse   # PHPStan only
composer cs        # CS Fixer only
composer test      # PHPUnit only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

When creating a new module or `CLAUDE.md` anywhere in this repository:

**CLAUDE.md structure:**
- Start with the full content of `CODING_GUIDELINES.md`, verbatim
- Then add `---` followed by `# Package: ez-php/<name>` (or `# Directory: <name>`)
- Module-specific section must cover:
  - Source structure (file tree with one-line descriptions per file)
  - Key classes and their responsibilities
  - Design decisions and constraints
  - Testing approach and any infrastructure requirements (e.g. needs MySQL, Redis)
  - What does **not** belong in this module

**Each module needs its own:**
`composer.json` · `phpstan.neon` · `phpunit.xml` · `.php-cs-fixer.php` · `.gitignore` · `.github/workflows/ci.yml` · `README.md` · `tests/TestCase.php`

**Docker setup:**   
run `vendor/bin/docker-init` from the new module root to scaffold Docker files (requires `"ez-php/docker": "0.*"` in `require-dev`). The script reads the package name from `composer.json`, copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the project, replacing `{{MODULE_NAME}}` placeholders — skips files that already exist. After scaffolding, adapt `docker-compose.yml` and `.env.example` for the module's required services (MySQL, Redis, etc.) and set a unique `DB_PORT` — increment by one per package starting with `3306` (root).

---

# Package: ez-php/auth

Session and Bearer-token authentication for ez-php applications.

---

## Source Structure

```
src/
├── Auth.php                       — Static façade and singleton; login/logout/check/user/id
├── AuthServiceProvider.php        — Registers Auth in the container; injects UserProviderInterface if bound
├── UserInterface.php              — Contract for authenticated user objects (getAuthId)
├── UserProviderInterface.php      — Contract for user lookup by ID or Bearer token
└── Middleware/
    └── AuthMiddleware.php         — Validates Bearer tokens; supports static list or UserProviderInterface

tests/
├── TestCase.php                   — Base PHPUnit test case
├── AuthTest.php                   — Covers Auth: login, logout, check, id, session restore, instance management
├── AuthServiceProviderTest.php    — Covers AuthServiceProvider registration with and without a UserProvider
└── Middleware/
    └── AuthMiddlewareTest.php     — Covers AuthMiddleware: missing header, invalid token, static list, provider mode
```

---

## Key Classes and Responsibilities

### Auth (`src/Auth.php`)

Static façade backed by a managed singleton instance. Provides the public API consumed by controllers and other code.

| Method | Behaviour |
|---|---|
| `Auth::check()` | Returns `true` if a user is currently authenticated |
| `Auth::user()` | Returns the authenticated `UserInterface` or `null` |
| `Auth::id()` | Returns `getAuthId()` of the current user or `null` |
| `Auth::login($user)` | Sets the current user; persists `auth_id` to `$_SESSION` when a session is active |
| `Auth::logout()` | Clears the current user; removes `auth_id` from `$_SESSION` when a session is active |

**Instance lifecycle:**
- `Auth::setInstance(Auth)` — replaces the singleton (used by `AuthServiceProvider` and tests)
- `Auth::getInstance()` — returns the singleton, creating a no-provider instance lazily if none set
- `Auth::resetInstance()` — sets the singleton to `null` (tests must call this in `setUp`/`tearDown`)

**Session restoration:** When `resolveUser()` is called and `$currentUser` is `null`, if a `UserProviderInterface` is configured and a PHP session is active, the stored `auth_id` is used to call `findById()` and restore the user.

**Global state is intentional and documented** — the static singleton is the deliberate design to make `Auth::user()` callable from anywhere without container access. It is the only global state in this module.

---

### UserInterface (`src/UserInterface.php`)

Minimal contract. Any application user model must implement it.

```php
public function getAuthId(): int|string;
```

---

### UserProviderInterface (`src/UserProviderInterface.php`)

Resolves users from a backing store (database, cache, in-memory, etc.).

```php
public function findById(int|string $id): ?UserInterface;   // session restoration
public function findByToken(string $token): ?UserInterface; // token authentication
```

Application code must implement and bind this interface in a service provider before `AuthServiceProvider` boots.

---

### AuthServiceProvider (`src/AuthServiceProvider.php`)

Registers the `Auth` singleton in the container and sets the static instance.

- **`register()`** — Binds `Auth::class` as a lazy factory. Attempts to resolve `UserProviderInterface` from the container; silently falls back to `null` if not bound (no-provider mode is valid).
- **`boot()`** — No-op. Auth is resolved lazily on first access.

**Provider registration order matters:** bind `UserProviderInterface` in a provider that runs *before* `AuthServiceProvider`, or the factory will not find it.

---

### AuthMiddleware (`src/Middleware/AuthMiddleware.php`)

Route-level middleware. Validates the `Authorization: Bearer <token>` header.

Two modes:

| Mode | How to configure | Behaviour |
|---|---|---|
| Static token list | `new AuthMiddleware(['secret-key'])` | Accepts any token in the list; no user is set |
| UserProvider | `new AuthMiddleware(userProvider: $provider)` | Calls `findByToken($token)`; calls `Auth::login($user)` on success |

Returns `401 Unauthorized` when:
- `Authorization` header is missing or not a Bearer token
- Provider mode: `findByToken()` returns `null`
- Static mode: token is not in `$validTokens` (only when list is non-empty)

If both `$validTokens` is empty and `$userProvider` is `null`, any Bearer token passes through (open/no-op mode — useful for development).

---

## Design Decisions and Constraints

- **Static façade with a managed singleton** — `Auth` uses a static instance so controllers can call `Auth::user()` without injecting the object. The singleton is set explicitly by `AuthServiceProvider`, not through `static::` magic, so it can be replaced in tests via `Auth::setInstance()`.
- **No session management** — This module reads from and writes to an already-active PHP session (`session_status() === PHP_SESSION_ACTIVE`) but never calls `session_start()` or `session_destroy()`. Starting/destroying sessions is the application's responsibility (e.g. via a session middleware).
- **No password hashing** — Credential verification (passwords, OAuth, etc.) belongs in the application or a future `ez-php/credentials` module. This module only handles identity after credentials have been verified.
- **`UserProviderInterface` is optional** — `AuthServiceProvider` catches the `ContainerException` when the interface is not bound. This keeps the module functional for pure token-list scenarios without requiring a full user provider setup.
- **`AuthMiddleware` is `final`** — Extend behaviour by composing a new middleware that wraps or replaces it, not by subclassing.
- **No JWT or OAuth support** — Out of scope. The token is treated as an opaque string; interpretation is delegated to `UserProviderInterface::findByToken()`.

---

## Testing Approach

- **No external infrastructure required** — All tests run in-process. No database, no Redis, no real HTTP.
- **Session tests** — A subset of `AuthTest` calls `session_start()` and `session_destroy()` to exercise session-backed login/logout and restoration. These are in-process PHP sessions and require no server.
- **Always call `Auth::resetInstance()`** in `setUp()` and `tearDown()` of any test that exercises `Auth`. Forgetting this causes state to leak between tests.
- **Inline anonymous classes** replace mocks for `UserInterface` and `UserProviderInterface` — keeps tests explicit and avoids mock framework noise.
- **`#[UsesClass]` required** — PHPUnit is configured with `beStrictAboutCoverageMetadata=true`. Declare indirectly used classes with `#[UsesClass]`.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---|---|
| Session lifecycle (start/destroy) | Application session middleware |
| Password hashing and verification | Application layer or future `ez-php/credentials` |
| JWT creation and validation | Application layer or a dedicated JWT package |
| OAuth / SSO flows | Application layer |
| User model / database schema | Application code implementing `UserInterface` |
| Rate limiting login attempts | `ez-php/rate-limiter` |
| HTTP Request / Response | `ez-php/http` |
| Middleware infrastructure | `ez-php/framework` (`MiddlewareInterface`) |
