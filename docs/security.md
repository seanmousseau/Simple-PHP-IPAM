# Security Notes

## Contents

- [HTTPS](#https)
- [Content Security Policy](#content-security-policy)
- [Authentication](#authentication)
- [Login rate limiting](#login-rate-limiting)
- [Login form protection](#login-form-protection)
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

## Content Security Policy

Every page response includes a strict `Content-Security-Policy` header:

```
Content-Security-Policy:
  default-src 'self';
  script-src 'self';
  style-src 'self';
  img-src 'self' data:;
  frame-ancestors 'none'
```

Key points:

- **No `unsafe-inline`** in either `script-src` or `style-src`. All JavaScript uses event delegation via `data-*` attributes in `app.js`. All styling uses external CSS classes — no inline `style=""` attributes remain in any template (v1.6 removed inline scripts; v1.9 removed inline styles).
- **`frame-ancestors 'none'`** prevents the app from being embedded in an iframe (equivalent to `X-Frame-Options: DENY`).
- **Login page extension:** when a widget-based `login_protection` method (Turnstile, hCaptcha, reCAPTCHA, Friendly Captcha) is active, `script-src` is extended on `login.php` only to include the provider's domain. All other pages remain unaffected.

Additional headers set on every response:

| Header | Value |
|--------|-------|
| `X-Frame-Options` | `DENY` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |

---

## Authentication

- Passwords are stored using PHP's `password_hash()` with `PASSWORD_DEFAULT` (bcrypt).
- `password_needs_rehash()` is checked on every login — hashes are silently upgraded if the cost factor changes.
- Session cookies are set with `Secure`, `HttpOnly`, and `SameSite=Strict`.
- `session.use_strict_mode` and `session.use_only_cookies` are enabled.
- The session ID is regenerated on login (`session_regenerate_id(true)`).
- **Timing normalisation:** when a username is not found or the account is inactive, a dummy `password_verify()` call is made so that response time does not reveal whether the username exists (v1.9).

### OIDC single sign-on

OIDC Authorization Code + PKCE is supported as an alternative to local passwords. ID token signatures are verified in-process using `openssl` — no network calls beyond the discovery and JWKS fetches.

- `preferred_username` and other claims are sanitised (characters outside `[a-zA-Z0-9._@\-]` stripped) before use as local usernames (v1.9).
- Email-based auto-link uses an email-only query — a username that happens to match another user's email cannot trigger a cross-account link (v1.9).

Auto-provisioned OIDC accounts are assigned an unusable random password and cannot log in locally unless an admin sets one. See the [OIDC guide](oidc.md).

### Default credentials

Change the bootstrap admin password **before** the site receives any traffic. The default credentials (`admin` / `ChangeMeNow!12345`) are well-known and must not be used in production.

---

## Login rate limiting

Failed login attempts are tracked per IP address in the `login_attempts` table.

- After `login_max_attempts` consecutive failures (default: **5**) within the lockout window, the IP is blocked for `login_lockout_seconds` (default: **15 minutes**).
- A successful login clears the failure counter for that IP.
- Blocked attempts are recorded in the audit log as `auth.login_blocked`.
- Rate limiting applies in demo mode as well as normal mode.
- Stale records are purged automatically — no cron job or manual cleanup is required.
- **Audit log privacy:** failed login entries record the IP address but not the submitted username, preventing mistyped passwords from appearing in logs (v1.9).

---

## Login form protection

*(Added in v1.9)*

Optional bot mitigation on the login form is available via the `login_protection` config block. This is separate from and complementary to IP-based rate limiting.

Supported methods: `honeypot`, `turnstile` (Cloudflare), `hcaptcha`, `recaptcha` (Google v2/v3), and `friendly_captcha`. See [`login_protection`](configuration.md#login_protection) in the configuration reference for available methods and setup instructions.

**Google reCAPTCHA Enterprise** is supported in addition to the standard reCAPTCHA v2/v3 service. When `recaptcha_enterprise.enabled` is `true`, the assessment is sent to the Enterprise API for scoring. See [`recaptcha_enterprise`](configuration.md#recaptcha_enterprise) for configuration details.

**Demo mode gate:** when the `demo_mode.gate` option is configured, the pre-login gate page (`demo_gate.php`) applies the same bot-mitigation widget before allowing access to the login form. This is distinct from login page protection and is configured under `demo_mode.gate` in `config.php`.

---

## Session management

- Sessions expire after `session_idle_seconds` of inactivity (default: **30 minutes**).
- On expiry the user is redirected to the login page with an informational message.
- The idle timeout is refreshed on every authenticated page load.

---

## CSRF protection

All POST endpoints call `csrf_require()`, which validates a per-session token stored in `$_SESSION['csrf']`. Requests with a missing or mismatched token are rejected with HTTP 403 and redirected to the login page so users with stale tabs can recover gracefully.

---

## Database access

- All queries use **PDO prepared statements** — no string interpolation of user input into SQL.
- SQLite WAL mode is enabled for better concurrency and crash safety.
- Foreign key enforcement is enabled (`PRAGMA foreign_keys = ON`).

---

## Binary IP storage

IP addresses and network prefixes are stored as raw 4-byte (IPv4) or 16-byte (IPv6) binary blobs in `network_bin` (subnets) and `ip_bin` (addresses) columns. This provides:

- **Correct sort order** — binary comparison of raw network addresses sorts numerically, not lexicographically.
- **Fast range queries** — containment checks use direct binary comparison, not string manipulation.

The text `ip` and `network` columns are stored alongside the blobs for display. `inet_pton()` encodes and `inet_ntop()` decodes at the application layer. Blobs are never compared with `==` — the code uses `hash_equals()` for safe timing-safe comparison where needed.

---

## Audit log integrity

The `audit_log` table is **append-only**. SQLite triggers prevent any `UPDATE` or `DELETE` on audit rows — even the database owner cannot silently alter past entries. The audit log records:

- Login, logout, and failed login events (including rate-limited blocks)
- All create / update / delete operations on subnets, addresses, sites, users, VLANs, VRFs, contacts, and tags *(v2.0+)*
- Database export and import events *(v2.0+)*
- CSV import events (dry-run and apply)
- Export actions
- API key lifecycle events (create, deactivate, activate, delete)

---

## File system hardening

The included `.htaccess` (Apache / LiteSpeed) blocks direct HTTP access to:

- `data/` directory and `*.sqlite` / `*.db` files
- `dialects/` directory (internal `Dialect` class hierarchy — `SqliteDialect`, `MysqlDialect`, `PgsqlDialect`, never meant to be served as a URL)
- `vendor/` directory (bundled Composer runtime libraries, starting v2.9.0)
- `*.sh`, `*.sql` files
- `config.php`, `lib.php`, `init.php`, `schema*.sql`, `PgsqlStatement.php`, `migrate.php`, `tmp_cleanup.php` at the web root
- Build and release artefacts (`*.tar.gz`, `*.zip`, `*.bundle.txt`, `SHA256SUMS`)

**Nginx users** must replicate these rules manually — Nginx does not process `.htaccess` files. See the [install guide](install.md#nginx) for the rules to translate.

**OpenLiteSpeed users** get full coverage out of the box — the shipped `.htaccess` uses root-level `RewriteRule` entries for `dialects/` and `vendor/` because OLS's lsphp handler dispatches PHP files before subdirectory-level rewrites fire (v2.11.0 #500). See the [OpenLiteSpeed setup notes](install.md#openlitespeed) for the one WebAdmin setting worth verifying (Auto Index → Off) and the automated regression guard (`.github/workflows/playwright-nightly.yml` runs the `.htaccess` assertion spec against a containerized OLS image on every nightly run alongside the Apache slot).

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
