# Changelog

All notable changes to `ez-php/auth` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [v1.0.1] — 2026-03-25

### Changed
- Tightened all `ez-php/*` dependency constraints from `"*"` to `"^1.0"` for predictable resolution

---

## [v1.0.0] — 2026-03-24

### Added
- Session-based authentication — login, logout, and session persistence via PHP native sessions
- Bearer token authentication — stateless token validation for API routes
- `UserProviderInterface` — pluggable user lookup contract; implement to integrate any user storage backend
- `Auth` static facade — `login()`, `logout()`, `user()`, `check()`, `id()` accessible globally after bootstrapping
- `AuthServiceProvider` — binds the authenticator and registers the `Auth` facade alias
- Rate limiter integration — login throttling via `ez-php/rate-limiter` to prevent brute-force attacks
- `AuthException` for authentication failures and unauthenticated access
