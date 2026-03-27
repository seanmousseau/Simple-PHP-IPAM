# Security Notes

## Contents

- [HTTPS](#https)
- [Authentication](#authentication)
- [Login rate limiting](#login-rate-limiting)
- [Session management](#session-management)
- [CSRF protection](#csrf-protection)
- [Database access](#database-access)
- [Audit log integrity](#audit-log-integrity)
- [File system hardening](#file-system-hardening)
- [REST API keys](#rest-api-keys)
- [Reverse proxy considerations](#reverse-proxy-considerations)

---

## HTTPS

**HTTPS is required.** The application redirects all HTTP traffic to HTTPS at the application layer and sets `Secure` on all session cookies. Do not run this in production over plain HTTP.

If you terminate TLS at a reverse proxy, set `'proxy_trust' => true` in `config.php` so the app trusts `X-Forwarded-Proto: https`. See the [configuration guide](configuration.md#behind-a-reverse-proxy).

---

## Authentication

- Passwords are stored using PHP's `password_hash()` with `PASSWORD_DEFAULT` (bcrypt).
- `password_needs_rehash()` is checked on every login — hashes are silently upgraded if the cost factor changes.
- Session cookies are set with `Secure`, `HttpOnly`, and `SameSite=Strict`.
- `session.use_strict_mode` and `session.use_only_cookies` are enabled.
- The session ID is regenerated on login (`session_regenerate_id(true)`).

### Default credentials

Change the bootstrap admin password **before** the site receives any traffic. The default credentials (`admin` / `ChangeMeNow!12345`) are well-known and must not be used in production.

---

## Login rate limiting

From v0.11, failed login attempts are tracked per IP address in the `login_attempts` table.

- After `login_max_attempts` consecutive failures (default: **5**) within the lockout window, the IP is blocked for `login_lockout_seconds` (default: **15 minutes**).
- A successful login clears the failure counter for that IP.
- Blocked attempts are recorded in the audit log as `auth.login_blocked`.
- Stale records are purged automatically — no cron job or manual cleanup is required.

These defaults can be tuned in `config.php`. See the [configuration reference](configuration.md#login_max_attempts).

---

## Session management

- Sessions expire after `session_idle_seconds` of inactivity (default: **30 minutes**).
- On expiry the user is redirected to the login page with an informational message.
- The idle timeout is refreshed on every authenticated page load.

---

## CSRF protection

All POST endpoints call `csrf_require()`, which validates a per-session token stored in `$_SESSION['csrf']`. Requests with a missing or mismatched token are rejected with HTTP 400.

---

## Database access

- All queries use **PDO prepared statements** — no string interpolation of user input into SQL.
- SQLite WAL mode is enabled for better concurrency and crash safety.
- Foreign key enforcement is enabled (`PRAGMA foreign_keys = ON`).

---

## Audit log integrity

The `audit_log` table is **append-only**. SQLite triggers prevent any `UPDATE` or `DELETE` on audit rows — even the database owner cannot silently alter past entries. The audit log records:

- Login, logout, and failed login events (including rate-limited blocks)
- All create / update / delete operations on subnets, addresses, sites, and users
- CSV import events (dry-run and apply)
- Export actions
- API key lifecycle events (create, deactivate, activate, delete)

---

## File system hardening

The included `.htaccess` (Apache / LiteSpeed) blocks direct HTTP access to:

- `data/` directory and `*.sqlite` / `*.db` files
- `*.sh`, `*.sql`, `*.json` files
- Build and release artefacts (`*.tar.gz`, `*.zip`, `*.bundle.txt`, `SHA256SUMS`)

**Nginx users** must replicate these rules manually — Nginx does not process `.htaccess` files. See the [install guide](install.md#nginx) for the rules to translate.

The recommended file permissions further limit exposure:

| Path | Permissions |
|------|------------|
| `data/` | `0700` — web server user only |
| `data/ipam.sqlite` | `0600` — web server user only |
| `config.php` | `0640` — not world-readable |

See the full [permissions reference](install.md#file-permissions-reference).

---

## REST API keys

API keys grant read-only access to the JSON API (`api.php`).

- Keys are generated using `random_bytes(32)` (256 bits of entropy) and encoded as 64-character hex strings.
- Only a **SHA-256 hash** of the key is stored — the raw key cannot be recovered from the database.
- If a key is lost, delete it and generate a new one.
- Keys can be deactivated instantly from **Admin → API Keys** without deleting them.
- All key lifecycle events are recorded in the audit log.
- The API is **read-only** — no write operations are exposed.

Pass keys via the `Authorization: Bearer <key>` header. Avoid passing them as query parameters in environments where URLs may appear in server logs or browser history.

---

## Reverse proxy considerations

If you place a reverse proxy (nginx, Caddy, HAProxy, AWS ALB, etc.) in front of the application:

- Set `'proxy_trust' => true` in `config.php` only if the proxy reliably strips or overwrites `X-Forwarded-Proto` from untrusted clients.
- Ensure the proxy forwards the real client IP in `REMOTE_ADDR` or a trusted header — the login rate limiter keys on `REMOTE_ADDR`. If all requests appear to come from a single proxy IP, the rate limiter will be ineffective.
- Apply rate limiting or WAF rules at the proxy layer as an additional defence-in-depth measure.
