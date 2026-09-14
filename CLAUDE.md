# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

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
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
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
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

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

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` unless `--namespace=` overrides it (`bignum` → `BigNum`,
`opcache` → `OPCache`, and `dotenv` → `Env` are existing exceptions the guess
gets wrong).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4`), `phpstan.neon`, `phpunit.xml`
(test suite **and** coverage source), and `packages.sh` (alphabetical position).

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — editing it marks all ~40
  `CLAUDE.md` copies as drifted at once, so the next `composer full` would fail for
  a brand-new module. The generator prints which ports to claim instead.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| **next free** | **3311** | **6383** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project is the one exception, since it has no host/container split and uses `REDIS_PORT` for both.

> This table tracks only MySQL, Redis, and Meilisearch ports — the three services shared across multiple modules where a collision is otherwise easy to introduce. `ez-php/mail`'s Mailpit service is the one other module with published host ports: SMTP `1025` and web UI `8025`, mapped through `MAILPIT_SMTP_HOST_PORT`/`MAILPIT_API_HOST_PORT` in `modules/mail/docker-compose.yml` (mirroring the `*_HOST_PORT` pattern above), documented in `modules/mail/.env.example`. It isn't a table column because no other module runs Mailpit, so there is nothing to collide with — but a new module adding its own single-use service's ports should likewise parameterize them and document the defaults in its own `.env.example` rather than adding a column here.

### 5 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/auth

Session and Bearer-token authentication for ez-php applications.

---

## Source Structure

