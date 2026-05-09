# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
as of v1.15.0. Versions prior to 1.15.0 used two-part numbering.

## [3.27.1] - 2026-05-09

Pass-A regression hotfix. Cuts a backlog of seven bugs surfaced during a manual regression sweep on 2026-05-08 that traced a production silent-failure incident on `ipam.seanmousseau.com` to a cluster of half-finished work in v3.21–v3.27. The honest framing: features were added at one site without propagation to adjacent sites in the same architectural surface — most visibly the IPAMBKP3 codec (v3.24.0) and `backup_vault_key` infrastructure (v3.26.0) that the orchestrator's encrypt write path was never wired to use. Every install whose `app_secret` was empty (the documented v3.26.0+ post-migration state) had encrypted scheduled backups silently failing, with five compounding observability gaps making the failures invisible in audit log, History UI, and stderr-redirected cron.

This patch ships seven fixes paired with the regression tests that would have caught each. Disaster recovery is restored. Sudo-class actions via OIDC re-auth work. The `sudo_once` TTL=0 policy now actually enforces "re-prompt every action" instead of behaving as a session-long warm grant. See `docs/internal/lessons-learned.md` §8 for the architectural pattern that produced these and the mandatory pre-PR checklist that aims to prevent recurrence; see `docs/internal/2026-05-08_Path_Forward.md` for the broader recovery plan including v3.27.2 follow-ups (#1123), the v3.28.0 test-tooling baseline release, and the deferred multi-tenancy decision.

### Fixed

- **Scheduled backups encrypted to remote destinations now actually use the vault key.** The IPAMBKP3 encrypt codec landed in v3.24.0 and the `backup_vault_key` infrastructure landed in v3.26.0, but the orchestrator at `lib/backup.php:316` was never updated to use either — it read `$config['app_secret']` only and threw `ipam_backup: encryption requested but app_secret is empty` on every install where `app_secret` was empty (the documented post-vault-relocation state). v3.27.1 wires the orchestrator's encrypt site to a new resolver `ipam_backup_resolve_encrypt_to_tmp` that picks vault key first (IPAMBKP3 stored, `.ipambkp3` suffix), falls back to `app_secret` (legacy IPAMBKP2, `.enc` suffix), and only throws when neither is configured — with an actionable error message pointing the operator at the vault-key setup UI. Mirrors the restore-side dispatcher's two-tier lookup at `lib/backup.php:1241-1264` (already correct since v3.26.0). Tests at `tests/BackupEncryptDispatchTest.php`.
- **Backup/restore-pipeline observability — five compounding gaps closed.** O1: `cron.php $fail()` now records across STDERR, `error_log()`, AND a new `cron.task_failed` audit row — the prior STDERR-only emission disappeared on every prod cron entry shaped `> /dev/null 2>&1`. O3+O4: orchestrator's pre-INSERT region (`lib/backup.php:316-413`) now wraps in try/catch and INSERTs a `backup_runs` row with `status='failed'`, synthetic filename `(preflight-failed-<8hex>)`, and the truncated exception in `error_message`; new `backup.preflight_failed` audit verb fires alongside. O5: the schedule-overdue detector now flags schedules whose most-recent run is `status='failed'` regardless of `next_run_at` (pre-fix it read only `next_run_at`, which `finalize_schedule_run` advanced on every tick whether the run succeeded or failed — silently masking "every fire fails" patterns). O2: `docs/internal/deploy-targets.md` adds a new "Cron wrapper script" section documenting the required `>> /var/log/ipam-cron.log 2>&1` redirect plus an example `logrotate.d` config. Tests at `tests/CronFailObservabilityTest.php`, `tests/BackupPreflightFailureTest.php`, `tests/OverdueDetectorTest.php`.
- **IPAMBKL1 archives can be restored again.** The dry-run path (`ipam_restore_dry_run`) historically read every staged file as SQL via `ipam_restore_split_sql_statements`. Fed an IPAMBKL1 archive (JSON-shaped), the SQL splitter interpreted JSON tokens as SQL identifier/dollar-quote openers and threw `unterminated …` errors at EOF — making every IPAMBKL1 backup with user data un-restorable on v3.27.0. Disaster recovery was broken. Fix adds magic-byte sniffing at the start of dry-run mirroring the apply-path dispatch at `lib/backup.php:1745-1754`; new `ipam_restore_logical_dry_run` helper walks the archive header, body row-counts, and footer checksum without invoking the SQL splitter. Test at `tests/RestoreDryRunTest.php::testIpambkl1ArchiveDoesNotErrorThroughSqlSplitter`.
- **`vault_set` Generate works on installs with legacy IPAMBKP2 archives.** The precondition gate at `ipam_vault_key_status()` refused vault-key generation when ANY `backup_runs` row had `encryption_mode != 'unencrypted'`. But IPAMBKP2 archives are encrypted with `app_secret` and are independent of `backup_vault_key` — generating a new vault key cannot orphan them. Fix narrows the gate to `... AND filename LIKE '%.ipambkp3'` so only true IPAMBKP3 archives trigger the protection. No migration required — pre-existing rows are all `.enc` or `.sql.gz`. Test at `tests/HasEncryptedRunsGateTest.php`.
- **OIDC re-auth for sudo-class actions actually returns to the originating page.** `ipam_sudo_oidc_reauth_redirect_url()` at `lib/auth_step_up.php:480-489` required `$returnPath[0] === '/'` (absolute paths only) and silently fell back to a hardcoded `'destinations.php'` on every relative input. Every sudo-class handler in the codebase passes a relative path (`api_keys.php`, `change_password.php`, `settings.php?tab=...`, `backup_admin.php?tab=destinations`). Net effect: every sudo-class action via OIDC re-auth dropped its original POST and bounced operators to `destinations.php` — silent action drop. Reproduced end-to-end against Authentik twice in Pass A. Fix mirrors the validator at `step_up.php:30-49` (`ipam_step_up_validate_return_to`), which had the right shape all along — accepts relative paths, rejects schemes/hosts/traversal/backslash/CRLF/oversize. Test at `tests/SudoOidcReauthValidatorTest.php` (21 cases via data providers).
- **TTL=0 step-up policy now actually re-prompts every action.** `ipam_sudo_consume_once()` was defined at `lib/auth_step_up.php:91-94` and documented as the consumption mechanism for one-shot grants — but ZERO handlers called it. Effect: TTL=0 grants persisted as session-long warm grants, weaker than even the 60-second timed grant. Operators choosing the strictest TTL got LOOSER security than its alternatives — directly contradicting the policy registry's UI text. Reproduced live in Pass A: under TTL=0, a single `auth.sudo_passed` audit row covered two distinct sudo-class actions in the same session. Fix inserts `ipam_sudo_consume_once()` after each `if (!ipam_sudo_require(...))` block in 6 handlers (9 call sites total). `step_up.php` is intentionally exempt (it's a relay endpoint, not an action handler). Test at `tests/SudoConsumeOnceWiringTest.php`.
- **MFA enrollment changes now invalidate stale sudo grants.** `step-up-auth.md` documented 11 events that should call `ipam_sudo_invalidate()`; Pass A audit found 6 had no caller. v3.27.1 wires the 3 self-action sites: TOTP enroll, Email OTP enroll, passkey add. The 3 cross-user sites in `users.php` (role downgrade, `oidc_sub` link/unlink) are documented limitations — `ipam_sudo_invalidate()` clears `$_SESSION` on the current request only and cannot reach into another user's session; cross-user invalidation requires a session-marker mechanism and is tracked for v3.28.0. Test at `tests/SudoInvalidateWiringTest.php`.

### Acknowledged limitations carried forward

