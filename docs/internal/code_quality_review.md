# Code Quality Review — Simple-PHP-IPAM (non-backup)

**Status:** APPROVED 2026-04-29 — milestone allocation locked, 87 issues filed across v3.26.0–v3.30.0.
**Scope:** Top-to-bottom code-quality audit of the codebase **excluding** backup/restore, which has its own dedicated review at `docs/internal/backup_overhaul.md` §B (98 findings, mapped to v3.20–v3.26).
**Goal:** All findings here must be addressed before **v4.0.0**.
**Single source of truth:** This document. Every issue filed against any milestone v3.26.0–v3.x must reference a finding ID in this file. Decisions made during triage are recorded inline (see §6).

---

## 1. Methodology

Four parallel audits were run on disjoint slices of the codebase:

| Audit | Scope | Findings |
|---|---|---|
| **A — `lib.php`** | 9,463 lines, ~260 functions across 25 concern areas (auth, OIDC, MFA, scanner, webhooks, settings, IP math, dialect helpers, dashboard, mail, custom fields, etc.) | 42 |
| **B — Pages + `api.php`** | 81 page-level PHP files + 2,993-line REST API (auth pages, admin pages, data pages, scanner, cron, init.php, all `views/*.php` non-backup) | 34 |
| **C — Migrations + Schema** | `migrations.php` (2,597 lines, 49 closures), `schema.sql` / `schema.mysql.sql` / `schema.pgsql.sql`, `dialects/*`, `tools/generate-data-dictionary.php`, schema-related tests | 18 |
| **D — Frontend + Tests/CI** | `assets/app.css` (1,981L), `assets/app.js` (2,733L), `assets/api-spec.yaml`, `tests/*`, `testing/*`, `phpunit.xml`, `phpstan.neon`, `.phpcs.xml`, `.semgrep/rules.yml`, `.github/workflows/*.yml`, `composer.json` | 21 |