```
src/
├── Auth.php                       — Static façade and singleton; login/logout/check/user/id
├── AuthServiceProvider.php        — Registers Auth in the container; injects UserProviderInterface if bound
├── JwtServiceProvider.php         — Registers JwtManager and JwtBlacklist; reads JWT_SECRET + JWT_TTL from env
├── UserInterface.php              — Contract for authenticated user objects (getAuthId)
├── UserProviderInterface.php      — Contract for user lookup by ID or Bearer token
├── AuthorizableInterface.php      — Optional contract for policy-style authorization: can(ability, subject): bool
├── PersonalAccessToken.php        — Immutable value object: id, userId, name, tokenHash, abilities, expiry
├── PersonalAccessTokenManager.php — Token CRUD via DatabaseInterface: create, find, revoke, rotate, pruneExpired
├── Console/
│   └── TokenCommand.php          — auth:token command: generates a token for a user, prints raw token once
├── Jwt/
│   ├── JwtException.php          — Thrown on invalid/expired/malformed JWT
│   ├── JwtManager.php            — Issues and validates HMAC-HS256 JWTs; claims: sub, iat, exp
│   └── JwtBlacklist.php          — Cache-backed blacklist for logout; keyed by SHA-256(token), TTL = remaining lifetime
└── Middleware/
    ├── AuthMiddleware.php         — Validates Bearer tokens; supports static list or UserProviderInterface
    └── JwtMiddleware.php          — Validates JWT Bearer tokens; rejects blacklisted tokens; optionally resolves Auth user

database/
└── migrations/
    └── 2024_01_01_000000_create_personal_access_tokens_table.php — Copy to app's database/migrations/ before migrating

tests/
├── TestCase.php                   — Base PHPUnit test case
├── AuthTest.php                   — Covers Auth: login, logout, check, id, session restore, instance management
├── AuthServiceProviderTest.php    — Covers AuthServiceProvider registration with and without a UserProvider
├── PersonalAccessTokenTest.php    — Covers PersonalAccessToken: isExpired, can, abilities
├── Jwt/
│   ├── JwtManagerTest.php        — Covers JwtManager: issue, validate, expiry, signature, tampered payload
│   └── JwtBlacklistTest.php      — Covers JwtBlacklist: add, isBlacklisted, SHA-256 keying, custom prefix
└── Middleware/
    ├── AuthMiddlewareTest.php     — Covers AuthMiddleware: missing header, invalid token, static list, provider mode
    └── JwtMiddlewareTest.php      — Covers JwtMiddleware: missing header, invalid/expired/blacklisted token, user resolution
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
| `Auth::hashPassword($plain)` | Hashes a plain-text password via `password_hash()` with `PASSWORD_DEFAULT` (bcrypt) |
| `Auth::verifyPassword($plain, $hash)` | Verifies a plain-text password against a stored hash via `password_verify()` |

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
- **`Auth` is intentionally exempt from the thin-delegation façade pattern used by `Cache`/`Mail`/`Flag`/`Metrics`/`Ai`/`RateLimiter`/`Storage`.** Those façades delegate every call to one injected interface instance. `Auth` cannot follow that shape because it supports **named guards** (`Auth::guard('api')`): each guard is an independent, stateful `Auth` instance holding its own `$currentUser`, so the state and the authentication logic (`attemptLogin()`, `loginUserWithRemember()`, `checkRememberToken()`, session mutation) have to live on the same class the guard registry holds — there is no single shared service to delegate to. Splitting the logic into a separate `AuthManager` would just move the same per-guard state one level down without removing it, at the cost of an extra indirection layer. The static-state trade-off this implies under concurrent runtimes is already covered above in the class's own "Async / concurrent runtime warning" docblock.
- **No session management** — This module reads from and writes to an already-active PHP session (`session_status() === PHP_SESSION_ACTIVE`) but never calls `session_start()` or `session_destroy()`. Starting/destroying sessions is the application's responsibility (e.g. via a session middleware).
- **Password hashing as thin wrappers** — `Auth::hashPassword()` and `Auth::verifyPassword()` are pure delegates to PHP's `password_hash()` / `password_verify()`. They live here so callers never import raw PHP functions in application code, but carry no state and no algorithm logic of their own.
- **`UserProviderInterface` is optional** — `AuthServiceProvider` catches the `ContainerException` when the interface is not bound. This keeps the module functional for pure token-list scenarios without requiring a full user provider setup.
- **`Auth::getInstance()` fails open (lazily creates a no-provider instance), unlike `Storage::getInstance()`/`Flag::getInstance()`-style facades, which fail closed (throw if unconfigured).** This is intentional, not an inconsistency: a no-provider `Auth` is a fully valid, supported state — `AuthMiddleware`'s static-token-list mode and password hashing (`Auth::hashPassword()`/`verifyPassword()`) never need a `UserProviderInterface` or `AuthServiceProvider` at all. `Storage`/`Flag` have no equivalent "valid unconfigured" mode — every one of their operations requires a concrete driver, so silently creating an unconfigured instance would just defer a guaranteed failure to a more confusing call site. Do not change `Auth::getInstance()` to throw: that would break the static-token-list-only usage this module explicitly supports.
- **`AuthMiddleware` is `final`** — Extend behaviour by composing a new middleware that wraps or replaces it, not by subclassing.
- **JWT is HMAC-HS256 only** — No RS256 or other asymmetric algorithms. The signing key (`JWT_SECRET`) must never be exposed to clients. Changing the secret invalidates all active tokens immediately.

---

## Testing Approach

- **No external infrastructure required** — All tests run in-process. No database, no Redis, no real HTTP.
- **Session tests** — A subset of `AuthTest` calls `session_start()` and `session_destroy()` to exercise session-backed login/logout and restoration. These are in-process PHP sessions and require no server.
- **Always call `Auth::resetInstance()`** in `setUp()` and `tearDown()` of any test that exercises `Auth`. Forgetting this causes state to leak between tests.
- **Inline anonymous classes** replace mocks for `UserInterface` and `UserProviderInterface` — keeps tests explicit and avoids mock framework noise.
- **`#[UsesClass]` required** — PHPUnit is configured with `beStrictAboutCoverageMetadata=true`. Declare indirectly used classes with `#[UsesClass]`.

---

### JwtManager (`src/Jwt/JwtManager.php`)

Issues and validates stateless HMAC-HS256 JSON Web Tokens.

| Method | Behaviour |
|---|---|
| `issue(int\|string $sub): string` | Creates a signed JWT with claims `sub`, `iat`, `exp` |
| `validate(string $token): array<string, mixed>` | Verifies structure, algorithm, signature, and expiry; throws `JwtException` on failure |

**Token format:** `base64url(header).base64url(payload).base64url(HMAC-SHA256-signature)`

**Config:** Constructed with `string $secret` and `int $ttl` (seconds). `JwtServiceProvider` reads these from `JWT_SECRET` and `JWT_TTL` env vars.

---

