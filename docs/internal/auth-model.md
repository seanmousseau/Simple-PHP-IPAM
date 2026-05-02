# Authentication model

> Reference for the auth surface: roles, helpers, CSRF, OIDC, MFA (TOTP, Email OTP, WebAuthn passkeys). CLAUDE.md keeps the load-bearing rules (CSRF on every POST, self-protection guards, last-active-admin guard); this doc is the implementation reference for sessions touching auth code.

---

## Roles

| Role | Capabilities |
|---|---|
| `admin` | Full access including all admin pages |
| `readonly` | Read-only access; all write operations return 403 |

---

## Auth helpers (lib.php)

| Helper | Purpose |
|---|---|
| `is_logged_in(): bool` | Boolean session check |
| `require_login(): void` | Redirect to login if not authenticated; also enforces session idle timeout |
| `require_role('admin'): void` | 403 if not admin |
| `require_write_access(): void` | 403 if readonly |
| `current_user(): array` | Returns `['id', 'username', 'role']` from session |
| `login_user(int $uid, string $username, string $role, ?PDO $db = null): void` | Sets session, regenerates ID, loads persisted theme if `$db` provided |

After calling `login_user()`, **always update `last_login_at`**:

```php
$db->prepare("UPDATE users SET last_login_at=datetime('now') WHERE id=:id")
   ->execute([':id' => $uid]);
```

Use `ipam_dialect()->now()` instead of literal `datetime('now')` if the code path is reachable on MySQL/Postgres (login is, via the multi-engine testing instances).

---

## CSRF

Every POST form must include:

```php
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
```

And the POST handler must call `csrf_require()` at the top of the handler.

The API (`api.php`) does **not** use CSRF — it is stateless and authenticated per-request via Bearer token. CSRF is only for browser-session-authenticated POSTs.

---

## Self-protection guards (users.php)

These actions are blocked both server-side and hidden in the UI for the logged-in user's own account:

- `toggle_active` (disable/enable)
- `set_role`
- `unlink_oidc`
- `delete`

The **last-active-admin guard** uses: count active admins **excluding the target user** (`AND id != :id`). Only applies when the target is **active AND admin** — never block deletion of an inactive admin.

---

## OIDC

Authorization Code + PKCE flow. Pure PHP, no Composer packages, requires `openssl` extension.

### Key functions in `lib.php`

| Function | Purpose |
|---|---|
| `oidc_enabled(array $config): bool` | Feature gate |
| `oidc_discovery(array $config): array` | Fetches/caches `/.well-known/openid-configuration` |
| `oidc_jwks(string $uri, bool $forceRefresh): array` | Fetches/caches JWKS |
| `oidc_verify_id_token(string $idToken, array $jwks, array $expect): array` | Verifies RS256/384/512 |
| `jwk_rsa_to_pem(array $jwk): string` | DER SubjectPublicKeyInfo from JWK `n`/`e`, no ext-gmp |

Cache files live in `data/tmp/` with 1-hour TTL. A single automatic cache-bust retry handles in-flight key rotation.

### Claim mapping on login

| Claim | Mapped to |
|---|---|
| `preferred_username` | username (fallback: email local-part, then `sub`) |
| `name` | display name |
| `email` | email field |
| `sub` | `users.oidc_sub` (unique, partial index where NOT NULL) |

### Auto-link order

1. Try `preferred_username` match against existing users.
2. Try `email` / username match.
3. Provision a new account.

### Why hand-rolled instead of a library

See `runtime-dependency-policy.md` → "Explicitly not adopted". The hand-rolled OIDC works; we have not adopted a JWT/JWK library on speculation. Revisit if a security-sensitive bug surfaces or if the RFC tracking burden becomes obviously not worth it.

---

## MFA

Three methods, all gated by per-user enrollment + per-install policy.

### TOTP (RFC 6238)

- Library: `robthree/twofactorauth ^2.1` (see `runtime-dependency-policy.md`)
- Secret material: derived from `app_secret` (v3.x — single tenant) or HKDF per-tenant in v4.0.0 (see `v4-tenancy-design.md`)
- QR rendering: `assets/vendor/qrcode.min.js` (vanilla JS, ~20KB)
- Audit action: `auth.totp_login`

### Email OTP

- Sent via the configured mail transport (PHPMailer SMTP if `smtp.enabled=true`, else native `mail()`)
- One-time code with short TTL stored in DB
- Audit action: `auth.email_otp_login`

### WebAuthn passkeys (v3.15.0)

- Library: `lbuchs/webauthn ^2.1`
- Stores credential ID, public key, sign count per user
- Supports platform authenticators (Touch ID, Windows Hello) and roaming authenticators (YubiKey)
- Audit action: `auth.passkey_challenge`

### MFA method switching

- `auth.mfa_method_switch` — user changed their preferred method
- `auth.mfa_preferred_set` — admin or user set the default method for an account

The `auth-actions.md` doc has the full action vocabulary.

---

## Session configuration

Configured in `init.php` before `session_start()`:

- `Secure` (HTTPS-only)
- `HttpOnly` (no JS access)
- `SameSite=Strict`
- Strict mode (`session.use_strict_mode=1`) — rejects uninitialised session IDs
- Custom session name per install (`IPAMSESSID_<8-char-hash-of-install-path>`) so multiple instances on the same domain don't collide
- Session save path under `data/sessions/` with web-deny `.htaccess`

Session idle timeout enforced by `require_login()` based on `security.session_idle_seconds` setting (default 1800).

---

## Cross-references

- `CLAUDE.md` → "Authentication & authorisation" — the load-bearing rules (CSRF, self-protection, last-active-admin).
- `adding-an-api-endpoint.md` — API auth (Bearer token, readonly key check).
- `audit-actions.md` — full auth action vocabulary.
- `runtime-dependency-policy.md` — why TOTP/WebAuthn use libraries but OIDC doesn't.
- `v4-tenancy-design.md` — per-tenant key derivation for v4.0.0.
- `docs/oidc.md`, `docs/security.md` — user-facing documentation.
