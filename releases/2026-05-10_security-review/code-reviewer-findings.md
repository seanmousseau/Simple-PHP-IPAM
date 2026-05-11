# Security review findings — 2026-05-10 EOD

**Reviewer:** pr-review-toolkit:code-reviewer agent
**Repo state:** v3.27.7 merged + deployed to all 7 targets (commit cb52d42 + the merge at 576660a)
**Purpose:** input for 2026-05-11 morning roadmap reshuffle + v3.28.0 scope lockdown

## Summary

| Severity | Count |
|---|---|
| Critical | 0 |
| High | 0 |
| Medium | 4 |
| Low | 4 |
| Note | 4 |

**Bottom line:** No new Critical/High findings on top of Pass C. The v3.27.7 F-S3-01 webhook-secret encryption work (lib.php encrypt/decrypt helpers, dispatch loop, save handlers, migration) is well-shaped — return contract distinguishes empty/legacy/decrypted/null/throw correctly; AES-256-GCM with random 12-byte IV per encrypt; HMAC verification via `hash_equals` everywhere it matters; SSRF guard hits before decrypt in dispatch. The restore upload + decrypt-backup CLI surface holds; auth + step-up gating on sensitive paths is consistent with `auth-model.md`. Everything below is defense-in-depth, audit-detail hygiene, or rough edges worth tracking, not exploitable today.

The audit deliberately avoided re-flagging items the Pass C summary already triaged (F-S3-01, F-S3-02, F-S5-01, F-S5-02, F-S6-*, F-S7-*, scanner F-S2-*); see Methodology Notes.

## Findings

### S-001 — Test-fire AJAX writes audit row with admin-supplied URL into details — Low

**File:** `Simple-PHP-IPAM/webhooks.php:81`
**What:** `audit($db, 'webhook.test_fire', 'webhook', $id, "url=" . to_str($row['url']))` writes the row's `url` into `audit_log.details` verbatim. `url` is sourced from a DB row, but a hostile admin could have stored a URL with newline sequences that survived `FILTER_VALIDATE_URL` (e.g. anchored fragment, path with embedded encoded bytes).
**Why it is a problem:** Audit log line injection by an admin who can already create webhooks is low value — same actor can also write arbitrary audit details by triggering any audited action with crafted entity fields. The bigger concern is grep-tool hygiene when an operator pipes audit_log through line-oriented tooling.
**Fix:** Either truncate the stored URL to a known-safe length, or strip CR/LF before concatenation. `audit-actions.md` already lists `webhook.test_fire`; only the details encoding needs tightening.
**Recommended slot:** backlog (bundle with v3.28.x audit-details hygiene sweep)

### S-002 — `audit_log` LIKE-anchor pattern carries the IP into the LIKE pattern with no validation gate — Low

**File:** `Simple-PHP-IPAM/lib.php:1170-1180` (`ipam_audit_ip_rate_limited`)
**What:** The dampener does `details LIKE '... ip=' . likeEscape(ip)`. `ip` originates from the request-derived client IP which is bounded in practice (IPv4 <= 15 chars, IPv6 <= 45 chars) but the code accepts whatever the caller passes — no `filter_var(ip, FILTER_VALIDATE_IP)` precondition. Combined with the cron path or a future caller, a malformed `ip` (e.g. a string from an XFF spoof) becomes part of the LIKE.
**Why it is a problem:** Not exploitable today — the call sites (`login.php`, `api.php`) compute `ip` from the trusted-proxy path that already filters with `FILTER_VALIDATE_IP`. But it is a latent injection point if a future caller forgets the validation. The LIKE itself is parameterized; the escape handles `%`/`_`/`!` so SQLi is not in play. Pure defense-in-depth.
**Fix:** Add `if (filter_var(ip, FILTER_VALIDATE_IP) === false) return;` at the top of `ipam_audit_ip_rate_limited`. One line.
**Recommended slot:** v3.28.x audit-details hygiene sweep