Backup/restore code (BackupEngine, RestoreEngine, destinations, S3/SFTP clients, encryption v1/v2/v3, retention/GFS, restore wizard, restore_*, ipam_backup_*, lib/backup.php, lib/*Client.php, views/destination_form_*, views/restore_*, all related tests) was **explicitly excluded** from every audit and remains tracked in `backup_overhaul.md`.

**Total: 115 findings across 4 audits.**

Severity rubric (consistent with backup_overhaul.md §B):

- **P0** — production-affecting bug or security issue today
- **P1** — significant smell or latent bug; ship before v4.0.0
- **P2** — maintainability / architectural debt; ship before v4.0.0
- **P3** — nit; nice-to-have

---

## 2. Verdict — overall

**The non-backup codebase is in better shape than its size suggests.** Security fundamentals (CSRF, output escaping, role guards, prepared statements, post-login redirect validation, CLI guards, FK-safe migrations) are consistently applied. There is **no P0 SQLi, XSS, auth bypass, open redirect, or data-loss vector** in the non-backup scope.

The dominant problems are **architectural sprawl** and a **handful of correctness gaps**:

- `lib.php` is a 9.4k-line monolith hosting 25 distinct concerns
- `api.php` is a 3k-line file with 56 hand-rolled handlers
- `import_csv.php` is a 1.1k-line god page
- `migrations.php` repeats the same three-driver `if/elseif/elseif` pattern 49 times
- Webhook subsystem has the highest concentration of correctness bugs (lastInsertId, event-LIKE substring match, SSRF gaps)
- OIDC has zero unit-test coverage — the riskiest hand-rolled crypto path in the project
- `php-qa.yml` only triggers on `pull_request`, not push to dev/main — direct main pushes (release-bundle commits) bypass CI
- 3 PHPUnit fixtures silently `markTestSkipped` when not committed, making the upgrade-replay job a potential no-op

**Severity distribution: 3 P0, 36 P1, 51 P2, 25 P3.**

---

## 3. Findings — Audit A: `lib.php` (non-backup)

### A.P0
None.

### A.P1 (10 findings — security/correctness)

| ID | Title | Location | Summary |
|---|---|---|---|
| A1 | CSS injection via `tags.colour` in render helpers | `lib.php:5237–5248`, `5205–5216` | `e()`-escaped colour inlined into `style="background:$bg"`; defence-in-depth missing if `tags.create` validation ever loosens. Move to CSS variable. |
| A2 | `lastInsertId()` raw call in webhook dispatch | `lib.php:6815` | Returns 0 on Postgres → subsequent UPDATE-by-id targets id=0; `delivered_at` never set. Replace with `ipam_last_insert_id()`. |
| A3 | Webhook `events LIKE '%"event"%'` substring match | `lib.php:6768–6774` | Future event names that are prefixes of others fire unrelated subscribers. Encode events as JSON array + use `JSON_CONTAINS`, or normalise to a join table. |
| A4 | `ipam_validate_webhook_url()` SSRF gaps | `lib.php:6688–6701` | Missing `0.0.0.0/8`, `100.64/10` (CGNAT), IPv4-mapped IPv6 (`::ffff:127.0.0.1`), multicast. `0.0.0.0` routes to localhost on Linux. |
| A5 | `ipam_setting()` swallows every Throwable | `lib.php:2034–2036` | Connectivity blip silently returns the registry default for security-relevant settings (session idle, MFA enabled, password policy). Log + rethrow on non-"missing column" PDO errors. |
| A6 | `ipam_app_base_url()` not memoised; throws when unset | `lib.php:8012–8021` | Caller try/catches Throwable → password reset / email OTP / verification silently fail with no operator-visible signal. |
| A7 | `oidc_pkce_pair()` PKCE verifier at RFC floor | `lib.php:7506–7511` | 43 chars exactly. Bump to `random_bytes(64)`. Not a bug; cosmetic comfort. |
| A8 | `ipam_email_otp_verify()` race window | `lib.php:9033–9092` | "Fetch attempts → verify → increment" is non-atomic; concurrent wrong-code requests under-count attempts. Wrap in transaction or use conditional `UPDATE … WHERE attempts=:prev`. |
| A9 | `ipam_setting_set()` MySQL `GET_LOCK` truncates names >64 chars | `lib.php:2091–2095` | Long key names (tenant id + 50-char setting key) collide. Hash the lock name. |
| A10 | `client_ip()` naïvely trusts first XFF entry | `lib.php:960–972` | Multi-proxy chains return the wrong client. Walk RTL with trusted-proxy CIDR list. |

### A.P2 (23 findings — maintainability)

Highlights (full list in audit transcript):

| ID | Title | Location |
|---|---|---|
| A11 | `lib.php` is a monolith — split into ~12 sub-files (`lib/auth.php`, `lib/oidc.php`, `lib/mfa.php`, `lib/scanner.php`, `lib/webhook.php`, `lib/settings.php`, `lib/dialect_helpers.php`, `lib/dashboard.php`, `lib/email.php`, `lib/ip_math.php`, `lib/custom_fields.php`, `lib/dhcp.php`) | file-level |
| A12 | `ipam_setting_definitions()` is 840 lines of array literal — extract to `settings_registry.php` | `lib.php:1036–1875` |
| A13 | `ipam_setting_set()` 117 lines mixing audit, lock, txn, upsert, cache-bust — decompose | `lib.php:2065–2182` |
| A14 | `demo_seed_data()` 390 lines of seed records — move to data file | `lib.php:5432–5824` |
| A15 | `page_header()` 230 lines mixing nav, CSP, theme, sidebar — finish the partial extraction | `lib.php:6941–7171` |
| A16 | `ipam_db_init()` duplicates bootstrap-admin INSERT in two branches | `lib.php:430–447` vs `463–470` |
| A17 | Inconsistent SQL `now()` — 6 hand-rolled spots vs `ipam_dialect()->now()` | `lib.php:8828`, `9296`, etc. |
| A18 | Inconsistent driver detection — some use Dialect, some `getAttribute(ATTR_DRIVER_NAME)` | `lib.php:141, 8828, 8868, 9295` |
| A19 | `ipam_api_key_rate_limit_check()` bespoke MySQL/non-MySQL upsert branching — promote to Dialect | `lib.php:8868–8879` |
| A20 | `ipam_totp_verify_backup_code()` O(N) `password_verify` iteration | `lib.php:8823–8839` |
| A21 | `audit()` returns void; failures swallowed by callers | call-sites in webhook + housekeeping |
| A22 | `ipam_send_mail()` swallows native `mail()` failures | `lib.php:3149–3153` |
| A23 | `prune_audit_log()` 3-arm driver branching — promote to `Dialect::with_append_only_bypass()` | `lib.php:2960–2996` |
| A24 | `ipam_db_dump_stream` / `ipam_db_dump` duplicate serialization logic | `lib.php:4566–4670` |
| A25 | `ipam_render_dhcpd_conf()` / `ipam_render_kea_json()` 160 lines — extract to `lib/dhcp.php` | `lib.php:8214–8371` |
| A26 | `ipam_passkey_dispatch_challenge()` mutates `$ac` by ref without comment | `lib.php:9217–9224` |
| A27 | `ipam_setting_cache_storage()` confused multi-mode function — split | `lib.php:2214–2247` |
| A28 | `ipam_normalise_version()` no PHPUnit coverage of pre-release strings | `lib.php:7233` |
| A29 | `recaptcha_enterprise_verify()` 60 lines of inline HTTP construction — extract `ipam_http_post_json()` | `lib.php:4741–4801` |
| A30 | `auto_reserve_subnet_ips()` partial-commit on audit failure — wrap in txn | `lib.php:5037–5099` |
| A31 | `ipam_dashboard_kpis()` `pct_used` is misleading — based on tracked addresses, not capacity | `lib.php:9410–9434` |
| A32 | Add `Dialect::increment_or_insert()` to consolidate finding A19 | `dialects/Dialect.php` |
| A33 | `to_int()`/`to_str()` defined twice with `function_exists` guard — extract to `lib/coerce.php` | `lib.php:520` |

### A.P3 (9 nits)

Numbered A34–A42: `e()` global naming, format_bytes precision, `@`-suppressions on file ops, `display_datetime` 1-line passthrough, redirect-stash duplicate validation (×6 checks), `oidc_jwks()` cache write success not checked, dead `unset($config)` parameters, scattered string-length sentinels (1024/2048/200), miscellaneous.

---

## 4. Findings — Audit B: Pages + `api.php`

### B.P0
None.

### B.P1 (12 findings)

| ID | Title | Location |
|---|---|---|
| B1 | `set_theme.php` POST without `csrf_require()` | `set_theme.php:3–34` |
| B2 | `api.php` audit-log accepts raw `from`/`to` strings — fails on Postgres for malformed input | `api.php:1457–1460` |
| B3 | `api.php` audit-log filter `LIKE '%action%'` vs `audit.php` allowlist `LIKE 'prefix.%'` — pick one | `api.php:1454` vs `audit.php:78–79` |
| B4 | No rate limiting on `forgot_password.php` / `reset_password.php` / `email_otp_verify.php` | files |
| B5 | `api.php` JSON envelope: `?envelope=1` mixes flat and envelope shapes per endpoint | `api.php:357–382` |
| B6 | `api.php` 2,993 lines / 56 handlers / no extraction layer — all share the same `where[]/params[]/count/paginate` pattern; extract `api_paginated_query()` helper | file-level |
| B7 | `scan_schedule_save.php` handler duplicated in `subnets.php` with different field names — explicitly noted in file header | `scan_schedule_save.php:8–10` |
| B8 | `addresses.php` 845L + `subnets.php` 856L — controller and render mixed; extract `*_handle_post()` | files |
| B9 | `change_password.php` 784L embeds 6 separate POST actions inline — collapse to dispatch table | `change_password.php:82, 223, 258, 292, 318, 347` |
| B10 | `api_addresses_bulk_create` / `api_subnets_bulk_create` — same envelope, ~100 lines each — extract `api_bulk_create()` | `api.php:1569, 1678` |
| B11 | `audit.php` POST handler order — admin gate inside POST block, after `csrf_require`. Reorder. | `audit.php:11` |
| B12 | `scan_schedule_save.php` returns 405 with no body vs JSON shape elsewhere | `scan_schedule_save.php:19–22` |

### B.P2 (12 findings)

B13–B24: `import_csv.php` god page (1,144 lines, 4-step state machine inline — extract `lib/csv_import.php`); `api.php` `?envelope=1` parsing inconsistency; `api_subnets()` raw `$countsSelect`/`$countsJoin` SQL strings; `addresses.php` possibly-dead `$addrDistinctSiteCount`; `audit.php` pluralisation duplication; `users.php` self-protection guards rely on string equality; `migrate.php`/`tmp_cleanup.php` duplicate CLI guard; `oidc_callback.php` double-cast on error code; `scan_run.php` vs `cron.php` overlap; `forgot_password.php` shape divergence; tag/contact join-table audit coverage uneven.

### B.P3 (10 nits)

B25–B34: `e()` over already-escaped output; `api_json` vs raw `echo json_encode`; `audit.php` `parse_sort` accessor; `init.php` 348L bootstrap creep; `users.php` `$validRoles` should be const; `api.php` session-name fallback duplicated from init.php; demo_gate.php cache-buster CI check; `aggregates.php`/`pd_pools.php`/`webhooks.php` fine; `health.php` belt-and-suspenders role check; `unassigned.php` ordering of CSRF vs role check.

### B.File-level health summary

| Bucket | Files |
|---|---|
| **Healthy** | logout.php, set_theme.php (modulo CSRF), ping_host.php, oidc_login.php, oidc_callback.php, scan_history.php, scan_run.php, migrate.php, tmp_cleanup.php, demo_reset.php, all `export_*.php`, all in-scope `views/*.php`, status.php, version.php, init.php (large but coherent) |
| **Acceptable** | login.php, audit.php, contacts.php, vlans.php, vrfs.php, sites.php, tags.php, api_keys.php, custom_fields.php, dhcp_pool.php, settings.php, forgot_password.php, reset_password.php, email_otp_verify.php, totp_*.php, passkey_*.php, devices.php, aggregates.php, pd_pools.php, webhooks.php, reports.php, dashboard.php, search.php, health.php, scan_schedule_save.php, smtp_test.php, settings_reveal.php, test_destination.php, cron.php |
| **Concerning** | users.php (537L, 6 inline actions), bulk_update.php (26 KB), change_password.php (784L, 6 POST actions), addresses.php (845L), subnets.php (856L) |
| **Disaster-grade** | import_csv.php (1,144L god page), api.php (2,993L, 56 handlers, no extraction layer) |

---

## 5. Findings — Audit C: Migrations + Schema

### C.P0
None. The four documented SQLite/FK footguns are handled at the `apply_migrations()` driver level (`lib.php:2502–2596`) — the correct architectural anchor. All inspected SQLite rebuilds (`2.1.0-vrfs`, `3.13.0-settings-cascade`) rely on it correctly. **No data-loss risk in non-backup migrations.**

### C.P1 (6 findings)

| ID | Title | Location |
|---|---|---|
| C1 | `3.13.0-settings-cascade` MySQL branch — composite UNIQUE allows duplicate global rows under concurrency; `GET_LOCK` is enforced at runtime (`lib.php:2088–2173`) but not documented in the migration. Add comment block + parity test should assert the lock pattern. | `migrations.php:2065–2079` |
| C2 | `settings` UQ divergence between SQLite/PG (partial UQ ×2) and MySQL (composite UQ) — deliberate but `SchemaParityTest` should explicitly whitelist + document. | `schema.*.sql` |
| C3 | `3.17.0-backup` single closure creates 3 tables across 3 engines (180 lines) — MySQL DDL implicitly commits, so the framework's `START TRANSACTION` is a no-op. Split into 3 migrations. **Note:** This is a backup migration but the architectural pattern (one closure → many tables) applies to non-backup migrations too, so capture as a general policy. | `migrations.php:2317–2499` |
| C4 | `0.3` migration not driver-guarded — calls `PRAGMA table_info(subnets)` which fails on non-SQLite. Likely safe in practice (non-SQLite installs use `schema.X.sql` direct + record migrations as applied) but **needs `MigrationTest::testFreshInstallOnMysql` to confirm**. | `migrations.php:9–35` |
| C5 | `3.6.0-lockout` (line 1950) and `3.0.0-subnet-contacts` (line 1163) — version keys interleaved out of chronological order. `applied_migrations()` saves us, but rename to `3.7.1-lockout` etc. to make late-arrival explicit. | various |
| C6 | Dialect helpers leaking — many migrations hand-roll `(NOW() AT TIME ZONE 'utc')` / `(UTC_TIMESTAMP())` instead of using `Dialect->now()`. CLAUDE.md says use Dialect inside migration closures. | various |

### C.P2 (9 findings)

C7–C15: every post-v2.9.0 migration repeats the three-arm `if/elseif/elseif` pattern (extract helper); `tableExists()` reinvented as inline closure ≥4 times (promote to `ipam_table_exists()` in lib.php); `3.2.0-devices` (184L) and `3.3.0-webhooks` (118L) sprawl; `?: throw new RuntimeException('Query failed')` repeated ~30+ times (extract `ipam_query_or_throw()`); two-part vs three-part version key mix (document convention); no migrations call `audit()` (TOTP, passkeys, etc. ship without audit trail); no batched backfill in `2.9.0-blob-affinity` (acceptable for typical scale, document upper bound); `3.6.0-lockout` vs `2.12.0-account-lockout` possible duplicate intent — verify; `key`-column collation comment only in PG file (mirror to SQLite).

### C.P3 (3 nits)

C16–C18: `2.1.0-vrfs` over-indented closure body; mixed `function` vs `static function` style; `ipam_migrate_2_6_0_settings` is a named function while everything else is a closure literal.

### C.Schema drift inventory

All three engines have the same 35 tables + `schema_migrations`. Spot-checked: `users`, `subnets`, `addresses`, `settings`, `webhook_deliveries`, `audit_log`, `webauthn_credentials`. **No structural drift requiring fix.** Two soft signals to verify:

1. `SchemaParityTest` should explicitly whitelist the `settings` UQ divergence — if it doesn't, the test is over-permissive (covered in C2).
2. `webhook_deliveries`, `audit_log` — verify all three engines carry the same indexes (not just the same columns). Index drift is the most common silent regression.

---

## 6. Findings — Audit D: Frontend + Tests/CI

### D.P0 (3 findings)

| ID | Title | Location |
|---|---|---|
| D1 | **`php-qa.yml` and `playwright.yml` only trigger on `pull_request`** — direct push to dev/main (e.g. release-bundle commits) runs no CI. CLAUDE.md says "every push." Add `push: { branches: [dev, main] }`. | `.github/workflows/*.yml` |
| D2 | **Zero test coverage on OIDC verify path** — no PHPUnit exercises `oidc_verify_id_token`, `jwk_rsa_to_pem`, claim mapping, or auto-link order. The hand-rolled JWK/JWS path is the riskiest non-Composer crypto code in the project. Same for `lbuchs/webauthn` server-side challenge fixture (only Playwright). | `tests/` |
| D3 | **`UpgradeReplayTest` skips 3 fixtures via `markTestSkipped('Fixture not found')`** — if fixtures are not committed, the upgrade-replay job is silently a no-op. Assert fixtures exist in CI, or commit them. | `tests/UpgradeReplayTest.php:332,341,351` |

### D.P1 (8 findings)

| ID | Title | Location |
|---|---|---|
| D4 | `app.js` 2,733 lines, 1 file, 12 distinct concerns (sidebar, command palette, inline-edit, drawer, DHCP export, TOTP enroll, webhooks tester, contact card, dropdown cascade, virtual table stub, visibility shim, import spinner). Move each IIFE under `assets/modules/*.js` as separate `<script defer>` tags — no bundler needed. | `assets/app.js` |
| D5 | `IpamVirtualTable` is dead code shipped to every page — wire up in v3.20+ or delete. | `app.js:2048–2074` |
| D6 | Cache-buster lives in 3 locations (`lib.php:6975–6980`, `demo_gate.php:84–85`, `totp_enroll.php:61`); `qrcode.min.js?v=3.6.0` is hardcoded → never busts. Centralize via runtime constant. | files |
| D7 | `phpstan-baseline.neon` is 403 lines and CLAUDE.md claims it's "almost entirely $db/$config undefined" — no longer true. Real bugs masked: `Cannot cast mixed to string` in `backup.php`, `Cannot access offset 'sha256' on mixed` in `db_tools.php`. Triage. | `phpstan-baseline.neon` |
| D8 | `composer audit --no-dev --locked` runs on install but NOT on the release tarball — call again post-bundle. | `releases/make_releases.sh` |
| D9 | Playwright `vr-*` projects not driver-gated — runs on every engine matrix slot. Mark `testIgnore` on non-sqlite, gate via env. | `playwright.config.ts:55–95` |
| D10 | `SchemaParityTest:116–139` skips on empty DSN env — locally pretends to pass. Hard-fail when `CI=true`. | `tests/SchemaParityTest.php` |
| D11 | `MigrationTest` is a 1,049-line mega-file — split into `MigrationDataPreservationTest`, `MigrationFKEnforcementTest`, etc. | `tests/MigrationTest.php` |

### D.P2 (7 findings)

D12–D18: 10 `!important` instances + 141 CSS variables at `:root` while Open Props is loaded; `api-spec.yaml` no drift test against `api.php`; `icons.svg` 22KB sprite no dead-symbol audit; `tests/bootstrap.php` minimal — extract `tests/Helpers/InMemoryDb.php` (pattern duplicated 4+ times); `bootstrap-app.sh` 346 lines, cold-boot dominates Playwright wall-clock; `EmailOtpTest.php` only 150 LOC for a credential delivery path; no test for `check_utilization_alerts()` (silently no-ops on empty `alert_email`).

### D.P3 (3 nits)

D19–D21: php-qa and playwright workflow setup duplication (extract composite action); `phpunit.xml` single testsuite (split Unit vs Integration); `composer.json` no `scripts` block.

### D.Test coverage gaps (non-backup)

| Area | Status |
|---|---|
| OIDC verify / JWK / claim mapping / auto-link | **None** — biggest gap |
| WebAuthn server-side challenge/response | None (Playwright only) |
| reCAPTCHA v3 verify path | None |
| Webhook signature generation/verification | None visible |
| `check_utilization_alerts()` SMTP fan-out | None |
| `audit()` append-only trigger (UPDATE/DELETE block) | Not in `tests/` |
| `find_parent_site_id()` / site hierarchy depth limit | Not in `tests/` |
| DHCP pool reservation conflict logic | `DhcpRenderTest` exists but reservation logic untested |
| API spec ↔ implementation drift | None |
| Custom fields type-system validation | None visible |
| CSV import row-error reporting | None visible |

---

## 7. Reusability assessment (from Audit A)

**Solid (don't touch)**: IP/CIDR math, CSRF helpers, `audit()`, `ipam_bind_binary()`, Dialect abstraction, scanner core, OIDC verifier, TOTP/WebAuthn helpers.

**Worth breaking up**: settings subsystem → `lib/settings/`; webhook subsystem → `lib/webhook.php`; OIDC → `lib/oidc.php`; mail+OTP+reset+verification → `lib/email.php`; DHCP renderers → `lib/dhcp.php`; dashboard KPI helpers → `lib/dashboard.php`; custom fields → `lib/custom_fields.php`; demo seed → `lib/demo_seed.php` (data only).

**God-functions to extract**: `ipam_setting_definitions` (840L), `page_header` (230L), `demo_seed_data` (390L), `ipam_setting_set` (117L), `ipam_db_init` (110L).

---

## 8. Effort estimate (per audit)

| Audit | P0 | P1 | P2 | P3 | Total engineer-days |
|---|---:|---:|---:|---:|---:|
| A — `lib.php` | 0 | 10 | 23 | 9 | ~10–15 |
| B — Pages + api.php | 0 | 12 | 12 | 10 | ~8–14 |
| C — Migrations + Schema | 0 | 6 | 9 | 3 | ~4 |
| D — Frontend + Tests/CI | 3 | 8 | 7 | 3 | ~10 |
| **Total** | **3** | **36** | **51** | **25** | **~32–43 days** |

Roughly **6–9 calendar weeks** of dedicated work, fits comfortably across 4–5 release milestones at the project's normal cadence.

---

## 9. Recommended milestone allocation (DRAFT — pending approval 2026-04-29)

The user has reserved **v3.26.0** as the entry point and authorized creating additional milestones as needed. All work must land before **v4.0.0**. Existing v3.26.0 backup-related items (cross-engine restore parking lot) coexist — code-quality items are added to it.

| Milestone | Theme | Items | Effort |
|---|---|---|---|
| **v3.26.0** | **Code quality: P0 + critical security/correctness P1s** — fix what's quietly wrong today | D1, D2, D3, A1, A2, A3, A4, A5, A8, A9, A10, A21, A22, A30, B1, B2, B3, B4, B11, C1, C4 (verify) | ~5–7 days |
| **v3.27.0 (NEW)** | **Test coverage + CI hardening** — close the OIDC/MFA/webhook test gap; CI rigor | D4 (partial), D6, D7, D8, D9, D10, D11, plus all "Test coverage gaps" rows from §6 (OIDC unit tests, WebAuthn server fixtures, recaptcha, webhook sig, alert fan-out, audit triggers, site hierarchy, DHCP reservation, API-spec drift, custom-fields types, CSV row-errors), A28, D12 (api-spec drift), D17, D18 | ~7–10 days |
| **v3.28.0 (NEW)** | **`lib.php` decomposition + page-handler refactor** — break the 9.4k-line monolith and the worst pages | A11, A12, A14, A15, A16, A23, A24, A25, A27, A33, B7, B8, B9, B11 (re-order), C-side: extract `ipam_table_exists()` (C8) | ~10–14 days |
| **v3.29.0 (NEW)** | **`api.php` + `import_csv.php` refactor + migrations cleanup** — kill the disaster-grade files; tighten migrations | B5, B6, B10, B13 (import_csv split), C2, C3 (note: backup-side already in v3.21–v3.23, here we capture the *policy* — split-one-table-per-migration), C5, C6 (Dialect leakage), C7, C9, C10, C11, C12, A6, A13, A17, A18, A19, A29, A32 | ~8–12 days |
| **v3.30.0 (NEW)** | **Frontend modularization + remaining P2/P3 polish** | D4 (full), D5, D13, D14, D15, D16, D19, D20, D21, all A.P3, B.P3, C.P3 nits, A20, A26, A31 | ~5–7 days |

Total: **~35–50 engineer-days across 5 milestones (v3.26.0 + 4 new)** — tracks the §8 estimate.

### 9.1 Why this split (rationale)

- **v3.26.0 is "stop the bleeding."** P0s + the highest-risk P1s (webhook lastInsertId on Postgres, SSRF gaps, set_theme CSRF, settings silent fallback, OTP race, raceless XFF). Smallest milestone, lands fast.
- **v3.27.0 is "trust the tests."** Every other milestone in this plan is a refactor — a refactor without test coverage of OIDC/MFA/webhooks/alerts is high-risk. We invest in the test surface first.
- **v3.28.0 is "split the monolith."** lib.php into 12 sub-files + extract god-functions + the worst single-page offenders (addresses, subnets, change_password). Once this lands every subsequent change is smaller-blast-radius.
- **v3.29.0 is "kill the worst files."** api.php (3k lines) and import_csv.php (1.1k lines) are the two disaster-grade files. Cleanup migrations.php at the same time since the three-arm dispatch refactor is the same shape of work.
- **v3.30.0 is "polish."** Frontend modularization, all remaining P2/P3, doc updates. Lands clean for v4.0.0.

### 9.2 Approval + filing record (2026-04-29)

User approved the 5-milestone split as proposed. Issues filed:

| Milestone | Code-quality issues filed | Notes |
|---|---:|---|
| v3.26.0 | 20 | P0s + critical security/correctness P1s (coexists with pre-existing backup parking-lot items) |
| v3.27.0 | 21 | New milestone — test coverage + CI hardening |
| v3.28.0 | 15 | New milestone — `lib.php` decomposition + worst page splits |
| v3.29.0 | 17 | New milestone — `api.php` + `import_csv` + migrations cleanup |
| v3.30.0 | 14 | New milestone — frontend modularization + remaining P2/P3 |
| **Total** | **87** | All tagged with the `code-quality` label. |

All issues cite their finding ID(s) (A1–A42, B1–B34, C1–C18, D1–D21) and link back to this document. Some adjacent findings were merged into single issues (e.g. A21+A22, A17+A18, A19+A32, B14–B24 umbrella, A.P3 / B.P3 / C.P3 nit umbrellas) — total finding coverage is preserved; total issue count is lower than total finding count.

---

## 10. Decision log

| Date | Decision | Rationale |
|---|---|---|
| 2026-04-29 | Audit scope explicitly excludes backup/restore code | `backup_overhaul.md` §B already catalogues 98 backup findings mapped to v3.20–v3.26; deduplication. |
| 2026-04-29 | All findings must close before v4.0.0 | User directive — don't carry quality debt into the multi-tenancy major. |
| 2026-04-29 | This file is single source of truth — issues must reference finding IDs | Mirror `backup_overhaul.md` discipline. |
| 2026-04-29 | 5-milestone split approved as proposed; 4 new milestones created (v3.27.0–v3.30.0) | User accepted the rationale in §9.1. |
| 2026-04-29 | 87 issues filed under `code-quality` label across v3.26.0–v3.30.0 | See §9.2 for per-milestone counts. Adjacent findings merged into single issues where the work is one commit. |

(Further decisions to be added as triage proceeds.)

---

## 11. Test-suite updates (per milestone)

Test work is non-optional alongside refactors — a refactor without test coverage is a regression risk. Each refactor-heavy milestone has a corresponding **test-update umbrella issue** so existing PHPUnit + Playwright + 3-engine matrix stays in lockstep.

| Milestone | Test umbrella | Highlights |
|---|---|---|
| v3.26.0 | covered inline (D1, D2, D3 are themselves test/CI work) | CI workflow trigger, OIDC unit-test scaffolding, UpgradeReplay fixtures. |
| v3.27.0 | **the test-coverage milestone itself** (21 issues filed) | OIDC, WebAuthn, recaptcha, webhook sig, alerts, audit triggers, hierarchy, DHCP, API spec, custom fields, CSV row errors, baseline triage, MigrationTest split, etc. |
| v3.28.0 | `tests: behavioral parity for lib.php decomposition + page-handler refactors` (#1045) | lib.php API-surface snapshot; settings registry diff-test; page-handler Playwright stability; \`ipam_table_exists\` helper. |
| v3.29.0 | `tests: api.php / import_csv / migrations refactor stability` (#1046) | API integration suite stability; OpenAPI drift; import_csv state-machine unit tests; MigrationTest split convergence; Dialect::increment_or_insert across 3 engines; out-of-order migration version-key rename. |
| v3.30.0 | `tests: app.js module-loading + frontend regression + P3 cleanup verification` (#1047) | app.js module load order; CSP regression guard; prefers-reduced-motion; CSS reconciliation; icons.svg dead-symbol audit. |

**Cross-cutting policy:**
- Each PR closing a code-quality finding must update or add the matching test in the same commit.
- The test umbrella issue stays open until *all* refactor issues in its milestone are closed.
- For the 3-engine matrix (SQLite/MySQL/Postgres), refactor PRs must show all-green local containerized gate before merge per CLAUDE.md.

## 12. Maintenance protocol

- When filing an issue, reference its finding ID (e.g. "Closes A2, A3" in PR title).
- When triaging defers/closes, add a row to §10.
- When a new finding surfaces during implementation, append to the matching audit's table and renumber if necessary; never silently re-use a finding ID.
- This document is **not** updated post-v4.0.0 — at that point it is archived alongside `backup_overhaul.md`.