### JwtBlacklist (`src/Jwt/JwtBlacklist.php`)

Cache-backed blacklist for logout support. The raw token is never stored — only a SHA-256 hash.

| Method | Behaviour |
|---|---|
| `add(string $token, int $expiresAt): void` | Blacklists a token with TTL = max(1, $expiresAt − now) |
| `isBlacklisted(string $token): bool` | Returns `true` when the token has been blacklisted |

**Key format:** `<prefix><sha256(token)>` — default prefix is `jwt_bl:`.

**Fallback:** `JwtServiceProvider` falls back to `ArrayDriver` when no `CacheInterface` is bound, making blacklisted tokens process-scoped (not shared across requests). Register `CacheServiceProvider` (Redis driver) before `JwtServiceProvider` for production logout support.

---

### JwtMiddleware (`src/Middleware/JwtMiddleware.php`)

Route-level middleware. Validates the `Authorization: Bearer <token>` header.

| Condition | Outcome |
|---|---|
| Missing or non-Bearer header | 401 Unauthorized |
| Invalid / expired / bad-signature token | 401 Unauthorized |
| Token is on the blacklist | 401 Unauthorized |
| UserProvider supplied, `findById()` returns null | 401 Unauthorized |
| Valid token, no provider | Calls next; `Auth::user()` is not set |
| Valid token, provider returns user | `Auth::login($user)` called; calls next |

Constructor: `(JwtManager $jwt, ?JwtBlacklist $blacklist = null, ?UserProviderInterface $userProvider = null)`

---

### JwtServiceProvider (`src/JwtServiceProvider.php`)

Registers JWT services in the container.

- `JwtManager` — bound lazily, reads `JWT_SECRET` and `JWT_TTL` from `getenv()`.
- `JwtBlacklist` — bound lazily, tries to resolve `CacheInterface`; falls back to `ArrayDriver`.

Register after `CacheServiceProvider` to get a fully functional blacklist.

---

### PersonalAccessToken (`src/PersonalAccessToken.php`)

Immutable value object representing a stored token record. The raw token is never held — only the SHA-256 hash. Properties: `id`, `userId`, `name`, `tokenHash`, `abilities` (string[]), `lastUsedAt`, `expiresAt`, `createdAt`.

| Method | Behaviour |
|---|---|
| `isExpired()` | Returns `true` when `expiresAt` is set and is in the past; `false` when no expiry |
| `can(string $ability)` | Returns `true` if `'*'` is in abilities (all-access) or ability is listed explicitly |

---

### PersonalAccessTokenManager (`src/PersonalAccessTokenManager.php`)

Manages token storage using `DatabaseInterface`. Works with the `personal_access_tokens` table created by the bundled migration.

| Method | Behaviour |
|---|---|
| `create(userId, name, abilities, expiresIn?)` | Generates raw token + hash, inserts row, returns `[rawToken, PersonalAccessToken]` |
| `find(rawToken)` | Hashes the raw token, looks up the row, touches `last_used_at`, returns null if missing or expired |
| `revoke(id)` | Deletes the token row by ID |
| `rotate(id)` | Revokes the old token and creates a new one with identical name/abilities/remaining TTL |
| `pruneExpired()` | Deletes all rows where `expires_at < now`; returns the row count |

Token format: `bin2hex(random_bytes(40))` — 80 hex characters. SHA-256 hash stored in the `token` column.

---

### TokenCommand (`src/Console/TokenCommand.php`)

Console command `auth:token`. Creates a personal access token for a user and prints the raw token once.

```
ez auth:token <user_id> <name> [--abilities=read,write] [--expires=3600]
```

Registration: `$app->registerCommand(TokenCommand::class)` before bootstrap (same pattern as `WorkCommand`).

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---|---|
| Session lifecycle (start/destroy) | Application session middleware |
| OAuth / credential flows beyond simple hashing | Application layer or future `ez-php/credentials` |
| OAuth / SSO flows | Application layer |
| OAuth / SSO flows | Application layer |
| User model / database schema | Application code implementing `UserInterface` |
| Rate limiting login attempts | `ez-php/rate-limiter` |
| HTTP Request / Response | `ez-php/http` |
| Middleware infrastructure | `ez-php/framework` (`MiddlewareInterface`) |