### S-003 — Restore upload accepts magic-byte sniff before size-class check on decompressed payload — Medium

**File:** `Simple-PHP-IPAM/lib/backup.php:1520-1540` (and surrounding gzip sniff branch)
**What:** `ipam_restore_prepare_for_upload` enforces `backup_max_upload_size_mb` on the compressed upload (`size > maxBytes` at :1489), then peeks 8 bytes for magic. The gzip-detection branch opens a stream-only decompress and sniffs the first ~128 plaintext bytes, but does not enforce any *decompressed* size cap. Downstream `ipam_restore_dry_run` ultimately reads the staged file fully.
**Why it is a problem:** An admin (the only role that can hit this surface — `restore_web.php` is `require_role('admin')` + step-up adjacent) could upload a small gzip bomb that expands to many GB during dry-run, exhausting tmpfs / data/tmp/ free space and DOSing the box. Admin-authenticated DoS is realistically Medium — privilege already required, but the failure mode is host-level not app-level (FS fill ⇒ broken app + cron + scanner).
**Fix:** Cap decompressed reads. Either (a) read with a hard byte limit during dry-run staging with a multiplier ceiling — e.g. 10x — over the compressed cap, or (b) enforce a configurable `backup_max_decompressed_size_mb` setting and short-circuit the decompress on overshoot. Pairs nicely with the existing `backup_max_upload_size_mb` registry entry.
**Recommended slot:** v3.28.x backup hardening (alongside §4.6 legacy-writer retirement)

### S-004 — `move_uploaded_file` target uses random suffix but no file-mode enforcement — Low

**File:** `Simple-PHP-IPAM/lib/backup.php:1506-1511`
**What:** `downloadPath = tmpDir + '/restore_dl_' + rand` is created via `move_uploaded_file`. The file inherits whatever umask is in effect on the worker. `data/tmp/` is created at :1455 with mode `0775` and no explicit chmod on the moved file.
**Why it is a problem:** On a multi-tenant host where `www-data` shares a group with another service, a backup archive in mid-restore could be read by sibling processes (encryption is not a defence here — by the time it lands at this path the file has already been decrypted by the dispatcher path, or it is a plaintext-mode IPAMBKU1). Single-tenant Docker hosts: no impact. Shared-host installs: low-likelihood but real.
**Fix:** Apply mode 0600 to `downloadPath` immediately after the `move_uploaded_file`. Same pattern applies to the cred-file path at `restore.php:259/347` (already tempnam + unlink in finally, but no mode hardening visible at the read sites — verify the tempnam default is 0600 on the target system).
**Recommended slot:** v3.28.x backup hardening

### S-005 — `recaptcha_action` config value flows to UI via `data-recaptcha-action` without echo escape audit — Note

**File:** `Simple-PHP-IPAM/login.php` (config-driven attribute emission)
**What:** CLAUDE.md "Key constraints" calls out that `recaptcha_action` is emitted as `data-recaptcha-action` and read by `app.js` at runtime. Config keys are operator-writable (config.php) so this is a Note, but `e()` coverage on the emission should be verified.
**Why it is a problem:** Sub-Low. Config.php editing is root-on-host. No realistic exploit path. Logged purely so a future audit can confirm the escape pass on this attribute.
**Fix:** Spot-check at next touch of `login.php`; if `e()` is not in line of sight, add it. No standalone PR needed.
**Recommended slot:** backlog / opportunistic

### S-006 — `ipam_webhook_dispatch` swallows ALL throwables silently — Medium

