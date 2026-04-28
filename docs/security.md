# Security

Simple PHP IPAM is designed for deployment on trusted internal networks. This guide covers the security model, available hardening options, and a configuration reference for all security-related settings.

## Contents

- [Threat model](#threat-model)
- [HTTPS](#https)
- [Content Security Policy](#content-security-policy)
- [Authentication](#authentication)
  - [Local accounts](#local-accounts)
  - [OIDC single sign-on](#oidc-single-sign-on)
  - [Two-factor authentication (TOTP)](#two-factor-authentication-totp)
  - [Email OTP 2FA](#email-otp-2fa)
  - [Passkeys (WebAuthn)](#passkeys-webauthn)
- [Session security](#session-security)
- [Account lockout](#account-lockout)
- [Login form protection](#login-form-protection)
- [API security](#api-security)
- [CSRF protection](#csrf-protection)
- [Output encoding](#output-encoding)
- [SQL injection](#sql-injection)
- [Database access](#database-access)
- [Audit log integrity](#audit-log-integrity)
- [File system hardening](#file-system-hardening)
- [Reverse proxy considerations](#reverse-proxy-considerations)
- [Security configuration reference](#security-configuration-reference)

---

## Threat model

Simple PHP IPAM is designed to protect against:

- **Unauthenticated access** — every page (except login, OIDC callback, and the health check) requires an authenticated session.
- **CSRF attacks** — every POST form is protected by a per-session CSRF token.
- **XSS** — all user-controlled output is HTML-escaped via `e()` before rendering; there is no `unsafe-inline` in the Content Security Policy.
- **SQL injection** — all queries use PDO prepared statements; direct string interpolation into SQL is prohibited and caught by Semgrep rules in CI.
- **Brute-force login** — IP-based and per-account rate limiting, with optional 2FA to prevent credential-stuffing from gaining access even with a known password.
- **Session hijacking** — session IDs are regenerated on login and on password change; cookies are `Secure`, `HttpOnly`, and `SameSite=Strict`.
- **Audit trail tampering** — the `audit_log` table is append-only via database triggers.

**What it is not designed for:**

- **Public internet deployment without additional hardening.** If the instance faces the internet, put it behind a reverse proxy with HTTP Basic Auth, IP allowlisting, or a WAF. The application's own authentication is not hardened against nation-state adversaries or automated credential-stuffing at scale.
- **Multi-tenant use.** All authenticated users share the same IP address database. Role separation (`admin` vs `readonly`) provides coarse access control, not tenant isolation.
- **Compliance certification.** The application provides the building blocks (audit log, 2FA, session timeouts) but has not been audited against any formal compliance framework.

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

- **No `unsafe-inline`** in either `script-src` or `style-src`. All JavaScript uses event delegation via `data-*` attributes in `app.js`. All styling uses external CSS classes — no inline `style=""` attributes remain in any template.
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

### Local accounts

- Passwords are stored using PHP's `password_hash()` with `PASSWORD_DEFAULT` (bcrypt).
- `password_needs_rehash()` is checked on every login — hashes are silently upgraded if the cost factor changes.
- Session cookies are set with `Secure`, `HttpOnly`, and `SameSite=Strict`.
- `session.use_strict_mode` and `session.use_only_cookies` are enabled.
- The session ID is regenerated on login (`session_regenerate_id(true)`).
- **Timing normalisation:** when a username is not found or the account is inactive, a dummy `password_verify()` call is made so that response time does not reveal whether the username exists.
- **Default credentials:** change the bootstrap admin password before the site receives any traffic. The default credentials (`admin` / `ChangeMeNow!12345`) are well-known and must not be used in production. A security warning banner is displayed to all admins until the password is changed.

### OIDC single sign-on

OIDC Authorization Code + PKCE is supported as an alternative to local passwords. ID token signatures are verified in-process using `openssl` — no network calls beyond the discovery and JWKS fetches.

Auto-provisioned OIDC accounts are assigned an unusable random password and cannot log in locally unless an admin sets one. See the [OIDC guide](oidc.md).

### Two-factor authentication (TOTP)

*(Added in v3.6.0)*

TOTP (RFC 6238) 2FA is available for all local accounts. It uses time-based one-time passwords compatible with any standard authenticator app (Google Authenticator, Authy, 1Password, Bitwarden, etc.).

#### Requirements

`app_secret` must be set in `config.php` before any user can enable 2FA. This key is used to encrypt stored TOTP secrets at rest. Changing `app_secret` after users have enrolled will invalidate all existing secrets and lock those users out until an admin resets their 2FA.

Generate a suitable key:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Add it to `config.php`:

```php
'app_secret' => 'your-64-character-hex-string-here',
```

#### Enrollment

1. Log in and go to **Account** (user menu, top right).
2. In the **Two-Factor Authentication** section, click **Enable 2FA**.
3. Scan the QR code with your authenticator app, or enter the manual key shown below it.
4. Enter the 6-digit code from your app to confirm enrollment.
5. Save the **backup codes** displayed after confirmation — these are shown once and cannot be recovered.

#### Mid-login challenge

After entering username and password, if 2FA is enabled the user is redirected to `totp_verify.php` to enter the 6-digit code from their authenticator app. The password verification and 2FA check are separate steps so that a failed 2FA attempt does not count against the IP-based login rate limiter.

#### Backup codes

Eight single-use backup codes are generated at enrollment. Each code is in `XXXXXXXX-XXXXXXXX` format. Backup codes can be used on the 2FA challenge screen instead of the 6-digit TOTP code. Each code is valid once only and is consumed on use.

When all backup codes are used, new ones can be generated from the Account page (this re-enrolls 2FA with a fresh secret and new backup codes).

Store backup codes securely — in a password manager, not in the same location as the device running the authenticator app.

#### Admin reset

Admins can reset another user's 2FA from **Admin → Users**. The reset action:

- Disables 2FA for that user.
- Deletes all of that user's backup codes.
- Is recorded in the audit log as `user.totp_reset`.

Use this when a user loses access to their authenticator app and has no backup codes.

#### Disabling 2FA

Users can disable their own 2FA from the Account page. This requires entering the current 6-digit TOTP code to confirm. Disabling 2FA also deletes all backup codes.

#### Admin global toggle

*(Added in v3.16.0)*

Admins can globally disable TOTP from **Admin → Settings → Multi-Factor Auth** by clearing the `mfa.totp_enabled` setting (default `true`). When disabled:

- TOTP enrollment is removed from the Account page UI.
- The login dispatcher skips TOTP — users with TOTP enrolled fall through to Email OTP or Passkey at login.
- **Per-user `users.totp_enabled` is preserved** — re-enabling the global setting restores every user's existing TOTP without re-running the QR enrollment flow. Disabling globally does not revoke individual enrollments.
- `mfa.require` enforcement now considers only methods that are both enrolled *and* globally enabled. A user enrolled in TOTP only, with `mfa.totp_enabled = false`, is treated as not having MFA and will be redirected to enroll Email OTP or a passkey.

The Settings page also surfaces a warning banner when `mfa.totp_enabled = true` but `app_secret` is unset in `config.php` — TOTP enrollment would silently fail in that state.

---

### Email OTP 2FA

*(Added in v3.14.0)*

Email OTP is a second 2FA method that sends a 6-digit code to the user's registered email address at each login. It requires a working SMTP configuration and an email address on the account.

**Priority:** the login dispatcher honours the user's `preferred_mfa_method` (set from the Account page; added in v3.16.0) when set. When `preferred_mfa_method` is `NULL`, the legacy priority order applies: passkey → TOTP → Email OTP. Each verify page also offers switch buttons to the user's other enrolled methods at login (added in v3.15.2 for TOTP; extended to all three methods in v3.16.0).

#### Requirements

- SMTP must be configured (Admin → Settings → Email Delivery). The `mfa.email_otp_enabled` setting must be enabled by an admin.
- The user must have a verified email address set on their account.

#### Enrollment

1. Log in and go to **Account** (user menu).
2. In the **Email OTP** section, click **Enable Email OTP**.
3. A 6-digit code is sent to your registered email address. Enter it in the confirmation field.
4. Enrollment is confirmed and Email OTP takes effect on your next login.

#### Mid-login challenge

After entering username and password, if Email OTP is enrolled (and TOTP is not), the user is redirected to `email_otp_verify.php`. The 6-digit code sent to their email must be entered within 10 minutes. After 5 consecutive incorrect attempts the challenge is aborted and the user must log in again.

#### Admin controls

| Setting | Description |
|---------|-------------|
| `mfa.email_otp_enabled` | Allow users to enroll Email OTP. Disabled by default. Requires SMTP. |
| `mfa.require` | Require all users to enroll in at least one 2FA method (TOTP, Email OTP, or passkey) before accessing the application. Admins are not exempt. |

#### Admin reset

Admins can reset a user's Email OTP enrollment from **Admin → Users → Reset Email OTP**. This:

- Disables Email OTP for that user.
- Clears any pending OTP code.
- Is recorded in the audit log as `user.email_otp_reset`.

---

### Passkeys (WebAuthn)

*(Added in v3.15.0)*

Passkeys are a phishing-resistant 2FA method based on the WebAuthn/FIDO2 standard. Instead of typing a code, the user authenticates with a hardware security key, platform authenticator (Touch ID, Face ID, Windows Hello), or a passkey stored in a password manager.

**Priority:** when a user has a passkey registered, the passkey challenge is presented at login. TOTP and Email OTP are not checked for passkey-enrolled users.

#### Requirements

- The `mfa.passkeys_enabled` setting must be enabled by an admin (Admin → Settings → Multi-Factor Auth).
- The browser must support WebAuthn (all modern browsers do; Chromium is required for the Playwright test suite due to the virtual authenticator API).
- HTTPS is mandatory — browsers refuse WebAuthn on plain HTTP.

#### Enrollment

1. Log in and go to **Account** (user menu).
2. In the **Passkeys** section, click **Add Passkey**.
3. Enter a friendly name for the passkey (e.g. "YubiKey 5" or "MacBook Touch ID").
4. Follow the browser prompt to touch your security key or use the platform authenticator.
5. The passkey appears in the list immediately. You can register multiple passkeys for redundancy.

#### Mid-login challenge

After entering username and password, if the user has at least one passkey registered and passkeys are enabled, they are redirected to `passkey_verify.php`. The browser presents the authenticator prompt automatically. The challenge is single-use with a 60-second server-side TTL.

#### Admin controls

| Setting | Description |
|---------|-------------|
| `mfa.totp_enabled` | Admin global toggle to enable/disable TOTP enrolment + login challenge. Default `true`. Disabling preserves per-user `users.totp_enabled` so re-enabling restores enrolments without re-enrollment. `mfa.require` only counts methods both enrolled and globally enabled. The Settings UI warns if `mfa.totp_enabled = true` but `app_secret` in `config.php` is unset. (v3.16.0) |
| `mfa.passkeys_enabled` | Allow users to register and use passkeys. Disabled by default. |
| `mfa.require` | Require all users to enroll in at least one 2FA method (TOTP, Email OTP, or passkey). |

#### Admin reset

Admins can delete all passkeys for a user from **Admin → Users → Reset Passkeys**. This:

- Removes all registered credentials for that user.
- Is recorded in the audit log as `user.passkey_reset`.

Use this when a user loses all their registered authenticators.

#### User deletion

Users can delete individual passkeys from the **Passkeys** section of their Account page. Deleting all passkeys removes the passkey 2FA requirement for subsequent logins.

---

### Password policy

*(Settings-backed as of v3.14.0; previously config.php only)*

Password complexity requirements are configured in Admin → Settings → Password Policy and enforced on all password-change flows (`change_password.php` and `reset_password.php`). Admin changes take effect immediately — no server restart required.

| Setting | Default | Description |
|---------|---------|-------------|
| `password_policy.min_length` | `12` | Minimum character count |
| `password_policy.require_uppercase` | `false` | At least one uppercase letter |
| `password_policy.require_lowercase` | `false` | At least one lowercase letter |
| `password_policy.require_number` | `false` | At least one digit |
| `password_policy.require_symbol` | `false` | At least one non-alphanumeric character |
| `password_policy.max_password_age_days` | `0` | Force change after N days; `0` = never |

---

## Session security

### Idle timeout

Configurable via `security.session_idle_seconds` (database setting, editable at Admin → Settings). Default: 1800 seconds (30 minutes). On expiry the user is redirected to the login page with an informational message. The idle timer is refreshed on every authenticated page load.

### Absolute session lifetime

*(Added in v3.6.0)*

Regardless of activity, sessions expire after `session.absolute_lifetime_minutes` (default: 480 minutes / 8 hours). This prevents a session from persisting indefinitely if a user leaves their browser open. Setting to `0` disables the absolute limit.

Configured via `config.php`:

```php
'session' => [
    'absolute_lifetime_minutes' => 480,
],
```

### Session rotation

The session ID is regenerated on login (`session_regenerate_id(true)`) and on password change, preventing session fixation attacks.

### Session isolation

Each IPAM install uses a unique session cookie name derived from the filesystem path of the install directory, preventing session sharing between multiple IPAM instances on the same server.

---

## Account lockout

### IP-based login rate limiting

Failed login attempts are tracked per IP address in the `login_attempts` table.

- After `security.login_max_attempts` consecutive failures (default: 5) within the lockout window, the IP is blocked for `security.login_lockout_seconds` (default: 15 minutes).
- A successful login clears the failure counter for that IP.
- Blocked attempts are recorded in the audit log as `auth.login_blocked`.
- Stale records are purged automatically — no cron job is required.
- Failed login entries record the IP address but not the submitted username, preventing mistyped passwords from appearing in logs.

### Per-account lockout

After `security.account_lockout_max_attempts` consecutive failed login attempts for a specific username (default: 10, across all source IPs), the account is locked for `security.account_lockout_seconds` (default: 15 minutes). Admins can unlock an account manually from Users admin.

### Persistent 2FA lockout

*(Added in v3.6.0)*

After `auth.lockout_after_failures` consecutive 2FA failures (default: 10) against a single account, the account is locked until `auth.lockout_duration_minutes` (default: 30 minutes) elapses or an admin unlocks it.

This lockout is distinct from the IP-based and per-account login lockouts:

- It tracks failures at the 2FA challenge step, not the password step.
- It is stored persistently in `users.locked_until` and `users.lock_reason`, so it survives server restarts.
- The Users admin page shows a "Locked (2FA)" badge for affected accounts.
- Admin unlock from the Users page clears both the time-windowed lockout and the persistent 2FA lockout.

Configured via `config.php`:

```php
'auth' => [
    'lockout_after_failures'  => 10,
    'lockout_duration_minutes' => 30,
],
```

---

## Login form protection

Optional bot mitigation on the login form is available via the `login_protection` config block. This is separate from and complementary to IP-based rate limiting.

Supported methods: `honeypot`, `turnstile` (Cloudflare), `hcaptcha`, `recaptcha` (Google v2/v3), and `friendly_captcha`. See [`login_protection`](configuration.md#login_protection) in the configuration reference for available methods and setup instructions.

---

## API security

### API key authentication

All API endpoints require a valid `Authorization: Bearer <key>` header. Keys are generated using `random_bytes(32)` (256 bits of entropy). Only a SHA-256 hash of the key is stored — the raw key cannot be recovered from the database.

### Per-API-key rate limiting

*(Added in v3.6.0)*

A sliding-window rate limit is applied per API key, stored in the `rate_limit_buckets` table.

- Default: 300 requests per 60-second window.
- Exceeding the limit returns HTTP 429 with a `Retry-After` header indicating when the window resets.
- Configurable via Admin → Settings:
  - `api.rate_limit_window_seconds` — window size (default: 60)
  - `api.rate_limit_requests` — max requests per window (default: 300)

The existing IP-based login rate limiter continues to apply independently.

---

## CSRF protection

All POST endpoints call `csrf_require()`, which validates a per-session token stored in `$_SESSION['csrf']`. Requests with a missing or mismatched token are rejected with HTTP 403. Users with stale tabs are redirected to the login page rather than receiving a bare error, so they can recover gracefully.

---

## Output encoding

All user-controlled data is run through `e()` (wraps `htmlspecialchars`) before output. Semgrep rules in CI (`ipam-xss-unsanitized-echo`) enforce this — `e()` is registered as an XSS sanitizer so correct usage is never flagged as a false positive.

---

## SQL injection

All database queries use PDO prepared statements with named parameters. Direct string concatenation into SQL is prohibited. Semgrep rule `ipam-sqli-raw-concat` catches violations in CI.

---

## Database access

- All queries use **PDO prepared statements** — no string interpolation of user input into SQL.
- SQLite WAL mode is enabled for better concurrency and crash safety.
- Foreign key enforcement is enabled (`PRAGMA foreign_keys = ON`).
- Binary IP columns (`ip_bin`, `network_bin`) use `PDO::PARAM_LOB` binding to ensure correct BLOB affinity storage and sort order.

---

## Audit log integrity

The `audit_log` table is **append-only**. SQLite triggers prevent any `UPDATE` or `DELETE` on audit rows — even the database owner cannot silently alter past entries. The audit log records:

- Login, logout, and failed login events (including rate-limited blocks)
- 2FA enrollment, disable, and admin reset events
- All create / update / delete operations on subnets, addresses, sites, users, VLANs, VRFs, contacts, and tags
- Database export and import events
- CSV import events (dry-run and apply)
- Export actions
- API key lifecycle events (create, deactivate, activate, delete)

---

## File system hardening

The included `.htaccess` (Apache / LiteSpeed) blocks direct HTTP access to:

- `data/` directory and `*.sqlite` / `*.db` files
- `dialects/` directory (internal `Dialect` class hierarchy)
- `vendor/` directory (bundled Composer runtime libraries)
- `*.sh`, `*.sql` files
- `config.php`, `lib.php`, `init.php`, `schema*.sql`, `migrate.php`, `tmp_cleanup.php` at the web root
- Build and release artefacts (`*.tar.gz`, `*.zip`, `SHA256SUMS`)

**Nginx users** must replicate these rules manually. See the [install guide](install.md#nginx).

The recommended file permissions:

| Path | Permissions |
|------|------------|
| `data/` | `0700` — web server user only |
| `data/ipam.sqlite` | `0600` — web server user only |
| `config.php` | `0640` — not world-readable |

See the full [permissions reference](install.md#file-permissions-reference).

---

## Reverse proxy considerations

If you place a reverse proxy (nginx, Caddy, HAProxy, AWS ALB, etc.) in front of the application:

- Set `'proxy_trust' => true` in `config.php` only if the proxy reliably strips or overwrites `X-Forwarded-Proto` from untrusted clients.
- Ensure the proxy forwards the real client IP in `REMOTE_ADDR` or a trusted header — the login rate limiter keys on `REMOTE_ADDR`. If all requests appear to come from a single proxy IP, the rate limiter will be ineffective.
- Apply rate limiting or WAF rules at the proxy layer as an additional defence-in-depth measure.

---

## Security configuration reference

### `config.php` keys

These are set directly in `config.php` on the server. They are read before the database is opened and cannot be changed via the admin UI.

| Key | Default | Description |
|-----|---------|-------------|
| `app_secret` | `''` | Encryption key for stored TOTP secrets. Required before enabling 2FA. Generate with: `php -r "echo bin2hex(random_bytes(32));"`. Changing this after enrollment invalidates all existing 2FA secrets. |
| `session.absolute_lifetime_minutes` | `480` | Absolute maximum session duration in minutes. Sessions expire at this point regardless of activity. Set to `0` to disable the absolute limit. |
| `auth.lockout_after_failures` | `10` | Number of consecutive 2FA failures that trigger a persistent account lockout. |
| `auth.lockout_duration_minutes` | `30` | How long a 2FA-triggered persistent lockout lasts in minutes. Admins can unlock early from the Users admin page. |

Example `config.php` additions for v3.6.0:

```php
// Encryption key for TOTP secrets. Required if enabling 2FA.
// Generate: php -r "echo bin2hex(random_bytes(32));"
'app_secret' => '',

// Absolute session lifetime. Default: 480 minutes (8 hours). 0 = disabled.
'session' => [
    'absolute_lifetime_minutes' => 480,
],

// Persistent lockout for repeated 2FA failures.
'auth' => [
    'lockout_after_failures'   => 10,
    'lockout_duration_minutes' => 30,
],
```

### Database settings

These are configured via **Admin → Settings** and take effect on the next request without a server restart.

| Key | Default | Description |
|-----|---------|-------------|
| `security.session_idle_seconds` | `1800` | Idle session timeout in seconds. Users are logged out after this much inactivity. |
| `security.login_max_attempts` | `5` | Consecutive login failures per IP before IP lockout. |
| `security.login_lockout_seconds` | `900` | IP lockout duration in seconds (default: 15 minutes). |
| `security.account_lockout_max_attempts` | `10` | Consecutive login failures per username before per-account lockout. |
| `security.account_lockout_seconds` | `900` | Per-account lockout duration in seconds (default: 15 minutes). |
| `api.rate_limit_window_seconds` | `60` | Sliding window size for per-API-key rate limiting. *(v3.6.0)* |
| `api.rate_limit_requests` | `300` | Maximum requests per window per API key. *(v3.6.0)* |
