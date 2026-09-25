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
`EzPhp\<PascalCase>` (each `-`-separated word upper-cased) unless `--namespace=`
overrides it. Existing exceptions the guess gets wrong: `bignum` → `BigNum`,
`dataloader` → `DataLoader`, `dotenv` → `Env`, `graphql` → `GraphQL`, `oauth` → `OAuth`,
`opcache` → `OPCache`, `swagger-ui` → `SwaggerUI`, `webauthn` → `WebAuthn` and
`websocket` → `WebSocket`; `websocket-client` → `WebsocketClient`, `websocket-tls` → `WebsocketTls`,
`webauthn-metadata` → `WebauthnMetadata` and `metrics-statsd` → `MetricsStatsd` are
intentional lower-case-word namespaces, and `testing-application` shares `EzPhp\Testing\`
with `testing`).

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
needs it — root `composer.json` (`autoload.psr-4` **and** the shared
`autoload-dev` `Tests\` directory list), `phpstan.neon`, `phpunit.xml` (test suite
**and** coverage source), and `packages.sh` (alphabetical position) — in both
generated and `--repo` mode.

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — claim the "next free" row by
  editing the table in `CODING_GUIDELINES.md` (never in a `CLAUDE.md` copy) and run
  `composer guidelines:sync` in the same change. Editing it drifts every `CLAUDE.md`
  until the sync runs, which is why the generator only reminds you instead of doing
  it. Skipping the edit leaves "next free" stale, so the next module collides.

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

Pass `--extensions` to merge PHP extension install blocks (apt packages plus `docker-php-ext-install`/`pecl` lines) directly into `docker/app/Dockerfile`, instead of hand-editing it afterward — supported extensions: `bcmath`, `gmp`, `gd`, `imagick`:

```
vendor/bin/docker-init --extensions=gmp,bcmath
vendor/bin/docker-init --extensions=gd,imagick
```

When run from a module directory inside this monorepo, any requested extension not already present is also merged into the shared root `docker/app/Dockerfile` — the container `composer full` at the root actually runs against, distinct from the module's own standalone image.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/` (application template) | 3308 | 6383 (`REDIS_PORT`) | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| `ez-php/event-store` | 3311 | — | — |
| **next free** | **3312** | **6384** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project and the `ez-php/` application template are the two exceptions, since both have no host/container split and use `REDIS_PORT` for both (the template's other in-container Redis settings — `CACHE_REDIS_PORT`, `QUEUE_REDIS_PORT`, `RATE_LIMITER_REDIS_PORT`, `HEALTH_REDIS_PORT` — stay fixed at `6379` regardless, same as every other module).

> This table tracks only MySQL, Redis, and Meilisearch ports — the three services shared across multiple modules where a collision is otherwise easy to introduce. Mailpit is the one other service with published host ports: SMTP `1025` and web UI `8025`. `ez-php/mail` maps them through `MAILPIT_SMTP_HOST_PORT`/`MAILPIT_API_HOST_PORT` in `modules/mail/docker-compose.yml` (mirroring the `*_HOST_PORT` pattern above, documented in `modules/mail/.env.example`); the root project and the `ez-php/` template each run their own Mailpit on the same defaults (`MAIL_PORT`/`MAIL_WEB_PORT`), so **these three stacks cannot run at the same time** without overriding those variables. It isn't a table column because no module beyond those three runs Mailpit — but a new module adding its own single-use service's ports should likewise parameterize them and document the defaults in its own `.env.example` rather than adding a column here.

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
│   ├── TokenCommand.php          — auth:token command: generates a token for a user, prints raw token once
│   └── AuthScaffoldCommand.php   — auth:scaffold command: writes an example login/register/logout controller + routes file into the application
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
├── Console/
│   └── AuthScaffoldCommandTest.php — Covers AuthScaffoldCommand: writes controller + routes, refuses to overwrite, stub content
├── Migration/
│   └── AuthMigrationsMysqlTest.php — Runs every database/migrations/*.php up() and down() on MySQL; skipped without DB_HOST
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
| `Auth::logout()` | Clears the current user; when a session is active, removes `auth_id` and `auth_remember_token` from `$_SESSION` and regenerates the session ID (mirroring `login()`) |
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
| Static token list | `new AuthMiddleware(['secret-key'])` | Accepts any token in the list (each compared with `hash_equals()`, no early exit); no user is set |
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
- **`Auth::getInstance()` fails open (lazily creates a no-provider instance), unlike façades such as `Storage::getInstance()` or `Flag` (whose calls go through a `FlagManager` set by `Flag::setManager()`), which fail closed (throw if unconfigured).** This is intentional, not an inconsistency: a no-provider `Auth` is a fully valid, supported state — `AuthMiddleware`'s static-token-list mode and password hashing (`Auth::hashPassword()`/`verifyPassword()`) never need a `UserProviderInterface` or `AuthServiceProvider` at all. `Storage`/`Flag` have no equivalent "valid unconfigured" mode — every one of their operations requires a concrete driver, so silently creating an unconfigured instance would just defer a guaranteed failure to a more confusing call site. Do not change `Auth::getInstance()` to throw: that would break the static-token-list-only usage this module explicitly supports.
- **`AuthMiddleware` is `final`** — Extend behaviour by composing a new middleware that wraps or replaces it, not by subclassing.
- **JWT is HMAC-HS256 only** — No RS256 or other asymmetric algorithms. The signing key (`JWT_SECRET`) must never be exposed to clients. Changing the secret invalidates all active tokens immediately.

---
- **`ez-php/cache` and `ez-php/rate-limiter` are hard `require`s even though their use is optional at runtime.** `JwtBlacklist` needs a `CacheInterface`, and `Auth`'s login-throttling argument is typed `?RateLimiterInterface` (`Auth.php`) and may be `null`. The packages stay in `require` so the type declarations always resolve and `JwtServiceProvider` works out of the box; an application that never uses JWT blacklisting or throttling simply never binds them. Moving them to `suggest` would require a `class_exists` guard around every use and a lock-file refresh, and was judged not worth it.
- **Static instance is wired in `boot()`** — `AuthServiceProvider::boot()` calls `Auth::setInstance($app->make(Auth::class))`; the binding closure itself has no side effect. `Auth::getInstance()` still lazily creates a provider-less `Auth` when no provider is registered (standalone/test use) — in an application this fallback is never reached because `boot()` runs first.


## Testing Approach

- **No external infrastructure required, except one MySQL check** — All tests run in-process (no Redis, no real HTTP). `AuthMigrationsMysqlTest` runs the shipped migrations against MySQL (`DB_HOST`/`DB_TESTING_DATABASE`, same convention as `ez-php/orm`'s schema tests) because DDL mistakes only surface in the production dialect — a `DEFAULT` on the `TEXT` `abilities` column shipped unnoticed because no test ran the migration, and failed on MySQL (error 1101). It is skipped when `DB_HOST` is unset. It builds tables through `ez-php/orm`'s `Schema`, hence `ez-php/orm` in `require-dev` (and `suggest`, since applications need it to run the migration).
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

Registration: `$app->registerCommand(TokenCommand::class)` before bootstrap — `AuthServiceProvider` does **not** auto-register it (unlike `ez-php/queue`'s commands), because issuing tokens from the CLI is opt-in.

---

### AuthScaffoldCommand (`src/Console/AuthScaffoldCommand.php`)

Console command `auth:scaffold`. Writes `app/Controllers/AuthController.php` (login/register/logout actions built on the `Auth` facade) and `routes/auth.php` (the matching route definitions) into the application. Refuses to run if `AuthController.php` already exists — never overwrites application code.

```
ez auth:scaffold
```

Registration: `$app->registerCommand(AuthScaffoldCommand::class)` before bootstrap, like `TokenCommand`. The optional `$basePath` defaults to the working directory, which is what makes class-name registration autowirable.

Not a complete user-management system — the generated controller's `findUserByEmail()`/`createUser()` stubs throw `RuntimeException` until the application replaces them with real lookups/persistence, since `ez-php/auth` has no user model or schema of its own to generate against.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---|---|
| Session lifecycle (start/destroy) | Application session middleware |
| OAuth2 / SSO flows (authorization code, PKCE, token exchange) | `ez-php/oauth` |
| User model / database schema | Application code implementing `UserInterface` |
| Rate limiting login attempts | `ez-php/rate-limiter` — see README.md "Rate-limited login attempts" for the recipe (`'throttle:5,600,login'` wired ahead of `AuthMiddleware`) |
| HTTP Request / Response | `ez-php/http` |
| Middleware infrastructure | `ez-php/framework` (`MiddlewareInterface`) |