**File:** `Simple-PHP-IPAM/lib.php:9301-9303`
**What:** The outer try / catch at the end of `ipam_webhook_dispatch` is deliberate ("dispatch must never surface to the user") but it also masks PDO errors, JSON-encode failures unrelated to the catch-and-record branches inside the loop, and any future code path that throws. There is no `error_log()` in the catch.
**Why it is a problem:** Defense-in-depth gap. A wrong-driver bind, a settings-table schema drift, or a regression that turns a non-fatal log row into a thrown exception will silently break ALL outbound webhook deliveries with no operator-visible signal until the next test_fire. Pass C surface 3 already exercised the SSRF / decrypt-failure cases (those have explicit `INSERT INTO webhook_deliveries` rows); this is the residual swallow.
**Fix:** Change the catch arm to log the message via `error_log` with a `[webhook_dispatch]` prefix. One line, zero behavior change for the operator UI but recoverable signal in the webserver error log.
**Recommended slot:** v3.28.x (paired with the Pass C step-up + atomic-dampener bundle — same release theme of "stop silent failures")

### S-007 — `ipam_webhook_retry_pending` reuses encrypted `secret` from the JOIN without decryption — Low (initially flagged Critical, downgraded after verification)

**File:** `Simple-PHP-IPAM/lib.php:9313-9375`
**What:** The retry path selects `w.url, w.secret` and passes it directly to `ipam_webhook_deliver(hook, ...)` at :9345. The original `row['signature']` from `webhook_deliveries.signature` is reused as-is (it is the HMAC that was computed at dispatch time with the plaintext secret). Reusing the stored signature is correct — the secret never needs to be touched on retry — but `hook['secret']` is still the ENCRYPTED `$2W$...` blob, never used for signing. **Functionally fine; the secret in the array is a dead value at retry time.**
**Why it is a problem:** Not a live bug — the signature is already in `webhook_deliveries.signature` and is what `ipam_webhook_deliver` actually sends. But the code shape is confusing: the array literal `'secret' => row['secret']` at :9344 reads like the secret will be used. A future refactor that calls `ipam_webhook_sign` inside retry will use the encrypted blob as the HMAC key and produce a wrong signature. Sane-code-shape issue, not a security bug.
**Fix:** Either (a) drop `secret` from the array passed to `ipam_webhook_deliver` since deliver does not read it (verify — :9090 `ipam_webhook_deliver` reads `url` only), or (b) add a comment at :9344 noting the secret is unused on retry. Belt-and-braces option: rename the array key so a future grep catches misuse.
**Recommended slot:** v3.28.x cleanup. (Downgrading from initial Critical-shape on verification — confirmed `ipam_webhook_deliver` never reads `secret`.)

### S-008 — Session-based readonly API auth grants `is_readonly=1` but does not re-check user role — Medium

**File:** `Simple-PHP-IPAM/api.php:108-112`
**What:** The session-API fallback for `GET contacts` and `GET subnet_stats` checks `!empty($_SESSION['uid'])` and grants a synthetic api_key row with `is_readonly => '1'`. It does not check `$_SESSION['role']` — an account that has been disabled (`is_active=0`) but still has a live session cookie would pass this check until the session expires.
**Why it is a problem:** `require_login()` (used elsewhere) enforces session idle timeout but NOT user-disabled status on every request (account-disabled lookups happen at login + a periodic refresh). This is a generic gap, not specific to v3.27.7. The session API path widens the exposure window — a disabled admin's stolen session cookie can read contacts / subnet_stats for the remainder of session lifetime. Bounded by the resource set (two GET endpoints, read-only).
**Fix:** Look up `users.is_active` here before granting the synthetic key. One extra `SELECT is_active FROM users WHERE id = :uid` (cheap, already running session_start). If 0, drop through to the 401.
**Recommended slot:** v3.28.x — pair with the user-disabled session-revocation work that periodically gets brought up.

### S-009 — `oidc_callback.php` surfaces raw exception messages from token verification — Low

