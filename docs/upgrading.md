# Upgrading

## Contents

- [Overview](#overview)
- [Upgrade steps](#upgrade-steps)
- [upgrade.sh options](#upgradesh-options)
- [Environment variables](#environment-variables)
- [What the backup looks like](#what-the-backup-looks-like)
- [CLI utilities](#cli-utilities)
- [Version-specific upgrade notes](#version-specific-upgrade-notes)
  - [v3.20.0](#v3200) — Backup destinations UX polish: inline Edit drawers, Run-now per destination, frequency-aware schedule fields, auto-Test on Save, TZ-correct timestamps, notify wiring, S3 redaction scope fix (no breaking changes)
  - [v3.19.1](#v3191) — **Hotfix:** S3 destinations actually work (SigV4 fix), MySQL/PG cloud backups unblocked, S3 download body-leak fixed (no breaking changes)
  - [v3.18.0](#v3180) — Per-toggle Settings save, backup/restore polish, contacts docs, pgsql test flake fix (no breaking changes)
  - [v3.17.0](#v3170) — Backup destinations, schedules, GFS retention, encryption, web restore wizard (no breaking changes)
  - [v3.16.0](#v3160) — Admin TOTP toggle, preferred MFA method, unified MFA card, Settings tabs, portable case-insensitive search (no breaking changes)
  - [v3.15.2](#v3152) — Bug fixes: passkey registration with password managers, MFA method choice, stale-session redirect (no breaking changes)
  - [v3.15.1](#v3151) — Bug fixes: post-login redirect, email charset, banner false positive (no breaking changes)
  - [v3.15.0](#v3150) — WebAuthn / Passkey 2FA (no breaking changes)
  - [v3.14.0](#v3140) — Email OTP 2FA, MFA enforcement (no breaking changes)
  - [v3.13.0](#v3130) — Settings cascade migration, UI/UX polish (no breaking changes)
  - [v3.12.0](#v3120) — Dashboard responsive fix, health alert indicators, webhooks cleanup (no breaking changes)
  - [v3.11.0](#v3110) — UI polish (no breaking changes)
  - [v3.10.0](#v3100) — IPAM_VERSION load-order fix, webhook test_fire (no breaking changes)
  - [v3.9.0](#v390) — Site filter strip, cascading address filter, DB admin consolidation (no breaking changes)
  - [v3.8.1](#v381) — Dashboard bug fixes, documentation refresh (no breaking changes)
  - [v3.8.0](#v380) — Sidebar navigation, command palette, uPlot dashboard, SVG icons, Fira Sans (no breaking changes)
  - [v3.7.0](#v370) — Backup/restore, health dashboard, audit retention, test coverage (no breaking changes)
  - [v3.6.0](#v360) — TOTP 2FA, per-API-key rate limiting, session hardening (no breaking changes)
  - [v3.5.0](#v350) — Custom fields (no breaking changes)
  - [v3.4.0](#v340) — DHCP config export (no breaking changes)
  - [v3.3.0](#v330) — webhooks, login history, brand polish (no breaking changes)
  - [v3.2.0](#v320) — devices, password recovery, OpenAPI spec (no breaking changes)
  - [v3.0.0](#v300) — **breaking changes**, config.php stub, driver promotion

---

## Overview

Upgrades are handled by `upgrade.sh`, included in every release bundle. The script:

- Creates a **timestamped backup** of your current install (including the SQLite DB and WAL files)
- Syncs new application files into the target directory using `rsync`
- **Preserves `config.php`** and the entire `data/` directory
- Fixes file permissions after the sync
- Runs **database migrations automatically** (if the `php` CLI is available)
- Removes upgrade artefacts from the webroot

If the migration step fails, `upgrade.sh` automatically **restores from the backup** and exits with code `10`.

---

## Upgrade steps

```bash
# 1. Download and extract the new release bundle
tar -xzf ipam-0.11.tar.gz -C /tmp/

# 2. Run upgrade.sh, pointing it at your current install directory
bash /tmp/Simple-PHP-IPAM/upgrade.sh /var/www/ipam
```

The script confirms the version transition (e.g. `0.10 → 0.11`) before making any changes.

To skip the confirmation prompt (e.g. in a CI/CD pipeline):

```bash
bash /tmp/Simple-PHP-IPAM/upgrade.sh --yes /var/www/ipam
```

---

## upgrade.sh options

| Flag | Description |
|---|---|
| `--yes` | Non-interactive — skip confirmation prompts |
| `--force` | Allow reinstalling the same version |
| `--force-downgrade` | Allow downgrading (not recommended — may break the DB schema) |

---

## Environment variables

| Variable | Default | Description |
|---|---|---|
| `CLEANUP_ARTIFACTS` | `1` | Remove build/upgrade artefacts from the target webroot after success |
| `REMOVE_UPGRADE_SH_FROM_TARGET` | `1` | Also remove `upgrade.sh` from the target webroot |

Example — keep `upgrade.sh` in place after the upgrade:

```bash
REMOVE_UPGRADE_SH_FROM_TARGET=0 bash /tmp/Simple-PHP-IPAM/upgrade.sh --yes /var/www/ipam
```

---

## What the backup looks like

```
/var/www/ipam.backup.20260326-143000/   ← timestamped copy of entire install
    data/
        ipam.sqlite
        ipam.sqlite-wal   ← if present (WAL mode journal)
        ipam.sqlite-shm   ← if present
    config.php
    ... (all other app files)
```

The backup is left in place after a successful upgrade. You can remove it manually once you have verified the new version is working correctly.

---

## Version-specific upgrade notes

### v3.20.0

Backup destinations UX + reliability polish. **No new pages, no schema migrations, no new runtime dependencies.** Standard upgrade: `bash upgrade.sh --yes <docroot>`.

Highlights (all on `destinations.php` / `backup_history.php`):

- **Inline Edit drawers** for destinations and schedules — edit name, type, config, retention, frequency, and credentials in place without a separate page (#778, #780). Secret-merge is hardened so partially-submitted forms cannot null out an existing key, and destination *type* changes are rejected on existing rows (#793).
- **Run-now per destination** — destination rows have their own "Run backup now" action; previously only schedules could trigger a manual run (#779).
- **Frequency-aware schedule fields** — selecting `hourly` / `daily` / `weekly` / `monthly` / `cron` hides the rows that don't apply (e.g. day-of-week is hidden for hourly), eliminating the "what value does this field even take for this frequency?" question (#781).
- **Auto-run Test on Save** — creating or editing a destination now triggers the connectivity test automatically and renders an inline pass/fail badge with the latency and any error message (#787).
- **Timestamps render in the user's configured timezone** instead of UTC across `backup_history.php` and the destination tables (#782). A semgrep guard rule prevents future drift back to raw UTC rendering.
- **Notification dispatch wired into both orchestrators** — `ipam_backup_notify()` was previously dead code on the schedule path; success and failure email now fires reliably from cron and from manual Run-now (#791).
- **`backup_schedules.updated_at` is now bumped on every cron transition** (success and failure), so the column reflects actual last-touched time (#792).
- **S3 error-body redaction is scoped to signature/credential XML elements** — long base64-ish runs in unrelated XML node text are no longer stripped, restoring full error context for non-auth S3 failures (#795).
- **`BackupClientInterface::list()` renamed to `listObjects()`** to avoid collision with PHP's `list()` language construct (#796). Site-local code that called the method directly (unlikely — internal interface) needs the rename.
- **`backup.php` CLI** uses `getopt()` for flag parsing and documents its exit codes (#794).

End-to-end coverage now includes a **MinIO-backed integration test in CI** (#789) — the backup pipeline (dump → encrypt → upload → list → download → decrypt → verify) runs against a real S3-compatible server on every push across all three database engines.

### v3.19.1

Hotfix release. **No new pages, no schema migrations, no new runtime dependencies.** Standard upgrade: `bash upgrade.sh --yes <docroot>`.

**If you are on v3.17.0 / v3.18.0 / v3.19.0 and tried to use a remote backup destination (S3 / SFTP / Local), READ THIS:**

- **S3 destinations were broken on every v3.17–v3.19 release.** A SigV4 canonical-request canonicalization bug caused every S3-compatible server (AWS, Wasabi, MinIO, Ceph) to reject the signature with HTTP 403 `SignatureDoesNotMatch`. The bug is fixed in v3.19.1 — re-test your destination after upgrading. Existing destination rows and credentials work unchanged; no re-entry required.
- **MySQL and PostgreSQL operators were locked out of remote destinations entirely.** `ipam_backup_dump_to_tmp()` hard-threw on non-SQLite drivers in v3.17–v3.19 — the dump step never ran, so no remote-destination backup ever completed for non-SQLite operators. v3.19.1 dispatches to `mysqldump` / `pg_dump` natively (passwords via env, never on cmdline) and gzips the output, so all three engines now produce a `.sql.gz` that flows through the existing encryption + upload pipeline. **Local-disk backups via the legacy `backup.php` CLI runner — which DID support all three engines — continued to work the whole time, so no data was at risk during the gap; only off-box / cloud-replicated backups were unavailable.**

After upgrading, if you set up a destination during the broken window and gave up: it should work now. If you set up a destination and never tested it: test it now.

### v3.18.0

Polish release. **No new pages, no schema migrations, no new runtime dependencies.** Standard upgrade: `bash upgrade.sh --yes <docroot>`.

- **Settings page** now saves boolean toggles individually — flipping one MFA switch no longer silently disables siblings. Each boolean is its own auto-submitting form. The "Save group" button still works for non-bool fields and continues to apply the legacy group-cascade behaviour for bools when used.
- **Backup retention** is now clock-aligned to the cron tick (was previously sensitive to PHP request timing across long ticks). Behaviour is unchanged for normal cron-driven schedules; the change only affects edge cases where a long-running tick straddled a retention slot boundary.
- **`BackupEngine` and `RestoreEngine` classes are gone.** Replaced by top-level functions (`ipam_backup_run_for_destination()`, `ipam_restore_prepare_for_restore()`, `ipam_restore_apply()`, etc.) in a new `lib/backup.php`. All the same orchestration, just procedural instead of class-wrapped. If you have site-local code that referenced these classes (unlikely — they were not part of any public API surface), update to the function names.
- **Unified MFA card** has a small visual polish pass — pill no longer italicises the "unavailable" state, preserved-enrollment hints use `role="note"`, the tab rail handles 768–900px viewports without label wrapping.
- **New doc:** [Contacts](contacts.md) — guide for linking contacts to IP addresses via the Owner typeahead.

### v3.17.0

**New tables:** `backup_destinations`, `backup_schedules`, `backup_log`. Migration `3.17.0-backup` is automatic and idempotent.

**New pages:**
- `destinations.php` — Backup destinations admin (admin only)
- `backup_history.php` — Backup history log with Schedules tab (admin only)
- `remote_backups.php` — Remote backup browser (admin only)
- `restore_web.php` — Web-based restore wizard with dry-run and confirmation typing gate (admin only)
- `download_remote_backup.php` — AJAX endpoint: download a file from a destination to the admin's browser
- `test_destination.php` — AJAX endpoint: test a destination connection
- `run_backup_now.php` — AJAX endpoint: trigger a schedule immediately

**New runtime dependency:** `phpseclib/phpseclib ^3.0` (SFTP transport, MIT license, zero transitive runtime deps). Bundled in the release tarball; no `composer install` needed at install time. Source installs must run `composer install --no-dev` after upgrade.

**New settings registry keys:**
- `backup.notify_on_failure` (bool, default `true`) — send an email to `alert_email` when a backup run fails.
- `backup.notify_on_success` (bool, default `false`) — send an email to `alert_email` when a backup run succeeds.

**New audit actions:** `destination.create`, `destination.update`, `destination.delete`, `destination.test`, `backup.run`, `backup.failed`, `backup.retention_pruned`, `db.restore_stage`, `db.restore_dryrun`, `db.restore`, `remote_backup.delete`, `remote_backup.verify`, `remote_backup.download`, `remote_backup.download_failed`.

**Cron:** if you already have `cron.php` scheduled, backup schedules run automatically the next time it fires. No additional cron entry is needed.

**SQLite-only for backup dumps:** v3.17.0 uses the existing SQLite-only `ipam_db_dump_stream()` helper for the backup dump. MySQL and PostgreSQL backup dumps are planned for a follow-up release. The legacy v3.7.0 `backup.php` CLI is unchanged and continues to write to `backup_history`.

**`app_secret` is required for encrypted backups.** The key must be set in `config.php` (not the `settings` table — it must be available before the database is opened). If you have not yet set `app_secret`, either leave the Encrypt option unchecked on each destination or set `app_secret` first:

```bash
php -r "echo bin2hex(random_bytes(32));"
# Add the output as 'app_secret' => '<value>' in config.php
```

---

### v3.16.0

- No manual upgrade steps required.
- The `3.16.0-preferred-mfa-method` migration adds a nullable `preferred_mfa_method` column to `users`. Non-destructive and idempotent. Existing users dispatch as before until they pick a preference from the Account page.
- **New setting `mfa.totp_enabled` (default `true`).** Existing installs continue to allow TOTP exactly as before. Admins who want to phase TOTP out in favour of Email OTP / passkeys can disable it from Admin → Settings → Multi-Factor Auth without revoking individual user enrollments — re-enabling restores each user's previous TOTP without re-enrollment.
- **`app_secret` warning banner.** The Settings page now flags the case where `mfa.totp_enabled = true` but `app_secret` is unset in `config.php`. This was previously a silent failure mode at TOTP enrollment time. If you see the banner, either generate and add `app_secret` to `config.php` (`php -r "echo bin2hex(random_bytes(32));"`) or disable `mfa.totp_enabled`.
- **Settings page reorganised into 5 tabs.** General, Authentication, Notifications, Data & Maintenance, Integrations. The per-subsection POST flow is unchanged — bookmarks to `settings.php` still work and the legacy `#group-<key>` anchor format auto-redirects to the correct tab via a JS shim. URL state is now carried via `?tab=`.
- **Account page MFA section consolidated.** TOTP, Email OTP, and Passkeys now live in a single "Two-Factor Authentication" card. No data migration; the layout change is purely UI. The "Two-Factor Authentication" heading replaces the prior three separate sections.
- **Login MFA switch graph.** Each verify page (TOTP, Email OTP, Passkey) now offers switch buttons to either of the other two methods when they are enrolled and globally enabled. Extends v3.15.2's TOTP-page-only buttons.
- **Search is now case-insensitive on every supported engine.** PostgreSQL was previously case-sensitive — operators who built habits around exact-case search on Postgres should know it now matches SQLite and MySQL behaviour. SQLite remains ASCII-only by design (no ICU bundled with the standard SQLite build); MySQL and PostgreSQL perform full Unicode case folding via their default collations.

---

### v3.15.2

- No manual upgrade steps required. No new migrations, no new settings, no new dependencies.
- **Bug fixes:**
  - Passkey registration with password-manager providers (LastPass, 1Password, Bitwarden) now succeeds: `requireResidentKey: 'preferred'` makes credentials discoverable so they save to the vault, and `attestation: 'none'` avoids the "no signature found" verification failure on packed self-attestation.
  - The WebAuthn `rp.name` now defaults to the configured `branding.site_name` setting (was hardcoded to "Simple PHP IPAM"). Most password managers and OS credential dialogs display this. LastPass labels entries by `rpId` regardless and is unaffected — that is a LastPass UX choice, not something this app can override.
  - The default credential name shown in IPAM's passkey list is the site name (was an interactive `window.prompt`).
  - When TOTP and Email OTP — or TOTP and a passkey — are both enrolled, the TOTP verify page now offers "Send a code to my email instead" and "Use a passkey instead" buttons. Previously TOTP was always dispatched first with no way to choose another method.
  - The post-login redirect URL now survives idle-timeout and absolute-lifetime expiry. v3.15.1 only stashed in the cold-start branch of `require_login()`, so users hitting an authenticated link with a stale or expired cookie still landed on the dashboard.
  - JS / CSS cache-buster query string now includes file mtime, so edits within a release invalidate browser caches without an `IPAM_VERSION` bump.

---

### v3.15.1

- No manual upgrade steps required. No new migrations, no new settings, no new dependencies.
- **Bug fixes:**
  - Post-login redirect now preserves the requested URL across all eight final-success paths (local login, demo, recovery, OIDC, TOTP, TOTP-bypass, Email OTP, passkey). Previously, clicking a deep link (e.g. an email-verification link) while logged out dropped you on `dashboard.php` after sign-in.
  - Email body charset is now declared as UTF-8. Previously, em-dashes and accented characters arrived as mojibake (`â—`) in clients that respected the declared charset.
  - The dashboard `config.php` cleanup banner no longer flags `app_secret`, `session`, `auth`, or `api` as stale keys. Following the previous bad advice would have broken every TOTP enrollment.
  - Email verification failures now surface the real cause (e.g. missing `base_url`) instead of a generic guess, with the underlying reason also logged via `error_log()` for server-side diagnostics.

---

### v3.15.0

- No manual upgrade steps required.
- The `3.15.0-passkeys` migration creates the `webauthn_credentials` table (passkey storage). This is non-destructive and idempotent.
- **New pages:** `passkey_verify.php` (mid-login passkey challenge; direct navigation redirects to `login.php`), `passkey_register.php` (AJAX registration endpoint, POST only; requires an active session).
- **New settings key:** `mfa.passkeys_enabled` (default `false`). Passkeys are opt-in — existing installs are unaffected. Enable via Admin → Settings → Multi-Factor Auth.
- **`mfa.require` now includes passkeys:** when enforcement is enabled, a registered passkey satisfies the requirement alongside TOTP and Email OTP.
- **New Composer dependency:** `lbuchs/webauthn ^2.1` (WebAuthn server-side library, MIT, zero transitive deps). Included in the release tarball under `vendor/`. No action required for tarball-based installs. Source installs must run `composer install --no-dev` after upgrade.

---

### v3.14.0

- No manual upgrade steps required.
- The `3.14.0-email-otp` migration adds Email OTP columns to `users` (`email_otp_enabled`, `email_otp_hash`, `email_otp_expires_at`, `email_otp_attempts`). Non-destructive and idempotent.
- **New page:** `email_otp_verify.php` (mid-login Email OTP challenge; direct navigation redirects to `login.php`).
- **New settings keys:** `mfa.email_otp_enabled` (default `false`) and `mfa.require` (default `false`). When `mfa.require` is enabled, users without any enrolled 2FA method are redirected to the Account page to enroll before accessing the application.
- **SMTP and `users.email` are required** for Email OTP enrollment. Without SMTP configured (`smtp.enabled` true, host/port valid) and a user-level email address, enrollment fails closed.
- Admins can reset a user's Email OTP enrollment from `users.php`.

---

### v3.13.0

- No manual upgrade steps required.
- The `3.13.0-settings-cascade` migration adds a `tenant_id` column to the `settings` table. This is non-destructive — all existing settings rows remain and gain `tenant_id = NULL` (global scope). The migration is idempotent.
- Existing `config.php` files are unaffected.

---

### v3.12.0

No breaking changes, new configuration keys, or migrations.

**UI changes:** The dashboard two-column widget grid now requires a wider viewport before splitting (fixes overlap at ~900–1100px). Health page scanning card has two new rows: Warn alerts and Crit alerts.

---

### v3.11.0

No database migrations or configuration changes. Pure UI polish release.

- No manual steps required on upgrade.
- The asset cache-buster has been bumped to `?v=3.11.0` — browsers will fetch fresh CSS/JS on first load after upgrade.

---

### v3.10.0

No breaking changes. No manual migration steps required — `migrate.php` handles all schema changes automatically.

**If upgrading from ≤v3.9.0:** The `IPAM_VERSION` load-order fix (#662) resolves webhook test_fire returning HTTP 500. No config changes needed.

---

### v3.9.0

- **`backups.php` removed from nav; redirects 301 to `db_tools.php`.** Update any bookmarks or internal links.
- No schema changes. No config changes. No new runtime dependencies.
- Asset cache-busters bumped to `?v=3.9.0` — hard-refresh may be needed after upgrade if CSS/JS is cached.

---

### v3.8.1

**No breaking changes.** Run `upgrade.sh` as normal — no manual steps required.

**Bug fixes:**
- Dashboard KPI cards now have proper spacing below action pills.
- Dashboard two-column grid collapses at the correct sidebar breakpoint; uPlot growth chart resizes when the sidebar is toggled.
- Address growth chart shows a friendly empty state when no new addresses were recorded in the last 30 days.

**Documentation:**
- All three database drivers (MySQL, MariaDB, PostgreSQL) correctly documented as production-ready since v3.0.0.
- New guides: [Sidebar & Command Palette](sidebar-and-command-palette.md), [Two-Factor Authentication](security.md#two-factor-authentication-totp) (moved to security.md).
- v3.8.0 upgrade notes corrected (mobile breakpoint was listed as `<768px`; correct value is `<1024px`).

---

### v3.8.0

**New pages:** None.

**UI changes:** The top navigation bar has been replaced with a sidebar navigation pattern. On desktop (≥1024px) the sidebar is always visible. On mobile (<1024px) a hamburger button opens the sidebar as an overlay. The previous emoji-icon nav links are now SVG Heroicons. The dashboard has been rewritten with KPI cards and a uPlot time-series chart.

**New keyboard shortcuts:** ⌘K / Ctrl+K opens the command palette for navigation, creating records, and toggling the theme.

**No config changes, no migration, no schema changes.**

### v3.7.0

**No breaking changes.** All new features are opt-in. Upgrading from v3.6.0 requires no manual action — run `upgrade.sh` as normal.

**New pages:**
- `backups.php` — Admin → Backups: lists backup history with status badges, download, SHA-256 verify, and delete
- `health.php` — Admin → Health: real-time operational metrics dashboard (DB size, backup status, scan health, webhook delivery, auth/security, system info)

**New CLI scripts (403 if accessed via web):**
- `backup.php` — Run a database backup on-demand (SQLite, MySQL, PostgreSQL)
- `restore.php` — Restore from a backup file with dry-run mode and SHA-256 verification

**Database migration** (applied automatically on first boot after upgrade):
- New table `backup_history` — tracks every backup run with status, size, and SHA-256

**New database settings** (Admin → Settings):

| Key | Default | Purpose |
|-----|---------|---------|
| `backup.enabled` | `false` | Enable scheduled database backups via cron |
| `backup.local_path` | `data/backups/` | Directory for backup files (relative to app root) |
| `backup.retention_count` | `7` | Number of backup files to retain; older files are deleted |
| `backup.schedule_cron` | `0 2 * * *` | Cron expression controlling backup frequency |
| `audit.retention_days` | `365` | Days to retain audit log entries; 0 = never prune |
| `audit.prune_batch_size` | `1000` | Rows deleted per prune pass |

**Action required to enable backups:** Set `backup.enabled = true` at Admin → Settings and ensure `cron.php` is scheduled (see [docs/backups.md](backups.md) for cron setup). SQLite installs: no additional configuration. MySQL/PostgreSQL installs: verify the `mysqldump`/`pg_dump` binary is on the server's `PATH`.

See [docs/backups.md](backups.md) for the full backup, restore, and disaster-recovery reference.

---

### v3.6.0

**No breaking changes.** All new features are opt-in. Upgrading from v3.5.0 requires no manual action — run `upgrade.sh` as normal.

**New pages:**
- `totp_enroll.php` — TOTP enrollment wizard (Account → Enable 2FA)
- `totp_verify.php` — Mid-login 2FA challenge (shown automatically after password check if 2FA is enabled)

**Database migration** (applied automatically on first boot after upgrade):
- New columns on `users`: `totp_secret_enc`, `totp_enabled`, `failed_auth_count`, `locked_until`, `lock_reason`
- New table `totp_backup_codes` — stores hashed single-use backup codes per user
- New table `rate_limit_buckets` — stores per-API-key sliding window counters

**New `config.php` keys (all optional — have safe defaults):**

| Key | Default | Purpose |
|-----|---------|---------|
| `app_secret` | `''` | Encryption key for stored TOTP secrets. **Required before any user can enable 2FA.** Generate with: `php -r "echo bin2hex(random_bytes(32));"` |
| `session.absolute_lifetime_minutes` | `480` | Absolute session lifetime in minutes. 0 = no limit. |
| `auth.lockout_after_failures` | `10` | Consecutive 2FA failures before persistent account lockout. |
| `auth.lockout_duration_minutes` | `30` | Duration of a 2FA-triggered persistent lockout in minutes. |

**New database settings** (configurable at Admin → Settings, no config.php change required):

| Key | Default | Purpose |
|-----|---------|---------|
| `api.rate_limit_window_seconds` | `60` | Sliding window size for per-API-key rate limiting. |
| `api.rate_limit_requests` | `300` | Max requests per window per API key. |

**Action required to enable 2FA:** Set `app_secret` in `config.php` before users attempt enrollment. Users who try to enroll without this key set will see an error. Existing installs with `app_secret` left empty have 2FA disabled; all other functionality is unaffected.

> **Warning — `app_secret` is a wrapping key.** `app_secret` encrypts every user's stored TOTP secret. If you change its value after users have enrolled, their stored secrets become undecryptable and those users will be unable to pass the 2FA challenge. They will be locked out of 2FA until an admin resets their TOTP (`users.php` → Reset 2FA) and they re-enroll. **Never rotate `app_secret` on a live instance without first resetting all enrolled users' TOTP.** If you need to rotate the key, the safe procedure is: (1) admin-reset every enrolled user's TOTP, (2) update `app_secret`, (3) have all users re-enroll.

See the [Security guide](security.md#two-factor-authentication-totp) for full enrollment and admin-reset instructions.

---

### v3.5.0

**No breaking changes.** Run `upgrade.sh` as normal — no manual steps required.

**New admin page:**
- `custom_fields.php` — Admin-only. Accessible from Admin → Custom Fields. Manage per-entity custom field definitions (create, edit, delete, reorder).

**New schema** (applied automatically by migration `3.5.0-custom-fields`):
- New table `custom_field_defs` — stores field definitions (key, label, entity type, type, options, sort order, required flag).
- `subnets.custom_fields TEXT NOT NULL DEFAULT '{}'` — JSON object of custom field values.
- `addresses.custom_fields TEXT NOT NULL DEFAULT '{}'` — same for addresses.

**API change (additive):** Subnet and address responses now include a `custom_fields` object. Existing clients that do not inspect this key are unaffected. `PUT` requests now accept `custom_fields`; unknown keys or type mismatches return HTTP 422.

**CSV change (additive):** A `custom_fields` column is appended to `export_addresses.php` output. The import wizard accepts an optional `custom_fields` mapping column. Existing import files without this column continue to work.

See the [Custom Fields guide](custom-fields.md) for full documentation.

---

### v3.4.0

**No breaking changes.** Run `upgrade.sh` as normal — no manual steps required.

**New schema columns on `subnets`:** `dhcp_routers`, `dhcp_dns_servers`, `dhcp_domain_name`, `dhcp_lease_default`, `dhcp_lease_max`, `dhcp_next_server`, `dhcp_boot_filename`. All nullable. Added automatically by migration `3.4.0-dhcp-options`.

**New page added:**
- `export_dhcp.php` — DHCP config export endpoint (write-role required). Accessible from Admin → DHCP Pools.

---

### v3.3.0

**No breaking changes.** Run `upgrade.sh` as normal — no manual steps required.

**New pages added:**
- `webhooks.php` — outbound webhook management (admin-only)

**New features:**
- Outbound webhooks: configure HTTP callbacks fired on address/subnet mutations. HMAC-SHA256 signed. Admin UI at Admin → Webhooks with test-fire, delivery log, and retry. Cron retries failed deliveries (up to 3 attempts). See [Webhooks guide](webhooks.md).
- Login history: Account page now shows last 20 login events. Users table gains a "Login history" link per user. Audit log accepts `?user_id=N` and `?action=auth.login` deep-link filters.
- Brand polish: nav and footer use text logo (`SimplePHPIPAM`) in Fira Code monospace; dark mode buttons match marketing-site brand green.

**New settings (Settings → Webhooks):**
- `webhook.retention_days` — delivery log retention in days (default 30; set to 0 to disable pruning)
- `webhook.allow_private_ips` — allow private-IP webhook targets (default false; enable for lab environments)

### v3.2.0

**No breaking changes.** Run `upgrade.sh` as normal — no manual steps required.

**New pages added:**
- `devices.php` — device and interface management (admin-only)
- `forgot_password.php` / `reset_password.php` — email-based password recovery (unauthenticated)

**New features:**
- Device & interface records: link IP addresses to named equipment. Includes admin CRUD, REST API resources (`devices`, `device_interfaces`), device search, and CSV import columns `device_name` / `interface_name`.
- Email password recovery with SHA-256 token, 1-hour expiry, and rate limiting (max 3 requests per hour per user). Requires SMTP configured under Admin → Settings.
- Email change verification: users can update their email on the Account page and must verify via a link sent to the new address.
- Utilization CSV export now supports `?include_trend=1&trend_days=N` to include delta columns from snapshot history.
- All API responses now include `X-IPAM-API-Version: 1` header.
- OpenAPI 3.1 spec available at `api.php?resource=spec` and committed to `docs/api-spec.yaml`.

**Nav change:** The "Password" link in the user dropdown is now labelled "Account". The URL (`change_password.php`) is unchanged.

---

### v3.0.0

**This is a breaking release.** Read these notes carefully before upgrading.

#### What changed

- **`config.php` is now a bootstrap stub.** All non-bootstrap settings (OIDC, alerting, passwords, housekeeping, backup, etc.) live in the `settings` database table and are managed through **Admin → Settings**. The upgrade migration automatically imports your customised values from the old `config.php` into the database and rewrites the file to stub format.
- **`?api_key=` query-parameter authentication removed.** Use the `Authorization: Bearer <key>` header instead. The deprecation headers have shipped since v2.x.
- **MySQL and PostgreSQL drivers are now stable.** The experimental/beta banners are removed. Minimum versions: MySQL 8.0, PostgreSQL 14.
- **`migrate_db.php`** — new CLI tool for migrating between database engines (all 6 direction pairs).
- **Multi-contact assignments** on sites and subnets (new `site_contacts` and `subnet_contacts` tables).

#### Pre-upgrade checklist

1. **Back up your database** — `upgrade.sh` does this automatically, but make a manual copy too
2. **Back up `config.php`** — the migration rewrites it; a `.bak-v3upgrade` copy is created automatically
3. **Check PHP version** — PHP 8.2+ required (unchanged from v2.x)
4. **Check API clients** — any client using `?api_key=` must switch to `Authorization: Bearer` header

#### Upgrade paths

**Path 1: Stay on SQLite (default)**

Run `upgrade.sh` as usual. The migration:
1. Imports customised `config.php` values into the settings table
2. Backs up old `config.php` as `config.php.bak-v3upgrade`
3. Rewrites `config.php` to stub format
4. Creates new tables (`site_contacts`, `subnet_contacts`)

No manual action required.

**Path 2: Migrate to MySQL or PostgreSQL during the upgrade**

After running `upgrade.sh`:

```bash
# 1. Provision the target schema
mysql -u ipam -p ipam_db < Simple-PHP-IPAM/schema.mysql.sql
# or: psql -U ipam ipam_db < Simple-PHP-IPAM/schema.pgsql.sql

# 2. Run the migration tool
php Simple-PHP-IPAM/migrate_db.php \
  --from=sqlite --from-dsn="sqlite:Simple-PHP-IPAM/data/ipam.sqlite" \
  --to=mysql --to-dsn="mysql:host=127.0.0.1;dbname=ipam_db" \
  --to-user=ipam --to-pass=secret

# 3. Update config.php with the new driver
# (migrate_db.php prints the exact lines to change)

# 4. Restart Apache / PHP-FPM
```

#### Post-upgrade verification

1. Admin login works
2. Settings page loads — verify your imported settings are correct
3. Create a test subnet and address
4. Run `php migrate.php` — should be a no-op
5. If using OIDC, verify SSO login still works

#### Rollback

If something goes wrong:
1. Restore `config.php.bak-v3upgrade` → `config.php`
2. Restore the database backup created by `upgrade.sh`
3. Redeploy the v2.x release bundle

---

### v2.9.0

**New `vendor/` directory in the release tarball.** Starting in v2.9.0, the release bundle includes `Simple-PHP-IPAM/vendor/` with a pre-built Composer autoloader. You do **not** need Composer on the target server — `upgrade.sh` handles `vendor/` like every other file in the tree (rsync overwrites it on each upgrade).

If you have customised anything inside `vendor/` on the server, those changes will be lost on upgrade. Don't do that — keep all customisation outside `vendor/`.

The bundled `vendor/.htaccess` denies direct HTTP access to library source. Verify after upgrade with:

```bash
curl -sI https://your-host/path/to/ipam/vendor/autoload.php | head -1
# Expected: HTTP/1.1 403 Forbidden (or 404 if your server hides denied paths)
```

**No schema changes that require manual action, provided `php` is in `PATH`.** v2.9.0 includes a one-shot internal migration that normalises `ip_bin` / `network_bin` storage to BLOB affinity on SQLite. `upgrade.sh` runs `migrate.php` automatically when the `php` CLI is available, so no operator action is required. The migration is idempotent — re-running it is a no-op.

> ⚠️ **If `php` is not in `PATH`, `upgrade.sh` skips the migration step** (see `upgrade.sh` lines 75-77 and 239-244). On minimalist containers or systems where `php` is only reachable via a full path, either add `php` to `PATH` before running `upgrade.sh` **or** run `php /var/www/ipam/migrate.php` manually after the upgrade completes. Without this step, your install retains TEXT-affinity `ip_bin` / `network_bin` storage, and `ORDER BY ip_bin` will produce incorrect results once new rows start arriving via v2.9.0's `PDO::PARAM_LOB` binding.

---

### v2.7.0

**No schema changes.** v2.7.0 is entirely a runtime rewire — every registered setting was already seeded into the `settings` table by v2.6.0's migration. `upgrade.sh` just syncs files, runs `php migrate.php` (no-op), and you are done.

**What changes at runtime:**

- Every subsystem now reads through `ipam_setting()` instead of `$config[...]`. Edits in **⚙ Admin → Settings** take effect on the next request without a `config.php` change or a restart.
- `config.php` still works as a fallback for back-compat. Nothing in your existing `config.php` stops working on upgrade.
- Three new registered OIDC keys (`oidc.disable_local_login`, `oidc.disable_emergency_bypass`, `oidc.hide_emergency_link`) are now visible in the admin UI. If you already have these in `config.php` they keep working via the fallback chain.

**Dealing with the deprecation banner.** After upgrading, if you have **customised** any non-bootstrap value in `config.php` (i.e. the value differs from the registry default), the admin Settings page will show a **config.php settings to migrate** banner listing each such key. You have two options:

1. **Click Import to database** on each row in the banner. This copies the current `config.php` value into the `settings` table in one atomic write (audited), and the row disappears. You can then delete the key from `config.php` at your convenience.
2. **Leave it.** The fallback keeps working through v2.7.x. You can batch the migration later — the banner stays visible until every customised key is imported or matches the registry default.

The dashboard shows a matching admin warning card linking to the banner so you do not forget.

**Server log warning.** Once per hour (rate-limited via `data/tmp/deprecation_warning.txt`), `init.php` writes a single consolidated line to the PHP error log listing every registered key still being served from `config.php`. This is informational — nothing is broken. It is there so your log aggregator surfaces the migration work before v3.0.0 removes the fallback.

**Sensitive values in the banner.** Sensitive keys (`oidc.client_secret`, `login_protection.secret_key`, `recaptcha_enterprise.api_key`) are masked as `***` in the banner — the secret itself is never rendered into the HTML source. **Import to database** still imports the real value from `config.php`; the mask only affects the display.

**What stays in `config.php` forever:** the bootstrap keys — `db_path`, `session_name`, `proxy_trust`, `force_https` / `base_url`, and `bootstrap_admin`. These are loaded before the database is open and will never be in the `settings` table.

---

### v2.1.0

**New database tables:** `vrfs`, `contacts`

**Modified tables:**
- `subnets`: the `UNIQUE(cidr)` constraint is replaced with `UNIQUE(cidr, vrf_id)` to support the same CIDR in different VRFs. Existing subnets get `vrf_id = NULL` (global VRF) — no data is lost.
- `addresses`: new nullable column `owner_contact_id` (FK → `contacts.id`).

These changes are applied automatically by `upgrade.sh` via `php migrate.php`.

**New admin pages:** `vrfs.php` (VRF management), `contacts.php` (Contacts management) — both accessible from the Admin dropdown.

**New config keys** added automatically by `config_auto_populate` on first boot after upgrade:
- `api_max_attempts` (default 20)
- `api_lockout_seconds` (default 300)
- `api_bulk_limit` (default 500)
- `recaptcha_enterprise` block (disabled by default)

**API additions:** `resource=vrfs` (full CRUD), `resource=contacts` (CRUD + `?q=` search), `?vrf_id=` filter on subnets, `?contact_id=` filter on addresses, `owner_contact_id`/`owner_contact_name` fields in address responses, `vrf_id`/`vrf_name` fields in subnet responses, `search.php?format=json` for the ⌘K overlay.

---

### v2.0.0

**New database tables:** `vlans`, `tags`, `subnet_tags`, `address_tags`, `alert_state`

**Modified tables:**
- `subnets`: new columns `vlan_fk` (FK → `vlans.id`), `tags` (via join table `subnet_tags`).
- `addresses`: new columns `mac`, `expires_at`, `tags` (via join table `address_tags`).
- `sites`: new column `parent_id` (nullable FK → `sites.id`, self-referential for region/site hierarchy).
- `users`: new columns `name`, `email`, `last_login_at`, `password_changed_at`, `theme`.

These changes are applied automatically by `upgrade.sh` via `php migrate.php`.

**New admin pages:** `vlans.php` (VLAN management), `tags.php` (Tag management) — both accessible from the Admin dropdown.

**New config keys** added automatically on first boot after upgrade:
- `utilization_warn`, `utilization_critical` (subnet utilization thresholds)
- `auto_reserve_network_broadcast` (default `true`)
- `alert_email`, `alert_util_warn_pct`, `alert_util_crit_pct` (email alerts, disabled by default)
- `password_policy` block
- `login_protection` block (bot protection, disabled by default)
- `demo_mode` block (disabled by default)
- `oidc` block (disabled by default)

**API additions:** `vlan_name` and `tags[]` on subnet responses, `tags[]` on address responses, `resource=vlans` (full CRUD), `?tag=` / `?vlan_id=` / `?parent_id=` / `?site_id=` / `?ip_version=` / `?expired=1` filters, bulk write (`POST ?resource=addresses&bulk=1` / `POST ?resource=subnets&bulk=1`).

---

## CLI utilities

These scripts are run from the application directory using the PHP CLI.

### Run database migrations manually

```bash
cd /var/www/ipam
php migrate.php
```

Applies any pending schema migrations. Migrations are also applied automatically on each web request (`ipam_db_init()`) and during upgrades via `upgrade.sh`, so this is only needed for scripted or manual deployments.

### Clean up stale temp files

```bash
cd /var/www/ipam
php tmp_cleanup.php
```

Deletes uploaded CSV files and import plan files in `data/tmp/` that are older than `tmp_cleanup_ttl_seconds` (default: 24 hours). This also runs automatically as part of lazy housekeeping on normal site traffic — a cron job is not required.