- **Cross-user sudo-grant invalidation** (`users.php` role downgrade, `oidc_sub` link/unlink) — documented in code with `// Bug T … KNOWN LIMITATION` markers. Tracked for v3.28.0.
- **OIDC auto-provisioner writes a real bcrypt password hash** instead of the `!disabled` sentinel the OIDC-only-admin lockout-protection model expects. Bug U from Pass A; deferred to v3.27.2 (#1120) per `2026-05-08-v3.27.1-hotfix.md` §9.5.
- **Settings page boolean-toggle vs group-form path separation** can wipe unsaved string-field input. Bug V from Pass A; deferred to v3.27.2 (#1121).
- **MFA-disable lockout precondition guard** — disabling the only enrolled step-up method strands the user. Bug Y from Pass A; deferred to v3.27.2 (#1122) per Path Forward §1 step 2.

### Process

- **Path Forward doc landed** at `docs/internal/2026-05-08_Path_Forward.md` — binding development-discipline doc for everything after this hotfix, covering the 9-step recovery plan, the multi-tenancy deferral with door-open rules for v3.28.0+ design, the architecture-decision review backlog, and a list of specific operating-mode commitments.
- **Lessons-learned §8** is new: "Architectural pattern: feature added at one site, propagation to adjacent sites missed." Maps every Pass A bug to the same architectural shape, lists 7 spot-the-pattern questions, ships a mandatory pre-PR checklist, and names the 3 missing test classes (round-trip, contract enforcement, negative regression) the v3.28.0 test-tooling baseline release will close.

[3.27.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.27.0...v3.27.1

## [3.27.0] - 2026-05-08

Step-up authentication. Sensitive admin actions (vault-key reveal/set/replace, API-key creation, settings-reveal, DB import/export, password change, MFA disable, step-up policy save) are now gated by a unified `ipam_sudo_verify()` helper that accepts any of the user's enrolled MFA methods (TOTP, Email OTP, WebAuthn passkey, account password, OIDC re-auth) per install policy — replacing the v3.26.0 hardcoded password re-prompt that locked OIDC-only deployments out of vault-key administration (#1098). Optional sudo-grant TTL caches a successful re-auth for the configured window so a single proof satisfies a fan-out of sensitive actions within that window. Admin-tunable policy card (`Settings → Authentication`) lets operators choose which methods to allow and the TTL.

### Added

- **`ipam_sudo_verify()` step-up helper + session sudo grant (#1107).** New `Simple-PHP-IPAM/lib/auth_step_up.php` centralises step-up proof verification across all sensitive admin actions. Supports `totp`, `email_otp`, `webauthn`, `password`, and `oidc_reauth` methods. On success mints a session grant (`sudo_until_ts` if TTL > 0, `sudo_once` otherwise) so sensitive handlers can call `ipam_sudo_require()` without re-prompting until the grant expires or is consumed. Hard-floors per-IP at 5 sudo failures per 15 minutes (`auth.sudo_rate_limited` audit). Failures audit `auth.sudo_failed` with a stable reason code (`password_invalid`, `totp_invalid`, `method_unavailable`, …); successes audit `auth.sudo_passed`. Shared step-up prompt partial at `Simple-PHP-IPAM/views/_step_up_prompt.php` renders the strongest enrolled method first (passkey > totp > email_otp > password > oidc_reauth) with an inline JS toggle when multiple methods are available.
- **Step-up authentication policy registry + migration (#1108).** Five new admin-tunable settings under `auth.step_up.*` (`allow_totp`, `allow_email_otp`, `allow_webauthn`, `allow_provider_reauth`, `ttl_seconds`). The TTL dropdown ships six discrete values: 0 (re-prompt every action), 60s, 5min, 15min, 30min, 1h. Migration `3.27.0-step-up-policy` registers defaults; existing installs get `allow_*=true` and `ttl_seconds=900` (15 min) on first boot.
- **Step-up policy admin card (#1109).** New group on `settings.php?tab=authentication#group-step_up` exposes the four allow-flags + TTL with a lock-out precondition guard: a save that would strand every active admin (no method satisfies any active admin's enrollment) is refused server-side before the sudo gate runs. The save itself is a sudo action and writes an `auth.step_up_policy.updated` audit row.
- **Step-up auth subsystem reference (#1115).** New `docs/internal/step-up-auth.md` documents the `ipam_sudo_verify()` contract, policy keys, session keys/TTL, invalidation triggers, prompt UX, and the recipe for migrating a new sudo-class admin handler. Read this before adding or migrating a sensitive action.
- **PHPUnit + Playwright coverage for step-up auth (#1114).** New `tests/SudoVerifyTest.php`, `tests/StepUpPolicyTest.php`, `tests/StepUpPolicyMigrationTest.php` cover every verify branch (rate-limit, method-unavailable, TOTP/email-OTP/password/webauthn/oidc, grant TTL slide-forward, idempotent migration). Four new Playwright specs (`step-up-policy-admin`, `step-up-fan-out`, `step-up-vault-flow`, `step-up-oidc-only`) exercise the end-to-end gate, the OIDC-only-admin regression case (#1098), the lock-out guard, and the one-grant-satisfies-many-actions TTL contract. Specs are portable across SQLite, MySQL/MariaDB, and PostgreSQL.

### Changed

- **Vault-key actions (`vault_set` / `vault_replace` / `vault_reveal`) migrated to `ipam_sudo_verify()` (#1110).** Replaces the v3.26.0 inline `admin_password` field with the unified step-up gate. The vault-specific per-IP reveal rate limit (5/15min) is retained on top of the helper's generic `sudo` bucket. **Closes the OIDC-only admin lockout (#1098):** an admin whose `password_hash` is `!disabled` (provisioned by OIDC, no local password) can now satisfy the gate via TOTP, passkey, or provider re-auth instead of being permanently refused.
- **`Reveal vault key` promoted out of `<details>` to a primary, always-visible button (#1111).** The v3.26.0 collapsed-disclosure UI hid the most-common admin action behind an extra click and made the action discoverable only after operators went looking for it. Now rendered inline next to the fingerprint with a one-line audit-log/rate-limit explainer.
- **`change_password.php` sudo actions migrated to `ipam_sudo_verify()` (#1112).** Disable-TOTP, disable-Email-OTP, and remove-passkey now go through the unified step-up gate. Replaces the v3.26.x bespoke password-only prompts with the policy-aware helper so an OIDC-only admin can drop a stale TOTP enrollment via TOTP itself or provider re-auth.
- **`settings_reveal.php`, `db_tools.php` import/export, `api_keys.php` create migrated to `ipam_sudo_verify()` + OIDC sudo re-auth wired (#1113).** `settings_reveal.php` now returns `401 step_up_required` JSON to ungated XHR callers (caller bounces to a step-up prompt and replays). The OIDC re-auth method completes via `oidc_login.php?prompt=login`; the callback handler calls `ipam_sudo_grant('oidc_reauth')` after a successful re-authentication.
- **Step-up TOTP branch reads encrypted secret + decrypts via `app_secret` (#1107 follow-up).** The TOTP verify path was previously reading a non-existent `totp_secret` column; now decrypts `totp_secret_enc` with `ipam_totp_decrypt_secret()` to match the login-time TOTP path.

### Fixed

- **Existing Playwright specs updated to handle the step-up gate.** Specs that exercise sudo-class actions (vault, api_keys, settings reveal, db_tools, change_password) now mint a sudo grant via the new `warmSudoGrant()` fixture before the action; `warmSudoGrant()` fail-louds if the proof is rejected so CI failures surface immediately instead of timing out 15s later on a misleading downstream assertion.
- **`step-up-vault-flow` + `step-up-oidc-only` Playwright specs purge encrypted `backup_runs` in `beforeEach`.** The CR #1100 "encrypted backups exist" guard was blocking these specs' `vault_set + generate` path because earlier specs in the suite (backup-integration etc.) leave encrypted runs behind. New CLI helper `Simple-PHP-IPAM/testing/scripts/purge_encrypted_backup_runs.php` resets the precondition.

### Deprecated

- **`backup.vault_key.sudo_failed` audit alias.** The unified `auth.sudo_failed` row covers every sudo-class action's verification failure; the vault-specific alias is retained for one release as a SIEM-query bridge and will be removed in v3.28.0. Existing log searches that filter on `backup.vault_key.sudo_failed` should migrate to combining `auth.sudo_failed` with the corresponding `backup.vault_key.*` audit row that follows on a refused vault action — see `docs/internal/audit-actions.md` for the migration guidance.

### Removed

- None.

### Security

- **Sensitive admin actions are no longer satisfied by a stale login session alone.** Every sudo-class action now requires either a fresh proof or a session sudo grant minted within the policy TTL. Reduces the blast radius of a stolen session cookie: the attacker still cannot reveal the vault key, mint an API key, import a SQL dump, change a password, or disable an MFA factor without the user's second factor.
- **Step-up failures rate-limited per IP (5/15min).** `auth.sudo_rate_limited` audit row fires before the proof is even checked once the bucket is full.

[3.27.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.26.0...v3.27.0

## [3.26.0] - 2026-05-07

Backup-overhaul closeout + code-quality sweep. Two breaking-change footnotes (legacy backup runner retired, vault-key relocated to DB) plus a sweep of P0/P1 security and correctness fixes that landed earlier in the cycle. The 26-issue scope across tracks A–E is documented in `docs/superpowers/plans/2026-05-06-v3.26.0.md`.

### Removed

- **Legacy v3.7 single-destination backup runner retired (#1059).** The `run_db_backup_if_due()` runner, the four legacy settings keys (`backup.enabled`, `backup.frequency`, `backup.retention`, `backup.dir`), and the `backup.php` CLI entry point are removed. Backups are driven entirely by the unified `backup_destinations` + `backup_schedules` surface from now on, with `cron.php` as the sole scheduler entry point. `db_tools.php`'s in-page "Automatic Backups" card + Backup History table moved to `backup_admin.php`. **Operators upgrading from a pre-v3.23 install must pass through v3.23.0–v3.25.x first** so `ipam_legacy_backup_migrate_if_due()` can materialise their legacy schedule into a unified destination + schedule pair; the new `3.26.0-retire-legacy-backup` migration enforces this with a hard-fail sentinel check. See `docs/upgrading.md` → v3.26.0 for the full operator runbook.

### Added

- **DB-resident `backup_vault_key` (#1098).** The 32-byte vault key that protects IPAMBKP3-encrypted archives moved out of `config.php` and into a wrapped envelope in the `settings` table. New `bootstrap_key` config field (auto-generated on first use, mirrors `app_secret` lifecycle) wraps the vault key via libsodium `crypto_secretbox`; the raw key never lives in the database. New admin panel on `backup_admin.php?tab=destinations` shows the 8-hex fingerprint, source label, and updated timestamp; reveal-key requires sudo-mode password re-prompt + per-IP rate limit (5/15min) and emits `backup.vault_key.revealed`. Set / Replace forms gate on first-time setup vs. encrypted-runs absence. Six new audit actions documented in `docs/internal/audit-actions.md`. v3.26.0 explicitly ships **without rotation** — replacing the key with encrypted backups in history would orphan them; the Replace form is hidden until the operator purges encrypted runs.
- **Destination-disabled-mid-backup signal (#859).** `ipam_backup_cancel_reason()` now returns `cancel_requested` (operator clicked Cancel) or `destination_disabled` (admin flipped `is_active=0`) so the orchestrator's audit detail discriminates the two. Both signals reach the same "mark canceled, audit, cleanup tmpfile" choreography.
- **Memory-bounded streaming property test (#860).** Asserts `ipam_backup_logical_dump` peak memory delta stays under 64 MB regardless of row count; nightly CI workflows can crank `IPAM_LARGE_DB_TEST_BYTES=1073741824` for a 1 GB synthetic round-trip.
- **Retention dry-run/apply round-trip property test (#1044).** Pins the contract that the UI's "what would be deleted" preview matches what the apply pass actually deletes — exact-set equality across protected-row exclusion, below-capacity no-op, and above-capacity prune cases.
- **Visual regression coverage restored on `/subnets` and `/search` (#1091).** The 200–470 px PostgreSQL drift reported in #1073 no longer reproduces; HTML and `api.php?resource=subnet_stats` JSON are byte-identical across SQLite, MySQL, and PostgreSQL.

### Security

- **CSRF on `set_theme.php` (#879).** Hard 403 on token-validation failure (no Location-redirect leak surface).
- **Rate-limit on auth endpoints (#882).** `forgot_password.php`, `reset_password.php`, and `email_otp_verify.php` now share `login.php`'s per-IP brute-force cap via the new `auth_rate_limited()` helper. New schema column `login_attempts.action` discriminates the limiter scope.
- **SSRF gaps closed in `ipam_validate_webhook_url()` (#872).** Block-list expanded to cover IPv4-mapped IPv6, link-local, and metadata-service ranges; resolution probes the actual hostname rather than the literal URL host.
- **Email-OTP attempt-count race (#874).** Counter increment now happens inside the same transaction as the verify check, so two parallel POSTs cannot both land below the cap.
- **Tags `colour` CSS injection defence (#869).** Tag colour is rendered through a CSS custom property indirection rather than inlined into the `style` attribute; an injected `;` breaks out of nothing.
- **`client_ip()` walks XFF right-to-left with a trusted-proxy CIDR list (#876).** Each hop is admitted only if it appears in `proxy_trust_cidrs`; the first untrusted hop wins. Closes the spoof vector where a remote client sets a forged `X-Forwarded-For` header.

### Changed

- **Audit-log filter normalises date params (#880).** `from`/`to` accept `YYYY-MM-DD` and ISO-8601 timestamps interchangeably; previously only the latter parsed correctly.
- **Audit-log action-prefix filter unified (#881).** `audit.php` and `api.php` now use the same prefix-match semantics; previously the API was strict-equality only.
- **Webhook delivery uses `ipam_last_insert_id()` (#870).** Cross-engine wrapper replaces the SQLite-specific `lastInsertId()` call that returned `'0'` on PostgreSQL.
- **Webhook event matching uses engine-native JSON containment (#871).** SQLite uses `json_extract`, MySQL uses `JSON_CONTAINS`, PostgreSQL uses `@>`. Previously matched on a regex over the serialised JSON, which broke on whitespace or key-order variance.
- **`ipam_setting()` differentiates schema-missing from real PDO errors (#873).** A genuinely failing query no longer silently returns the registry default; only `42S22 / 42703 / 'no such column'` are swallowed.
- **MySQL `GET_LOCK` name hashed to a 32-char digest (#875).** MySQL silently truncates lock names beyond 64 bytes; long settings keys sharing a prefix could collide. The composed name is now `ipam_setting:<md5(key:scope)>`.
- **`audit()` and `ipam_send_mail()` failures are surfaced (#877).** Previously caught and silently dropped; now logged to `error_log` with context so a misconfigured SMTP setup or a corrupted `audit_log` table is visible to operators.
- **`auto_reserve_subnet_ips()` wrapped in a transaction (#878).** A partial failure mid-loop no longer leaves the subnet half-reserved.
- **Settings UQ divergence cross-referenced (#884).** Comment block in `migrations.php` (`3.13.0-settings-cascade`), `lib.php` (`ipam_setting_set` GET_LOCK call), and `tests/SchemaParityTest.php` whitelist now point at each other so a maintainer changing one sees the contract.

### Fixed

- **`audit.php` admin gate ordering (#883).** The role check now runs before any database read; previously a non-admin's POST could trigger a settings query before the 403.
- **CI triggers correctly on push to `dev`/`main` (#866).** `php-qa.yml` and `playwright.yml` workflows updated.
- **UpgradeReplay fixtures hard-fail on missing files (#868).** Previously skipped silently if a fixture path was wrong, masking a real coverage gap.
- **OIDC verify + JWK unit test scaffold (#867).** New `OidcVerifyTest` covers token-signature validation in isolation.
- **Multi-driver fresh-install migration parity (#885).** Asserts the same end state across SQLite, MySQL, and PostgreSQL.

### CI / Tests

- **`tests/VaultTest.php`** (12 cases) — wrap/unwrap round-trip, tamper-detection, length validation, fingerprint stability.
- **`tests/BackupCancelReasonTest.php`** (7 cases) — cancel-discriminator under all signal combinations.
- **`tests/BackupRetentionDryRunTest.php`** (4 cases) — round-trip property test for retention dry-run/apply.
- **`tests/BackupStreamingMemoryTest.php`** (1 case + nightly env-var crank) — streaming memory property.
- **`testing/playwright/tests/vault-key-admin.spec.ts`** (5 tests) — sudo gate, rate limit, one-shot flash, replace gating.

[3.26.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.25.0...v3.26.0

## [3.25.0] - 2026-05-06

The operator-facing finale of the backup-overhaul stream. **Surfaces the v3.23.0 `IPAMBKL1` engine-agnostic backend via a new picker UI** (Logical default, Database under Advanced), **rehomes retention from per-schedule to per-destination**, and ships the U-series UX polish: dashboard backup card, health-page connectivity section, encryption-format icons in History, type-name-to-confirm on destination delete, skeleton loaders, cancel-in-flight on Run-now, S3 range-resume, and a Verify-all bulk action. Plus per-tab notification overrides and one orphan UX fix (subnet description in the addresses-page dropdown).

### Known limitations
- **Verify-all and Run-now cancel UX use simple confirm/alert dialogs.** Drawer-based progress UI with per-row pills will be filed against v3.26 once milestone is open. The server-side handlers, audit, and cancel infrastructure are complete and shipping.
- **Test-suite expansion is partial.** The umbrella test issues #1044 (picker UI / retention rehome / GFS table), #859 (destination-disabled mid-backup), #860 (large-DB streaming), and #1091 (PG VR drift bisect) will land in a v3.25.x patch. The shipping code paths are exercised by the existing 794-test PHPUnit suite plus the v3.23.0 `IPAMBKL1` round-trip and v3.24.0 `IPAMBKP3` tests; this release adds operator-facing surface, not new engine code.

### Added
- **Backup format picker (#1076).** New radio on every destination form: **Logical** (engine-agnostic `IPAMBKL1`, default) or **Database** (engine-native dump, escape hatch). Run-now and schedule create both pre-fill the destination's `default_backup_type`. Backwards compatible &mdash; existing destinations migrate to `default_backup_type='logical'`.
- **Dispatch wire-up (#849).** `ipam_backup_run_for_destination()` now branches on `backup_type` to call the v3.23.0 `IPAMBKL1` backend or the existing engine-native dump path. The hardcoded `'database'` value at `lib/backup.php:701` is gone; `backup_runs.backup_type` reflects the chosen format and the History tab's filter chip finally has both values to filter against.
- **Retention rehome (#846).** Retention windows (hourly/daily/weekly/monthly) move from `backup_schedules` to `backup_destinations` so they describe "how much to keep at this storage location" rather than "how often we write to it." Existing per-schedule values backfill into the destination on upgrade. Per-schedule retention columns are preserved for one release cycle for downgrade safety.
- **Default destination flag (#848).** New `is_default` column on `backup_destinations` plus "Set default" action with single-row uniqueness via transactional UPDATE. Pre-fills Run-now drawer and schedule-create form.
- **`is_protected` UI (#847).** Per-row Protect / Unprotect action on the History tab; "★ protected" badge in the History list. Schema and retention-skip logic shipped in v3.21.0; this release completes the operator-facing surface.
- **Opt-out encryption for trusted Local destinations (#851).** New `default_encryption_mode` column with values `stored | transitory | unencrypted`. `unencrypted` is gated to Local destinations both server-side and in the UI. Backfilled from the legacy `encrypt` boolean on upgrade so `encrypt=0` destinations stay unencrypted.
- **Cancel-in-flight on Run-now (#856).** New `cancel_requested` column on `backup_runs`; orchestrator polls at chunk boundaries (post-dump, before-upload, mid-upload). Canceled runs land as `status='failed'` with `error_message='canceled-by-operator: <phase>'` and a `backup.cancel` audit row.
- **S3 range-request resume (#852).** `S3Client::download()` now does up to 3 retry attempts with `Range: bytes=offset-` headers, accumulating bytes in a `<dest>.partial` sidecar across attempts. Sidecar persists across calls when download throws. Falls back to full re-download if the server returns 200 with a non-zero offset (Range ignored).
- **Verify-all bulk action (#850).** "Verify all" button per destination row downloads + re-hashes every successful `backup_runs` row on the destination, with summary envelope `{ok, total, success, failed, failures[]}`. Audit: `backup.verify_bulk` once per bulk run.
- **Encryption-format icon in History (#857).** Per-row pill (`v1` / `v2` / `v3` / `Plaintext` / `Per-passphrase`) derived from `encryption_mode` + `source_version`. Tooltip explains the format.
- **Type-name-to-confirm on destination delete (#858).** Extends the existing `[data-confirm-delete]` JS so any form carrying `[data-confirm-typename]` requires the operator to type the destination name before submission.
- **Dashboard backup card (#853).** Admin-only card on `dashboard.php` showing last run / next scheduled / total stored / destinations count.
- **Health page destinations section (#854).** New "Backup destinations" section on `health.php` reads the existing `backup.destination_health` setting (cron Task 6c since v3.22.0) and renders one row per destination with last-test status. "X of Y healthy" headline.
- **Per-tab notification recipients + delivery (#1078).** 3 new admin settings (`backup.notify_recipient_user_ids`, `backup.notify_recipient_email_extra`, `backup.notify_delivery_method`) let backup notifications target a different audience than the global alert infrastructure. Empty override falls back to `alert.recipient_user_ids`. Extra free-form CSV emails combine on top.
- **Skeleton loading states (#855).** New `[data-skeleton]` attribute toggle (`loading`/`ready`) plus `window.ipamSkeleton` helper. Reuses the existing `.skeleton-row` primitive from v2.13.0; respects `prefers-reduced-motion`.

### Changed
- **`backup.encryption_change` audit generalised** from the legacy `encrypt` boolean to the `default_encryption_mode` enum (`stored | transitory | unencrypted`). `$details` now carries `name=<destname> old=<mode> new=<mode>`.
- **Subnet description in the addresses-page dropdown (#1093).** The Subnet selector at the top of `addresses.php` renders `<cidr> — <description>` instead of bare CIDR. CIDR-first ordering preserved so existing operator muscle memory still works.

### Migrations
- `3.25.0-backup-destination-evolution` adds `retention_hourly|daily|weekly|monthly`, `is_default`, `default_backup_type`, `default_encryption_mode` to `backup_destinations`, plus `cancel_requested` to `backup_runs`. Backfills retention from any per-schedule rows (most-generous MAX) and `default_encryption_mode` from the legacy `encrypt` boolean. Idempotent. Applies cleanly across SQLite / MySQL / PostgreSQL.

## [3.24.0] - 2026-05-05

The encryption-format release. New on-disk backup format **`IPAMBKP3`** with three modes (stored / transitory / unencrypted), a new server-side secret `backup_vault_key` separate from `app_secret`, and a manual upload-and-restore wizard step with passphrase entry. Backwards compatible &mdash; existing IPAMBKP1 and IPAMBKP2 archives remain restorable. Plus a long-running PostgreSQL intermittent finally tracked down and fixed: `ensure_audit_log_table()` was emitting `CREATE OR REPLACE TRIGGER` on every request, racing on the system catalog under concurrent PHP-FPM load.

### Added
- **`IPAMBKP3` encryption format (#836).** Three modes per backup:
  - **Stored**: HKDF-SHA256 over a server-managed `backup_vault_key`; auto-generated on first encrypted backup, lives in `config.php` distinct from `app_secret`. Used for scheduled / destination-driven runs.
  - **Transitory**: Argon2id (RFC 9106 v1.3, defaults `t=3 / m=64 MiB / p=1`) over an operator-typed passphrase. Server never persists the passphrase. Used for manual upload-restore where the operator wants a passphrase only they know.
  - **Unencrypted (`IPAMBKU1`)**: integrity-only wrapper (magic + SHA-256 + plaintext) for trusted-local destinations.
  - Single-pass streaming encrypt-then-MAC (AES-256-CTR + HMAC-SHA256). Atomic tempfile-then-rename on decrypt &mdash; a failed HMAC verify leaves no plaintext on disk. Argon2id parameters are header-embedded so future tuning is non-breaking.
  - Comprehensive tamper / corruption test suite including AES-256-CTR SP 800-38A reference vector and 5 MiB streaming peak-memory bound assertion. `tools/decrypt-backup.php` standalone CLI ships for offline decrypts when the originating IPAM install is gone.
- **Manual upload-and-restore wizard step (#837).** New "Upload a backup file" affordance on the Restore tab, parallel to the destination-driven browser. Accepts every supported format. For IPAMBKP3 transitory archives the wizard prompts for the passphrase between upload and dry-run via a typed `IpamBackupKeyRequiredException`. Backed by a new admin-tunable `backup_max_upload_size_mb` setting (default 2 GiB).
- **Cron tick fire-decision tests (#840, T7).** Five PHPUnit tests cover schedule fire under inactive bits (schedule, destination, both) plus reactivation paths, complementing existing `CronConcurrencyTest` coverage.

### Changed
- **`ensure_audit_log_table()` is now probe-first.** Cheap no-row `SELECT 1 FROM audit_log LIMIT 0` short-circuits the full CREATE-TABLE / CREATE-INDEX / CREATE-TRIGGER path on every request after first init. Race protection comes from the probe gating the DDL &mdash; if the table exists, no `CREATE OR REPLACE TRIGGER` fires. `prune_audit_log()` (SQLite) and migration 1.12, which DROP triggers and previously relied on `ensure_audit_log_table()` as a side effect, now call the new `ensure_audit_log_triggers()` helper explicitly.
- **`backup_decrypt_to_path()` signature extended** with optional `$passphrase` and `$vaultKey` parameters. v1/v2 callers pass nothing &mdash; same legacy paths run unchanged. The dispatcher peeks 9 bytes (magic + mode for IPAMBKP3) and routes to the matching codec.
- **Encryption-mode vocabulary clarified.** Documentation now describes "stored / transitory / unencrypted" as **key-source labels**, not encryption-at-rest vs in-transit labels. The wire path is always TLS-protected for S3 and SSH-protected for SFTP regardless of mode. See [`docs/backups.md` &rarr; Encryption](docs/backups.md#encryption).

### Fixed
- **PostgreSQL `tuple concurrently updated` intermittent.** `ensure_audit_log_table()` previously emitted `PgsqlDialect::append_only_trigger()`'s `CREATE OR REPLACE TRIGGER` on every request, racing on the system catalog when concurrent requests (page + sub-resources, parallel API calls, bursty Playwright runs) all hit `init.php` at the same time. Surfaced as `SQLSTATE[XX000]` and a sporadic 500 on whichever request lost the race. SQLite + MySQL were unaffected because their dialects use racy-safe `CREATE TRIGGER IF NOT EXISTS`. Same class of issue that #1091's bisection was deferred for; both classes resolved by the probe-first change.
- **#838 audit cluster** &mdash; `random_bytes()` availability assert at every encrypt entry point (B-P1-35), HMAC purpose-string audit across every dialect call site (B-P1-13), constant-time double-HMAC compare for IPAMBKP3 verify (B-P1-40), `BACKUP_TAG_LEN` / `BACKUP_IV_LEN` docblock clarifications (B-P2-46), and IPAMBKP1 GCM IV-reuse note in upgrade docs (B-P2-5).

### Documentation
- **`docs/backups.md`** rewritten Encryption section + IPAMBKP3 / IPAMBKU1 byte layouts in On-disk format (#842).
- **`docs/configuration.md`** new `backup_vault_key` reference section with lifecycle, rotation, storage guidance and `app_secret` comparison table (#841).
- **`docs/upgrading.md`** new v3.24.0 section: vault-key auto-generation, legacy archive compatibility, manual upload-restore step, encryption-mode vocabulary change (#845).
- **`docs/internal/data-dictionary.md`** regenerated &mdash; no diff (IPAMBKP3 reuses the v3.21.0 `backup_runs.encryption_mode` column) (#843).

### Security
- New `backup_vault_key` (32 random bytes, base64 in `config.php`) auto-generated on first encrypted backup. Distinct rotation lifecycle from `app_secret`. Required for IPAMBKP3 stored mode; ignored for transitory mode (operator passphrase only) and unencrypted (no key).

## [3.23.0] - 2026-05-03

The backup-overhaul wave. Engine-agnostic logical backup format ships end-to-end (writer + reader); per-schedule notification overrides editable in the unified Backup &amp; Restore admin surface; the legacy `backup.*` settings group is deprecated with an automatic one-shot migration to a Local destination + schedule on first v3.23.0 page load. Plus retention/scheduler refactors, OOM-safe streaming restore, three-driver SFTP/Local/MinIO integration coverage, MFA switch-graph live e2e, and a destination-driven backup browser on the Restore tab. **18 milestone issues closed in a single release.**

### Known limitations
- **Visual-regression coverage on `subnets` and `search` is paused on PostgreSQL.** Both pages render +200&ndash;470 px taller on PG than on SQLite/MySQL with no source change since v3.21.0; #1073's option (c) excluded them from the VR set pending bisection. Tracked as #1091.

### Added
- **`IPAMBKL1` engine-agnostic backup format (#824).** Full PDO-driven dump + restore backend &mdash; gzipped NDJSON with abstract types (int / string / bool / null / ISO-8601 timestamp / base64-binary). Re-emit-IDs replay strategy with two-pass self-FK resolution. Magic-byte dispatch (`IPAMBKL1` &rarr; PDO; `IPAMBKP1`/`IPAMBKP2` &rarr; existing shell-out). Three-driver 3&times;3 cross-engine round-trip parity proven against MySQL 8.0 + PostgreSQL 14.
- **Per-schedule notification overrides (#825).** Every backup schedule may now override the global *Scheduled-backup failure* / *Scheduled-backup success* defaults and pin its own recipient CSV. Edit on `backup_admin.php?tab=notifications` &mdash; tri-state per field (Inherit / On / Off) plus optional recipient list. Schema migration `3.23.0-notify-overrides` adds four columns to `backup_schedules` (idempotent across all three engines).
- **Destination-driven backup browser on the Restore tab (#1077).** Step 1 of the restore wizard now lists the selected destination's actual contents (filename, size, date, encryption, type, checksum) with per-row Download / Restore / Verify-Delete-link / degraded-restore notice. The free-text filename input is preserved under an Advanced disclosure for slow-LIST destinations.
- **OpenSSH SFTP CI sidecar (#833).** `linuxserver/openssh-server` runs alongside MinIO under `bootstrap-app.sh`; the existing `backup-integration.spec.ts` now iterates `[ci-minio, ci-local, ci-sftp]` for full SFTP transport coverage.
- **Live click-and-land tests for the MFA switch-graph buttons (#770).** TOTP &harr; Email OTP transitions are exercised end-to-end (MailHog-gated). Closes the v3.18.0 cover gap.
- **Local destination CI coverage (#834).** Unit-level error paths (missing source, read-only directory) and a checksum round-trip alongside the existing Playwright integration coverage.

### Changed
- **`run_db_backup_if_due()` is gated by the migration sentinel.** On installs where `ipam_legacy_backup_migrate_if_due()` has materialised a unified destination + schedule, the legacy v3.7 runner is suppressed so the unified cron path doesn't double-fire.
- **Settings &rsaquo; Data &amp; Maintenance &rsaquo; Backup section is deprecated (#1058).** A banner at the top points to `backup_admin.php`. Legacy keys remain readable through v3.25.0; hard-removal is in v3.26.0 (#1059).
- **`ipam_backup_apply_retention()` split into compute + apply (#826).** Pure-function compute step is unit-testable in isolation; orchestrator stays thin. Dead `$nowEpoch` parameter chain dropped from the GFS selector through to the cron call site.
- **`ipam_backup_next_run_at()` rewritten on `DateTimeImmutable` (#827)** with extracted per-frequency helpers. 18 table-driven edge cases (#832) pin behaviour.
- **SQL splitter is a chunked generator now (#829).** Multi-gigabyte dumps stream through the restore pipeline without round-tripping the whole file as a PHP string.
- **Dry-run pre-validates the dump via the splitter (#830).** Unterminated `BEGIN...END` blocks fail at parse time, not mid-apply.
- **Legacy retention sorts by `filemtime`, not lex (#828).** Operator-renamed files no longer get pruned first regardless of age.
- **`mysqldump` / `mysql` opt into `--no-login-paths` when supported (#1081).** MariaDB 11.4+ and Oracle MySQL 8.x clients get the flag; older MariaDB clients fall back gracefully.

### Fixed
- **MySQL identifier quoting in the IPAMBKL1 writer/reader.** Per-driver `ipam_logical_q()` emits backticks on MySQL and ANSI double-quotes on SQLite/PostgreSQL.
- **MySQL DATETIME rejection of ISO-8601 'T'/'Z' form.** `ipam_logical_timestamp_for_mysql()` coerces to the space-separated literal MySQL accepts under default `sql_mode`.
- **PostgreSQL BYTEA returns as a stream resource by default.** `PgsqlStatement` now wraps test connections (matches production `ipam_db()`) so `ipam_logical_encode_value()` doesn't reject `ip_bin` / `network_bin` columns.
- **PostgreSQL `lastInsertId()` requires a sequence name.** The replay path uses `INSERT...RETURNING <pk>` on PostgreSQL; SQLite and MySQL keep the existing path.

## [3.22.3] - 2026-05-02

Hotfix for v3.22.2. The Backup History per-row drawer (#803) rendered the Download action as a `GET <a href="download_remote_backup.php?run_id=...">`, but `download_remote_backup.php` is a POST-only endpoint that requires CSRF plus `destination_id` + `name` (it does not read `run_id`). Every Download click in the drawer returned `405 POST required`. Other entry points to the same endpoint (e.g. `remote_backups.php`) already used a POST form and were unaffected.

### Fixed
- **Download from Backup History drawer no longer returns 405** — `Simple-PHP-IPAM/views/_backup_run_detail_body.php` now emits a sibling `<form id="backup-run-download" method="post" action="download_remote_backup.php">` carrying `csrf`, `destination_id` (from the joined `backup_runs` row), `name` (the run's `filename`), and `as=file`. The Download button inside `.drawer-actions` is bound to that form via the HTML5 `form="..."` attribute so the disabled-state matrix and visual placement match Verify/Delete. The existing JS handler at `assets/app.js:2152` already short-circuits on `data-action="download"`, so the native form submit fires unchanged.

## [3.22.2] - 2026-05-02

Hotfix for v3.22.1. Production MySQL backups failed against Oracle MySQL 8.x clients because the v3.22.1 SSL-verify flag emitted the MariaDB-canonical `=on/off` form, which Oracle MySQL 8.4 rejects with `[ERROR] unknown variable 'ssl-verify-server-cert=off'`, exit code 7. Conversely, MariaDB 11.x clients (CI's runner image) verify the server cert by default and need an explicit opt-out flag — so a one-flag-fits-all simplification regresses the other half of the field. The diagnostic also only landed in PHP's `error_log`, not the backup-run row, so operators saw a generic "see error_log" message in the UI without a clear path to the actual cause.

### Added
- **`ipam_mysql_client_flavor(string $binary = 'mysqldump')` helper** in `lib/backup.php` — probes `<binary> --version` once per binary, classifies the local client as `'mariadb'`, `'mysql'`, or `'unknown'`, caches per-binary so a host with split-vendor `mysql` / `mysqldump` (e.g. distro `mysqldump` plus a custom `mysql` client) gets the correct dialect for each. The deferred #1081 follow-ups (`--no-login-paths`, broader `--ssl-mode` adoption) reuse this probe.
- **`ipam_mysql_ssl_verify_args(bool $verify, string $binary = 'mysqldump')`** — emits the flavor-correct flag list. MariaDB gets `--skip-ssl-verify-server-cert` (off) or `--ssl-verify-server-cert` (on). Oracle MySQL gets `--ssl-mode=VERIFY_IDENTITY` (on) or no flag (off — `ssl-mode=PREFERRED` is the client default and does not verify). Unknown flavors fall back to no flag.

### Changed
- **`backup_run_dump()` surfaces stderr to callers via a new optional by-reference `$errorOut` parameter.** The legacy CLI runner now records the full `mysqldump`/`pg_dump` stderr (truncated to 500 chars) in the `backup_runs.error_message` column, and the modern destination orchestrator includes it in the `RuntimeException` message that drives the failure-notification pipeline. Operators see the actual cause in the UI and email body instead of "see error_log". The `proc_open()` startup-failure branch also populates `$errorOut` (e.g. `"proc_open failed to start mysqldump (not on PATH or disabled)"`) so a missing binary is no longer an empty diagnostic.

### Fixed
- **`mysqldump` / `mysql` SSL verify flag now flavor-aware** across all three call sites — `Simple-PHP-IPAM/lib/backup.php::ipam_backup_native_cmd` (destination orchestrator), `Simple-PHP-IPAM/lib.php` (legacy CLI runner), and `Simple-PHP-IPAM/restore.php` (mysql restore). Replaces the v3.22.1 hard-coded `--ssl-verify-server-cert=on/off` syntax that worked on MariaDB but bricked every prod backup running against Oracle MySQL ≥ 8.x.

## [3.22.1] - 2026-05-02

Hotfix for v3.22.0. Two production-affecting issues with MySQL/MariaDB backups:

1. **MariaDB 11.4+ / MySQL 8.4+ clients verify the server certificate chain by default.** Both `mysql`/`mysqldump` invocations from v3.22.0 (legacy CLI runner in `lib.php`, restore in `restore.php`) failed against any MySQL/MariaDB server with a self-signed certificate — common on internal/on-prem deployments. The error surfaces as `mysqldump: Got error: 2026: "TLS/SSL error: self-signed certificate in certificate chain"` and the operator-facing Run-now / Restore reports `fclose(): Argument #1 ($stream) must be of type resource, null given` (the secondary cascade through `ipam_backup_dump_to_tmp`). PDO already connects without verifying the server cert (PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT defaults to false), so the dump/restore wrappers should match.

2. **#1075 completes the v3.22.0 #820 destination orchestrator credential migration.** v3.22.0 routed mysql/psql passwords through 0600 temp files for the legacy CLI path and the restore path; the v3.17+ destination dump pipeline (`lib/backup.php::ipam_backup_native_cmd`) was attempted in PR #1074 but reverted at the last minute when the same TLS issue (above) made MySQL/MariaDB Run-now fail in CI. With #1 fixed, the temp-file pattern can now ship for the destination orchestrator too.

### Added
- **New setting `backup.dump_ssl_verify`** (bool, default false) — controls whether `mysqldump` / `mysql` client invocations verify the MySQL/MariaDB server TLS certificate chain. Default off matches PHP PDO_MYSQL's default behaviour (`PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` defaults false) and lets the app connect to internal/on-prem servers with self-signed certs. Operators with a properly-chained CA cert can flip the setting to ON for stricter verification.

### Fixed
- **mysqldump / mysql client TLS verification (CR fix on PR #1080)** — `--ssl-verify-server-cert` is now toggled by the new `backup.dump_ssl_verify` setting (default off — same outcome as the original v3.22.1 patch but operator-overridable, fixing the CR finding that the flag was hardcoded and command-line precedence prevented `/etc/mysql/conf.d/` override). All three call sites: `Simple-PHP-IPAM/lib/backup.php::ipam_backup_native_cmd` (destination orchestrator), `Simple-PHP-IPAM/lib.php` (legacy CLI runner), and `Simple-PHP-IPAM/restore.php` (mysql restore).
- **`--no-login-paths` deferred to v3.23.0 (#1081).** Initially added to all mysqldump/mysql invocations during PR #1080 CR remediation, but reverted because MariaDB 10.x clients (which Debian 12's `default-mysql-client` resolves to and which CI uses) reject the flag with "unknown option" and the dump fails outright. v3.23.0 will add a one-time `mysqldump --help` probe to detect support and conditionally enable. Until then the `~/.mylogin.cnf`-substitution attack surface remains — bounded by the requirement that an attacker first land a login-path entry in the app-server account's home directory, an unusual threat model.

### Security
- **DB credentials no longer in the process environment for the destination orchestrator** (#1075, completes #820). `lib/backup.php::ipam_backup_native_cmd` now routes the password through a 0600 temp credential file (`--defaults-extra-file` for mysql, `PGPASSFILE` for pgsql) instead of `MYSQL_PWD` / `PGPASSWORD` env vars. The v3.22.0 #820 fix shipped this for the legacy CLI runner and restore path; the destination orchestrator (the primary v3.21+ code path) was reverted at PR #1074 due to the TLS issue above and now ships in this hotfix. Inherited password env vars from the parent shell are still stripped defensively before `proc_open`.

## [3.22.0] - 2026-05-02

Backup/restore concurrency hardening, cron architecture rework, and the v3.22.0 Notifications scope from `backup_overhaul.md` §2.4. Theme: lock-file/DB-level guards across backup runs, prune, retention, and scheduled tests; the §2.4 per-event email surface; a stale-row reaper so a crashed orchestrator can't permanently block fresh runs. Eleven milestone issues plus the §2.4 placeholder commitment.

### Added
- **Per-schedule pessimistic claim** in `cron.php` Task 6 — `BEGIN IMMEDIATE` on SQLite, `FOR UPDATE SKIP LOCKED` on MySQL/PostgreSQL. Closes the SELECT-then-UPDATE race where two cron processes could both fire the same due schedule. (#816)
- **Stale-running-row reaper** + active-run concurrency guard in `lib/backup.php`. Any `backup_runs` row stuck in `running` past the threshold (default 7200s) gets force-marked `failed` so a crashed/killed orchestrator can't block fresh runs. The orchestrator runs the reaper inline as a defensive sweep; cron runs it on every tick so liveness doesn't depend on operator action. (#815)
- **Soft time budget for the scanner** (`IPAM_CRON_SCANNER_BUDGET_SECS = 600`). When elapsed time on a tick exceeds the budget, the scan block defers to the next tick — earlier work (backups, webhook delivery) never starves on a long sweep. (#817)
- **Bulk multi-select delete on the History tab** — checkboxes + bulk-action bar reusing the `addresses.php` / `subnets.php` pattern, CSRF-protected POST handler, per-row delegation to `ipam_backup_run_delete()`, whole-batch refusal on protected rows. (#1052)
- **Time-based auto-purge for `backup_runs` rows** — new settings `backup_runs.retention_days` (default 90, 0 disables) and `backup_runs.prune_batch_size` (default 500). Cron task deletes rows older than retention in batches; skips `running` and `is_protected = 1` rows. One audit entry per call (not per row) — purge volume can be in the thousands. (#1053)
- **§2.4 Notifications — per-event email preferences.** Replaces the v3.21.0 read-only summary with an editable surface covering eight events: backup-success (scheduled / manual), backup-failure (scheduled / manual), destination connection-test failure, schedule-overdue, retention-prune, encryption-mode change. Two new cron tasks: destination connection re-test (alerts on healthy → failing transition) and schedule-overdue detector (cooldown via JSON map keyed by schedule_id). All settings global-only — per-schedule overrides remain parking-lot per §2.4.
- **New audit actions**: `backup.skipped_concurrent`, `backup.reaped`, `backup.wal_checkpoint_failed`, `backup_run.bulk_delete`, `backup_run.purge`, `backup.connection_test_failed`, `backup.schedule_overdue`, `backup.encryption_change`.
- **Test coverage** — `tests/CronConcurrencyTest.php` (7 methods, includes `pcntl_fork` actually-concurrent path), `tests/BackupReaperTest.php` (6), `tests/NotificationDispatcherTest.php` (6), `tests/OverdueDetectorTest.php` (6). Closes the test categories from #1041. (#823, #822)

### Changed
- **Cron task ordering reshuffled.** Scheduled backups now run BEFORE the scanner; scanner is last among heavy tasks, gated by the soft time budget. New layout: tmp_cleanup → audit prune → address-history prune → utilization alerts → legacy db_backup → backup reaper → backup_runs purge → backup_schedules → webhook retry → webhook prune → connection re-test → overdue detector → scanner → demo_reset. (#817)
- **`started_at` / `created_at` unification on `backup_runs`** — confirmed already-shipped during v3.22.0 scope-lock. v3.21.0 #799 collapsed `backup_history` + `backup_log` into the unified `backup_runs` table; the schema comment at `schema.sql:624` carries the explicit closure note. Naming differs from the issue body's spec (`completed_at` ≡ requested `finished_at`); the requested separate `created_at` was deliberately not added since the orchestrator inserts the row AND begins the run in the same call. (#809)
- **Scheduled-run failure semantics.** `next_run_at` is now advanced at claim time, not after success. Failed scheduled runs no longer auto-retry on the next tick — `Run-now` is the recovery path. The previous "retry every tick until success" interacted poorly with destinations failing predictably (expired credentials, etc.) and produced one alert per tick. (#816)
- **`ipam_backup_notify()`** rewritten as event/context dispatcher; the legacy 4-arg signature is preserved as a thin shim so `BackupNotifyWiringTest`'s source-scan still holds.
- **Old notification settings retired** — `backup.notify_on_failure` and `backup.notify_on_success` no longer in the registry. Defaults of the eight new keys preserve prior operator intent (failure_scheduled+manual ON; success_scheduled+manual OFF). Orphaned rows in the `settings` table are harmless until a future cleanup migration sweeps them.
- **`schedule_id` is now populated on `backup_runs`.** `cron.php` passes the claimed schedule's id through `ipam_backup_run_for_destination()` into `ipam_backup_insert_log()`; insert binds `PDO::PARAM_NULL` for manual runs. Closes silent NULL since v3.21.0. (#821)

### Fixed
- **WAL checkpoint exceptions are no longer silent.** `lib.php` and `restore.php` previously had empty `catch (Throwable) {}` around `PRAGMA wal_checkpoint(...)`. Both now audit `backup.wal_checkpoint_failed`, `error_log` the message, and continue (checkpoint is best-effort, not correctness-critical). (#819)
- **Cross-suite `custom_field_defs` leak.** `testing/playwright/tests/custom-fields-csv.spec.ts` self-heals via a pre-create cleanup that deletes by `key` (not by id), and the teardown id-delete falls through to a key-delete on any failure. Stops `cf_csv_spec_txt` from leaking across runs and cascading into `test_api.sh` 422s. (#1051)
- **Notifications-tab Playwright fixtures.** Rewrote the read-only-summary describe block as an editable-preferences block (8 toggles, grace-minutes input, CSRF, recipients summary). Regenerated all eight `backup-admin-notifications-*` visual-regression baselines for the post-§2.4 view.

- **`backup_run_dump` pipe-buffer fill risk** — confirmed already-shipped during v3.22.0 scope-lock. Verified the non-blocking stderr drain landed in commit `b30fd5d7` on 2026-04-22, eight days before the issue was filed. The deadlock the audit finding describes cannot occur in current code. (#818)

### Security
- **DB credentials no longer in the process environment (legacy CLI + restore paths).** `mysql`/`mysqldump` and `psql`/`pg_dump` invoked from the legacy CLI runner in `lib.php` and from `restore.php` route the password through a 0600 temp credential file (`--defaults-extra-file=<tmp>` for mysql, `PGPASSFILE=<tmp>` for pgsql) instead of `MYSQL_PWD`/`PGPASSWORD` env vars. Inherited password env vars from the parent shell are stripped defensively before `proc_open` so a hostile parent can't override the routing. New helpers `ipam_backup_write_mysql_defaults_file()` / `ipam_backup_write_pgpass_file()`; callers wrap `proc_open` in `try/finally` so the temp file is unlinked on every exit path including `restore_die()`. (#820)
- **Note — destination orchestrator path retains env-var routing in v3.22.0.** The v3.17+ destination dump pipeline (`lib/backup.php::ipam_backup_native_cmd`) still passes the password via `MYSQL_PWD` / `PGPASSWORD` env vars. A planned migration to the temp-file pattern broke under MySQL/MariaDB CI in PR #1074 and was reverted to keep the release shippable. Tracked for v3.22.1 hotfix. The defensive strip-inherited-env behaviour applies here too, so a parent-shell-owned secret can no longer silently override the configured one.

## [3.21.1] - 2026-05-01

Hotfix for v3.21.0. The new `3.21.0-schedule-unique` migration's dedup loop issued an UPDATE that referenced the named placeholder `:keep` twice in the same query (`SET schedule_id = :keep … AND id <> :keep`). On MySQL with PDO native prepared statements (the project's default — `EMULATE_PREPARES = false`), each placeholder occurrence is a distinct slot, so executing with one `:keep` value tripped `SQLSTATE[HY093]: Invalid parameter number` and rolled the upgrade back. SQLite tolerates the reuse, which is why the bug only surfaced on the prod MySQL deploy (demo, with no duplicate schedules, never reached the dedup loop body).

### Fixed
- **`3.21.0-schedule-unique` dedup-loop UPDATE on MySQL.** The repoint statement now uses distinct placeholder names per occurrence (`:keep_target` for the SET, `:keep_filter` for the subquery WHERE), bound separately at execute time. Behavior is unchanged on SQLite/PG. (#1054 production-deploy regression.)
- **New `tests/MigrationTest.php::testV321ScheduleUniqueDedupRepointsBackupRuns` regression.** Pre-seeds duplicate `backup_schedules` rows with attached `backup_runs` history before running migrations, asserts that (a) the highest-id schedule survives, (b) every historical `backup_runs.schedule_id` is repointed to the survivor before the loser rows are deleted, and (c) the unique index lands afterward. (The MySQL-specific HY093 itself isn't reproducible on SQLite, but the dedup-with-repoint behavior is.)

## [3.21.0] - 2026-05-01

Unified Backup & Restore admin surface, restore-wizard rewrite, and a single `backup_runs` history table merging the two legacy logs. Theme: collapse the six pre-existing backup pages into one tabbed admin surface with consistent drawer-driven CRUD. Includes a P0 sigchild fix on the restore path and a real-lexer SQL splitter rewrite.

### Added
- **Unified `Backup & Restore` admin (5 tabs)** at `backup_admin.php` — Destinations, Backup, History, Restore, Notifications. The legacy URLs (`backup_history.php`, `destinations.php`, `restore_web.php`, `notifications.php`) continue to work as direct entry points but the sidebar nav points at the unified surface. (#797)
- **Per-row backup detail drawer on the History tab** — clicking a history row opens a global drawer showing run metadata (started, completed, size, checksum, error) plus three actions: Verify (re-downloads the artifact and SHA-256 compares against the stored checksum), Download (signed link via `download_remote_backup.php`), and Delete (requires typing `DELETE` literal-string confirmation; deletes both the DB row and the remote artifact, audits `remote_backup.delete` / `remote_backup.delete_failed`). (#803)
- **Filter chips on History** — three chip rows (Status / Backup type / Time) above the existing form. Chip clicks mutate single URL parameters (`status`, `backup_type`, `since`); a Clear-all chip resets all filters. Time chips are `Last 24h / 7d / 30d / All time`; the Backup type axis (`database` | `logical`) replaces the obsolete `backup`/`restore` selector and the URL parameter renames from `type=` to `backup_type=`. (#804)
- **Drawer-driven CRUD across the surface** — Destination editors and schedule editors now open in the global drawer (`destination_edit_drawer.php` partial endpoint). Replaces the inline `<tr id="edit-destination-…">` collapsing rows; the single drawer pattern is now the canonical CRUD UI for backup admin. (#800, #803)
- **Inline progress reporting on Run-now** — manual backups dispatch async and report success/failure inline in the page rather than redirect-and-flash. (#801)
- **Empty-state copy across all five tabs** — "No history yet — kick off a Run-now to populate this view" / "No destinations configured — add one below" rather than blank tables. (#802)
- **Manual upload-and-restore flow** in the Restore tab with passphrase entry for `IPAMBKP2`/`IPAMBKP3` archives.
- **`tests/BackupAdminRbacTest.php`** — structural lint asserting every backup admin page contains `require_role('admin')` in the first 8 KB. Covers all five tabs plus the two drawer-partial endpoints (`backup_run_detail.php`, `destination_edit_drawer.php`). Paired with `testing/playwright/tests/backup-rbac.spec.ts` for HTTP-level coverage. (#811)
- **`tests/RestoreSplitterTest.php`** — property tests for the SQL statement splitter, exercising synthetic dumps with embedded semicolons, multi-line strings, and dollar-quoted PostgreSQL function bodies. Pinned before the splitter rewrite. (#810)
- **`testing/playwright/tests/backup-history-drawer.spec.ts`** — E2E coverage for the per-row drawer (open, action wiring, disabled-state for retained-by-policy rows, DELETE-confirm flow). (#803)

### Changed
- **Single `backup_runs` history table** consolidating the two legacy logs (`backup_history` from v3.7's CLI runner + `backup_log` from v3.17's destination runner). Migration copies all rows from both into the new table preserving destination/schedule linkage where present, then drops the legacy tables. The new schema explicitly separates `backup_type` (database | logical) from `triggered_by` (schedule | manual | cli) — these were previously conflated in `backup_log.type` and `backup_history.triggered_by` with non-orthogonal values. (#799, #808)
- **Restore wizard rewritten as a phase-locked state machine** in `lib/restore_wizard.php`. Adds upload-token persistence across phases, deterministic step-machine transitions, explicit cleanup on every exit path, and `set_time_limit(0)` + session invalidation around the restore execution phase. Closes #6, #43, #50, #56, #61, #62. (#807)
- **SQL splitter rewritten as a real lexer** rather than naive `;`-split. Correctly handles escaped quotes, multi-line string literals, dollar-quoted PostgreSQL function bodies, and `--` / `/* */` comments. Pinned by `RestoreSplitterTest`. (#806)
- **Backup history filter URL parameter renamed** `type=backup|restore` → `backup_type=database|logical`. The legacy `type=restore` value still parses (yields zero rows in `backup_runs` since the restore axis is no longer tracked there); existing bookmarks of `type=backup` are no-ops. (#804)
- **`docs/backups.md` rewritten end-to-end** for the unified surface; obsolete `docs/backup.md` removed. (#812)
- **`docs/restore.md` rewritten** covering both Logical and Database restore paths, the manual upload flow, the same-or-newer cross-version restore policy, and CLI restore commands for MySQL/PostgreSQL Database backups. (#813)
- **New `docs/internal/backup-restore-runbook.md`** — operator runbook for incident response (restore from S3, verify integrity, cross-version restore). Living doc; eight failure modes covered at v3.21.0. (#814)

### Fixed
- **Sigchild fix on `restore.php`'s `proc_close`** — the same fallback v3.19.1 applied to `backup_run_dump`. Prevents a sigchild-handler race that returned exit code -1 on slow-to-terminate child processes. (#805)
- **Schedule edit drawer freq-field gating** when injected via the new global-drawer fetch — the existing `applyFreqGating()` was bound only at DOMContentLoaded, so drawer-loaded forms left `day_of_month` with `value="0"` against `min="1"`, silently blocking HTML5 form validation. Drawer fetch now dispatches a `drawer:loaded` CustomEvent and the gating re-binds against newly-injected `form.schedule-form`.
- **`backup_runs.started_at` populated unambiguously** by both orchestrators (CLI and destination runner). Closes the `started_at` vs `created_at` divergence in queries; `started_at` is now the canonical "when did this run begin" timestamp. (A separate audit-only `created_at` column is deferred to v3.22.0 — see #809.)

### Removed
- **`db_tools.php` Database admin sidebar nav entry**. The page itself is retained for direct-URL data-export flows but no longer exposes any backup functionality and is no longer linked from the sidebar. (#798)

### Known limitations
- **`backup_runs.created_at` audit column** (#809, B-P1-31) is deferred to v3.22.0. The issue's spec admits `created_at == started_at in practice`; formal column split needs a table rebuild because SQLite forbids non-constant `DEFAULT (datetime('now'))` on `ALTER TABLE ADD COLUMN`. Pairs naturally with v3.22.0's planned concurrency-hardening migration window.

## [3.20.0] - 2026-04-30

Backup destinations UX + reliability polish. No schema migrations, no new runtime dependencies, no breaking changes.

### Added
- **Inline Edit drawers for destinations and schedules on `destinations.php`** — edit name, type, config, retention, frequency, day-of-week, day-of-month, time-of-day, and credentials in place. Replaces the previous "delete and recreate to change anything" workflow. (#778, #780)
- **Per-destination Run-now action** — destination rows now expose their own "Run backup now" button; previously only schedules could trigger a manual run. (#779)
- **Auto-run Test on Save** — creating or editing a destination now triggers `test_destination.php` automatically and renders an inline pass/fail badge with latency and any error text. Eliminates the "save then click Test then maybe see a flash on next page load" two-step. (#787)
- **MinIO-backed end-to-end backup integration test in CI (#789)** — the full pipeline (dump → encrypt → upload → list → download → decrypt → verify) runs against a real S3-compatible server on every push, across SQLite / MySQL / PostgreSQL. Sidecar pattern via `bootstrap-app.sh` (always-on `ipam-pw-net` Docker network); MinIO container started/torn down with the test app, idempotent destination seeding via `seed-backup-destinations.php`.

### Changed
- **Backup timestamps render in the user's configured timezone** instead of UTC across `backup_history.php` and the destinations tables. Uses `ipam_format_datetime()` everywhere; a new semgrep rule (`ipam-backup-utc-timestamp`) prevents future drift back to raw `gmdate()` / `date()` in echo context. (#782)
- **Schedule form hides fields that don't apply to the selected frequency** — `day_of_week` is hidden for hourly/daily/monthly; `day_of_month` for hourly/daily/weekly; `time_of_day` for hourly. JS toggles `[hidden]` on the field row + label via `data-freq-field` attributes; server-side normalisers null out hidden fields on submit so stale values can't leak from a frequency change. (#781)
- **`BackupClientInterface::list()` renamed to `listObjects()`** to avoid collision with PHP's `list()` language construct. Internal interface only — no public API surface. Site-local code that called the method directly needs the rename. (#796)
- **`backup.php` CLI uses `getopt()` for flag parsing** and documents its exit codes. `-f`, `--force`, and `--force=1` are now interchangeable. Web requests still return HTTP 403. (#794)

### Fixed
- **Destination secret-merge logic hardened** — the form's secret-merge path previously assumed every credential field was always submitted, so a partially-submitted form could null out an existing key. New `ipam_destination_merge_secrets()` helper is a pure function with explicit per-field merge semantics, plus a guard that rejects type changes on existing rows (S3 → SFTP would have left orphaned config keys). (#793, #778)
- **`ipam_backup_notify()` wired into both orchestrators** — success and failure email was previously dead code on the schedule path. Cron-driven and manual Run-now backups now both fire notifications reliably; a regression test pins the call. (#791)
- **S3 error-body redaction scoped to signature/credential XML elements** — the previous regex stripped any 20+ char base64-ish run from response bodies, which collaterally erased XML node text in unrelated S3 errors (e.g. bucket-not-found). Redaction now targets `<Signature>` / `<AWSAccessKeyId>` / credential-bearing elements specifically. (#795)
- **Schedule create-form Playwright selectors no longer collide with Edit-drawer fields** — the new inline Edit drawers introduced duplicate field IDs in the DOM; create-form selectors are now scoped via `:not([hidden])` and within the create-form scope so strict-mode locators match exactly one element.

### Security
- **`ipam-backup-utc-timestamp` semgrep rule** — prevents reintroduction of UTC-formatted backup timestamps in echo context (regression guard for #782).

---

## [3.19.1] - 2026-04-29

Hotfix release closing two long-standing gaps in the v3.17.0 remote-backup pipeline.

### Fixed
- **S3 destinations: HTTP 403 SignatureDoesNotMatch on every signed request (#784).** `S3Client::canonicalRequest()` emitted three newlines (`\n\n\n`) between the canonical-headers block and SignedHeaders where AWS SigV4 spec mandates two. The bug was a vestigial empty-string element in the `implode` argument list that compensated for an unrealistic test-input shape; in production every S3-compatible server (Wasabi, AWS, MinIO, Ceph) recomputed the canonical request from the wire bytes and rejected the resulting signature. Existing in v3.17.0–v3.19.0; no operator using S3 destinations was able to upload a backup to any S3-compatible service for those three releases. Added a `tests/S3CanonicalRequestTest.php` regression suite that pins the canonical-request layout against AWS SigV4 spec textbook inputs and an independent HMAC reference, plus an explicit `@param` contract on `canonicalRequest()` that headers must end with `\n`.

### Changed
- **Engine-agnostic remote-backup dump (#783).** `ipam_backup_dump_to_tmp()` no longer hard-throws on non-SQLite drivers. Added `ipam_backup_native_cmd()` helper (in `lib/backup.php`) and extended the dump function to dispatch by driver: SQLite via the existing `ipam_db_dump_stream` + gzip path; MySQL via `mysqldump` (password via `MYSQL_PWD` env, never on cmdline); PostgreSQL via `pg_dump` (`PGPASSWORD` env). Output format is `.sql.gz` for all three engines so the encryption + upload pipeline downstream is unchanged. Closes the v3.17.0 deferral that shipped without a tracking issue (see process fix below).

### Process / infrastructure
- **CHANGELOG follow-up lint (#785).** New `testing/scripts/check_changelog_followups.sh` and CI step in `php-qa.yml` that fails the build if a release entry contains deferral language (`follow-up`, `deferred`, `pending`, etc.) without a `#NNN` tracking-issue reference. Origin: a v3.17.0 CHANGELOG bullet shipped saying "MySQL/PostgreSQL backup pending follow-up" with NO issue, vanished from project memory, and surfaced only when an operator hit the gap on prod. `docs/internal/release-workflow.md` Phase 2 updated with the audit step and a top-level `### Known limitations` convention for any release that ships a feature partial.

### Operator notes for v3.17.0 / v3.18.0 / v3.19.0 upgraders
- If you tried to configure an S3 backup destination on v3.17–v3.19 and got HTTP 403 SignatureDoesNotMatch, the bug is fixed in v3.19.1. Re-test the destination after upgrading; existing destination rows + credentials work unchanged.
- If you are on MySQL or PostgreSQL and were told "SQLite-only dump" when running a backup, that restriction is lifted in v3.19.1. Local-disk backups via the legacy `backup.php` CLI runner (which DID support all three engines) continue to work as before.

---

## [3.19.0] - 2026-04-29

### Changed
- Encrypted backups now stream both during creation and during restore staging. The new on-disk format (`IPAMBKP2`) uses AES-256-CTR + HMAC-SHA256 in encrypt-then-MAC mode, with 64 KiB streaming chunks. Memory usage is bounded regardless of database size — multi-GB backups no longer OOM (#769).
- Restore is single-pass: each chunk is decrypted into a temporary file in the destination directory while an HMAC accumulates over the same ciphertext. The temp file is atomically renamed to the staged path only after the trailing MAC matches; on any failure the temp is unlinked, so failed verification leaves no plaintext file behind.
- Backward compatibility: v3.17 and v3.18 single-shot AES-256-GCM backups (`IPAMBKP1`) continue to restore unchanged via the back-compat decrypt path. No migration required for existing backups.

### Security
- Per-file HKDF salt fed through the RFC 5869 `salt` parameter (HKDF-Extract): every v2 backup derives a fresh enc_key + mac_key via `HKDF-SHA256(app_secret, info='ipam-v3:backup-v2', salt=<random 16 B>)`. Two backups with the same `app_secret` no longer share key material.
- HMAC covers magic + salt + iv + ciphertext, so an attacker cannot tamper with the header.

---

## [3.18.0] - 2026-04-29

### Added
- **Per-key save in `settings.php`** — boolean toggles can now be flipped individually via a new POST shape (`key=...&value=...`). The legacy group-POST handler treated every absent boolean as false, which silently flipped sibling MFA toggles when an admin only intended to flip one. Each boolean renders as its own `<form data-setting-toggle>` and auto-submits on change. The group form continues to handle non-bool fields with the existing batch validation UI. (#756)
- **`docs/contacts.md`** — new user guide for linking contacts to IP addresses via the Owner typeahead on `addresses.php`. Covers when to use a linked contact vs the free-text Owner field, the inline-edit behaviour that clears the link, and the API alternative. (#759)
- **`ipam_restore_assert_staged_path()` and `ipam_restore_canonicalize_staged()` helpers in `lib/backup.php`** — defence-in-depth containment guards called at every write site in the restore code path so a future refactor cannot accidentally write outside `data/tmp/`. (#762)

### Changed
- **`BackupEngine` and `RestoreEngine` refactored from classes to procedural functions** in a new `Simple-PHP-IPAM/lib/backup.php`. Both engines were single-implementation, no polymorphism, no per-instance state beyond constructor-injected `$db` / `$config` — natural fit for top-level functions per the project's procedural ethos. All call sites (`cron.php`, `restore_web.php`, `run_backup_now.php`, `download_remote_backup.php`) updated. Behaviour-equivalent: no schema, no API, no UI changes. (#762)
- **`cron.php` backup-schedules block** now forwards a pinned `$tickEpoch` through to `ipam_backup_run_for_destination()` and on to `ipam_backup_apply_retention()`, so retention prune timing is aligned to the cron tick rather than drifting with `time()` across long ticks. (#762)

### Fixed
- **`ipam_backup_apply_retention()`** now accepts and forwards `?int $nowEpoch` to `ipam_gfs_select_for_deletion()`. The wrapper previously dropped the parameter on the floor, so retention silently fell back to `time()`. Reflection + static-source contract test added. (#762)
- **Settings page UX** — skip-link to `#settings-content` jumps past the rail; rail `grid-template-columns` switched to `minmax(180px, 220px) 1fr` so 'Data & Maintenance' / 'Authentication' labels stop wrapping mid-word at 768–900px viewports; inline `style="margin-…"` strings lifted to a `.settings-row__*` utility set in `app.css`; `$flash` unset for consistency with sibling local helpers. (#758)
- **Unified MFA card** — drop `font-style:italic` on `.mfa-method-pill--unavailable` (italic on tight-tracked `--font-size-xs` hurt dark-mode legibility — `font-weight:500` upright now); document the `extra` grid-template-areas row as passkey-only by design; switch the three preserved-enrollment hint `<p>` elements from `role="status"` (polite live region) to `role="note"` (static contextual annotation). (#758)
- **Playwright `unassigned.spec.ts:76` flake on PostgreSQL full-suite runs** — the shared `deleteSubnet()` fixture in `testing/playwright/fixtures/ipam.ts` was single-shot, but `UNIQUE(cidr, vrf_id)` with NULL `vrf_id` permits duplicate rows per SQL standard. Orphan rows from an earlier spec's `afterAll` could cause `subnetIdFor()` to return the wrong subnet, making 'assigned IP excluded from unassigned table' false-positive. Fixture now loops `subnetIdFor → delete → reload` until empty. (#760)

### Notes
- No schema migrations. No new runtime dependencies. No new pages.
- The cumulative diff is small but the static analysis surface is significant: PHPStan L9 stays clean across the engine refactor; PHPUnit gains `BackupRetentionWrapperTest` (2) and `RestoreStagingPathTest` (8) — 411 tests, 0 failures.

---

## [3.17.0] - 2026-04-28

### Added
- **Web-based backup destinations.** Three destination types — S3-compatible (AWS, MinIO, Backblaze B2, Wasabi, DigitalOcean Spaces, Cloudflare R2 — hand-rolled AWS Sig V4 client, no AWS SDK), SFTP (via `phpseclib/phpseclib ^3.0`), and Local filesystem (under `data/`). Admin UI at `destinations.php` to create, edit, test connection, and delete destinations. (#690, #691, #692, #693)
- **Backup schedules with GFS retention.** Per-destination schedule (hourly / daily / weekly / monthly) with clock-aligned `next_run_at` and grandfather-father-son retention counters. Newest backup always preserved. Wired into `cron.php`. Manual "Run now" via AJAX. Email notifications via `backup.notify_on_failure` / `backup.notify_on_success` settings. (#695, #696)
- **AES-256-GCM backup encryption** with HKDF-SHA256 key derivation from `app_secret`. Magic header `IPAMBKP1` for forward-compat. Tamper detection on body, tag, IV, truncation, and plaintext-input. (#694)
- **Backup history log page** at `backup_history.php` — paginated 50/page with destination/status/date filters, Type column distinguishing backup vs restore, status badges, status-by-destination summary card. (#697)
- **Remote backup browser** at `remote_backups.php` — list/verify/delete files on a chosen destination via the client's `list()` interface. Verify recomputes SHA-256 against the stored `backup_log.checksum`. (#698)
- **Web-based restore wizard** at `restore_web.php` — three-step flow: stage from remote → dry-run preview (tables, row deltas, schema diff, warnings) → live apply with confirm-typing gate (`RESTORE` exactly). Wraps in transaction; runs `apply_migrations()` post-restore. (#699, #700)
- **Restore audit and history integration.** Restore operations write `backup_log` rows with `triggered_by='web_restore'` and audit_log entries (`db.restore_stage`, `db.restore_dryrun`, `db.restore`). History page Type filter shows backup vs restore. (#701)
- **PHPUnit coverage:** GFS retention algorithm (20 tests), encryption round-trip + tamper (12 tests), HKDF (3 tests including RFC 5869 TC1 vector), AWS Sig V4 (13 tests), SFTP constructor (6), LocalBackupClient (7), schedule next-run (8), v3.17.0 schema migration (2). 401 PHPUnit tests total, all passing. (#719, #720)
- **Playwright coverage:** 17 tests across destinations admin / scheduling / history (`backups.spec.ts`) and 7 across the restore wizard / history filter (`restore.spec.ts`). Live restore round-trip is opt-in via `IPAM_PW_RESTORE_LIVE=1` so default CI runs do not destroy the test DB. (#721, #722, #723)
- **New runtime dependency:** `phpseclib/phpseclib ^3.0` (SFTP). Bundled in the release tarball; no `composer install` needed at install time.

### Changed
- The legacy v3.7 `backup.php` CLI continues to write to the legacy `backup_history` table. New v3.17 destinations write to `backup_log`. They co-exist; no data migration required.
- Admin sidebar gains four entries under the Admin section: **Destinations**, **Backup History**, **Remote Backups**, **Restore Database**.

### Security
- Backup encryption keys are derived per-purpose via HKDF-SHA256 from `app_secret`. Rotating `app_secret` invalidates existing encrypted backups by design. (#694)
- The web-restore wizard requires admin role + CSRF; live apply requires the user to type `RESTORE` exactly. The staged file path is HMAC-signed so an attacker cannot forge an arbitrary path through the apply step.
- New semgrep rule `ipam-unlink-user-path` enforces that `unlink()` calls on user-input-derived paths go through `realpath()` containment.

### Notes
- Backup dumps are SQLite-only in v3.17.0; MySQL and PostgreSQL backup dumps are a follow-up.
- The new `backup_log` table is independent of the legacy `backup_history` table introduced in v3.7.0.

---

## [3.16.0] - 2026-04-28

### Added
- **Admin TOTP toggle** (`mfa.totp_enabled`, default `true`). Admins can globally disable TOTP enrollment and login dispatch from Admin → Settings → Multi-Factor Auth. Per-user `users.totp_enabled` is preserved when an admin toggles the global switch off; re-enabling restores each user's previous enrollment without re-running the QR flow. When TOTP is globally off, `mfa.require` falls back to Email OTP and Passkey. The Settings page also surfaces a warning banner when `mfa.totp_enabled = true` but `app_secret` is unset in `config.php`. (#747)
- **Preferred MFA method picker.** New nullable column `users.preferred_mfa_method` (text). Users with multiple methods enrolled can pick TOTP / Email OTP / Passkey as their default from the Account page; the dropdown lists only methods that are both enrolled on the account and globally enabled. Login dispatches the preferred method first and falls back gracefully if the chosen method becomes unusable (admin disabled it, account no longer has email, etc.). Audited as `auth.mfa_preferred_set`. (#746)
- **Full MFA switch graph at login.** Each verify page (`totp_verify.php`, `email_otp_verify.php`, `passkey_verify.php`) now offers switch buttons to either of the other two methods when they are enrolled and globally enabled. Clicking a switch button generates a fresh challenge, swaps the pending session keys, and redirects. Audited as `auth.mfa_method_switch`. Extends the v3.15.2 TOTP-page-only switch pattern to the full 3×2 graph. (#746)
- **Settings page tab navigation.** The 16 settings groups are reorganised into 5 vertical left-rail tabs: General, Authentication, Notifications, Data & Maintenance, Integrations. URL state via `?tab=`. Per-subsection POST flow is unchanged. Mobile <768px collapses the rail to a `<select>`. Includes skip-link, arrow-key keyboard navigation, `aria-current="page"` on the active tab. Legacy `#group-<key>` bookmarks auto-redirect to the right tab via a JS shim. (#749)

### Changed
- **Case-insensitive search across all DB engines.** `search.php`, `api_search()`, and `export_search.php` now use a portable `LOWER()`-based comparison via two new `Dialect` interface methods: `lower_expr()` and `case_fold_value()`. SQLite remains ASCII-only by design (no ICU bundled with the standard build); MySQL and PostgreSQL perform full Unicode case folding. (#750)
- **Account page Two-Factor Authentication card.** TOTP, Email OTP, and Passkeys now render as three rows inside a single "Two-Factor Authentication" card with consistent status pills, action affordances, and preserved-enrollment hints when an admin globally disables a method. New CSS classes under `.mfa-*`. Three Heroicon symbols added to the sprite: envelope, finger-print, plus-circle. (#745, #755)

### Fixed
- **PostgreSQL search was case-sensitive.** Previous `LIKE`-based search returned zero hits for `Acme` when the DB held `acme`. The portable `LOWER()` rewrite (above) corrects this. SQLite and MySQL behaviour is unchanged for ASCII; both now also handle non-ASCII input consistently with the per-engine collation. (#750)

---

## [3.15.2] - 2026-04-26

### Fixed
- **Email-verification link still bounced to dashboard for users with a stale or expired session.** The v3.15.1 redirect fix only stashed the request URI in the cold-start `!is_logged_in()` branch of `require_login()`. Users hitting an authenticated link with an idle-timed-out cookie went through `logout_user()` first, which wiped the session before any stash happened, and users hitting it with an absolute-lifetime-expired session went through `session_destroy()` for the same reason — both paths landed on the dashboard after re-login instead of the original URL. Both branches now stash `REQUEST_URI` before the session is wiped, then re-open a fresh session to write the stash.
- **Passkey registration failed with `verification_failed` ("no signature found") when using LastPass and other password-manager passkey providers.** The browser was returning a `packed` self-attestation without a `sig` field, which the WebAuthn library rejected. We now request `attestation: 'none'` from the browser so authenticators omit attestation statements entirely; we still verify origin, RP-ID hash, and user-presence flags, which work identically.
- **Passkey registration didn't make the credential discoverable.** `requireResidentKey` was hard-coded to `false`, which prevented LastPass / 1Password / Bitwarden from saving the credential to their vaults. It is now `'preferred'`, so password managers store the passkey while hardware keys that don't support resident keys still register normally.
- **Passkey credential name prompted via `window.prompt()` after registration.** Replaced the prompt with a server-rendered default — the configured `branding.site_name` — passed via a `data-default-name` attribute on the Add Passkey button. Users can rename in the management UI (or the password manager).
- **Relying-party name in WebAuthn options was hard-coded to "Simple PHP IPAM".** Now defaults to `branding.site_name`, so password managers / OS credential dialogs that honour `rp.name` (Apple Keychain, 1Password, Bitwarden, Windows Hello) display the install's friendly name. (LastPass uses the rpId regardless — that is a LastPass UX choice and cannot be overridden server-side.)
- **No way to choose Email OTP or a passkey at login when TOTP is also enrolled.** `login.php` always dispatched to `totp_verify.php` first. The TOTP verify page now offers "Send a code to my email instead" and "Use a passkey instead" buttons when those methods are enrolled and enabled; clicking either generates a fresh challenge, swaps the pending session keys, and redirects to the matching verify page. Audited as `auth.mfa_method_switch`. (Refactored the passkey-challenge dispatch out of `login.php` into a shared `ipam_passkey_dispatch_challenge()` helper.)

### Changed
- **Asset cache-buster auto-busts on file edits.** `page_header()` now appends `filemtime()` of `assets/app.js` and `assets/app.css` to their `?v=` query strings, so in-version edits invalidate browser caches without requiring an `IPAM_VERSION` bump. Other assets (icons, fonts, vendor) keep the version-only buster since they change rarely.

---

## [3.15.1] - 2026-04-26

### Fixed
- **Post-login redirect preserves the requested URL.** When an unauthenticated user clicked a link into the app (e.g. an email-verification link) they were bounced through `login.php` and dropped on `dashboard.php`, losing the original URL — they had to click the link again. `require_login()` now stashes the request URI in the session and the eight final-success login paths (local login, demo, recovery, OIDC, TOTP, TOTP-bypass, Email OTP, passkey) consume it. Stash is restricted to safe relative paths (no scheme, no host, no `..`, no CRLF) to prevent open-redirect or header-injection.
- **Email body charset.** PHPMailer's default `$CharSet` of `ISO-8859-1` mojibaked any UTF-8 in subjects or bodies (em-dashes, smart quotes, accented characters). `ipam_send_mail()` now sets `CharSet = UTF-8`. Encoding is left at the PHPMailer default (`8bit`), which travels fine on any modern SMTP and keeps the body human-readable.
- **Email-verification error reports the real cause.** `ipam_send_email_verification()` previously returned a bare `bool`, so the UI could only guess "Ensure SMTP and base_url are configured." It now returns `['success' => bool, 'error' => string]` and `change_password.php` surfaces the real reason (e.g. missing `base_url`, SMTP host unreachable).
- **`config.php` cleanup banner false positive on `app_secret`, `session`, `auth`, and `api`.** `ipam_config_stale_keys()` had a hardcoded bootstrap whitelist that only listed pre-v3.6.0 keys, so the dashboard banner has been telling admins to delete the v3.6.0 security-sensitive keys ever since — actions that would have broken every TOTP enrollment, the session absolute-lifetime gate, the 2FA lockout policy, and the per-API-key rate limiter. Whitelist now includes all four top-level keys.

### Changed
- **Command palette** (`app.js`) renamed "Audit Log" → "Audit" and added an "Unassigned" entry to match the v3.8.0+ sidebar labels.

### Security
- `ipam_post_login_redirect_stash()` and `ipam_post_login_redirect_consume()` reject backslash characters as well as the previous open-redirect / header-injection guards, since some browsers canonicalise `\` to `/` (which would let `/\evil.com` slip through as `//evil.com`).

### Documentation
- Added the missing `### v3.14.0` upgrade-notes section to `docs/upgrading.md` and the missing v3.15.1, v3.15.0, v3.14.0, v3.11.0, v3.10.0 entries to the table of contents.
- `docs/security.md` — `mfa.require` description now lists passkey alongside TOTP and Email OTP.
- `docs/install.md` — added explicit HTTPS-required-for-passkeys note in the Optional integrations table.
- `docs/oidc.md` — config example block now includes `auto_link`, `hide_emergency_link`, and `disable_emergency_bypass`; settings table adds `auto_link` and corrects `hide_emergency_link` / `disable_emergency_bypass` (database-backed since v2.7.0, not config.php-only); body sections updated to point at the Settings UI.
- `docs/api.md` — VLAN object table now lists the `site_name` field; device example `created_at` shows the actual `YYYY-MM-DD HH:MM:SS` format returned by the API.
- `docs/custom-fields.md` — removed the documented `?resource=custom_field_defs` API endpoints (never implemented in `api.php`); definitions are managed only through the admin UI.
- `docs/sidebar-and-command-palette.md` — sidebar tables refreshed to match the live nav (added Unassigned, Aggregates, PD Pools, Devices, Reports, ARP Import, Settings); command-palette Pages list updated to match.
- `docs/upgrading.md` — added the v3.15.1 body section that the new TOC entry points at.
- `docs/custom-fields.md` — fixed in-page TOC anchor for "Managing definitions" after the heading was renamed.
- `docs/oidc.md` — reconciled the v2.7.0 note with the rest of the page; the `config.php` fallback continues to work in current v3.x releases (the v3.0.0 fallback-removal plan was not executed).

---

## [3.15.0] - 2026-04-26

### Added
- **Passkeys / WebAuthn 2FA**: users can register FIDO2 passkeys (hardware security keys, platform authenticators like Touch ID/Face ID/Windows Hello, or password-manager passkeys) on the Account page. At login, the passkey challenge replaces TOTP and Email OTP for enrolled users. Passkeys are phishing-resistant and require no codes. (#687, #688, #689)
- `passkey_register.php`: AJAX endpoint for the WebAuthn registration ceremony (POST only, 405 on GET).
- `passkey_verify.php`: mid-login passkey challenge page; redirects to `login.php` without a pending session.
- `mfa.passkeys_enabled` admin setting to opt-in to passkeys per installation. Disabled by default.
- Admin can view passkey count and reset all passkeys for a user from `users.php`.
- `webauthn_credentials` schema migration stores registered public keys per user (multi-engine: SQLite BLOB, MySQL VARBINARY(255), PostgreSQL BYTEA).
- Composer dependency: `lbuchs/webauthn ^2.2.0` (MIT, zero transitive deps, pure PHP 8.0+).

### Changed
- `mfa.require` enforcement now accepts a registered passkey as a satisfying 2FA factor alongside TOTP and Email OTP.

### Testing
- Playwright `passkeys.spec.ts`: 8 tests using Chromium's CDP `WebAuthn.addVirtualAuthenticator` API — register, login, invalid assertion rejection, admin passkey count, user delete, admin reset, disabled notice, Add Passkey button, mid-login redirect guards. (#718)

---

## [3.14.0] - 2026-04-25

### Added
- **Email OTP 2FA** (`email_otp_verify.php`): users can enroll Email OTP as a second authentication factor on the Account page. A 6-digit code is sent via email at each login. Supports 10-minute expiry and a 5-attempt rate limit. Requires SMTP and an email address on the account. (#684)
- **MFA admin settings**: new `mfa.email_otp_enabled` and `mfa.require` settings in the admin Settings page. When `mfa.require` is enabled, users without any enrolled 2FA method are redirected to the Account page to enroll before accessing the application. (#685)
- Admin can reset a user's Email OTP enrollment from `users.php`. (#684)

### Fixed
- Password policy settings (`password_policy.*`) now read from the settings table via `ipam_setting()` in `change_password.php` and `reset_password.php`. Previously, admin changes to the Settings page had no effect on enforcement. (#686)

### Testing
- PHPUnit `PasswordPolicyTest` covering `validate_password_complexity()` across all policy combinations. (#716)
- PHPUnit `EmailOtpTest` covering OTP generation, hash storage, expiry, rate limiting, and cleanup. (#716)
- Playwright `password_policy.spec.ts` covering Settings admin controls and change-password enforcement. (#717)
- Playwright `email_otp.spec.ts` covering enrollment, challenge, wrong-code, and admin-reset flows. (#715)

---

## [3.13.0] - 2026-04-24

### Added
- Settings cascade: `ipam_setting()` now accepts optional `?int $tenantId` parameter with tenant→global→default resolution order, groundwork for v4.0.0 multi-tenancy (#711)
- `tenant_id` column added to `settings` table with `UNIQUE(tenant_id, key)` constraint across all three engines (SQLite, MySQL, PostgreSQL) (#711)
- PHPUnit `SettingsTest` covering all cascade levels (tenant shadow, global fallback, code default, null tenantId) (#713)
- Playwright `settings.spec.ts` covering admin CRUD, nav visibility, readonly 403 (#714)
- `reports.php`: server-side LIMIT/OFFSET pagination (100 rows/page) replacing the 2000-row hardcap (#734)
- `api_keys.php`: styled `.empty-state` card with CTA replaces bare "No API keys yet." text (#732)

### Fixed
- `ipam_setting_set()`: PostgreSQL `SQLSTATE[42P18]` (indeterminate datatype) when `$tenantId` is null — build WHERE clause as SQL literal instead of parameterized NULL
- Theme toggle button: SVG icon is now preserved after JS `updateThemeButton()` runs (was overwritten by `textContent` assignment to emoji) (#729)
- `addresses.php`: Subnet notes section heading no longer uses 📝 emoji (#730)
- `audit.php`: "Prune now" button no longer uses 🗑️ emoji — uses SVG trash icon (#731)
- `health.php`: "Refresh now" link (`<a class="button-secondary">`) now renders correctly as a styled button (#733)

## [3.12.0] - 2026-04-24

### Fixed
- Dashboard: raised `.grid.cols-2` minmax threshold from 420px to 520px, eliminating a 904–1023px viewport window where two narrow columns appeared without the sidebar (#677)
- Dashboard: added `overflow-x:auto` scroll wrappers to both widget tables as defense-in-depth against future overflow (#677)
- Webhooks: removed stale `<main id='main-content'>/<div class='container'>` wrappers (invalid nested `<main>` elements left from pre-v3.8.0 layout) and replaced ad-hoc inline `.row` header with standard `.toolbar` CSS class (#661)

### Added
- Dashboard: "Addresses by Site" widget now shows a contextual empty state with a "Manage Sites" CTA for admins when no site data exists (#660)
- Health: Scanning card now shows Warn alerts and Crit alerts counts from `alert_state`; a count of zero shows a green dot (#659)

## [3.11.0] - 2026-04-24

### Added
- Breadcrumb navigation added to `health.php`, `db_tools.php`, and `webhooks.php` for consistent admin orientation (#657)
- Collapsible expand/collapse for sites admin — parent regions collapse child sites with `sessionStorage` persistence, expand-all/collapse-all controls when 2+ groups (#632, #633)
- Structured empty state for the dashboard address growth chart: icon + message + "Go to Subnets" CTA replaces bare text (#644)
- `reports.spec.ts` Playwright spec — utilization history page functional coverage (#668)
- Extended `exports.spec.ts` — `export_search.php`, `export_utilization_history.php`, `export_unassigned.php` (with/without `subnet_id`), `export_import_report.php` coverage (#669)

### Changed
- All emoji icons in breadcrumbs and action pills replaced with SVG Heroicons, matching the sidebar icon convention established in v3.8.0 (#654)
- Action button styles standardised: `action-pill` applied consistently to all interactive links in page content areas; stray `btn`-only affordance on Account page fixed (#655, #656)

### Fixed
- Dark-mode sparklines in the dashboard subnet trend column are now visible; stroke colour resolved via CSS rule instead of SVG presentation attribute (#653)
- Sidebar navigation no longer renders on pre-auth pages (login, forgot-password, reset-password); the redundant Login link is gone (#658)

## [3.10.0] - 2026-04-23

### Fixed
- `IPAM_VERSION` constant now loaded at `lib.php` module scope, not lazily inside
  `page_footer()` — fixes HTTP 500 on webhook test_fire and "?" in health dashboard (#662, #646)
- CSP violation: inline `<script>` IIFEs moved from `webhooks.php` and `addresses.php`
  to `assets/app.js` — webhook drawer, gen_secret, test_fire, and device→interface
  cascade now function correctly (#645)
- Dashboard uPlot y-axis integer formatter — small-count ranges no longer show `0.000` (#647)
- Dashboard uPlot cursor artifact — cursor lines and points fade on mouse leave (#648)
- Dashboard metric cards always render in 3 columns at ≥640px viewport (#649)
- Dashboard widget CTA links pushed to card bottom in equal-height grid (#650)
- `action-pill` elements no longer overflow card boundaries at narrow viewports (#651)
- Password fields in `users.php` and `change_password.php` now carry correct
  `autocomplete` attributes for password manager compatibility (#652)

### Added
- PHPUnit `ConstantLoadOrderTest` — regression guard asserting `IPAM_VERSION` is
  defined at `lib.php` module scope (#666)
- Fleet guard specs (`pages.spec.ts`, `csp.spec.ts`, `console-clean.spec.ts`) now
  cover `webhooks.php`, `health.php`, `reports.php`, `devices.php`, `custom_fields.php`,
  `contacts.php`, `import_arp.php` (#664)
- New `webhooks.spec.ts` — CRUD, drawer open/close, test_fire regression guard (#665)
- New `health.spec.ts` — asserts IPAM version cell shows semver (#667)
- Semgrep rule `ipam-inline-script-csp` — detects inline `<script>` in PHP at push time (#673)
- Expanded `command-palette.spec.ts` — nav Enter key, New Subnet action, readonly filter (#672)
- Expanded `drawer.spec.ts` — focus-in, backdrop close, focus-return, address drawer (#674)
- Expanded `a11y-infrastructure.spec.ts` — focus rings, aria-label, form label assertions (#670)
- Expanded `css-regression.spec.ts` — theme persistence, metric-row 3-column guard (#671)

## [3.9.0] - 2026-04-23

### Added
- Subnet site filter strip on `subnets.php`: site pills with region→site hierarchy support; client-side filter with `sessionStorage` persistence (#629)
- Site→subnet cascading filter on `addresses.php`: Site dropdown narrows Subnet dropdown client-side; pre-selects site from `?subnet_id=` URL param (#630)
- Subnet description shown as subtitle in address page header when set (#631)
- Unified **Database** admin page (`db_tools.php`): merges Backup History (previously `backups.php`) with export/import; `backups.php` redirects to `db_tools.php` (#615)
- Playwright TOTP spec: enrollment, login, backup-code login, single-use invalidation, admin 2FA reset (#628)

### Fixed
- Admin TOTP 2FA reset (Reset 2FA button in users.php) confirmed implemented in v3.8.x; closed #624

## [3.8.1] - 2026-04-23

### Fixed
- Dashboard KPI cards now have proper spacing below action pills — added `margin-bottom` to `.page-actions` (#635)
- Dashboard two-column grid now collapses at the sidebar breakpoint (`minmax` raised from 320px to 420px) (#636)
- uPlot growth chart resizes correctly when the sidebar opens or closes (#636)
- Address growth chart shows a friendly empty state instead of a flat zero-line when no new addresses were created in the last 30 days (#637)

### Documentation
- Removed stale "(experimental)" labels for MySQL, MariaDB, and PostgreSQL — stable since v3.0.0 (#638)
- Removed stale Known Issues sections and outdated v2.x references from install.md (#638)
- Fixed "top navigation" references in install.md — updated to "sidebar" (v3.8.0 nav change) (#638)
- Added v3.8.0 and v3.8.1 entries to upgrading.md (#638)
- New user guide: Sidebar & Command Palette (`docs/sidebar-and-command-palette.md`) (#638)
- Updated feature index and documentation table in `docs/index.md` (#638)

## [3.8.0] - 2026-04-22

### Added
- SVG icon sprite system (Heroicons) — replaces all emoji icons (#511)
- Sidebar navigation (≥1024px Enterprise Gateway pattern, mobile hamburger) (#512)
- uPlot dashboard time-series and sparklines — vendored in v3.7.1, wired here (#514)
- KPI card grid on dashboard (Total Subnets, Addresses, % Used, Crit Alerts) (#514)
- Right-side drawer for all create/edit flows (subnets, addresses, users) (#517)
- Command palette ⌘K — navigate, create, toggle theme (#516)
- Table virtualization, sticky thead, bulk-select action bar (#515)
- `ipam_render()` view-helper layer and `views/` shared partials directory (#522)
- Fira Sans self-hosted body font — completes Fira type family (#518)
- Playwright spec files for sidebar, drawer, command palette, dashboard, tables (#527)
- Playwright performance assertions — LCP, chart render timing (#528)

### Changed
- `page_header()` nav: all emoji replaced with consistent SVG icons (#511)
- Dashboard rewritten: flat table replaced with KPI cards + uPlot growth chart (#514)
- Database Tools nav item hidden for non-SQLite engines (#614)
- Typographic scale updated to Fira Sans + refined sizes (#518)
- Micro-interaction transitions added to buttons, badges, row hovers (#519)

### Fixed
- Dark-mode WCAG AA contrast violations across all new overlay surfaces (#520)
- Responsive layout at 375/768/1024/1440 breakpoints (#521)

## [3.7.1] - 2026-04-22

### Fixed

- **#625 — TOTP login always fails.** Two `name="code"` inputs in `totp_verify.php`: the backup-code field was not `disabled` by default, so PHP read its empty value as `$_POST['code']` and discarded the authenticator code entirely. Fixed by adding `disabled` to the backup input by default; `app.js` now toggles `disabled` alongside `required` when switching modes.
- **#626 — TOTP enrollment redirects to "already enabled" after step 2.** The already-enrolled guard in `totp_enroll.php` ran before the step=3 handler. After step 2 sets `totp_enabled=1` and redirects to `?step=3`, the guard fired and swallowed the backup-codes page. Fixed by moving the step=3 check above the enrolled guard.
- **#617 — backups.php inline `<script>` blocked by CSP.** `page_header()` sets `script-src 'self'` (no `unsafe-inline`). The modal trigger functions and all `onclick=` attributes are now moved to `app.js` using `data-action` / `data-*` event delegation; `data-php-root` on a container element passes the PHP-generated path prefix. Focus management, Escape-key close, and backdrop-click close are preserved.
- **#621 — health.php shows blank DB version on MySQL/PostgreSQL.** The hardcoded `SELECT sqlite_version()` is replaced with a per-driver branch: `sqlite_version()` for SQLite, `VERSION()` for MySQL, `version()` for PostgreSQL.
- **#620 — health.php timestamps display raw UTC.** Last-backup and last-scan timestamps now pass through `ipam_format_datetime()` so they render in the user's configured timezone.
- **#619 — health.php layout: missing header gap and inconsistent card heights.** Added `margin-top:1.25rem` before `.health-grid` and `align-self:stretch` on `.health-grid > .card` so all cards fill their grid cell.
- **#622 — `app_secret` comment insufficient.** Improved comment in `config.php.example` explains that changing the value locks out all enrolled TOTP users until 2FA is reset, and that the key must never be empty on a production instance with 2FA enabled. Added the missing `app_secret` entry to the example template.
- **#623 — Broken TOC anchor links in install.md.** kramdown (GitHub Pages) collapses consecutive hyphens into one, so em-dash headings like "Step 1 — Download" generate single-hyphen IDs. All five TOC links and two inline cross-references updated from `--` to `-`.
- **#618 — Backup prerequisites undocumented.** Added a Prerequisites table to `docs/configuration.md` backup section listing that MySQL requires `mysqldump` and PostgreSQL requires `pg_dump` in `$PATH`. The Health Dashboard now detects a missing tool and shows a critical warning badge.

## [3.7.0] - 2026-04-22

### Added

- **#423 — Database backup infrastructure.** `backup.php` CLI script dumps the configured database to a timestamped file with SHA-256 integrity verification. Supports SQLite (file copy + WAL checkpoint), MySQL (`mysqldump`), and PostgreSQL (`pg_dump`). Results recorded in new `backup_history` table. Configurable via DB settings: `backup.enabled`, `backup.local_path` (default `data/backups/`), `backup.retention_count` (default 7), `backup.schedule_cron` (default `0 2 * * *`). `cron.php` runs backups on schedule. Web access to `backup.php` returns 403.
- **#424 — Restore workflow and backup admin UI.** `restore.php` CLI supports `--from=<file>`, `--dry-run`, and `--force` flags. Verifies SHA-256 against `backup_history` before restoring; runs `apply_migrations()` after restore. `backups.php` admin page lists `backup_history` rows with status badges, supports SHA-256 verification, file download, and delete with confirmation. Restore is intentionally CLI-only; the admin page shows the command to run. Both new CLI files blocked at web (`backup.php`, `restore.php` return 403). New `docs/backup.md` covers the full disaster recovery workflow.
- **#425 — Operational health dashboard.** `health.php` admin-only page showing real-time metrics across six sections: Database (size, row counts, driver/version), Backups (last run, next scheduled, retention, storage used), Scanning (active schedules, overdue count, last scan, stale addresses), Webhooks (active count, 24-hour delivery success rate, failed/pending, last error), Auth/Security (locked accounts, 2FA-enabled ratio, failed login attempts over 1 h / 24 h), and System (PHP version, IPAM version + update flag, disk free on `data/`, temp dir size). Color-coded status dots (green / amber / red). Results cached for 60 seconds in `data/tmp/health_cache.json`; bypass with `?nocache=1`. Admin dropdown link added.
- **#426 — Audit log retention controls.** Admin-configurable `audit.retention_days` setting (default 365; 0 = never prune) and `audit.prune_batch_size` (default 1000). `cron.php` batch-prunes `audit_log` rows respecting the retention window. Prune operations drop and re-create the append-only triggers for safety. `audit.php` now shows a retention info panel (oldest entry, total row count, current retention setting) and a CSRF-protected "Prune now" button. The `backup` action prefix is added to the audit viewer filter list.
- **#460 — CSV import Playwright spec and upgrade-replay PHPUnit suite.** `testing/playwright/tests/import-csv.spec.ts` covers the full CSV import wizard (upload → map → dry-run → apply), edge-case CSVs (BOM, CRLF, multiple MAC notations, quoted owner), malformed CSV friendly error, and read-only user 403. Fixture CSVs committed under `testing/playwright/fixtures/csv/`. `tests/UpgradeReplayTest.php` runs `apply_migrations()` against in-memory and file-based SQLite fixtures from old schema states and asserts no row loss, FK restoration, and idempotency; specifically guards the v2.2.1 data-loss regression.
- **#465 — `status.php` Playwright spec.** `testing/playwright/tests/status.spec.ts` verifies the load-balancer health endpoint returns HTTP 200 with `{"status":"ok"}` body, is accessible without authentication, and does not set a `PHPSESSID` cookie.

## [3.6.0] - 2026-04-21

### Added

- **#418 — TOTP Two-Factor Authentication.** RFC 6238 TOTP enrollment wizard (`totp_enroll.php`), mid-login challenge (`totp_verify.php`), and 2FA management on the Account page (`change_password.php`). Users scan a QR code in any authenticator app, verify a 6-digit code, and receive 8 single-use backup codes. Admins can reset another user's 2FA from the Users admin page. TOTP secrets are encrypted at rest with AES-256-CBC using the new `app_secret` config key. Uses `robthree/twofactorauth ^2.1` (MIT, zero transitive deps); QR rendering via vendored `qrcode.min.js` (~20 KB, client-side only).
- **#419 — Per-API-key rate limiting.** Sliding-window rate limit per API key on top of the existing IP-based limit. Returns HTTP 429 with `Retry-After` header when the window is exceeded. Configurable via `api.rate_limit_window_seconds` (default 60) and `api.rate_limit_requests` (default 300) DB settings. Backed by new `rate_limit_buckets` table.
- **#420 — Session absolute lifetime.** Every session is bounded by an absolute expiry (`$_SESSION['_abs_expires']`) enforced in `init.php`. Configurable via `$config['session']['absolute_lifetime_minutes']` (default 480 = 8 hours; 0 = disabled). Session ID is rotated on password change.
- **#421 — Persistent account lockout for 2FA failures.** After `auth.lockout_after_failures` (default 10) consecutive 2FA failures the account is locked until `auth.lockout_duration_minutes` (default 30) elapses or an admin manually unlocks it. Adds `failed_auth_count`, `locked_until`, and `lock_reason` columns to `users`. Admin unlock clears both the time-windowed login lockout and the new persistent lockout in one action. The Users admin table shows a "Locked (2FA)" badge for 2FA-triggered lockouts.
- **#422 — `docs/security.md` security reference.** Comprehensive guide covering threat model, TOTP walkthrough, session hardening, account lockout types, API rate limiting, CSRF, output encoding, SQL injection prevention, and a full configuration reference table.
- **#459 — Playwright reverse-proxy spec skeleton.** `testing/playwright/tests/reverse-proxy.spec.ts` auto-skips unless `IPAM_PROXY_MODE=1`.
- **#467 — PHPUnit tests.** `testTotpSecretRoundTrip`, `testTotpBackupCodeFormat`, `testRateLimitWindowBoundary`, `testUpdateCheckEscapesVersionString` in `tests/UtilTest.php`; `testV360MigrationsApply` in `tests/MigrationTest.php`.

## [3.5.0] - 2026-04-21

### Added

- **#313 — Custom Fields.** Admin-defined per-entity metadata on subnets and addresses, stored as JSON in a new `custom_fields` TEXT column. No schema changes required for end users; the `3.5.0-custom-fields` migration adds the column idempotently on SQLite, MySQL/MariaDB, and PostgreSQL.
  - **`custom_fields.php`** — New admin page (Admin → Custom Fields). Define fields with a key (slug), display label, type (`text`, `number`, `date`, `boolean`, `select`), and optional select options. Fields are scoped to `subnet` or `address` entities. Duplicate keys are rejected; delete is blocked if any values are stored.
  - **Subnet and address edit forms** — Custom field inputs render below the core fields. Required fields are marked; type-mismatched values are rejected with a clear error both client- and server-side.
  - **REST API** — `fmt_subnet()` and `fmt_address()` now include a `custom_fields` object. PUT/POST handlers accept and strictly validate `custom_fields` payloads; unknown keys or type mismatches return HTTP 422.
  - **CSV export/import** — `export_addresses.php` adds a `custom_fields` JSON column. `import_csv.php` accepts and validates the column on import; malformed rows are reported without aborting the batch.
  - **Documentation** — New [`docs/custom-fields.md`](docs/custom-fields.md) covering admin workflow, API shape, CSV format, strict-type behaviour, and upgrade path. OpenAPI spec updated.
- **#461 — Search filter matrix.** `search.spec.ts` expanded from 6 to 28 parameterized test cases covering status × IP version combos, site/tag filter, free-text query fields (IP, hostname, owner, notes, group, MAC), special-character LIKE-escape regression, and CSV export validation.

### Fixed

- **#594 — action-pill button text invisible.** `.action-pill` elements were inheriting `--btnfg` instead of the neutral `--fg` token, making button text unreadable in both light and dark themes. Fixed by adding `color: var(--fg)` to the `.action-pill` rule in `app.css`.

## [3.4.1] - 2026-04-19

### Fixed

- **Migration `3.4.0-dhcp-options`** — replaced `SHOW COLUMNS FROM subnets LIKE :col` with an `information_schema.columns` query for MySQL/MariaDB idempotency check. `SHOW` statements cannot be used as native prepared statements on some MariaDB versions, causing a migration failure (`SQLSTATE[42000]: near '?'`). The `information_schema` approach is portable across all MySQL 5.7+/MariaDB 10.x/MySQL 8.x versions.

## [3.4.0] - 2026-04-18

### Added

- **#338 / #402–#405** — DHCP config export. Generate server-ready DHCP configuration files directly from subnet options and address reservations.
  - New schema columns on `subnets`: `dhcp_routers`, `dhcp_dns_servers`, `dhcp_domain_name`, `dhcp_lease_default`, `dhcp_lease_max`, `dhcp_next_server`, `dhcp_boot_filename`. All nullable; migration key `3.4.0-dhcp-options` is idempotent across SQLite, MySQL, and PostgreSQL.
  - **ISC DHCP** (`dhcpd.conf`) format: subnet blocks with `option routers`, `option domain-name-servers`, `option domain-name`, `default-lease-time`, `max-lease-time`, `next-server`, `filename`, and per-address `host` entries (`hardware ethernet` / `fixed-address`) for all `status=reserved` addresses with a non-empty MAC.
  - **ISC Kea 2.x JSON** format: `Dhcp4.subnet4` array with `option-data`, `valid-lifetime`, `max-valid-lifetime`, and `reservations` per subnet.
  - **DHCP Options** collapsible section in the subnet edit drawer. Fields auto-expand when any value is set. IP fields (routers, DNS, next-server) are validated server-side.
  - **Export card** on the DHCP Pools page with IPv4 subnet picker (checkboxes, all selected by default), "Download dhcpd.conf", "Download Kea JSON", and "Preview" buttons. Preview fetches inline without triggering a download.
  - New endpoint `export_dhcp.php` (write-role required): supports `?format=dhcpd|kea`, `?subnets=N,N,N`, `?subnet_id=N`, and `?preview=1`. Audited as `export.dhcp` / `export.kea`.
  - IPv4 only — IPv6 subnets are silently skipped.
  - New unit tests in `tests/DhcpRenderTest.php` (20 assertions). New E2E spec `testing/playwright/tests/dhcp-export.spec.ts`.
  - New documentation at [`docs/dhcp-export.md`](docs/dhcp-export.md).

## [3.3.0] - 2026-04-18

### Added

- **#337 / #398–#401** — Outbound webhooks. Configurable HTTP callbacks fired on address and subnet mutations (`address.create`, `address.update`, `address.delete`, `subnet.create`, `subnet.update`, `subnet.delete`). Payloads are HMAC-SHA256 signed (`X-IPAM-Signature: sha256=…`). Admin UI (`webhooks.php`) supports create, edit, delete, enable/disable, test-fire with inline result panel, and per-webhook delivery log (last 50 deliveries with status badges). Failed deliveries are retried by the cron runner (up to 3 attempts, ~1 min / ~5 min backoff). Delivery log retention configurable via `webhook.retention_days` (default 30 days). SSRF protection blocks private/loopback/link-local targets; `webhook.allow_private_ips` config overrides for lab environments.
- **#544** — Login history. The Account page (`change_password.php`) now shows a "Recent Login Activity" table (last 20 logins/OIDC logins). Admins can view any user's activity via `?user_id=N`. Users table gains a "Login history" link per user row that opens `audit.php` filtered to that user's login events.
- **#578** — Brand polish. Navigation and footer now render a `SimplePHPIPAM` text logo in Fira Code / JetBrains Mono monospace, with "PHP" highlighted in brand green (`--brand`). Dark-mode primary button and success colors aligned to the marketing-site brand green (`#22C55E`). `--brand` CSS custom property added for use across themes.

### Changed

- `audit.php` now accepts `?user_id=N` and `?action=auth.login` query parameters, enabling direct deep-links from the users table and Account page. Filters compose with existing prefix/date-range filters.

## [3.2.0] - 2026-04-18

### Added

- **#394** — Devices schema. New `devices` table (name, type, site FK, vendor, model, serial, note) and `device_interfaces` table (device FK, name, description). New nullable `device_id` and `interface_id` FK columns on `addresses` (SET NULL on device delete). Migration key `3.2.0-devices`.
- **#395** — Devices admin CRUD UI (`devices.php`). Admin-only page with filter bar (type, site, free-text), device table with type badges, inline expand for edit/delete, nested interface sub-table with add/delete per device. Linked from the ⚙ Admin dropdown.
- **#396** — Devices REST API resources. `?resource=devices` (list with `?type=`, `?site_id=`, `?search=`, pagination; single with `?id=`; POST/PUT/DELETE) and `?resource=device_interfaces` (list by `?device_id=`; single `?id=`; POST/PUT/DELETE).
- **#397** — Device search in global search. `search.php` now includes matching devices and interfaces in a dedicated "Matching Devices" result card. CSV import (`import_csv.php`) gains optional `device_name` and `interface_name` columns; on import, devices and interfaces are looked up by name (created if missing) and linked to the imported address rows.
- **#541** — Email-based password recovery. New `forgot_password.php` and `reset_password.php`. Tokens are single-use (stored as SHA-256 hash), expire in 1 hour, rate-limited to 3 requests per user per hour. Always returns the same UI response regardless of whether the username/email exists (prevents user enumeration). Login page gains a "Forgot password?" link. New migration key `3.2.0-password-reset` adds `password_reset_tokens` table and pending-email columns to `users`.
- **#542** — Email change verification. Users can update their email address on the Change Password page; a verification link is sent to the new address. The change is applied only after clicking the link. Pending state (with expiry display) shown while verification is outstanding.
- **#318** — Subnet utilization CSV export trend delta. `export_subnet_utilization.php` accepts `?include_trend=1&trend_days=N` (1–365, default 30). Appends four columns: `used_Nd_ago`, `free_Nd_ago`, `delta_used`, `delta_pct`, sourced from a batch-loaded snapshot at the cutoff date.
- **#427** — API versioning header. Every `api.php` response now includes `X-IPAM-API-Version: 1`. Current stable API = v1; breaking changes will increment to v2.
- **#312** — OpenAPI 3.1 spec. Full machine-readable spec served at `?resource=spec` as `Content-Type: application/yaml` (no auth required). Covers all v3.2.0 resources including devices, device interfaces, and the spec endpoint itself. Swagger UI compatible. New file `docs/api-spec.yaml`.

### Fixed

- **#574** — Utilization bars not rendering on subnet list page. CSS specificity fix for `.util-bar-fill[data-pct]` rules.

## [3.1.0] - 2026-04-17

### Added

- **#571** — Contact popover × close button. Dismisses the popover card without requiring a click-outside; existing Escape and scroll-dismiss behaviour unchanged.
- **#415** — PHPMailer direct SMTP support. New `smtp.*` settings group (`smtp.enabled`, host, port, encryption, auth, from, verify_peer, timeout). New `ipam_send_mail()` function replaces native `mail()` when enabled. Admin test endpoint `smtp_test.php` sends a validation email and returns JSON result. Fallback to native `mail()` when SMTP disabled.
- **#457** — Per-subnet `alerts_enabled` toggle. New `alerts_enabled INTEGER NOT NULL DEFAULT 1` column on `subnets`. Checkbox in the subnet edit drawer; disabling clears existing `alert_state` rows. Bell-off SVG badge in the subnet list. Bulk "Enable all / Disable all alerts" pills (admin, respects site filter). `alerts_enabled` exposed in GET/POST/PUT API and CSV export.
- **#458** — MailHog end-to-end test harness (`IPAM_TEST_MAILHOG=1`). `bootstrap-app.sh` / `teardown-app.sh` start and stop a `mailhog/mailhog` container on the Docker bridge network. New `alerts-smtp.spec.ts` Playwright spec: alert fires → MailHog receives it, 24 h cooldown dedup, `alerts_enabled=0` silences subnet. New `alerts-smtp` CI job.
- **#414** — Per-user timezone preference. New nullable `timezone TEXT` column on `users`. Timezone select on the Change Password page grouped by region. Timestamps display in the resolved user timezone (`users.timezone` → `branding.timezone` setting → PHP default → UTC). New `ipam_user_timezone()` and `ipam_format_datetime()` helpers; `display_datetime()` delegates to the new function.
- **#315** — Expiring Addresses dashboard widget. Three KPI tiles (Expired, ≤7 days, ≤30 days), each linking to `addresses.php?filter=expired` or `?filter=expiring&days=N`. New bulk expiry actions in Bulk Update: extend by 30 / 60 / 90 days and clear expiry. API `GET ?resource=addresses` gains `?expired=1` and `?expiring_days=N` filters. CSS `.expired-row` highlight.
- **#311** — Utilization snapshot history + sparklines. New `utilization_snapshots` table captures per-subnet used/free/total on every housekeeping run. Retention pruning via `housekeeping.snapshot_retention_days` (default 365 days). Dashboard top-subnets table gains a Trend column with inline SVG sparklines. New `reports.php` (admin): full history table with date range and per-subnet filter. New `export_utilization_history.php`: CSV with `?subnet_id=N` and `?days=N` params. API `GET ?resource=utilization_snapshots&subnet_id=N&days=N`.

## [3.0.1] - 2026-04-17

### Fixed
- **Config stub migration writes `__DIR__`-relative db_path** — the v3.0.0 migration resolved `__DIR__ . '/data/ipam.sqlite'` to an absolute path at migration time. On Docker or container setups where the host and container filesystem paths differ, this broke the app after upgrade. The migration now detects paths relative to config.php's directory and preserves the `__DIR__` prefix.

## [3.0.0] - 2026-04-17

**Breaking release.** Multi-engine database support promoted to stable, `config.php` reduced to a bootstrap stub, deprecated API auth method removed.

### Breaking

- **#341** — `config.php` reduced to a bootstrap stub containing only `db_driver`, `db_dsn`, `db_user`, `db_pass`, `session_name`, and `force_https`. All other settings live in the `settings` database table and are managed through Admin → Settings. An upgrade-time migration automatically imports customised values and rewrites the file. A `.bak-v3upgrade` backup is created.
- **#340** — Removed `?api_key=` query-parameter authentication from `api.php`. Use the `Authorization: Bearer <key>` header instead. Deprecation headers shipped since v2.x.

### Added

- **#390** — `migrate_db.php` bidirectional CLI migration tool. Supports all 6 direction pairs between SQLite, MySQL 8.0+, and PostgreSQL 14+. Batched copies, binary blob round-trip via `PARAM_LOB`, row count verification, `--dry-run` mode.
- **#563** — Multi-contact assignments on sites and subnets. New `site_contacts` and `subnet_contacts` join tables with optional role labels. Contact picker UI on sites page, contact badges in site/subnet tables.
- **#391** — Integration test harness for `migrate_db.php` round-trips (`testing/scripts/test_migrate_db.sh`) and `tests/tools/db_diff.php` for comparing databases.
- **#393** — `docs/upgrading.md` gains a dedicated "Upgrading to 3.0" section with pre-upgrade checklist, two upgrade paths (stay SQLite vs migrate to MySQL/Postgres), post-upgrade verification, and rollback instructions.
- `config.php.example` — new stub-format template with SQLite, MySQL, and PostgreSQL example stanzas.
- 30+ new settings registered in `ipam_setting_definitions()`: housekeeping, backup, limits, API, password policy, display, and account lockout groups.

### Changed

- **#392** — MySQL 8.0+ and PostgreSQL 14+ drivers promoted from experimental to stable. Beta banners removed from the admin UI.
- **#393** — `upgrade.sh` now offers optional driver migration after schema migrations complete. Prompts for target engine, DSN, and credentials when run interactively.
- `docs/configuration.md` updated to reflect the v3.0.0 two-tier precedence chain (DB → registry default, no config.php fallback).
- `ipam_validate_config()` rewritten to use `ipam_setting()` instead of direct `$config` reads.
- `settings.php` deprecation banner and import handler removed (superseded by the migration).
- Settings source badge simplified to 🟢 Database or ⚪ Default (no more 🟡 config.php).

### Removed

- `ipam_config_sync()` — config.php auto-population on boot (superseded by settings table).
- `ipam_setting_deprecated_keys()` — config.php deprecation scanner (no longer needed).
- `?api_key=` query-parameter API authentication.
- MySQL/PostgreSQL experimental driver banners.

## [2.14.0] - 2026-04-17

UX polish, performance, and bug fix release. Contact interaction improvements on the addresses page, subnets page performance overhaul, and a utilization calculation fix.

### Added
- **#564** — Expandable audit log details. Click or keyboard-activate truncated audit detail cells to toggle full text inline.
- **#561** — Contact details popover on address rows. Click a linked contact name in the Owner column to view email, phone, org, and note in a floating card without leaving the page.
- **#562** — Contact picker browse button. "Browse" button next to the Owner field opens a searchable overlay of all contacts for easy selection.
- **#565** — Async subnet stats loading. Subnet tree renders immediately with skeleton placeholders; counts and utilization bars populate via a new `subnet_stats` session-auth API endpoint. Page size reduced ~90% on large deployments by moving edit forms to a shared drawer (#567).
- **#567** — Shared subnet edit drawer. VLAN/VRF/site `<select>` dropdowns rendered once instead of per-node, cutting page size from 6.5MB to ~780KB on 500-subnet deployments.

### Fixed
- **#566** — Dashboard "Top Subnets" widget and utilization alerts counted infrastructure IPs (network, broadcast, gateway) against assignable capacity, producing impossible >100% fill values on small subnets. Both now use the shared `ipv4_unassigned_summary()` function that excludes infrastructure addresses.
- Session-auth in `api.php` now correctly derives session name, cookie path, and save path to match `init.php`, fixing session-auth failures on installs not at the web root.
- Column visibility gear dropdown no longer clipped by `overflow:auto` on the table wrapper.
- Subnet edit drawer no longer clears `site_id` on save (duplicate form field bug).

### Changed
- Subnet tree helper functions (`build_subnet_tree`, `subnet_direct_counts`, `subnet_aggregated_counts`, `ipv4_unassigned_summary`, `ipv4_unassigned_aggregated`, `ipv4_broadcast_bin`) moved from `subnets.php` to `lib.php` for reuse by dashboard, API, and alert system.
- Subnets page cards wrap each subnet node with indented hierarchy (28px per depth level, site group indent).

## [2.13.0] - 2026-04-16

UI/UX accessibility and design token foundation release. Theme: *"Fix what's broken, don't touch the vibe."* Non-visual rework laying the groundwork for the v3.8.0 visual overhaul.

### Added
- **#506** — Design token scales (`--space-*`, `--z-*`, `--radius-*`, `--font-size-*`, `--focus-ring`) with [Open Props](https://open-props.style) (~29KB, MIT) vendored under `assets/vendor/`. 210 hardcoded CSS literals replaced with semantic tokens — zero visual delta.
- **#504** — `:focus-visible` rings on all interactive elements using `--focus-ring` token (double-ring shadow). ARIA landmarks (`<header>`, `<nav>`, `<main>`, `<footer>`) in `page_header()`/`page_footer()`. Skip-to-content link. `lang="en"` on `<html>`.
- **#505** — `prefers-reduced-motion` respect. Global `@media` block suppresses all CSS animations/transitions. JS `scrollIntoView()` uses `behavior:'auto'` under reduced motion.
- **#507** — Loading states on every POST form. Delegated submit handler disables button + shows CSS spinner (static "Working…" under reduced motion). Preserves multi-button `name`/`value` via hidden input injection.
- **#508** — Skeleton loaders on subnets, addresses, and unassigned pages via early output flush. CSS-only shimmer animation, removed by `app.js` (CSP-safe).
- **#509** — Monospace font for IP/CIDR/MAC columns. Self-hosted Fira Code woff2 subset (~6.7KB, OFL-1.1) with `--font-mono` token and `.ip-cell` utility class applied to 6 pages.
- **#525** — Playwright a11y test infrastructure: `@axe-core/playwright`, keyboard-walk helper, focus-ring detection, form loading-state assertions.
- **#526** — Playwright visual regression baseline: 40 screenshots (5 pages × 2 themes × 4 viewports) for zero-delta verification.
- **#546** — Gateway and broadcast address badges on the addresses page. New `ipam_compute_gateway_bin()` helper with 5 unit tests.

### Fixed
- **#510** — Light-mode contrast: `--muted` darkened from `#667085` to `#586174` (4.8:1 → 6.2:1 on `--bg`, now comfortably above WCAG AA 4.5:1).
- **#547** — Subnet utilization map view no longer counts `status='free'` addresses in the denominator. Capacity is now computed from prefix length.

### Changed
- **CI** — Playwright suite restricted to `--project=chromium` (excludes local-only visual regression projects). `.htaccess` job skips system font install for ~30s speedup.

## [2.12.0] - 2026-04-16

Post-multi-engine cleanup and hardening release. Security improvements, performance optimization, first-class MariaDB support, and test infrastructure upgrades.

### Added
- **#534** — First-class MariaDB 10.11+ support. The `ipam_db()` version gate now detects MariaDB (stripping the historical `5.5.5-` prefix), enforces a 10.11.0 floor, and reuses the `mysql` PDO driver. CI gains `mariadb:10.11` in both the PHP QA matrix (`php-qa.yml`) and the nightly Playwright matrix (`playwright-nightly.yml`). `bootstrap-app.sh` and `teardown-app.sh` learn a `mariadb` driver that spins a `mariadb:10.11` service container. New `test-config-mariadb.php` fixture and `docs/install.md` MariaDB section.
- **#543** — Per-account login lockout. Failed login attempts now track the submitted `username` in `login_attempts` (new column + index via `2.12.0-account-lockout` migration). After `account_lockout_max_attempts` failures (default 10) within `account_lockout_seconds` (default 900), the account is locked regardless of source IP. `users.php` shows a "Locked" badge and an "Unlock" button that clears the lockout and audit-logs `user.unlock`. New config keys: `account_lockout_max_attempts`, `account_lockout_seconds`.
- **#540** — Emergency `recovery_mode` config key. When `'recovery_mode' => true` in `config.php`: the local login form is always shown (overrides `disable_local_login`), CAPTCHA is disabled, IP and account rate limiting are bypassed, and submitting the `bootstrap_admin` credentials resets the admin password (or recreates the admin if deleted). A red sticky banner warns on every page. Audit-logged as `auth.recovery_login` / `auth.recovery_reset` / `auth.recovery_provision`.
- **#413** — `testing/scripts/deploy_staging.sh` helper for ad-hoc staging deploys to dev-direct. CLI: `--name=<slug> [--driver=sqlite|mysql|pgsql] [--fresh] [--yes]`. Validates slug, refuses `--name=ipam`, rsync → config template → chown → migrate. Config templates in `testing/samples/staging-configs/`.
- **#466** — `tests/ConcurrencyTest.php` — write-race concurrency PHPUnit class. Four test methods exercise SQLite WAL mode with dual PDO connections: UNIQUE violation on concurrent insert, scan lock vs short save, snapshot isolation on uncommitted writes, and last-writer-wins semantics.
- **#463** — `testing/playwright/tests/unicode.spec.ts` — 30 parameterized Playwright round-trip tests. Six Unicode fixtures (Cyrillic, CJK, Arabic RTL, emoji, combining diacriticals, ZWJ sequences) × five entity types (subnet description, address hostname, contact name, tag name, search). Catches `utf8` vs `utf8mb4` collation bugs on MySQL/MariaDB.

### Changed
- **#530** — `ipv4_unassigned_summary_local()` N+1 query optimization. The per-subnet exclusion query (network/broadcast address deduction) is replaced with a single batched query chunked at 200 subnets per round-trip. Targets ≥60% TTFB reduction on 500-subnet deployments.

## [2.11.0] - 2026-04-15

Experimental PostgreSQL 14+ driver on top of the v2.9.0 Dialect foundation and the v2.10.0 MySQL work. Opt-in via `db_driver = 'pgsql'`; SQLite remains the default and is unchanged. Postgres is the third engine to ship against the shared Dialect contract — the path is beta-quality and labelled as such in the install docs and with a dismissible admin banner on every page when active. By the time v2.11.0 ships, all three drivers (SQLite, MySQL, PostgreSQL) run the same per-commit CI matrix and nightly containerized Playwright suite so cross-engine regressions are caught within hours of landing.

### Added
- **#386** — `PgsqlDialect` implementation of every `Dialect` method. `now()` → `(NOW() AT TIME ZONE 'utc')`; `upsert()` → `ON CONFLICT (col, ...) DO UPDATE SET col = EXCLUDED.col`; `autoincrement()` → `BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY`; `binary_type(16)` → `BYTEA`; `case_sensitive_collation()` → `null` (handled at the schema level via per-column `COLLATE "C"`); `append_only_trigger()` → one PL/pgSQL function plus two `CREATE OR REPLACE TRIGGER` statements using `current_setting('ipam.bypass_append_only', true) IS DISTINCT FROM '1'` as the housekeeping bypass and `TG_OP`-gated `RETURN OLD` / `RETURN NEW` so the bypass path actually proceeds (Postgres BEFORE triggers that return NULL silently suppress the row — surfaced by `PgsqlSmokeTest` during v2.11.0 smoke testing and locked in as a regression guard in `PgsqlDialectTest`); `pragma_foreign_keys()` → `null` (Postgres has no per-connection FK toggle); `null_safe_eq()` → `IS NOT DISTINCT FROM`. **Effective minimum Postgres version: 14** because `CREATE OR REPLACE TRIGGER` landed in PG14 and removes the drop-and-recreate race window from the self-heal path. 21 new string-output tests in `tests/PgsqlDialectTest.php`.
- **#386** — `Simple-PHP-IPAM/PgsqlStatement.php`, a `PDOStatement` subclass installed via `PDO::ATTR_STATEMENT_CLASS` on the pgsql connection. pdo_pgsql returns BYTEA columns as PHP stream resources instead of byte strings, regardless of how they were bound at INSERT. Without an auto-unwrap, every one of the ~80 existing `ip_bin` / `network_bin` fetch sites across `lib.php` and the page handlers would either break outright (`strlen` on a resource returns "Resource id #NNN" length) or silently corrupt data. The subclass overrides `fetch()`, `fetchAll()`, and `fetchColumn()` to walk each fetched row and `stream_get_contents()` any resource values. Wiring this once at the PDO layer keeps every existing call site unchanged. Discovered by `PgsqlSmokeTest`'s four binary IP round-trip vectors failing with "Resource id #533" on the fetched column.
- **#387** — `Simple-PHP-IPAM/schema.pgsql.sql`: authoritative 22-table PostgreSQL schema mirroring the fully-migrated state of `schema.sql` and `schema.mysql.sql`. Uses `BIGINT GENERATED BY DEFAULT AS IDENTITY` for surrogate PKs, `BYTEA` for binary IP columns, `TIMESTAMP` with `DEFAULT (NOW() AT TIME ZONE 'utc')`, per-column `COLLATE "C"` on byte-comparable text columns (usernames, CIDRs, IPs, hashes, settings keys) so exact equality and uniqueness are deterministic regardless of cluster `LC_COLLATE`, a single shared `set_updated_at_utc()` PL/pgSQL function driving per-table `BEFORE UPDATE` triggers, a per-table append-only PL/pgSQL function with `SET LOCAL ipam.bypass_append_only = '1'` as the housekeeping escape hatch, and a pre-seed of all 28 historical migration version rows via `ON CONFLICT (version) DO NOTHING`. End-to-end validated against `postgres:14-alpine` during development (bootstrap + idempotent re-run, binary IP round-trip on the three #410 vectors, append-only trigger enforcement including the housekeeping bypass, byte-wise `ORDER BY ip_bin`).
- **#387** — `tests/PgsqlSmokeTest.php` — 12 end-to-end integration tests gated on `IPAM_PGSQL_DSN`. Covers dialect pinning, the PG14 version floor, the `schema_migrations` pre-seed count, bootstrap admin insertion, **four binary IP round-trip vectors** (`10.0.0.0`, `255.255.255.255`, `2001:db8::`, `ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff`) for both `subnets.network_bin` and `addresses.ip_bin`, audit_log UPDATE/DELETE blocking, the `SET LOCAL ipam.bypass_append_only = '1'` housekeeping path (asserts `rowCount == 1` so a silent suppress would fail loudly), and byte-wise `ORDER BY ip_bin` with the mixed-length text-sort proof case (`10.0.0.1 < 10.0.0.100 < 10.0.0.254`).
- **#409** — `tests/SchemaParityTest.php`: cross-engine schema parity protection that boots each engine (SQLite `:memory:` always; MySQL and Postgres gated on `IPAM_MYSQL_DSN` / `IPAM_PGSQL_DSN`), applies the matching `schema.*.sql`, reads structural metadata back through `PRAGMA` / `information_schema`, normalises to a canonical per-table shape (type class, nullability, PK, FK targets + on-delete, UNIQUE constraints), and asserts all three shapes converge. Type normalisation folds `BLOB` / `VARBINARY(N)` / `BYTEA` → `"binary"` etc so semantic equivalents compare equal. Driver-branched FK introspection (MySQL's `referenced_table_name` extension vs Postgres's standards-compliant `constraint_column_usage` join). Handles the SQLite `INTEGER PRIMARY KEY AUTOINCREMENT` nullability quirk (reported as nullable by `PRAGMA table_info` despite never being NULL-able at storage). **Immediately caught three real drift bugs** in `schema.sql` that had been shipping since v2.4.0 — see the Fixed section.
- **#409** — `tests/MigrationTest::testAllMigrationsAreIdempotentOnFreshSchema()`: SQLite-scoped replay idempotency test. Loads `schema.sql` into an empty in-memory DB, stamps every migration as applied, clears `schema_migrations`, calls `apply_migrations()` a second time, and asserts the canonical column shape is unchanged and every migration re-stamped itself. Catches the bug class where a new migration forgets its `PRAGMA table_info()` / `sqlite_master` existence guard and is therefore unsafe to re-run against a DB that already has the target schema shape.
- **#388** — Per-commit CI matrix slot running the full PHP QA suite (lint, PHPStan, PHPCS, PHPUnit, Semgrep) and `test_api.sh` against a `postgres:14-alpine` service container. Matrix is `fail-fast: false` across `[sqlite, mysql, pgsql]` so a regression on any one engine never masks another's failure. `Dockerfile.apache` picks up `libpq-dev` and `pdo_pgsql` so the containerized Playwright harness can also talk to Postgres. `testing/playwright/bootstrap-app.sh` and `teardown-app.sh` learn a `pgsql` branch that spins/destroys a `postgres:14-alpine` service container on the same docker network the long-running Apache container joins. `testing/playwright/fixtures/test-config-pgsql.php` pins `db_driver=pgsql` with a DSN targeting the service container.
- **#388 — nightly Playwright Postgres matrix slot.** `playwright-nightly.yml` gains `pgsql` in the driver matrix so the full ~330-test end-to-end suite runs against real Postgres 14 on every nightly run. All three drivers (SQLite, MySQL, Postgres) now run the same suite against real service containers every night.
- **#388 — engine-agnostic API-key creation in `testing/scripts/test_api.sh`.** When `DOCKER_CONTAINER` is set, the script now routes the `api_keys` DDL/DML through the app's own `ipam_db($config)` inside the container instead of hardcoding a raw SQLite PDO connection. Works against sqlite, mysql, and pgsql containerized harnesses with no caller changes.
- **#389** — "PostgreSQL (experimental)" section in `docs/install.md` with version requirements, collation guidance, grant list, two-phase privilege tightening (CREATE-then-revoke), connection example, and a detailed known-issues list covering BYTEA stream behaviour, `PARAM_LOB` binding, IDENTITY sequence semantics, SQLite-format-only backups, the absence of a per-connection FK toggle, and the 7-nightly-green-run release gate. Extended the dismissible admin UI beta banner in `page_header()` to also render when `db_driver=pgsql` is active — the key is suffixed with the engine name and `IPAM_VERSION` so dismiss state is per engine + per version and upgrading either resurfaces the banner.

### Fixed
- **#409 (latent v2.4.0+ bugs caught by SchemaParityTest)** — `Simple-PHP-IPAM/schema.sql` was missing four tables entirely (`vlan_ranges`, `aggregates`, `pd_pools`, `pd_delegations`) that MySQL and Postgres installs have. These tables were only ever created by v2.4.0 migration closures, but because `ipam_db_init()` stamps every migration as applied on fresh install, a brand-new SQLite DB never ran them. Any user hitting `vlans.php#ranges`, `aggregates.php`, `pd_pools.php`, or the PD delegations flow on a fresh SQLite install would have triggered a 500 until they added rows via SQL surgery. Backfilled into `schema.sql` with column definitions copied byte-for-byte from `migrations.php`. Same cause and fix for the four missing `vrfs` BGP attribute columns (`asn`, `rt_import`, `rt_export`, `enforce_unique`) added by the `2.4.0-vrf-bgp` migration. Same cause and fix for the missing `subnets.site_id → sites.id ON DELETE SET NULL` FK: `schema.sql` declared `site_id INTEGER` with no `REFERENCES` clause while both other engines enforced it.
- **#388 (eight binary-bind bypass sites)** — `addresses.php`, `aggregates.php`, `api.php` (×4 including the dup-check SELECTs), `import_csv.php`, `subnets.php` (×2: create and update), and `unassigned.php` were all passing `ip_bin` / `network_bin` through positional `execute([':bin' => ...])`, which PDO binds as `PARAM_STR`. On SQLite and MySQL this mostly round-trips because neither engine strictly enforces UTF-8 on binary columns. On Postgres `BYTEA` it fails hard with `SQLSTATE[22021]: invalid byte sequence for encoding "UTF8"` the moment any non-ASCII byte lands in the column. Refactored all eight sites to `bindValue()` + `ipam_bind_binary()` so `PARAM_LOB` binding is used uniformly, matching the v2.9.0 #410 chokepoint contract. The dup-check `SELECT ... WHERE ip_bin = :b` queries needed the same binding because Postgres compares the WHERE bytes the same way.
- **#388 (Postgres IDENTITY sequence not advanced after demo seed)** — `demo_seed_data()` INSERTs fixtures with explicit `id` values across sites, vrfs, vlans, contacts, tags, subnets, addresses, and others. SQLite auto-advances ROWID; MySQL resets `AUTO_INCREMENT = 1` via the pre-seed driver branch; Postgres's `GENERATED BY DEFAULT AS IDENTITY` accepts explicit ids but does **not** advance the backing sequence, so the next implicit insert picked id=1 and collided with `duplicate key value violates unique constraint "*_pkey"`. Added a Postgres-only post-seed loop that calls `setval(pg_get_serial_sequence('t', 'id'), MAX(id))` for every seed table.
- **#388 (`SUM(boolean)` not cross-engine)** — `api.php`'s subnet `?counts=1` path and `export_subnet_utilization.php` used `SUM(status = 'used')`, which treats booleans as 0/1 on SQLite and MySQL. Postgres returns a real boolean that `SUM()` cannot aggregate (`function sum(boolean) does not exist`). Rewrote both sites as `SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END)`.
- **#388 (`SUBSTR(TIMESTAMP)` not cross-engine)** — `api_scan_history()` and `scan_history.php` used `SUBSTR(scanned_at, 1, 16)`, which works on SQLite (TEXT column) and MySQL (DATETIME auto-coerced to string in string-function contexts). Postgres rejects it with `function substr(timestamp, integer, integer) does not exist`. No portable `CAST` target exists — MySQL's `CAST AS CHAR` is variable-length, Postgres's `CAST AS CHAR` is `CHAR(1)` and would truncate. Branched on `ipam_dialect()->driver_name()` to emit `(scanned_at)::text` on pgsql and leave the bare column on the other two engines.

## [2.10.0] - 2026-04-15

Experimental MySQL 8.0.29+ driver on top of the v2.9.0 Dialect foundation. Opt-in via `db_driver = 'mysql'`; SQLite remains the default and is unchanged. The MySQL path is beta-quality — labelled as such in the install docs and with a dismissible admin banner on every page when active — and exists so real users can validate it against their data before v3.0.0 commits to the contract.

### Added
- **#482** — `Dialect::upsert_or_ignore(table, conflictCols)` for `INSERT … ON CONFLICT DO NOTHING` semantics, distinct from `upsert()` which updates on conflict. SQLite emits `ON CONFLICT(col, ...) DO NOTHING`; MySQL emits `ON DUPLICATE KEY UPDATE firstCol = firstCol` (no-op self-assign). Refactors the last deferred literal in `migrations.php:673` (v2.8.0-alert-recipients closure). 4 new `DialectTest` cases.
- **#484** — `Dialect::indexed_text_type(int $maxLen = 191)` for TEXT-like columns used in indexes, UNIQUE constraints, or primary keys. Returns `TEXT` on SQLite / Postgres and `VARCHAR($maxLen)` on MySQL. Required because MySQL 8.0 rejects `BLOB/TEXT column used in key specification without a key length`. Routed into `ensure_audit_log_table()` and `ensure_migrations_table()` for `audit_log.action`, `audit_log.created_at`, and `schema_migrations.version`.
- **#382** — `MysqlDialect` implementation of every `Dialect` method. `now()` → `UTC_TIMESTAMP()`; `upsert()` → `ON DUPLICATE KEY UPDATE col = VALUES(col)`; `autoincrement()` → `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`; `binary_type(16)` → `VARBINARY(16)`; `case_sensitive_collation()` → `COLLATE utf8mb4_bin`; `append_only_trigger()` → two `BEFORE UPDATE/DELETE ... SIGNAL SQLSTATE '45000'` triggers (requires MySQL 8.0.29+ for `CREATE TRIGGER IF NOT EXISTS`); `pragma_foreign_keys()` → `SET FOREIGN_KEY_CHECKS = 1|0`. 20 new string-output tests in `tests/MysqlDialectTest.php`. **Effective minimum MySQL version: 8.0.29.**
- **#383** — `Simple-PHP-IPAM/schema.mysql.sql`: authoritative 19-table MySQL schema mirroring the fully-migrated SQLite state through `2.9.0-blob-affinity`. InnoDB on every table, `utf8mb4_bin` on case-sensitive columns, `VARBINARY(16)` for binary IP columns (native length, never padded). Ends with a pre-seed of all 28 historical migration version rows so `apply_migrations()` is a no-op on fresh MySQL installs — historical SQLite-only migration closures never execute on MySQL. End-to-end validated against MySQL 8.0.45 during development (bootstrap, binary IP round-trip on the three #410 vectors, append-only trigger enforcement, byte-wise `ORDER BY ip_bin` sort).
- **#385** — "MySQL (experimental)" section in `docs/install.md` with version requirements, grant list, connection example, and a known-issues list. New dismissible admin UI beta banner in `page_header()` that appears on every page while `db_driver=mysql` is active. Dismiss state is per-browser + per-app-version so upgrading the app resurfaces the banner. Uses the existing `.admin-notice` family with a new `--warning` variant.
- **#384** — Per-commit CI matrix slot running the full PHP QA suite (lint, PHPStan, PHPCS, PHPUnit, Semgrep) and `test_api.sh` against a MySQL 8.0 service container. Matrix is `fail-fast: false` across `[sqlite, mysql]` so a MySQL regression never masks a SQLite failure or vice-versa.
- **#433** — Nightly Playwright matrix slot for MySQL on top of the v2.5.2 containerized harness. `testing/playwright/bootstrap-app.sh` accepts `mysql` and provisions a `mysql:8.0` service container alongside the Apache/PHP container on a shared Docker network. `testing/playwright/fixtures/test-config-mysql.php` pins `db_driver=mysql` with a DSN targeting the service container. Four specs that exercise SQLite-format dumps or pre-v2.0.0 upgrade paths (`db-tools`, `large-db` ×2, `upgrade`) skip cleanly on MySQL via an `IS_MYSQL` guard.
- **Polish follow-ups (#497)** — 10 proactive Semgrep rules in `.semgrep/rules.yml` catching the SQLite-specific idioms swept out during the MySQL work (`datetime('now')`, `BEGIN EXCLUSIVE`, `sqlite_sequence`, `PRAGMA table_info`, `INSERT OR IGNORE`, `strftime`, `datetime('now', '-N days')`, `IS :param`, unquoted `settings.key`, `LIKE ... ESCAPE '\\'`). Each rule excludes the files where the idiom is correct by design (`schema.sql`, `migrations.php`, `SqliteDialect.php`) using Semgrepignore-v2-compliant `**/` globs. New top-level `Makefile` with a `gate` target that runs the full local PHP gate in one command.

### Changed
- **#483** — Routed the last five `PRAGMA foreign_keys` literals in `lib.php` through `Dialect::pragma_foreign_keys()`. Sites: `ipam_db()` post-connection enable; four inside `apply_migrations()` (FK-off pre-BEGIN + three restore paths). Dialect returns `null` on engines without a per-connection toggle (Postgres via deferred constraints, v2.11.0) and the call sites null-check before executing. `lib.php:2198` and `:2269` in `ipam_db_dump_stream()` stay as SQLite-format backup output by design.
- **#382 dispatcher** — `ipam_db()` signature changed from `(string $path)` to `(array $config)`. Branches on `$config['db_driver']`: `sqlite` (default, uses `db_path`) or `mysql` (uses `db_dsn` / `db_user` / `db_pass`). Rejects MySQL < 8.0 at connect time with a clear `RuntimeException`. 7 caller sites updated: `init.php`, `api.php`, `cron.php`, `status.php`, `demo_seed.php`, `demo_reset.php`, `scan_run.php`. New `ipam_dialect_from_config(array $config)` helper for tests that need to pin a dialect without a live connection.
- **#383 installer wiring** — `ipam_db_init()` is now driver-aware: SQLite sentinel fast-path only runs under `driver_name() === 'sqlite'`; the users-table probe was rewritten from a `sqlite_master` query to a `try/catch SELECT 1 FROM users LIMIT 1` so it works on every engine; schema file picked by driver (`schema.sql` on SQLite, `schema.mysql.sql` on MySQL); fresh-install migration stamping routed through `Dialect::upsert_or_ignore()` instead of SQLite-specific `INSERT OR IGNORE`.
- **#385 asset cache-buster** — bumped to `?v=2.10.0` in `lib.php::page_header()` and `demo_gate.php`.

### Fixed
- **#484** — Routed `AUTOINCREMENT` and `datetime('now')` literals in `lib.php` bootstrap DDL (`ensure_audit_log_table()`, `ensure_migrations_table()`) through `$dialect->autoincrement()` / `$dialect->now()` / `$dialect->append_only_trigger()` so fresh MySQL / Postgres installs emit engine-valid DDL from the self-heal path. SQLite output is byte-equivalent to v2.9.0. Historical migration closures in `migrations.php` deliberately not refactored — `schema.mysql.sql` / `schema.pgsql.sql` pre-seed `schema_migrations` so replay is a no-op on non-SQLite fresh installs, and changing those closures would risk `MigrationTest` fixture drift for zero runtime benefit.
- **#383 (found during E2E)** — `ipam_db_init()` fresh-install migration stamping used SQLite-specific `INSERT OR IGNORE`. Rewritten through `Dialect::upsert_or_ignore()`.
- **#383 (CR feedback)** — `ipam_db_init()` sentinel fast-path hardcoded `data/.db_initialized` and `data/ipam.sqlite`, ignoring a deployment's configured `$config['db_path']`. Now reads `$config['db_path']` via `global $config` and co-locates the sentinel next to the resolved DB file. Pre-existing latent bug (the same hardcoded paths existed on v2.9.x); closed out during the v2.10.0 refactor.

## [2.9.2] - 2026-04-15

Second hotfix on top of v2.9.1 for a related cascade. Same failure mode ("headers already sent" wall after an error got written to stdout), different trigger. No new features, no schema changes. Upgrade is safe from any v2.9.x install.

### Fixed
- **Database Tools import cascade on missing `import_sql_max_mb` config key.** On an install upgraded from an older release whose `config.php` never had `import_sql_max_mb`, `db_tools.php:100` read the undefined key, PHP 8.x emitted an `E_WARNING` ("Undefined array key") to stdout, and every subsequent `header()` / `session_start()` call cascaded with "headers already sent". The v2.9.1 hotfix only handled PHP **startup** warnings (e.g. `post_max_size` exceeded); this was a **runtime** warning and slipped through.

### Changed
- **`Simple-PHP-IPAM/lib.php`** — `ipam_config_defaults()` registry now includes `import_sql_max_mb` with `default => 200`. Root cause of the missing key: `ipam_config_sync()` reads this registry to auto-populate missing keys on boot during `upgrade.sh` → `migrate.php` → `init.php`, but the registry entry was absent, so the sync silently skipped it. On the next request after this release lands, missing keys are written back into `config.php` automatically.
- **`Simple-PHP-IPAM/init.php`** — installed a global `set_error_handler()` at the very top that routes `E_WARNING` / `E_NOTICE` / `E_DEPRECATED` / `E_USER_*` to `error_log()` only, never to stdout. **This is the root-cause fix for the entire class of cascade bug.** Any future unguarded array access, deprecated function call, or runtime notice will be logged to the PHP error log instead of cascading into the HTTP response body. Fatal errors (`E_ERROR`, parse errors) bypass user handlers and still exit as normal. The handler respects `error_reporting()` so `@`-suppressed calls behave unchanged.
- **`Simple-PHP-IPAM/db_tools.php`** — belt-and-suspenders `?? 200` null-coalesce on the `import_sql_max_mb` read site.
- **`phpstan.neon`** — marked `import_sql_max_mb?: int` in the `IpamConfig` type alias (it is optional in practice on upgrades from older installs).

## [2.9.1] - 2026-04-15

Hotfix release for one post-v2.9.0 user report. No new features. No schema changes. Upgrade is safe from any v2.9.x install.

### Fixed
- **Database Tools SQL import cascade on oversized uploads.** Uploading a SQL dump larger than `post_max_size` produced a wall of "headers already sent" warnings and left the session broken. Root cause: PHP emitted its "Request Startup" size-limit warning to stdout *before any script ran*, which committed the HTTP response body. Every subsequent `session_name()` / `session_set_cookie_params()` / `session_start()` call in `init.php` then cascaded with "headers already sent".

### Changed
- **`Simple-PHP-IPAM/.htaccess`** — default upload limits raised from `upload_max_filesize 200M` / `post_max_size 210M` to `512M` / `520M`. Added `php_flag display_startup_errors Off` so PHP's startup warnings never land in the HTTP response body (they still go to the PHP error log for operators).
- **`Simple-PHP-IPAM/db_tools.php`** — early `CONTENT_LENGTH` check *before* `require init.php`. When a POST's content length exceeds `ini_get('post_max_size')` AND `$_POST` and `$_FILES` are both empty (the exact signature of PHP silently discarding an oversized upload), the endpoint emits a clean 413 page with actionable per-SAPI guidance (Apache + mod_php → `.htaccess`, PHP-FPM → pool config, CGI → `php.ini`). Normal-sized POSTs flow through unchanged.
- **`docs/install.md`** — new "Upload limits for DB import" subsection under Requirements documenting the bundled defaults, the table of places to raise them per server type, and the app-level `import_sql_max_mb` soft cap in `config.php` (default 200 MB).

## [2.9.0] - 2026-04-15

Driver-abstraction foundation release. Introduces the `Dialect` interface, a data-integrity migration that normalizes SQLite's `ip_bin` / `network_bin` columns to BLOB affinity, Composer runtime-dependency infrastructure for future curated libraries, a CI tier restructure with an engine-matrix seam for v2.10.0 MySQL and v2.11.0 Postgres, and a handful of smaller fixes carried over from the v2.8.0 CodeRabbit sweep.

End-user behaviour is unchanged — same SQLite, same vanilla deployment, same `upgrade.sh` flow. The codebase is now ready to grow in three directions: multi-engine SQL, curated Composer deps, and tiered CI.

### Added
- **#378** — `Dialect` interface + `SqliteDialect` implementation. New `Simple-PHP-IPAM/dialects/` subdirectory with a stateless 8-method interface (`now`, `upsert`, `autoincrement`, `binary_type`, `case_sensitive_collation`, `append_only_trigger`, `pragma_foreign_keys`, `driver_name`). `init.php` instantiates the dialect based on `$config['db_driver']` (default `sqlite`); `mysql` / `pgsql` values exit with a clear "lands in v2.10.0 / v2.11.0" message. New `ipam_dialect()` helper in `lib.php` lazy-instantiates `SqliteDialect` for CLI scripts that bootstrap `lib.php` without `init.php`. 16 new PHPUnit tests in `tests/DialectTest.php` including an end-to-end in-memory SQLite check that the generated append-only trigger DDL actually blocks `UPDATE`.
- **#410** — `ipam_bind_binary()` helper in `lib.php` using `PDO::PARAM_LOB` unconditionally on every driver, plus a one-shot `2.9.0-blob-affinity` migration that rewrites every `subnets.network_bin` and `addresses.ip_bin` row from TEXT to BLOB affinity on existing SQLite installs. Pre-v2.9.0 the project used `PARAM_STR` (the default), and SQLite's loose typing stored these values with TEXT affinity even though the columns were declared `BLOB`. Bytes round-tripped correctly but `ORDER BY ip_bin` and range queries would break the moment new rows arrived bound as `BLOB` — SQLite's comparison rules say any BLOB sorts greater than any TEXT regardless of byte content. The migration is idempotent (skips when 0 TEXT rows), audits a single `migration.blob_affinity_normalized` row with per-table counts, and runs automatically via `migrate.php` during upgrade. 11 new tests in `tests/MigrationTest.php` and `tests/BinaryBindTest.php` including a 5-vector data provider covering the documented byte shapes.
- **#416** — Composer runtime-dependency infrastructure. `composer.json` gains a `require` block (empty in v2.9.0 — first actual dep lands in a future release), `prefer-stable: true`, `minimum-stability: stable`, `platform-check: true`. `releases/make_releases.sh` now runs `composer install --no-dev --optimize-autoloader --no-scripts` into a scratch tmp dir and rsyncs the resulting `vendor/` into the staging payload, so the release tarball bundles a pre-built autoloader. `vendor/.htaccess` denies direct HTTP access to library source (Apache 2.4 + 2.2 syntax). `init.php` conditionally requires `vendor/autoload.php` so fresh git clones without Composer still boot. CI gains `composer validate --strict` and `composer audit --no-dev --locked` steps. End-user deployment story is unchanged — still tarball + extract, no Composer needed on the target server.
- **#411** — CI tier restructure. `.github/workflows/php-qa.yml` is now the Tier 1 fast-per-commit workflow with a `fail-fast: false` engine matrix (sqlite slot, placeholder comments for the v2.10.0 mysql and v2.11.0 pgsql slots). Composer cache via `actions/cache@v4` keyed on `composer.lock`. `concurrency: cancel-in-progress` so busy PRs do not stack runs. **CI gap fixed**: PHP QA now runs on PRs targeting `dev`, not just `main` (feature branches into `dev` previously had zero PHP QA coverage). New `docs/ci.md` documents all four tiers and how to debug a failing matrix slot.
- **#451** — `testing/scripts/test_api.sh` now accepts a `DOCKER_CONTAINER` env var that routes auto-API-key creation through `docker exec` instead of ssh. New `CURL_INSECURE` env var (auto-on when `DOCKER_CONTAINER` is set). New `api-tests` CI job in `playwright-nightly.yml` runs the 160-assertion REST regression suite against a freshly bootstrapped container alongside the existing `containerized-playwright` and `htaccess-subset` jobs. API regression coverage previously required a shared dev-direct deployment; now it runs in CI on every PR.
- **#455** — `oidc.default_role` is now an enumerated `<select>` dropdown on **⚙ Admin → Settings**. Options: `Read-only` (default, recommended) and `Administrator`. A typo previously broke OIDC auto-provisioning silently; the enum rejects out-of-set values in the two-phase POST handler.
- **#381** — `tests/CliGuardTest.php` asserts every CLI-only entry point (`cron.php`, `scan_run.php`, `migrate.php`, `demo_seed.php`, `demo_reset.php`, `tmp_cleanup.php`) contains a `PHP_SAPI !== 'cli'` guard in its first 8KB. All 6 already had guards as of v2.8.0 — pure regression protection.

### Changed
- **#379 / #380** — Every binary IP write site in `lib.php` and the page handlers now routes through `ipam_bind_binary()`: `auto_reserve_subnet_ips()` (network / broadcast / gateway auto-reserve), `ipam_ensure_subnet()` (CSV import + API auto-create), `demo_seed_data()` (IPv4 / IPv6 subnets + addresses), and `migrations.php` migration 0.3 network_bin backfill. After this change every new `subnets.network_bin` / `addresses.ip_bin` row is BLOB affinity on SQLite from the start.
- **#379 / #380** — Routed four upsert call sites through `$dialect->upsert()`: `ipam_set_setting()` (settings registry), `check_utilization_alerts()` (alert_state), and the scan_schedules upserts in `api.php`, `subnets.php`, and `scan_history.php`. Routed three `PRAGMA foreign_keys` call sites in `db_tools.php` through `$dialect->pragma_foreign_keys()`.
- **`.phpcs.xml`** — adds exclusions for `PSR1.Methods.CamelCapsMethodName.NotCamelCaps` and `PSR1.Classes.ClassDeclaration.MissingNamespace` with documented rationale. Snake_case method names match the codebase's procedural convention; no namespaces per CLAUDE.md → "When to use classes vs functions".

### Fixed
- **#452** — `.claude/hooks/block-sensitive-paths.sh` canonicalization bypass. The `${file#"$PWD/"}` strip only matched exact absolute prefixes, so `./Simple-PHP-IPAM/config.php` and `Simple-PHP-IPAM/data/../config.php` slipped past every block pattern. Replaced with a `python3 os.path.realpath` canonicalizer that resolves both input and `$PWD` (macOS BSD `realpath` lacks `-m`). Verified against 11 path-form regression cases including symlinked OneDrive working directories.
- **#453** — `.claude/skills/release-gate/SKILL.md` stage step pointed at a non-existent `ipam-X.Y.Z/` directory at the repo root. `make_releases.sh` emits directly to `releases/ipam-X.Y.Z/`. Replaced with a `test -f && git add` block.
- **#454** — `.claude/agents/ip-binary-auditor.md` shipped-version baseline bumped from v2.7.0 → v2.8.0.
- **`dialects/.htaccess`** — new file denying direct HTTP access to bundled dialect class source. A preemptive Playwright test (`htaccess.spec.ts:94 "blocks /dialects/ when present"`) was added in v2.5.2 with a `test.skip(!hasDialects)` guard so it lit up automatically when v2.9.0 shipped the directory.

### Security
- **#410** — Binary IP column storage is now explicitly BLOB on SQLite. Pre-v2.9.0 data is normalized automatically on upgrade. Fixed root cause: `ORDER BY ip_bin` returning nonsense when mixed TEXT/BLOB affinity rows coexisted.
- **#416** — `composer audit --no-dev --locked` now runs in CI on every PR and fails the build on any known CVE in a runtime dep.

## [2.8.0] - 2026-04-14

Quality-of-life and API release on top of v2.7.0's settings rewire. Adds long-form notes on subnets, multi-recipient utilization alerts tied to user records, write API for tags, paginated API metadata, a keyboard shortcut for power users, and a redesigned password-show toggle that finally works in real browsers with password managers installed.

### Added
- **#316** — `subnets.notes` long-form operational notes column. New `2.8.0-subnet-notes` migration adds `notes TEXT NOT NULL DEFAULT ''`. Edited via a textarea in the subnet create/edit drawers, rendered as a collapsible card above the address table on `addresses.php`, exposed as `notes` on the subnet API GET/POST/PUT, included as a column in the subnets CSV export, and indexed by the global search LIKE query alongside `description`.
- **#443** — Alert recipients are now picked from active users with email instead of a single free-text address. New `alert.recipient_user_ids` registry key (`type: json`, `default: []`); the multi-select on the Settings → Alerting card lists every active user with a non-empty email and shows a live "Emails will be sent to:" preview. `check_utilization_alerts()` resolves selected user IDs to current emails on every send, so deactivating a user or clearing their email drops them automatically — no need to re-save the settings page. Loop-per-recipient delivery with one audit row per send for per-recipient debuggability. New `2.8.0-alert-recipients` migration auto-maps the legacy `alert.email` value to a single matching active user (case-insensitive); unmigratable values produce a `settings.alert_email_unmigrated` audit row instead of being silently dropped.
- **#314** — API pagination metadata. Every paginated `GET` endpoint (subnets, addresses, contacts, history, search, audit, unassigned) now sets an `X-Total-Count` response header and accepts an optional `?envelope=1` query parameter that wraps the response as `{data, meta:{total, page, per_page, pages}}`. Default flat shape unchanged for backwards compatibility.
- **#310** — Write API for tags. New endpoints `GET/POST/PUT/DELETE ?resource=tags`, plus association endpoints `POST/DELETE ?resource=subnet_tags` and `POST/DELETE ?resource=address_tags`. Subnet and address `POST`/`PUT` bodies now accept an optional `tag_ids[]` array that replaces the full tag set via `save_tags_for_entity()`. All write operations honour the `is_readonly` API key flag and emit `tag.create` / `tag.update` / `tag.delete` / `tag.attach` / `tag.detach` audit entries.
- **#317** — `Cmd+N` / `Ctrl+N` keyboard shortcut on `addresses.php` opens the Add Address drawer and focuses the IP field. Gated by `<body data-page="addresses">` so it only fires on the intended page; suppressed when focus is in any text input or textarea so users can still type the letter `n`. A `⌘N` hint badge renders next to the Add Address button.

### Changed
- **page_header()** now accepts an `opts['page']` key that emits `<body data-page='…'>` for per-page JS gating.
- **#449** — Sensitive-field show toggles on `settings.php` are no longer checkboxes. Replaced with eye-icon `<button type="button">` siblings of the password input, sitting at `right:36px` to leave room for password manager icons. The button toggles the input's `type` attribute and its own `aria-pressed` state. Per-browser opt-out via `localStorage['ipam-pw-toggle-hidden'] = '1'`.
- **Asset cache-buster** — `page_header()` and `demo_gate.php` bump to `?v=2.8.0`.

### Deprecated
- **`alert.email`** registry key, replaced by `alert.recipient_user_ids`. Hidden from the Settings UI but kept in the registry so the v2.8.0 migration can read its value once. Slated for removal in v3.0.0.

### Fixed
- **#449** — The sensitive-field show toggle on `settings.php` now flips the input type in real browsers. The v2.6.0 inline-onclick and v2.7.0 nested-`<label>` checkbox approaches both regressed in deployed Chrome / Firefox / Safari with password managers installed despite passing headless Playwright. Eye-icon button design avoids both failure modes and stays on `type=password` by default so 1Password / Bitwarden autofill keeps working.

## [2.7.0] - 2026-04-14

Subsystems now read configuration from the database. Every non-bootstrap key covered by the v2.6.0 registry (OIDC, alerting, branding, login protection, update checker, reCAPTCHA Enterprise) routes through `ipam_setting()` instead of touching `$config` directly, so edits in the admin Settings UI take effect on the next request without a `config.php` change or a restart. `config.php` continues to work as a fallback for back-compat — removal is v3.0.0.

### Added
- **#442** — `options` contract on the settings registry. `ipam_setting_definitions()` now accepts an `options` key (static assoc array or `'@timezone'` sentinel for PHP zoneinfo). `settings.php` renders a `<select>` for any string setting with options and rejects out-of-set values in the two-phase POST handler. First consumers: `login_protection.method` (7-item enum) and `branding.timezone`.
- **#373** — Three new registered OIDC hardening flags — `oidc.disable_local_login`, `oidc.disable_emergency_bypass`, `oidc.hide_emergency_link` — editable in the admin Settings UI.
- **#376** — Deprecation tooling. New `ipam_setting_deprecated_keys()` helper walks the registry and returns any registered key whose `config.php` value is customised but has not been imported into the `settings` table. Drives a new deprecation banner above the settings group forms with per-key **Import to database** buttons (sensitive values masked as `***`), a rate-limited boot-time `error_log()` line listing the deprecated keys once per hour, and an admin warning card on the dashboard linking to the banner.
- **Testing** — 15+ new PHPUnit tests covering the `options` contract, `oidc_enabled()` DB→config fallback chain, alert threshold fallback chain, and the deprecated-keys helper (empty-on-clean, skip defaults, flag customised, hide already-imported). 8+ new Playwright tests for the settings.php UX fixes and the deprecation banner round-trip (force a deprecated state via `docker exec`, assert the Import flow persists the value and drops the banner row).

### Changed
- **#373** — OIDC subsystem routes every config read through `ipam_setting('oidc.*')`. `oidc_enabled()`, `oidc_discovery()`, `oidc_login.php`, `oidc_callback.php`, and the OIDC button in `login.php` all go through the helper. Function signatures are preserved for back-compat; the `array $config` parameter is now advisory.
- **#374** — Alerting subsystem routes through `ipam_setting('alert.*')`. `check_utilization_alerts()`, `alerts_check_if_due()`, and `cron.php` task 4 read `alert.email`, `alert.util_warn_pct`, `alert.util_crit_pct`, and `alert.interval_seconds` via the helper. Silent-no-op-when-empty behaviour preserved; the `alert_state` dedup table is untouched.
- **#375** — Branding, login protection, and update checker subsystems route through `ipam_setting()`. `page_header()` reads `branding.site_name`; `init.php` and `cron.php` seed a UTC default pre-DB and re-apply `branding.timezone` once `$db` is open (bad value falls back to UTC, never a broken state); `login_protection_verify()`, `login_protection_widget_html()`, and `login_protection_extra_csp()` route through `ipam_setting('login_protection.*')` and `ipam_setting('recaptcha_enterprise.*')`; `ipam_update_check()` reads `update_check.enabled`, `ttl_seconds`, and `notify_prerelease` via the helper.
- **Asset cache-buster** — `page_header()` and `demo_gate.php` bump to `?v=2.7.0`.

### Fixed
- **#440** — The sensitive-field **show** toggle on `settings.php` now actually reveals the input. Root cause was a nested `<label>` inside `<label>` where the outer label's implicit labeling swallowed click intent to the password field. Restructured the markup so the password field and its show-toggle are siblings with explicit `<label for="…">` associations — no more nested labels anywhere in the settings form. Hardened the JS handler with `closest()` as a belt-and-braces.
- **#441** — Bool settings now render with their checkbox inline next to the label, source badge, and key code on a single row instead of stacked underneath. New `.setting-head--bool` CSS class overrides the global `label { display:flex; flex-direction:column }` rule that was forcing every setting row to stack.

### Deprecated
- Every non-bootstrap key in `config.php` is explicitly deprecated as of v2.7.0 and will be removed in v3.0.0. Admins can use the new Settings banner to import customised values into the database with one click; the rest auto-fall-through to the registry defaults. Bootstrap-only keys (`db_path`, `session_name`, `proxy_trust`, `force_https`, `base_url`, `bootstrap_admin`) stay in `config.php` forever.

## [2.6.0] - 2026-04-14

Settings-in-database groundwork release. Introduces a new `settings` table, a typed read/write helper, and an admin UI under ⚙ Admin → Settings. `config.php` continues to work as a fallback for every non-bootstrap key — v2.7.0 rewires each subsystem to read from the database, and v3.0.0 removes the `config.php` fallback entirely.

### Added
- **#369** — `settings` table (`key`, `value`, `type`, `updated_at`, `updated_by`) added via new `2.6.0-settings` migration. Fresh installs also get the table from `schema.sql`. The migration is idempotent and seeds every registry key from the live `config.php` on first run, preserving any values operators later edit directly in the database.
- **#370** — `ipam_setting()` / `ipam_setting_set()` / `ipam_setting_all()` helpers in `lib.php`, plus the authoritative `ipam_setting_definitions()` registry that enumerates every tunable and its type, group, default, sensitivity, and `config.php` fallback path. Reads fall through: database → `config.php` → registry default → caller default. Writes produce `setting.update` audit entries with masked old/new values for sensitive keys. Includes a per-request cache cleared by `ipam_setting_cache_bust()`.
- **#371** — New admin page `settings.php` (⚙ Admin → Settings) that renders every registered setting grouped by namespace (Branding, Security, Alerting, Update checker, OIDC / SSO) as card forms with per-type inputs (text / number / checkbox / password with show-toggle / JSON textarea). Each row shows a source badge — 🟢 Database, 🟡 config.php, ⚪ Default — so the transition state is explicit. readonly users and demo mode are blocked at the top of the handler.
- **docs/configuration.md** — new "Where configuration lives" section documenting the two sources, read precedence, and the v2.6 → v2.7 → v3.0 transition plan.
- **PHPUnit** — new `tests/SettingsTest.php` with 15 tests covering registry shape, all four type round-trips, `$config` fallback, default path, invalid-JSON safety, audit-entry uniqueness, sensitive-key masking, cache busting, source classification, and flat/nested config lookup. `tests/MigrationTest.php` gains `testSettingsMigrationSeedsAndIsIdempotent` asserting the table is created, every registry key is seeded, and a user-written value survives a second migration run.

### Changed
- **Admin dropdown** — "Settings" added as a new entry under ⚙ Admin in both the desktop dropdown and the mobile drawer.
- **Configuration** — administrators can now edit most operational settings from the web UI. `config.php` remains the authoritative source for bootstrap keys (`db_path`, `session_name`, `proxy_trust`, HTTPS enforcement) forever.

### Deprecated
- Reading non-bootstrap configuration from `config.php` is deprecated. The fallback still works in v2.6.0 and v2.7.0, and will be removed in v3.0.0. See `docs/configuration.md` → "Transition from config.php" for the migration plan.

## [2.5.2] - 2026-04-14

**Internal tooling release. No user-facing changes.** Application code, schema, and config are unchanged from v2.5.1 — end users can skip this release and go straight from v2.5.1 to v2.6.0. There is no release tarball and no GitHub release; v2.5.2 exists only as a git tag marking the milestone boundary for contributors.

### Internal
- **#428** — Playwright suite audit. The CDP → `@playwright/test` rewrite was already complete in earlier releases; this pass verified no dev-direct-only assumptions remain in `playwright.config.ts`, `fixtures/ipam.ts`, or the 32 spec files. Findings documented in `testing/playwright/MIGRATION_NOTES.md`.
- **#429** — New containerized Playwright harness. `testing/playwright/bootstrap-app.sh` boots a Dockerized Apache+PHP instance with a self-signed cert on `https://127.0.0.1:8443`, seeds from `demo_seed.php`, and is ready for `npx playwright test`. New `.github/workflows/playwright-nightly.yml` nightly job runs the full suite against the SQLite matrix slot. v2.10.0 adds `mysql` and v2.11.0 adds `pgsql` to the same matrix.
- **#430** — New Apache `.htaccess` coverage subset. `testing/playwright/tests/htaccess.spec.ts` (~13 tests) runs against the same Dockerized image as a separate CI job, verifying that the root and `data/` `.htaccess` deny rules block direct access to `config.php`, `lib.php`, `init.php`, schema files, `data/*`, CLI-only scripts, and `SHA256SUMS`. Future-gated skips for `vendor/` and `dialects/` that land in v2.9.0.
- **#431** — Flake policy and contributor docs. `playwright.config.ts` now sets `retries: 2` in CI (0 locally), 60s test timeout, 10s expect timeout, 15s action timeout, and `trace: 'retain-on-failure'` for CI failure diagnosis. New `testing/playwright/scripts/flake-rate.mjs` aggregates the JSON reporter output into a green/yellow/red exit code. New `testing/playwright/README.md` documents running the suite, writing tests, debugging CI failures, and the flake budget. `CLAUDE.md` "Running the test suites" section rewritten to lead with the containerized path and keep dev-direct as a fallback for real-IdP OIDC and timezone tests.
- **#432** — `demo_seed.php` audit. Added a header comment listing every fixture the seed produces (3 users, 6 sites, 2 VRFs, 4 VLANs, 3 contacts, 4 tags, 13 subnets, 50+ addresses, pre-populated audit log) so contributors can see what test data already exists before extending it. New `testing/playwright/SEED_AUDIT.md` serves as the running log of seed/test mismatches to be populated once nightly CI produces its first 7-run baseline.

## [2.5.1] - 2026-04-14

### Fixed
- **`cron.php` self-lock** — concurrent cron invocations now exit cleanly instead of stacking. A new `data/cron.lock` file is held with `flock(LOCK_EX | LOCK_NB)` for the lifetime of each run; a second invocation that finds the lock held emits `{"task":"cron","skipped":true,"reason":"another cron.php instance is still running"}` and exits 0. This prevents the runaway scenario observed on a misconfigured `* * * * *` testing host where 30+ overlapping cron processes hammered the same `scan_results` table, eventually exhausted SQLite's `busy_timeout=30000`, and caused user-facing writes (e.g. `clear_login_failures()` on login) to fail with `database is locked`. Also covers the case where a single scan run takes longer than the configured cron interval and the next tick fires before the previous one exits. Released by `register_shutdown_function()` so it covers normal exit, fatal errors, and uncaught throws alike.

## [2.5.0] - 2026-04-13

### Added
- **#364** — `cron.php` now runs the demo database reset as Task 7. No-op when `demo_mode.enabled=false`; throttled to once every 24 hours when enabled (tracked via `data/demo_last_reset.txt`). Operators can continue to call `demo_reset.php` directly for an unthrottled manual reset.
- **docs/install.md** — new **Step 6: Register the cron runner** section with the recommended `*/15 * * * *` crontab entry.
- **docs/configuration.md** — cron task table now lists the demo reset task and its throttling behaviour.
- **Testing** — new Playwright specs: `csp.spec.ts` (CSP header + no inline-style/script violations across every admin page), `console-clean.spec.ts` (no `console.error` or unhandled rejections across every admin page), `css-regression.spec.ts` (theme switching, sticky headers, status badge tokens, `.util-bar-fill` width). Extended `tooltips.spec.ts` with fleet-wide `[data-tooltip]`/`[title]` non-empty assertions and single-tooltip-element guarantee. Extended `js-behaviour.spec.ts` with CSRF-token-on-every-POST-form sweep, theme persistence across reload, and search overlay Escape regression. 329 Playwright tests total.
- **PHPUnit** — 7 new unit tests for `ipam_compute_broadcast_bin()` covering `/24`, `/29`, `/30`, `/31` (RFC 3021), `/32`, and IPv6 (no broadcast). 96 PHPUnit tests total.

### Changed
- **#363** — Scanner now excludes IPv4 network and broadcast addresses from scan targets. These IPs are never probed even if they exist in the `addresses` table: some hosts respond to broadcast ICMP, producing misleading up/down results. Broadcast exclusion applies to `/30` and larger; `/31` (RFC 3021 point-to-point) and `/32` (single host) have no reserved addresses. IPv6 has no broadcast concept, so only the network address is skipped. Reserved IPs are also excluded from the stale-marking pass. Scan summary gains a `skipped` counter. See `docs/scanning.md` → *What gets scanned*.

### Security
- Full QA gate re-run: PHP lint, PHPStan level 9, PHPCS, PHPUnit, Semgrep taint rules, and Playwright (329 tests) all green. No new baseline entries.

## [2.4.1] - 2026-04-13

### Fixed
- **aggregates.php** — Fatal SQL error (`no such column: a.cidr`) caused by missing table alias in `FROM aggregates` clause; corrected to `FROM aggregates a`.
- **CSP compliance** — Eliminated all inline-style Content Security Policy violations: JS `element.style` assignments replaced with `classList`/`hidden`/`data-*` attribute patterns; dynamic percentage widths and indentation depths moved to CSS attribute selectors; PHP inline styles in `subnets.php` replaced with utility classes. `style-src-attr 'unsafe-inline'` added to allow tooltip dynamic `top`/`left` positioning while keeping `style-src 'self'` for stylesheet links.
- **Footer logo** — Removed `style="vertical-align:middle;opacity:.7;"` inline style; replaced with `.footer-logo` CSS class.
- **test_api.sh** — Fixed empty-array expansion (`${arr[@]}`) under `bash set -euo pipefail` using the safe `"${_ba_args[@]+"${_ba_args[@]}"}"` pattern; fixed `${BASIC_AUTH}` reference under `set -u` with `${BASIC_AUTH:-}`.

## [2.4.0] - 2026-04-13

### Added
- **#357** — ICMP scan false negatives fixed: `ipam_probe_icmp()` now reads `stdout` before closing pipes (eliminating SIGPIPE-induced silent failures), correctly parses RTT from both Linux (`time=6.12 ms`) and macOS (`time=6.125 ms`) ping output formats, and handles exit code 2 (permission denied / missing `CAP_NET_RAW`) with a logged error instead of a silent null.
- **#352** — Sticky table headers fixed: changed `position:sticky` from per-cell (`thead th`) to the `thead` element itself, creating a single unified stacking context. Added `z-index:52` on `thead` and `background:var(--card-2)` on `thead th`. Topbar height is now measured dynamically via `ResizeObserver` (replaces the unreliable `DOMContentLoaded`+resize approach).
- **#358** — Timezone support: new `timezone` config key (`config.php`) and `date_default_timezone_set()` call in `init.php`. New `display_datetime()` helper in `lib.php` converts UTC SQLite timestamps to the configured timezone for display. `scan_history.php` and other pages use `display_datetime()` for all timestamp output.
- **#360** — Unified `cron.php` housekeeping runner: consolidates temp cleanup, audit log pruning, address history pruning, utilisation alerts, and database backup into a single cron entry. JSONL output per task. Network scanning (`scan_run.php`) remains separate.
- **#356** — Scan schedule UI moved from `subnets.php` inline details to a dedicated section on `scan_history.php`. Subnet rows now show a "Scan History" link instead of an inline schedule form.
- **#359** — Live ping button: each address row on `addresses.php` gains a "Ping" button that fires a POST to `ping_host.php` via `fetch()`. The response shows latency (green) or "down" (red). Available to all authenticated users including read-only. CSRF-protected. IP resolved from the database — raw IP never trusted from the request body.
- **#326** — BGP attributes on VRFs: `asn`, `rt_import`, `rt_export`, and `enforce_unique` fields added to the VRF add/edit forms and detail table. Database migration `2.4.0-vrf-bgp` with idempotency guards.
- **#329** — VLAN ranges: new `vlan_ranges` table and management UI under Admin → VLANs. Named 802.1Q VLAN ID allocation blocks with optional site scoping. Migration `2.4.0-vlan-ranges`.
- **#354** — Tooltip system: `[data-tooltip]` attribute on any element renders a CSS pseudo-element tooltip (no JS required for display). JavaScript edge-clamping adds `.tooltip-left`/`.tooltip-right` to prevent overflow at viewport edges. Used throughout VRF BGP and aggregate forms.
- **#328** — Aggregates page (`aggregates.php`): supernet/aggregate CRUD for admin users. RIR selection (ARIN, RIPE, APNIC, LACNIC, AFRINIC, Internal), subnet coverage count, IPv4 and IPv6 support. Migration `2.4.0-aggregates`. Linked from Admin dropdown.
- **#325** — IPv6 Prefix Delegation pools (`pd_pools.php`): RFC 3633 PD pool management. Create pools from IPv6 parent subnets, delegate sub-prefixes to subscribers (linked to contacts), track expiry. Expired delegations highlighted in red. Migration `2.4.0-pd-pools`. Linked from Admin dropdown.
- **#327** — DNS zone export (`export_dns.php`): BIND-format zone file download from any subnet. Supports forward (A/AAAA), reverse (PTR), or both. IPv4 PTR uses octet-reversed in-addr.arpa origin; IPv6 PTR uses nibble format ip6.arpa. Linked as "DNS Export" action pill on addresses page.
- **#330** — `docs/advanced-networking.md`: comprehensive guide covering VRF BGP attributes, VLAN ranges, aggregates, IPv6 PD pools, and DNS zone export.
- **#353** — GitHub Pages site: `docs/_config.yml` and `docs/index.md` added. Deploy from `docs/` branch to publish documentation at the project's GitHub Pages URL.
- **Testing** — PHPUnit `tests/ScannerTest.php` extended with 4 new RTT-parsing tests (Linux format, macOS format, sub-millisecond, empty output). Total: 81 tests.

### Changed
- Admin nav dropdown now includes **Aggregates** and **PD Pools** links between VLANs and Tags.

## [2.3.0] - 2026-04-12

### Added
- **#319** — Ping sweep scanner (ICMP + TCP) implemented in pure PHP with no external dependencies. `ipam_probe_icmp()` uses `proc_open()` with the system `ping` binary (1-second timeout, 1 packet). `ipam_probe_tcp()` uses `fsockopen()`. All IPs validated through `normalize_ip()` before system calls. OS-aware flag handling (macOS vs Linux ping differences).
- **#322** — Auto-stale detection: `ipam_mark_stale_addresses()` sets `addresses.is_stale=1` for addresses that have missed N consecutive scan results. Addresses that come back online are automatically cleared. Stale badge displayed inline on the addresses page.
- **#321** — Scan history timeline: new `scan_history.php` page shows per-subnet scan run history with up/down counts, per-address last-seen timestamps, and stale badges. Accessible to all logged-in users (no admin role required). Linked from each subnet row via a "Scan History" action pill.
- **#324** — Scan REST API endpoints: `GET /api.php?resource=scan_results&subnet_id=N` (last scan run), `GET scan_history` (paginated history), `GET/POST/DELETE scan_schedules` (per-subnet schedule management), `POST scan_run` (synchronous trigger, capped at /28 to prevent timeout). All documented in `docs/api.md`.
- **#323** — CLI scan runner `scan_run.php`: cron-compatible script (`php scan_run.php --all`). Guards against web execution (403 on non-CLI SAPI). Supports `--subnet-id=N`, `--all` (runs all active scheduled subnets past their interval), `--dry-run`, `--method=icmp|tcp|both`, `--stale-threshold=N`. JSON summary output.
- **#320** — ARP / neighbour table import: new `import_arp.php` page (write role required). Paste ARP output in any format (space/tab/CSV, Linux `arp -a` style). Two-step flow: preview parsed entries with in-subnet indicator, then apply to update `addresses.mac`. Audit logged as `address.arp_import`. Linked from the Admin nav dropdown.
- **#351** — Sticky table headers fully fixed: `syncTopbarHeight()` in `app.js` measures the topbar at runtime (DOMContentLoaded + resize) and writes the accurate pixel value to `--topbar-h` CSS custom property, eliminating the hard-coded 79px offset that caused headers to anchor at the wrong scroll position. `thead th` z-index raised to 51 (one above topbar's z-index: 50) so tbody rows can never render above pinned headers.
- **Database** — Two new tables: `scan_schedules` (per-subnet scan configuration with method, interval, active flag) and `scan_results` (one row per IP per scan run with latency). Two new columns on `addresses`: `last_seen_at` (timestamp of last successful ping response) and `is_stale` (auto-stale flag). Migration `2.3.0-scanning` with full idempotency guards.
- **Testing** — PHPUnit `tests/ScannerTest.php`: 13 test methods covering ARP table parsing (space/tab/CSV/comment/invalid/`arp -a` formats), ARP import MAC update/skip/no-match, and stale detection (threshold, clear on recovery, no-data). Three new Playwright specs: `scan-history.spec.ts`, `import-arp.spec.ts`, `scan-schedule.spec.ts`. Existing `addresses.spec.ts` and `subnets.spec.ts` extended for scan column and schedule UI. `test_api.sh` extended with scan resource tests. Large-DB sample updated to seed scan schedules and results.
- **Docs** — `docs/scanning.md` (new): setup, cron example, ICMP vs TCP, ARP import format, stale detection. `docs/api.md` updated with all scan endpoints.
- **Config** — Semgrep rule `ipam-proc-open-safe`: flags user-controlled input reaching `proc_open()`/`fsockopen()` without `normalize_ip()` validation. CodeRabbit path instructions added for `scan_run.php`, `scan_history.php`, `import_arp.php`, and scanner functions in `lib.php`.

## [2.2.1] - 2026-04-12

### Fixed
- **Critical data-loss bug** — Upgrading from any v1.x release directly to v2.1.0 or later wiped all IP addresses. Root cause: the `2.1.0-vrfs` migration rebuilds the `subnets` table (SQLite cannot drop a UNIQUE constraint in-place) by creating a new table, copying rows, dropping the old table, then renaming. With `PRAGMA foreign_keys = ON` active, SQLite executes an implicit row-by-row DELETE before dropping the parent table, which triggers `ON DELETE CASCADE` on the `addresses` table — deleting every address. Fix: `apply_migrations()` now disables FK enforcement (`PRAGMA foreign_keys = OFF`) before each migration's `BEGIN EXCLUSIVE` transaction and unconditionally re-enables it afterwards. Users already on v2.1.x are unaffected (the migration's idempotency guard exits early if the `vrf_id` column already exists); users upgrading directly from v1.x to v2.2.1 will now preserve all address data.

## [2.2.0] - 2026-04-12

### Fixed
- **#342** — Sticky table headers now correctly pin below the topbar on all table pages. Two CSS stacking-context bugs resolved: `table { overflow: hidden }` replaced with `clip-path: inset(0 round 14px)` (removes the table as a scroll container while preserving rounded corners), and `overflow-y: clip` added to `.table-wrap` (prevents browsers from silently promoting `overflow-y` to `auto` which made `.table-wrap` the sticky ancestor instead of the viewport). Topbar offset variable corrected from `73 px` to `79 px`.
- **#344** — `visual-audit.spec.ts` subnet map selector fixed: the spec used non-existent CSS classes `.subnet-node` and `.subnet-map`. Corrected to `#subnet-map-view` (the container rendered server-side) and `.map-node` (individual map entries).

### Added
- **Playwright test spec `contacts.spec.ts`** — full CRUD (create, edit, delete), contact typeahead integration on addresses page, readonly-user access control (403), and API `?resource=contacts` coverage.
- **Playwright test spec `vrfs.spec.ts`** — full CRUD, VRF picker on subnet create, VRF badge on subnet list, delete-guard validation (blocked when subnets are assigned, button disabled), and API `?resource=vrfs` coverage.
- **Playwright test spec `dhcp_pool.spec.ts`** — subnet picker navigation, pool reservation, reserved-address count verification, clear-reservation, empty-state check, readonly-user form-hiding, and out-of-subnet IP validation.
- **#345** — Large-DB sample database regenerated with current v2.1.x schema (VRFs, contacts, tags, VLANs, `expires_at`, MAC addresses included). 500 subnets, 43 000+ addresses, 100 000 audit rows, 50 000 history rows.

## [2.1.5] - 2026-04-12

### Fixed
- **#300** — VLAN badge em dash rendered as literal `\u{2014}` on the subnet list. PHP does not interpret `\u{...}` Unicode escapes in single-quoted strings; the em dash separator is now embedded as a literal UTF-8 `—` character in `subnets.php`.
- **#301** — Search overlay placeholder showed literal `\u2026` instead of an ellipsis (`…`). The escape sequence in `assets/app.js` has been replaced with the actual UTF-8 ellipsis character.
- **#302** — Sticky table header appeared below the first data row during scroll due to insufficient z-index. `thead th` z-index raised from `2` to `10` in `assets/app.css`; `--topbar-h` corrected to match the actual rendered topbar height.

### Added (testing)
- **#303** — Sample data generators (`gen_large_db.php` and CSV generators) updated to include VRFs, contacts, tags, VLANs, `expires_at`, and MAC addresses so generated datasets exercise all v2.x features.
- **#304** — Demo database (`demo_seed.php`) now seeds VRFs, contacts, tags, VLANs (with `vlan_fk` linkage), addresses with `expires_at` and MAC populated, and a parent+child site pair so the live demo showcases all available features.
- **#305** — Upgrade-path test suite: `testing/playwright/tests/upgrade.spec.ts` imports a pre-v2.0.0 SQL snapshot, triggers `apply_migrations()` via a page load, and asserts all v2.x pages (VLANs, VRFs, contacts, tags, addresses, audit) load correctly with migrated data intact. Supporting shell script at `testing/scripts/test_upgrade.sh`.
- **#306** — Large-DB import/export test suite: `testing/playwright/tests/large-db.spec.ts` imports 100 subnets and 5 000 addresses via `db_tools.php`, verifies counts and export integrity, then performs a full round-trip (export → re-import). Original database is always restored in `afterAll`.
- **#307** — Full visual audit spec: `testing/playwright/tests/visual-audit.spec.ts` covers every page in the page inventory, checks for literal Unicode escapes in rendered text (both `\uXXXX` and `\u{XXXX}` forms), verifies sticky header z-index (≥ 10), and captures screenshots for every page in desktop, mobile, and dark-mode viewports.

## [2.1.4] - 2026-04-12

### Fixed
- Migration locking (root cause): `ipam_db_init()` now calls `closeCursor()` on the `sqlite_master` check statement before running migrations. An unfinalised PDOStatement holds a WAL read mark at the **database level** — not just on the table it queried — which prevents `DROP TABLE` from entering WAL exclusive mode and causes `SQLITE_LOCKED` even in a single-process CLI context. This is the real reason v2.1.2/v2.1.3 retries all failed: the lock was intra-process, so sleeping never helped. Confirmed fix on OpenLiteSpeed (PHP 8.4).

## [2.1.3] - 2026-04-12

### Fixed
- Migration locking (improved): `apply_migrations()` now retries up to 60 times (1s sleep between attempts) when a migration fails with `SQLITE_LOCKED` or `SQLITE_BUSY`. This handles the case where `busy_timeout` does not retry DDL inside an existing transaction (e.g. `DROP TABLE` needing WAL exclusive mode while PHP-FPM connections are open). SQLite DDL is fully transactional — `ROLLBACK` cleanly undoes partial work so each retry starts from a clean state. Confirmed to fix v2.0.0→v2.1.x upgrades on live servers.

## [2.1.2] - 2026-04-12

### Fixed
- Migration locking: `apply_migrations()` now uses `BEGIN EXCLUSIVE` instead of `BEGIN DEFERRED` so SQLite's `busy_timeout` retry fires at transaction start rather than mid-transaction on the first DDL statement. This prevents `SQLITE_LOCKED` failures when the web server has connections open during an upgrade that rebuilds the subnets table (introduced in v2.1.0).

## [2.1.1] - 2026-04-12

### Fixed
- Nav bar ⌘K search shortcut was rendered as a duplicate nav item showing literal `\u2318K` text (PHP does not interpret `\u` escapes without curly braces). The standalone button and Search link are now merged into a single `🔎 Search ⌘K` nav link; clicking it opens the quick-search overlay (keyboard shortcut unchanged).

## [2.1.0] - 2026-04-12

### Added
- **#271** — VRF (Virtual Routing and Forwarding) support: new `vrfs` admin page (CRUD with name, description, route-distinguisher fields), `vrfs` DB table, `subnets.vrf_id` FK. Overlap detection is now VRF-scoped (same CIDR allowed in different VRFs). Subnet create/edit form includes a VRF picker; subnet list shows VRF badge. API: `?resource=vrfs` endpoint (GET/POST/PUT/DELETE); subnet response includes `vrf_id`/`vrf_name`; `?vrf_id=` filter on subnets.
- **#272** — Contacts as first-class objects: new `contacts` admin page (CRUD with name, email, phone, org, note fields), `contacts` DB table, `addresses.owner_contact_id` FK. Address create/edit form includes a contact typeahead (debounced, session-auth). Address list shows linked contact name with mailto link. Free-text `owner` field retained for legacy rows. API: `?resource=contacts` endpoint (GET/POST/PUT/DELETE, `?q=` fuzzy search usable from browser session); address response includes `owner_contact_id`/`owner_contact_name`; `?contact_id=` filter on addresses.
- **#253** — Global ⌘K / Ctrl+K search overlay: keydown listener opens a modal overlay; debounced 300ms fetch to `search.php?format=json`; ↑↓ keyboard navigation, Enter navigates, Escape/backdrop closes. New `format=json` branch on `search.php` returns up to 20 address results as JSON. ⌘K shortcut button added to nav bar.
- **#254** — Inline cell editing on address rows: clicking hostname, owner, note, or group cells on the address list opens an in-place `<input>`; Enter saves, Escape reverts, Tab moves to next editable cell. New `action=update_cell` JSON handler on `addresses.php` (write role only; CSRF protected; whitelisted fields; history logged).
- **#255** — Subnet visual hierarchy map view: "List / Map" toggle buttons on `subnets.php`. Map view renders a server-side indented tree with CIDR, description, and utilization bar per node. Toggle state persisted to `localStorage`. Graceful cap at 200 nodes.
- **#256** — Per-user column visibility preferences: gear dropdown on the addresses table lets users show/hide columns; state persisted to `localStorage` per table. At least one column always remains visible.
- **#257** — Dashboard pinnable widgets and site filter: ✕ button on each dashboard card hides the widget (persisted to `localStorage`). "Addresses by Site" panel has a live site-filter dropdown (saved to `localStorage`). "↺ Reset widgets" link in page-actions clears all `ipam_*` localStorage keys.

---

## [2.0.0] - 2026-04-11

### Added
- **#268** — VLANs as first-class managed objects: new `vlans` admin page (CRUD), `vlans` DB table, `subnets.vlan_fk` FK column. Subnet create/edit form includes a VLAN picker; subnet list shows VLAN name badge. API: `?resource=vlans` endpoint (GET list, GET by id, POST, PUT, DELETE); subnet response includes `vlan_name` field; `?tag=` filter on subnets/addresses.
- **#269** — Site hierarchy / region support: `sites.parent_id` self-referential FK (max depth 2). Sites admin page shows parent picker and indented tree. Subnet site picker shows hierarchy. API: sites response includes `parent_id` / `parent_name`; `?parent_id=` filter on sites endpoint.
- **#266** — Tags on subnets and addresses: new `tags` admin page (CRUD with colour picker), `tags`, `subnet_tags`, `address_tags` tables. Coloured tag badges on subnet and address lists. `?tag=` filter in API and search. CSV export includes `tags` column.
- **#267** — Auto-reserve network/broadcast/gateway IPs on subnet create: checkbox (pre-checked per `auto_reserve_network_broadcast` config key) and optional gateway field on subnet create form. Inserts network, broadcast, and gateway as `status=reserved` addresses automatically.
- **#270** — Email utilization threshold alerts: `check_utilization_alerts()` in `lib.php` sends email via `mail()` when subnet utilization crosses `alert_util_warn_pct` (default 80%) or `alert_util_crit_pct` (default 95%). 24-hour cooldown per subnet per level tracked in new `alert_state` table. Configurable via `alert_email`, `alert_util_warn_pct`, `alert_util_crit_pct`, `alert_interval_seconds` config keys.
- **#251** — Sortable columns on key tables: `addresses.php`, `search.php`, `users.php`, `audit.php` support `?sort=` and `?dir=` query parameters with ▲/▼/⇅ sort indicators; sort state preserved in pagination links.
- **#249** — Consistent breadcrumb trail on all pages via new `page_breadcrumb()` helper in `lib.php`; `.breadcrumbs` nav element renders on every page with correct hierarchy.
- **#250** — Mobile hamburger navigation: `#nav-toggle` button (☰) and `#nav-drawer` slide-in overlay with all nav links; toggled by `body.nav-open` class; closes on overlay click, nav link click, or ESC key. Desktop nav unchanged.
- **#248** — Sticky table headers: `thead th` elements use `position: sticky; top: var(--topbar-h)` so column headers stay visible while scrolling long tables.
- **#252** — Inline status toggle on address rows: clicking a `.status-badge` cycles `free→used→reserved→free` via a JSON POST to `addresses.php?action=update_status`; optimistic UI update with revert on error. Readonly users see a static badge.
- **#247** — Slide-in form drawer: add/edit forms on `subnets.php`, `addresses.php`, `users.php`, `sites.php`, `api_keys.php` open in a fixed right-panel drawer (`#form-drawer`) instead of inline page sections. Falls back gracefully when JS is unavailable.
- **#289** — reCAPTCHA v3 configurable action name: new `recaptcha_action` top-level config key (default `'login'`) controls the action string passed to `grecaptcha[.enterprise].execute()`; previously hardcoded.

### Security
- **#288** — GitHub Actions workflows now pin `actions/checkout` and all third-party actions to full commit SHAs instead of mutable version tags, preventing supply-chain attacks via tag mutation.

---

## [1.19.1] - 2026-04-12

### Fixed
- **reCAPTCHA Enterprise** — `enterprise.js` is now loaded instead of the standard `api.js` when `recaptcha_enterprise.enabled = true`, enabling proper Enterprise token generation.
- **reCAPTCHA Enterprise** — `grecaptcha.enterprise.execute()` / `grecaptcha.enterprise.ready()` are now called instead of the standard `grecaptcha.*` equivalents when Enterprise mode is active.
- **reCAPTCHA v3** — Removed premature `typeof grecaptcha !== "undefined"` guard in `DOMContentLoaded`; the guard prevented the submit-event listener from being attached when the async `api.js`/`enterprise.js` script had not yet executed, causing the form to submit with an empty token.
- **reCAPTCHA Enterprise** — Default `expected_action` changed from `'LOGIN'` to `'login'`; the action name passed to `grecaptcha.enterprise.execute()` is lowercase and the comparison is case-sensitive, so the mismatch caused every verification to fail.

## [1.19.0] - 2026-04-11

### Added
- **#264** — Address `mac` field: free-form MAC address (max 64 chars) stored per address; shown and editable in addresses, search, bulk update, and CSV import/export.
- **#262** — Address `expires_at` field: optional lease expiry date (`YYYY-MM-DD`); rows with a past expiry date are highlighted in the addresses table; filterable via `?expired=1` in the API.
- **#235** — Session activity log on user profile (`change_password.php`): last 10 auth events (login, logout, OIDC login, failed login) with IP and user agent; admins can view any user's activity via `?user_id=N`.
- **#236** — Config validation on boot: `ipam_validate_config()` checks key settings on every page load; admins see a dismissible banner for each invalid value.
- **#237** — Configurable utilization warn/critical thresholds: `utilization_warn` and `utilization_critical` config keys replace the previous hardcoded values (80/95); applied on dashboard and subnets list.
- **#265** — API bulk write endpoints: `POST ?resource=addresses&bulk=1` and `POST ?resource=subnets&bulk=1` accept a JSON array of up to 500 items; partial success returns HTTP 207; each item reports `{success, id?, error?}`.
- **#263** — IPv6 unassigned IP tracking: `unassigned.php` and `export_unassigned.php` now support IPv6 subnets (capped at 256 addresses); API `resource=unassigned` also supports IPv6.
- **#284** — reCAPTCHA Enterprise API support: new `recaptcha_enterprise` config block enables the GCP Enterprise Assessment API for backend token verification when `login_protection.method = 'recaptcha'`.
- **#246** — Subnet list deep-link: CIDR in the subnets table now links directly to the addresses view for that subnet.
- **#245** — Empty state CTA buttons: all empty-state divs across subnets, addresses, sites, audit, and search pages now include contextual action buttons.

### Changed
- **#238** / **#230** — PHPStan static analysis raised to level 9 (strictest); zero errors; empty baseline.
- **#243** — Audit log default page size changed from 20 to 50 rows.
- **#244** — Audit log Details column truncated with ellipsis (`max-width: 300px`); full text shown on hover via `title` attribute.

## [1.18.0] - 2026-04-09

### Added
- **#232** — API subnets: `site_id` integer field now included in GET subnet response objects alongside the existing `site` name field.
- **#258** — API subnets: `?counts=1` opt-in adds an `address_counts` object (`used`, `reserved`, `free`, `total`, `utilization_pct`) to each subnet in the GET response.
- **#231** — API addresses: `?site_id=` filter on GET addresses; returns addresses in subnets belonging to the specified site.
- **#260** — API: new `resource=unassigned` endpoint returns paginated unassigned IPv4 host IPs for a subnet; requires `?subnet_id=`; limited to /24 or smaller (≤256 assignable IPs).
- **#227** — Address history CSV export (`export_address_history.php`): per-address change history download linked from the Address History page.
- **#259** — Subnet utilization summary CSV export (`export_subnet_utilization.php`): one row per subnet with used/reserved/free/total/utilization% columns.
- **#261** — Cross-subnet all-addresses CSV export: `export_addresses.php` now supports omitting `subnet_id` (write-role users), producing a full export across all subnets with site column prepended.
- **#241** — Dismissible security warning banners on `db_tools.php`, `import_csv.php`, and `demo_gate.php`; dismissed per-session via `?dismiss_warning=<context>`.
- **#234** — `status.php` health-check response now includes `schema_version` (latest applied migration version); hidden when `status_hide_version` is set in config.

### Changed
- **#233** — Audit log UI (`audit.php`): Client IP column now displays `—` for null values instead of an empty cell.
- **#242** — DHCP Pools moved from the main navigation bar into the Admin dropdown.
- **#229** — PHPStan static analysis level raised from 6 to 7; all new type errors resolved (proper handling of `string|false` from `inet_pton`, `json_encode`, `unpack`, `PDO::query`; proper list typing for `applied_migrations` and `oidc_jwks`; `strtotime` casts).

### Fixed
- **#276** — `demo_gate.php` asset cache-buster URLs updated to match the running version (was stuck at `v=1.15.1`).
- **#240** — `addresses.php`: empty state when no subnet is selected now shows a navigation link to Subnets instead of a bare "No subnet selected." message.
- **#239** — `address_history.php`: missing or invalid `address_id` now renders a styled error page via `page_header()`/`page_footer()` instead of a bare HTTP response.

## [1.17.0] - 2026-04-08

### Added
- **#225** — Read-only API keys: new `is_readonly` flag on API keys blocks write operations (`POST`/`PUT`/`DELETE`) with a 403 response; toggle button in the admin UI lets admins flip any key between read-only and read-write without deactivating it.
- **#225** — API key description field: optional free-text description stored alongside the key name; shown in the keys table for documentation purposes.
- **#226** — Subnets API filter params: `GET /api.php?resource=subnets` now accepts `ip_version=4|6` and `vlan_id=1–4094` query parameters; both params validate on input (400 for invalid values) and can be combined with each other and the existing `site_id` filter.

### Changed
- **#224** — PHPStan static analysis level raised from 5 to 6; all 99 `missingType.iterableValue` errors resolved with `@param`/`@return` PHPDoc annotations across `lib.php`, `api.php`, `subnets.php`, `import_csv.php`, `migrations.php`, `init.php`, `address_history.php`, `search.php`, and `unassigned.php`.

## [1.16.0] - 2026-04-08

### Added
- **#216** — `apple-touch-icon.webp` added to `assets/`; `page_header()` now emits a WebP `<link rel="apple-touch-icon">` before the existing PNG fallback, matching the favicon WebP+PNG pattern.

### Fixed
- **#217** — `import_csv.php`: dead `if ($msg)` success block at the top of the rendered page removed; `$msg` is only assigned inside the Step 4 processing block that follows, so this check could never be true.
- **#218** — `import_csv.php`: redundant `if ($step === 4)` wrapper around the Step 4 block removed; Steps 1–3 each `exit` before reaching that point, so the condition is always true.
- **#219** — `import_csv.php`: dead `if ($entry['resolved_cidr'] === null)` guard removed; every non-`continue` code path assigns a `string` to `resolved_cidr` before line 266, making the null check unreachable.

## [1.15.0] - 2026-04-08

### Fixed
- **#204** — `demo_reset.php` and `demo_seed.php` passed an extra `$config` argument to `ipam_db_init()`, which takes only one parameter; removed the stray arg.
- **#205** — `import_csv.php` had an unreachable `page_footer()` call after the step dispatcher; all code paths exit before reaching it; removed the dead call.
- **#206** — `bulk_update.php` had a dead `if ($msg)` template block; `$msg` is initialised to `''` and never reassigned on that path; block removed.
- **#207** — `unassigned.php` same dead `if ($msg)` block removed.
- **#208** — `lib.php` and `api.php`: removed redundant `?? ''` guards on `explode()[0]` (offset 0 always exists) and removed redundant `!== null` after `isset()`.
- **#209** — `lib.php`: removed redundant `?? ''` on `$role` in `page_header()` (assigned unconditionally at function entry); added `@phpstan-impure` annotations to `housekeeping_should_run()` and `backup_is_due()`.
- **#182** — Card `margin-top` inside CSS Grid containers zeroed out; the `.card + .card` adjacency margin was reducing card height by 16 px in the Database Tools grid despite `gap` already providing the spacing.

## [1.14] - 2026-04-06

### Fixed
- **#193** — `oidc_fail()` in OIDC callback was missing required `$db` parameter, causing a TypeError crash on username collision after 5 retries.
- **#194** — Bulk update pagination referenced non-existent `total_pages` key (correct key: `pages`), so pagination controls never rendered.
- **#197** — `upgrade.sh` utility functions moved above first call site; `/home/*` path guard relaxed to allow subdirectories.
- **#198** — API subnet update now calls `find_parent_site_id()` to enforce site inheritance (matching web UI).
- **#199** — API sites update checks for duplicate name (409); sites delete wrapped in transaction; `api_audit()` now records `user_agent`.
- **#200** — `ipam_db_init()` sentinel path now calls `ensure_audit_log_table()` for self-healing; highlight-row CSS animation preserves zebra stripes.

### Security
- **#195** — SQL import `CREATE TRIGGER` now restricted to `RAISE(ABORT)` patterns only, preventing arbitrary SQL injection via crafted trigger bodies.
- **#196** — `prune_audit_log()` rewritten to drop triggers + DELETE + recreate (instead of `ALTER TABLE RENAME` + `SELECT *` which is unsafe in SQLite transactions).

## [1.13] - 2026-04-05

### Added
- **#174** — REST API `resource=search` endpoint (text search across addresses), `resource=audit` endpoint (paginated audit log with action/date filters), sites write API (POST/PUT/DELETE), `updated_at` field in address responses.
- **#176** — Dashboard quick action links, weekly/monthly growth trend metrics, responsive grid layout.
- **#168** — "Next available IP" shown on the addresses page for IPv4 subnets with a one-click "Use" link.
- **#162** — Search page now also searches subnets by CIDR and description.
- **#164** — Subnet CSV export (`export_subnets.php`) with optional site and IP version filters.
- **#167** — Address history renders before/after changes as a field-by-field diff table instead of raw JSON.
- **#175** — Client-side CIDR and IP validation via `data-validate` attributes with JavaScript validation on blur.
- **#181** — Database import shows a CSS spinner overlay during processing and reports statement count and elapsed time on completion.

### Changed
- **#182** — Export/Import cards on Database Tools page now stretch to equal height via CSS Grid `align-items: stretch`.
- **#166** — All data tables wrapped in `.table-wrap` for horizontal scroll on mobile. Subnet tree indentation collapses on small screens.
- **#165** — Dashboard "Top IPv4 Subnets" now sorted by utilization percentage instead of absolute address count.
- **#163** — Search results include "Edit" link that navigates to the address with the row highlighted.
- **#183** — SQL import file size limit now configurable via `import_sql_max_mb` in config.php (default 200 MB).
- **#171** — `prune_audit_log()` rewritten with subquery approach. Audit log DDL extracted into `ensure_audit_log_table()` helper (deduplicated from 4 locations).

### Fixed
- **#186** — Host header validation regex too strict (v1.12 regression). Widened to accept underscores in hostnames and IPv6 bracket notation.
- **#179** — Six code quality cleanups: removed redundant config.php load, duplicate `global $config`, uninitialised `$formError`, dead `$auditQs` variable, exposed PDO exceptions, added sentinel file for bootstrap queries.
- **#178** — OIDC username collision retry now loops up to 5 attempts with incrementing suffix.
- **#177** — DHCP pool page accessible to readonly users (view-only); write-access check moved inside POST handlers.
- **#173** — Inline theme-priming script replaced with a `<meta>` tag + app.js reader, fixing CSP `script-src` violation.
- **#169** — Added breadcrumb navigation to bulk_update.php and sites.php.

### Security
- **#170** — Status endpoint version field now conditional on `status_hide_version` config. API key via query parameter emits `Deprecation` response header.

## [1.12] - 2026-04-05

### Added
- **#137** — Subnet overlap confirmation: creating or updating a subnet that overlaps existing subnets shows a confirmation page. API responses include a non-blocking `warnings` array.
- **#154** — Flash messages survive POST-redirect-GET via `flash_set()`/`flash_get()`. Applied to sites, addresses, API keys, and subnet overlap warnings.
- **#157** — CSV export of search results now respects site and IP version filters.
- **#151** — Indexes on `audit_log(action)` and `audit_log(created_at)` for faster filtering and date-range queries.

### Changed
- **#149** — Subnet tree builder rewritten from O(N²) to O(N log N) using a sorted-stack algorithm.
- **#150** — IPv4 utilization replaced with single `GROUP BY` aggregate query.
- **#152** — Database export refactored into streaming `ipam_db_dump_stream()`.
- **#160** — Bulk update page paginated to prevent memory exhaustion on large subnets.
- **#147** — API audit logging now uses `client_ip()` (respects proxy trust config).
- **#148** — API address create/update/delete now record entries in `address_history`.
- **#153** — API subnet creation inherits `site_id` from the tightest enclosing parent subnet.

### Fixed
- **#158** — Dashboard empty-state text corrected from "/8–/30" to "/8–/32".
- **#159** — Audit action names normalised: `api_key.*` → `apikey.*`, `user.password_change` → `user.change_password`. Migration updates existing rows.
- **#161** — POST action fall-through in `addresses.php` and `sites.php` fixed with `if`→`elseif` chains.

### Security
- **#146** — Disabling or demoting the last active admin account is now blocked.
- **#155** — SQL import whitelist: only `INSERT`, `CREATE`, `DROP`, `DELETE` allowed. `ATTACH`, `DETACH`, `LOAD_EXTENSION` blocked.
- **#156** — Host header validated before HTTPS redirect when `base_url` is not configured.

## [1.11] - 2026-03-15

### Added
- **#133** — Group field on addresses: optional free-text `group` field shown as badge, searchable, in CSV exports/imports, and REST API.
- **#134** — Browser tab title now reads `{AppName} — {Page}`.
- **#135** — Configurable `app_name` in config.php.
- **#136** — App name in nav bar brand link and login page.
- **#138** — VLAN ID (1–4094) on subnets with badge in subnet list and REST API.
- **#139** — REST API write support: POST/PUT/DELETE for addresses and subnets.
- **#140** — Per-user theme persistence in `users.theme`.
- **#141** — Session idle timeout notice on login page.
- **#142** — DHCP pool view/edit: hostname, owner, note columns; inline Edit/Delete; range headers.
- **#143** — Application logo and favicons.

## [1.10] - 2026-02-20

### Fixed
- **#130** — CSV import PHP 8.4 deprecation: `str_getcsv`/`fgetcsv` now pass `''` as escape argument.
- **#127** — "Add Subnet" card duplicate `class` attribute merged.
- **#128** — Audit log filter labels now inline with controls.
- **`.htaccess`** — Removed global CSP header that conflicted with PHP per-page CSP.

## [1.9] - 2026-02-01

### Added
- **#124** — Optional login form bot protection (`honeypot`, `time_check`, `turnstile`, `hcaptcha`, `recaptcha`, `friendly_captcha`).
- **#125** — Optional demo mode pre-login challenge gate.
- **#114** — API history endpoint returns 200 (not 404) for deleted addresses.
- **#115** — Demo mode login rate limiting.
- **#119** — Admin notice when config keys could not be auto-saved.

### Changed
- **#116** — `find_containing_subnet()` uses per-request static cache for large CSV imports.

### Fixed
- **#107** — OIDC auto-link email fallback was matching usernames.
- **#109** — Login timing side-channel for username enumeration.
- **#111** — OIDC username claims not sanitised.
- **#108** — `validate_password_complexity()` used `strlen()` instead of `mb_strlen()`.
- **#118** — Dashboard top subnets excluded /31 and /32.
- **#120** — `prune_audit_log()` used `$db->quote()` instead of prepared statement.
- **#122** — `demo_reset_db()` blocked by append-only trigger.

### Security
- **#112** — CSP `style-src 'unsafe-inline'` removed; all 122 inline styles replaced with CSS classes.
- **#113** — `backup_dir()` path traversal via relative paths.
- **#117** — Failed login audit no longer logs submitted username.

## [1.8] - 2026-01-15

### Fixed
- **#104** — CSRF mismatch now redirects to login instead of plain-text error.
- **#106** — HTTP→HTTPS redirect used `HTTP_HOST` directly; new `base_url` config option.
- **#98** — `IPAM_VERSION` double-include fatal error.
- **#103** — DHCP pool missing demo mode guard.
- **#99** — `data/.htaccess` OLS compatibility (RewriteRule instead of FilesMatch).
- **#100** — Root `.htaccess` OLS compatibility.
- **#101** — `upgrade.sh` now always overwrites `data/.htaccess`.

## [1.7] - 2025-12-20

### Added
- **#86** — Server-side sortable columns on all data tables.
- **#87** — `data/.htaccess` hardened with extension blocking.
- **#88** — nginx and Caddy configuration docs.
- **#90** — Opt-in demo mode with nightly reset and realistic seed data.

### Fixed
- **#85** — User creation form field alignment with password managers.
- **#89** — Audit page Export/Apply button alignment.

## [1.6] - 2025-12-01

### Added
- **#82** — `address_history_retention_days` config option.
- **#81** — CSV exports include UTF-8 BOM for Excel.
- **#75** — Dark mode calendar picker icon visibility.

### Fixed
- **#77** — Users admin page error messages swallowed after v1.5 refactor.
- **#73** — SSO-only toggle state on page load.
- **#78** — Subnet overlap warnings XSS.
- **#79** — Unassigned quick-add raw PDO exception leak.

### Security
- **#74** — All inline JS event handlers removed; CSP `script-src 'self'` compliance.
- **#80** — OIDC HTTP response reads capped at 1 MB.

## [1.5] - 2025-11-15

### Added
- **#59** — Password complexity returns all failing rules at once.
- **#60** — User creation form re-populates on validation failure.
- **#61** — OIDC `auto_link` separated from `auto_provision`.
- **#68** — API 429 includes `Retry-After` and `X-RateLimit-Limit` headers.
- **#70** — Audit log date filters (From / To).
- **#67** — Security headers on all responses (CSP, X-Frame-Options, etc.).

### Fixed
- **#69** — Duplicate subnet/address shows specific "already exists" message.

### Security
- **#64** — `X-Forwarded-For` validated when `proxy_trust` enabled.
- **#65** — OIDC callback error parameters sanitised.
- **#66** — OIDC auto-provision validates `netops` role.

## [1.4] - 2025-11-01

### Added
- **#41** — `netops` role with write access but no user/key management.
- **#38** — SSO-only accounts with unusable password hash.
- **#39** — Password complexity and rotation policy.
- Audit log access opened to all roles.

## [1.3] - 2025-10-15

### Added
- **#37** — Collapsible site groups on Subnets page with localStorage persistence.
- **#55** — API subnets pagination.
- **#56** — Search results Site column.

### Fixed
- **#53** — XSS in inherited site name warning.
- **#52** — Version comparison for patch releases.
- **#48** — Rate limiting empty IP fallback.
- **#54** — Subnet delete now records cascade-deleted address count.
- **#47** — Search query capped at 500 characters.

## [1.2.3] - 2025-10-05

### Fixed
- Site group collapse removed from v1.2.x due to rendering issues; restored to static divs.

## [1.2.2] - 2025-10-04

### Fixed
- Site groups replaced with div-based JS toggle after `<details>` browser issues.

## [1.2.1] - 2025-10-03

### Fixed
- Site groups blank due to `<h2>` inside `<summary>` breaking `<details>` rendering.

## [1.2] - 2025-10-01

### Added
- **#35** — Parent subnet utilization bars roll up descendant counts.
- **#37** — Collapsible site groups with `<details>` elements.
- **#40** — Config deep-merge for nested blocks on upgrade.
- **#44** — API rate limiting.

### Fixed
- **#43** — LIKE wildcard escaping in search/filter queries.
- **#45** — OIDC SSL peer verification.
- **#46** — Hostname/owner/note length enforcement.
- **#42** — SSO-only accounts no longer shown password change form.

## [1.1] - 2025-09-15

### Added
- **#22** — Free-status addresses excluded from utilization.
- **#23** — Bulk edit shows unconfigured IPs with quick-add.
- **#25** — `ipam_update_check()` memoised.
- **#28** — Default password warning banner.
- **#29** — API address history endpoint.
- **#30** — Subnet node address count badges.
- **#31** — Audit log category filter.

### Fixed
- **#21** — Update-check cache not invalidated after upgrade.
- **#26** — Fresh installs now stamp all migrations.
- **#27** — `find_containing_subnet()` returned broadest instead of tightest parent.

## [1.0] - 2025-09-01

### Added
- Audit log retention (`audit_log_retention_days` config option).
- Health check endpoint (`status.php`).

### Fixed
- HTML escaping in Database Tools.
- OIDC claim values capped at 255 characters.

### Security
- Name/email `maxlength` enforced in UI and server-side.

## [0.15] - 2025-08-15

### Added
- Config auto-population of missing keys on boot.
- Automatic database backups (daily/weekly, configurable retention).
- Database Tools admin page (export, import, manual backup).
- Mobile-responsive CSS (768px and 480px breakpoints).
- Dismissible update banner; `notify_prerelease` option; 24-hour cache TTL.

## [0.14] - 2025-08-01

### Added
- DHCP pool reservation tool (bulk reserve/clear IPv4 ranges).
- Update check badge in footer.
- User management: disable self-targeting guards, last login column.
- Subnet utilization colour-coded progress bars.
- OIDC emergency access controls (`hide_emergency_link`, `disable_emergency_bypass`).
- Application renamed to Simple PHP IPAM.

## [0.13] - 2025-07-15

### Added
- User management: name/email fields, delete users, disable/enable, manual OIDC linking.
- OIDC: `preferred_username` claim, name/email sync, auto-link by username then email.
- `disable_local_login` config option.
- Child subnets inherit site from tightest parent.

## [0.12] - 2025-07-01

### Added
- User dropdown menu (username + role badge on far-right of nav).
- OIDC Authorization Code + PKCE authentication (pure PHP, no Composer).
- Auto-provisioning and auto-linking of OIDC users.

### Fixed
- `api.php` addresses resource used `description` instead of `note`.

## [0.11] - 2025-06-15

### Added
- Login rate limiting (IP-based lockout).
- Session idle timeout.
- Admin dropdown menu.
- Single theme cycle button.
- Read-only JSON REST API with Bearer token auth.
- API key management admin page.

## [0.10] - 2025-06-01

### Added
- CSV exports for addresses, search results, audit log, unassigned IPs, and import reports.
- CSV import safety: dry-run plan, row-level report, duplicate/conflict detection.

[3.25.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.24.0...v3.25.0
[3.24.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.23.0...v3.24.0
[3.23.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.22.3...v3.23.0
[3.22.3]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.22.2...v3.22.3
[3.22.2]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.22.1...v3.22.2
[3.22.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.22.0...v3.22.1
[3.22.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.21.1...v3.22.0
[3.21.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.21.0...v3.21.1
[3.21.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.20.0...v3.21.0
[3.20.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.19.1...v3.20.0
[3.19.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.19.0...v3.19.1
[3.19.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.18.0...v3.19.0
[3.18.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.17.0...v3.18.0
[3.17.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.16.0...v3.17.0
[3.16.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.15.2...v3.16.0
[3.15.2]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.15.1...v3.15.2
[3.15.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.15.0...v3.15.1
[3.15.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.14.0...v3.15.0
[3.14.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.13.0...v3.14.0
[3.13.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.12.0...v3.13.0
[3.12.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.11.0...v3.12.0
[3.11.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.10.0...v3.11.0
[3.10.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.9.0...v3.10.0
[3.9.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.8.1...v3.9.0
[3.8.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.8.0...v3.8.1
[3.8.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.7.1...v3.8.0
[3.7.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.7.0...v3.7.1
[3.7.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.6.0...v3.7.0
[3.6.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.5.0...v3.6.0
[3.5.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.4.1...v3.5.0
[3.4.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.4.0...v3.4.1
[3.4.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.3.0...v3.4.0
[3.2.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.1.0...v3.2.0
[3.1.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.0.1...v3.1.0
[3.0.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v3.0.0...v3.0.1
[3.0.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.14.0...v3.0.0
[2.14.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.13.0...v2.14.0
[2.13.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.12.0...v2.13.0
[2.12.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.11.0...v2.12.0
[2.11.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.10.0...v2.11.0
[2.10.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.9.2...v2.10.0
[2.9.2]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.9.1...v2.9.2
[2.9.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.9.0...v2.9.1
[2.9.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.8.0...v2.9.0
[2.8.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.7.0...v2.8.0
[2.7.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.6.0...v2.7.0
[2.6.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.5.2...v2.6.0
[2.5.2]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.5.1...v2.5.2
[2.5.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.5.0...v2.5.1
[2.5.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.4.1...v2.5.0
[2.4.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.4.0...v2.4.1
[2.4.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.3.0...v2.4.0
[2.3.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.2.1...v2.3.0
[2.2.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.2.0...v2.2.1
[2.2.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.1.5...v2.2.0
[2.1.5]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.1.4...v2.1.5
[2.1.4]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.1.3...v2.1.4
[2.1.3]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.1.2...v2.1.3
[2.1.2]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.1.1...v2.1.2
[2.1.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.19.1...v2.0.0
[1.19.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.19.0...v1.19.1
[1.19.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.18.0...v1.19.0
[1.18.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.17.0...v1.18.0
[1.17.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.16.0...v1.17.0
[1.16.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.15.0...v1.16.0
[1.15.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.14...v1.15.0
[1.14]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.13...v1.14
[1.13]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.12...v1.13
[1.12]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.11...v1.12
[1.11]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.10...v1.11
[1.10]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.9...v1.10
[1.9]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.8...v1.9
[1.8]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.7...v1.8
[1.7]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.6...v1.7
[1.6]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.5...v1.6
[1.5]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.4...v1.5
[1.4]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.3...v1.4
[1.3]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.2.3...v1.3
[1.2.3]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.2.2...v1.2.3
[1.2.2]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.2...v1.2.1
[1.2]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.1...v1.2
[1.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.0...v1.1
[1.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v0.15...v1.0
[0.15]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v0.14...v0.15
[0.14]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v0.13...v0.14
[0.13]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v0.12...v0.13
[0.12]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v0.11...v0.12
[0.11]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v0.10...v0.11
[0.10]: https://github.com/seanmousseau/Simple-PHP-IPAM/releases/tag/v0.10