**File:** `Simple-PHP-IPAM/oidc_callback.php:72, 89-95`
**What:** `oidc_fail(db, 'token exchange: ' . e->getMessage())` and `oidc_fail(db, 'id_token verification: ' . e2->getMessage())` pass through underlying library / curl error strings. Depending on where `oidc_fail` ultimately renders these (likely audit_log + a flash banner on login.php), the operator-visible failure can include internal hostnames (token endpoint URL on connection failure) or hand-rolled JWT-library detail strings.
**Why it is a problem:** Info disclosure of OIDC IdP topology to unauthenticated viewers if the message reaches the login page. Most messages are tame; the corner cases (DNS failure, TLS handshake failure) leak more than necessary. Already a pattern across the auth code; not a regression.
**Fix:** `oidc_fail` already audits to DB. Render a generic "Authentication failed — see audit log" to the user and stash the detail for admins only. Equivalent in spirit to what `auth-model.md` documents but verify the login.php flash path.
**Recommended slot:** v3.29.0 (paired with #1137 OIDC settings page rewire, already on the roadmap)

### S-010 — `ipam_restore_degraded_database_unsupported` runs a child process via the shell — Note

**File:** `Simple-PHP-IPAM/lib/backup_admin_restore.php:384`
**What:** Uses `@exec` to run `command -v <bin>`. `bin` is a hardcoded `'mysql'`/`'psql'`/`''` literal — no operator input. The `escapeshellarg` is belt-and-braces.
**Why it is a problem:** No issue today. Logged purely because any shell invocation is worth a note in the audit. Confirmed safe.
**Fix:** None.
**Recommended slot:** N/A

### S-011 — Webhook signing secret transiently in `_POST` flows through `to_str` + `trim` without length cap — Note

**File:** `Simple-PHP-IPAM/webhooks.php:95, 135`
**What:** `secret = trim(to_str(_POST['secret'] ?? ''))` — no max-length enforcement on operator-supplied secret. Encrypted envelope is unbounded TEXT after the v3.27.7 ALTER (good), but a multi-MB POST against the secret field would be accepted, encrypted, and stored.
**Why it is a problem:** Operator footgun, not a security issue. PHP `post_max_size` caps the total request body. Logged for completeness.
**Fix:** Add a `strlen` check (e.g. <= 1024). Trivial.
**Recommended slot:** backlog

### S-012 — `tools/decrypt-backup.php` accepts `--passphrase` on argv, warns in usage but does not refuse — Note

**File:** `Simple-PHP-IPAM/tools/decrypt-backup.php:105-124`
**What:** The script accepts `--passphrase <secret>` on argv. The usage block notes "Falls back to IPAM_BACKUP_PASSPHRASE env if unset" but does not actively refuse argv passphrases. Operators following the docs literally will use the env var; those who do not will leak to `/proc/<pid>/cmdline`.
**Why it is a problem:** Documented self-service tool footgun. Tool is CLI-only (line 35 guard), local execution. Low practical risk.
**Fix:** Either (a) explicitly refuse `--passphrase` and require env var, or (b) emit a warning to STDERR when argv is used. Documented as preference in `decrypt-tool-test-plan.md` already.
**Recommended slot:** v3.28.x decrypt-tool hardening (matches `decrypt-tool-test-plan.md` Pass 2 work)

## Methodology notes

### Surfaces covered

1. v3.27.7 F-S3-01 webhook crypto stack:
   - `lib.php` `ipam_webhook_encrypt_secret` / `ipam_webhook_decrypt_secret` / dispatch / retry (lines 8985-9391)
   - `webhooks.php` save / test_fire / gen_secret handlers
   - `migrations.php` `3.27.7-webhook-secret-encrypt` (lines 3743-3832)
2. Backup/restore subsystem:
   - `lib/backup.php` upload path (`ipam_restore_prepare_for_upload`, magic-byte sniff, gzip branch)
   - `lib/backup_admin_restore.php` POST controller (stage / dryrun / apply, token sign+verify)
   - `lib/vault.php` IPAMWK1 envelope (libsodium secretbox; clean)
   - `tools/decrypt-backup.php` CLI surface
3. Auth + step-up:
   - `lib/auth_step_up.php` `ipam_sudo_verify` / `ipam_sudo_require` / consume / invalidate semantics
   - `change_password.php` sudo gates on TOTP/email-OTP/passkey disable
   - `api_keys.php` create-gate
   - `step_up.php` page contract
   - `login.php` rate-limit dampener + `auth.ip_rate_limit_unlock_at` correctness (CR PR #1141 round 2 fix verified)
4. API layer:
   - `api.php` lines 55-165 (auth, rate limit, readonly enforcement, JSON parse). Pass C surface 1 already cleared sudo-bypass; this audit covered the session-API path that Pass C did not drill into.
5. Spot-checks:
   - SSRF helper `ipam_validate_webhook_url` (lib.php:8871-8970) — sound; covers RFC1918, link-local, IPv6 ULA/loopback, AAAA via `dns_get_record`
   - `S3Client.php` constructor — endpoint is admin config, no SSRF guard needed (admin-trust boundary)
   - JS delegated handlers (`data-confirm`, `data-submit-on-change`, `data-stop-propagation`) — all read from DOM elements pre-rendered through `e()`; inline-edit cell uses HTML-escape pass on `data.value` before innerHTML assignment

### Cross-references to Pass C items skipped (already triaged)

- **F-S3-01** (webhook plaintext-at-rest) — shipped in v3.27.7; new helpers reviewed positively (S-001/S-006/S-007 are residual edges, not the original finding).
- **F-S3-02** (no step-up on webhook CRUD) — in the step-up bundle; not re-flagged.
- **F-S5-01** (no step-up on `backup.notify_recipients`) — in the bundle.
- **F-S5-02** (write-side race on JSON state blobs) — already paired with #1143.
- **F-S6-*** (DHCP findings) — operational, not security; deferred to v3.28.x/v3.29.0.
- **F-S7-***  (custom-field def step-up) — in the bundle.
- **F-S2-*** (scanner findings) — observability/contract-drift, not security.

### Cross-references to roadmap items skipped (already scoped)

- v3.27.8 backup bugs (§3.7): silent plaintext fallback drop, 4 CDP-diagnosed bugs — not duplicated here.
- v3.28.x legacy backup writer retirement (§4.6) — S-003 (gzip-bomb DoS) and S-004 (file-mode) intentionally slot into this thread.
- v4.0.0 backup cold break (§6.7) — out of scope.
- v3.28.1 step-up coverage sweep (§4.5) — Pass C bundle, not duplicated.

### What I did not get to

- Full read of `lib/auth_step_up.php` (926 lines) — sampled the public-API surface (lines 91, 100, 347, 408, 512, 808) and the call-site usage in `change_password.php` / `api_keys.php` / `step_up.php`. Did not audit the WebAuthn assertion verifier in depth — that is a candidate for a dedicated WebAuthn-focused pass since it crosses into `lbuchs/webauthn` library territory.
- `SftpClient.php` (349 lines) — same admin-trust boundary as S3Client; not audited beyond the file inventory.
- `lib/backup.php` is 3843 lines; covered the upload prep, magic-byte sniff, HMAC sign/verify (`ipam_hkdf_sha256` derivation), and decrypt-to-path entry point. Did not audit the writer paths (assumed unchanged from v3.27.6 Pass C scope).
- `assets/app.js` — sampled delegated handlers + inline-edit; did not full-read the 1100+ lines.
- `oidc_login.php` (only `oidc_callback.php` was sampled).
- `users.php` self-protection guards — Pass C covered, no v3.27.7 changes.

If Sean wants a follow-up pass focused on any of these, S-003 (gzip-bomb DoS in restore upload) and S-006 (silent dispatch swallow) are the two most actionable items from tonight's run. S-008 (session-API user-disabled gap) is the most subtle and probably warrants its own GitHub issue.

### Severity calibration sanity

Initial Critical-shaped concern on S-007 (retry path encrypted secret) was downgraded after verifying `ipam_webhook_deliver` ignores the `secret` field. No Critical / High in the final tally. Resisted re-flagging Pass C items; resisted promoting S-003 to High despite its DoS shape because the trust boundary is admin-only.
