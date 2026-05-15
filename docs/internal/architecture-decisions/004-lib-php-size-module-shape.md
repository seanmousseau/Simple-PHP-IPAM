# ADR-004: `lib.php` size + module shape

**Status:** accepted
**Decided:** 2026-05-15
**Scope:** refactor wave 1's blueprint — defines the target module layout that v3.30.0's `#56` decomposition extracts toward.
**Stamped by:** Sean Mousseau

---

## Context

`Simple-PHP-IPAM/lib.php` is **12,559 lines / 289 functions** as of v3.29.0. The roadmap's "~9,000 lines" estimate is from late 2025; the file has grown another 3,500 lines since across v3.27.x / v3.28.x security and DR work.

Some decomposition has already happened. `Simple-PHP-IPAM/lib/` is a real directory and currently contains:

```
app_secret.php                  (#1178 — v3.28.2 — install-key lifecycle)
auth_step_up.php                (step-up auth from v3.26.0)
backup.php                      (backup orchestrator)
backup_admin_destinations.php   (admin UI helpers)
backup_admin_history.php
backup_admin_restore.php
BackupClientInterface.php       (interface + 3 impls)
LocalBackupClient.php
S3Client.php
SftpClient.php
restore_dsn.php                 (#1177 — v3.28.1 — DSN parser)
restore_wizard.php
vault.php                       (backup vault key)
```

So decomposition isn't starting from zero — the **backup**, **step-up auth**, and **install-key** subsystems are already out. What remains in `lib.php` is everything else, which falls into 20-ish thematic areas (rough inventory from `grep ^function` + manual grouping):

| Theme | Approx. functions | Examples |
|---|---|---|
| Core utilities | 20 | `e`, `to_int`, `to_str`, `q_int`, `base64url_*`, `format_bytes` |
| IP math | 30 | `parse_cidr`, `ip_in_cidr`, `ipv4_*`, `ipv6_*`, `ipam_bind_binary`, `ipam_compute_*` |
| DB / migration / engine | 15 | `ipam_db`, `ipam_db_init`, `apply_migrations`, `ensure_*_table` |
| Auth / login / session | 30 | `require_login`, `csrf_*`, `login_protection_*`, `recaptcha_*`, `record_*_failure`, `ipam_argon2id_derive` |
| OIDC | 10 | `oidc_discovery`, `oidc_resolve_user`, `oidc_provision_user`, `oidc_verify_id_token` |
| MFA / TOTP | 10 | `ipam_totp_*`, `ipam_user_available_mfa_methods` |
| Settings registry + cache | 12 | `ipam_setting`, `ipam_setting_all`, `ipam_setting_definitions`, `ipam_setting_cache_*` |
| Audit | 10 | `audit`, `audit_export`, `audit_filter_*`, `prune_audit_log` |
| Subnet / address logic | 20 | `build_subnet_tree`, `find_containing_subnet`, `auto_reserve_subnet_ips`, `subnet_overlap_warning_text` |
| DHCP rendering | 5 | `ipam_render_dhcpd_conf`, `ipam_render_kea_json`, `ipam_dhcp_load_reservations` |
| Scanning | 5 | `ipam_scan_subnet`, `ipam_apply_arp_import` |
| Webhooks | 10 | `ipam_webhook_*`, `ipam_validate_webhook_url` |
| Alerts / utilization | 10 | `check_utilization_alerts`, `capture_utilization_snapshot`, `ipam_resolve_*_recipients` |
| Backup / vault / DR (remaining inline) | 30 | `backup_encrypt_*`, `backup_decrypt_*`, `ipam_backup_*`, `ipam_destination_*`, `ipam_retention_*` |
| Housekeeping | 5 | `run_housekeeping_if_due`, `housekeeping_*`, `prune_*` |
| Email / SMTP | 5 | `ipam_send_mail`, `ipam_send_email_verification`, `ipam_send_reset_email` |
| Page / view helpers | 15 | `page_header`, `page_footer`, `render_*`, `icon`, `flash_*`, `sort_th`, `paginate` |
| Import / CSV | 15 | `csv_read_preview`, `detect_csv_delimiter`, `save_import_plan`, `import_max_bytes` |
| Custom fields | 10 | `custom_field_*`, `parse_custom_fields_row`, `validate_custom_fields_*` |
| Tags / contacts | 5 | `get_*_for_entity`, `save_*_for_entity`, `parse_contact_assignments` |
| Demo mode | 5 | `demo_mode_enabled`, `demo_reset_db`, `demo_seed_data` |
| Misc (rate limit / update check / skeleton) | 5 | `ipam_api_key_rate_limit_check`, `ipam_update_check`, `ipam_skeleton_*` |

**Why this matters now.** Three forces converge:

1. **Code-quality**: a single 12k-line file is hard to navigate, hard to PHPStan-analyse incrementally, and review-tax-heavy on every PR.
2. **Test isolation**: PHPUnit tests for one subsystem currently autoload all 12k lines because everything is in one file. Unit-test setUp is slower than it should be.
3. **ADR-001 + ADR-002 land in v3.30.0** and both touch `lib.php` settings code. Decomposing while those land lets the new code be born already in its final home, instead of moved twice.

## Decision drivers

- **Migration cost vs payoff per file.** Some areas (IP math, custom fields, backup encrypt/decrypt) have tight internal cohesion and modest cross-file calls — cheap and high-payoff. Others (auth/session, settings) are entangled with `$config` globals (ADR-003) and rendering helpers (page_header) — extraction is harder.
- **Procedural-vs-namespace axis.** Independent from "split into files." Today every function is in the global namespace. Going procedural-with-files is one change; going procedural-with-PHP-namespaces is a second. Bundling both doubles call-site churn.
- **Autoloading.** Composer PSR-4 is class-based. For procedural functions, you either (a) use `composer.json` autoload-files (works but is a hot loader on every request), (b) explicit `require_once` chain in `init.php` (documents dependency order, slightly verbose), or (c) one shim file that requires everything.
- **Test isolation.** Modular files unlock per-module unit tests with minimal autoload. This is genuine payoff.
- **Cross-module discipline.** "Settings calls audit, audit calls `e()`, `e()` is a util" — the layer graph needs to be enforced so we don't end up with circular requires.
- **Coupling to ADR-001 + ADR-002 + ADR-003.** The settings code path is one of the largest cohesive areas, so it's a natural module — but its shape is decided by ADR-001 (`setting_definitions`) + ADR-002 (`user_preferences` separation) + ADR-003 (`$config` resolution). The lib.php split has to consume those decisions, not pre-empt them.
- **Wave staging.** Doing all ~20 extractions in v3.30.0 makes v3.30.0 enormous (already inflated by ADR-001). Phasing across v3.30.0 → v3.31.0 → v3.32.0 spreads the work.

## Options considered

### Option A — Many small thematic modules (20+ files)

**Mechanism:** Each themed area becomes its own file under `Simple-PHP-IPAM/lib/`:

```
lib/
  utils.php                  (e, to_int, to_str, q_int, base64url_*, format_bytes)
  ip.php                     (parse_cidr, ip_in_cidr, ipv4_*, ipv6_*, ipam_bind_binary)
  db.php                     (ipam_db, ipam_db_init, apply_migrations, ensure_*)
  audit.php                  (audit, audit_export, audit_filter_*, prune_audit_log)
  auth.php                   (require_login, csrf_*, login_user/logout_user, current_user)
  auth_password.php          (validate_password_complexity, ipam_argon2id_derive, ipam_create_reset_token)
  auth_rate_limit.php        (login_rate_limited, account_locked_out, record_*_failure)
  auth_recaptcha.php         (recaptcha_*, login_protection_*)
  oidc.php                   (existing oidc_* — extracted)
  mfa.php                    (ipam_totp_*, ipam_user_available_mfa_methods)
  settings.php               (post-ADR-001 dispatch: ipam_setting, ipam_setting_set, definitions, cache)
  user_preferences.php       (post-ADR-002: ipam_user_preference_get/set)
  subnets.php                (build_subnet_tree, find_*, auto_reserve, overlaps)
  addresses.php              (address-specific helpers, history_log_address)
  dhcp.php                   (ipam_render_dhcpd_conf, ipam_render_kea_json, dhcp_load_reservations)
  scan.php                   (ipam_scan_subnet, ipam_apply_arp_import)
  webhooks.php               (ipam_webhook_*)
  alerts.php                 (check_utilization_alerts, capture_utilization_snapshot, recipients)
  backup_codec.php           (backup_encrypt_*, backup_decrypt_*, v3 header pack/unpack)
  backup_run.php             (backup_run_dump, ipam_backup_*, retention)
  email.php                  (ipam_send_mail, send_email_verification, send_reset_email)
  views.php                  (page_header, page_footer, render_*, icon, flash_*, sort_th, paginate)
  import_csv.php             (csv_read_preview, detect_csv_delimiter, save/load_import_plan)
  custom_fields.php          (custom_field_*, validate_custom_fields_*)
  tags_contacts.php          (get/save_*_for_entity, parse_contact_assignments)
  demo.php                   (demo_mode_enabled, demo_reset_db, demo_seed_data)
  housekeeping.php           (run_housekeeping_if_due, prune_*)
  rate_limit.php             (ipam_api_key_rate_limit_check, ipam_audit_ip_rate_limited)
  update_check.php           (ipam_update_check)
```

`lib.php` becomes a 30-line shim that `require_once`s each in dependency order, or `init.php` requires them directly and `lib.php` is deleted.

**Pros:**
- Highest cohesion per file (~200-500 lines each).
- PHPStan analyses one module at a time; CI gets faster.
- Per-module unit tests with cheap autoload.
- Easy to grep — "where does theme X live?" is obvious.
- Locked names mean reviewers know which file a new function belongs in.

**Cons:**
- 25-30 file moves in one wave is a lot of CR surface.
- Some functions are genuinely cross-theme (e.g. `audit()` is called from auth, settings, webhooks, backups). Their home has to be picked and everyone else `require`s it.
- The dependency graph needs to be drawn explicitly — utils.php has no deps, ip.php depends on utils, db.php depends on utils, audit.php depends on db, auth.php depends on audit+db+utils, etc.

### Option B — Fewer larger groups (5-8 files)

**Mechanism:** Group by major subsystem:

```
lib/
  core.php          (utils + ip + db + audit — ~80 functions, ~2500 lines)
  auth.php          (auth + oidc + mfa + recaptcha — ~50 functions, ~2000 lines)
  settings.php      (settings + user_preferences — ~20 functions, ~1500 lines)
  subnet_address.php (subnet + address + dhcp + scan — ~40 functions, ~2000 lines)
  backup.php        (backup codec + run + destinations + retention — already partially extracted)
  notify.php        (webhooks + alerts + email — ~25 functions, ~1500 lines)
  views.php         (page header/footer + renderers + flash + sort_th + paginate — ~15 functions, ~1000 lines)
  pages_helpers.php (import_csv + custom_fields + tags_contacts + demo + housekeeping + misc — ~50 functions, ~2000 lines)
```

**Pros:**
- Half the files of Option A.
- Each file is still meaningfully cohesive.
- Less ceremony per PR — landing a new auth feature touches `auth.php` instead of `auth_password.php` + `auth_rate_limit.php` + `auth_recaptcha.php`.
- Easier dependency order (8 nodes, not 25).

**Cons:**
- "settings.php" the library file collides nominally with "settings.php" the page file (the existing page handler in the repo root). Operationally a non-issue (`lib/settings.php` vs `settings.php`) but confusing.
- Files in the 1500-2500 line range are still big enough that PHPStan analyse-one-file isn't a huge win over the status quo.
- Less granular test isolation.
- "Where does function X live?" is less obvious — `auth.php` could mean session, password, rate limit, or OAuth.

### Option C — Many modules + PHP namespaces

**Mechanism:** Same file split as Option A, but introduce `namespace Ipam\Auth;`, `namespace Ipam\Settings;`, etc. Functions become namespaced; every call site changes from `csrf_require()` to `Ipam\Auth\csrf_require()` (or aliased via `use function`).

**Pros:**
- Idiomatic modern PHP.
- Composer PSR-4 autoload (if we convert functions to static methods on `\Ipam\Auth\Csrf` etc.).
- Name collisions become impossible by namespace.

**Cons:**
- **Every call site changes.** ~280 function names × dozens of call sites each = thousands of lines of touched code, every one a CR surface.
- Procedural → namespace → static-method-class is two axes of change pretending to be one.
- The Composer dev-loop test that passed PHPUnit 1228 tests today would have to be re-verified across the namespace rename.
- Plugin/extension authors (none today, but if any in the future) face a breaking change.

### Option D — Status quo + linter-enforced section markers

**Mechanism:** Keep `lib.php` as one file. Add `// === SECTION: Auth ===` markers. Linter enforces functions are grouped under their section. PHPStan analyses one section at a time via a custom extractor.

**Pros:**
- Zero migration cost.
- No call-site changes.

**Cons:**
- Doesn't actually decompose. Test autoload still loads all 12k lines.
- "Splitting lib.php" was the locked roadmap §10 decision; D is fundamentally a defer.

### Option E — Many small modules, NO namespacing, explicit `require_once` chain in `init.php`

**Mechanism:** Same file split as Option A. **Stay procedural** — every function keeps its current name in the global namespace. `lib.php` either becomes a shim that requires every `lib/*.php` in dependency order, or is deleted and `init.php` does the requires directly. Composer autoload-files is **not** used (it's hot on every request); explicit `require_once` documents the dependency order.

**Pros:**
- All of Option A's modularity wins.
- Zero call-site changes — `csrf_require()` stays `csrf_require()`. PRs are pure file moves + require-order plumbing.
- Dependency order is explicit in `init.php`; circular requires are caught by the linter.
- Procedural-to-namespaced can happen later (v4.0.0 if ever) as a separate axis, with its own ADR.
- Lowest risk of breakage during the wave.

**Cons:**
- 25-30 files is more than Option B.
- Future grep-by-class doesn't work (you grep by function name as today).
- No PHP-language-level enforcement that `lib/auth.php` only contains auth-themed functions — relies on convention + reviewer discipline + the new test in step 4 below.

## Recommendation

**Pick Option E (many small modules, no namespacing, explicit require chain).**

Decision drivers tip toward E because:

1. **Call-site churn is the load-bearing risk.** Option C's namespace migration touches every line of every file that calls a `lib.php` function. The bug-introduction rate per touched line is non-zero; we shipped 5 CR rounds on a v3.29.0 PR that touched orders of magnitude less. Multiplying that across the entire codebase in v3.30.0 is reckless.

2. **The modularity wins come from file structure, not namespace structure.** PHPStan analyse-one-file, per-module unit-test isolation, "where does this live" greppability — all of these come from Option A's file count. Namespace adds operator/developer-facing churn without proportional payoff on this codebase.

3. **E is forward-compatible with namespacing.** If a v4.0.0 cold-break wants to namespace, the call-site update is the same magnitude whether we do it from "one big lib.php" or "25 procedural lib/*.php files." Adding namespaces *after* the file split is the same work; doing it *during* the file split makes both harder to review.

4. **Procedural is what this codebase IS.** Multi-tenant aware, three-engine portable, but procedural. The roadmap's identity is "boring PHP that operators can vendor and patch." Namespaces don't change that — they just make grep results have more colons in them.

5. **Test isolation is the operator-invisible win.** Unit tests today autoload all 12k lines of lib.php through every setUp. With per-module files, `tests/Migration/EngineParityTest` only autoloads `lib/db.php` + `lib/audit.php` + `lib/utils.php`. This is a real CI-time savings.

The file list in Option A's mechanism block is the recommended initial layout. Names are not bikeshed-locked — they should match what's already conventional (e.g. `lib/dhcp.php` already exists in the roadmap finding A25 as the destination, no reason to rename).

## Implications

This ADR's implementation **spans v3.30.0 and v3.32.0**, with v3.31.0 (encrypt-at-rest, ADR-001 part 2) sitting between them on its own concerns. The phasing:

### v3.30.0 — Foundation layer (no-deps + thin deps)

- `lib/utils.php` (no deps)
- `lib/ip.php` (deps: utils)
- `lib/db.php` (deps: utils — also incorporates ADR-001's new `setting_definitions` schema reads)
- `lib/audit.php` (deps: db, utils)
- `lib/settings.php` (deps: db, audit, utils — ADR-001 dispatch lives here)
- `lib/user_preferences.php` (deps: db, utils — ADR-002 endpoint lives here)
- `lib/presentation.php` (deps: utils — page_header, render_* helpers)
- `lib/auth.php` (deps: db, audit, utils, views)
- `lib/auth_password.php` (deps: auth, utils)
- `lib/auth_rate_limit.php` (deps: auth, db)
- `lib/auth_recaptcha.php` (deps: auth, utils)

**11 modules in v3.30.0.** Covers the foundation + everything ADR-001/ADR-002 touch. `lib.php` shrinks to ~6,000 lines (~half).

### v3.32.0 — Domain layer (after wave 2's #57 has stabilised api.php)

- `lib/subnets.php`, `lib/addresses.php`
- `lib/dhcp.php`
- `lib/scan.php`
- `lib/webhooks.php`
- `lib/alerts.php`
- `lib/backup_codec.php` + `lib/backup_run.php` (consolidates the inline backup encrypt/decrypt code with the existing `lib/backup.php` orchestrator)
- `lib/email.php`
- `lib/oidc.php`
- `lib/mfa.php`
- `lib/import_csv.php` (lives in same file ADR-001's wave 2 #57 reshaped — natural home)
- `lib/custom_fields.php`
- `lib/tags_contacts.php`
- `lib/demo.php`
- `lib/housekeeping.php`
- `lib/rate_limit.php`, `lib/update_check.php`

**~14 modules in v3.32.0.** `lib.php` is fully drained; the file becomes the dependency-order require shim or is deleted entirely.

### Cross-cutting requirements

- `init.php` owns the dependency order. The require chain is explicit, comment-annotated, and easy to read:

  ```php
  // Foundation — no inter-deps among these (utils first, ip just uses utils)
  require_once __DIR__ . '/lib/utils.php';
  require_once __DIR__ . '/lib/ip.php';
  // DB layer — depends on utils
  require_once __DIR__ . '/lib/db.php';
  require_once __DIR__ . '/lib/audit.php';
  // Settings — depends on db + audit
  require_once __DIR__ . '/lib/settings.php';
  // ...
  ```

- **Linter for module discipline.** A new `testing/scripts/lib-module-linter.php` enforces that:
  - Every `lib/*.php` file has a header comment declaring its single thematic area.
  - No `lib/*.php` file uses `require` or `require_once` (only `init.php` does).
  - No function appears in two modules.
  - Functions whose call graph crosses a module boundary route through declared public-API helpers (a future-proofing step; not enforced strictly in v3.30.0).
- **PHPStan baseline.** Re-run after every module extraction so phpstan-baseline.neon entries don't go stale (ADR-001's lessons-learned).

### GH issues to open

- `feat(lib): extract lib/utils.php` — milestone #56
- `feat(lib): extract lib/ip.php` — milestone #56
- `feat(lib): extract lib/db.php (incorporates setting_definitions reads from ADR-001)` — milestone #56
- `feat(lib): extract lib/audit.php` — milestone #56
- `feat(lib): extract lib/settings.php (ADR-001 dispatch home)` — milestone #56
- `feat(lib): extract lib/user_preferences.php (ADR-002 endpoint home)` — milestone #56
- `feat(lib): extract lib/presentation.php` — milestone #56
- `feat(lib): extract lib/auth.php + auth_password.php + auth_rate_limit.php + auth_recaptcha.php` — milestone #56
- `tools: lib-module-linter.php — file header + cross-module require enforcement` — milestone #56
- v3.32.0 issues land at wave-2 close; defer until wave-1 is shipped.

### Files changed

- All of `Simple-PHP-IPAM/lib.php` (drained progressively across v3.30.0 + v3.32.0)
- `Simple-PHP-IPAM/init.php` — require chain
- `Simple-PHP-IPAM/lib/` — 11 new files in v3.30.0, ~14 more in v3.32.0
- `phpstan.neon` — `bootstrapFiles` may need an update if any constant moves
- `composer.json` — no autoload changes (we are not using PSR-4 for procedural code)
- `tests/` — per-module test files where natural (some already exist: SettingsTest, OidcClaimMappingTest, etc.)

### Schema migrations needed

None. This ADR is pure code organisation; no DB shape changes are caused by it. (ADR-001's migration is what drives schema work in v3.30.0.)

### Docs to update

- `docs/internal/design-document.md` — add a "Code organisation" section pointing to `lib/`
- `docs/internal/coding-guide.md` — add the "new function goes in the right `lib/*.php`" rule + a module-membership cheat sheet
- `docs/internal/roadmap.md` § 10 — strike "lib.php size + module shape" from the locked-pre-wave-1 list
- `docs/internal/architecture-decisions/README.md` — index update
- `CLAUDE.md` — point to the new `lib/` layout in the "Where to read next" routing table

### Future ADRs unblocked

- **ADR-003 (`$config` global)** can now declare `lib/config.php` as the destination for any new config resolver.
- **ADR-005 (`backup.php` separation)** maps cleanly onto `lib/backup_codec.php` + `lib/backup_run.php` already part of this ADR's v3.32.0 phase.

## Open questions

1. **`lib.php` survival.** After all functions move out, does the file get deleted entirely, or kept as a require-everything shim for backward compat with any external code that does `require_once 'Simple-PHP-IPAM/lib.php';`? My read: delete it. There is no such external caller in any known integration. But worth confirming.
2. **`lib/presentation.php` naming.** `views.php` is a thematic label for "page_header, render_*, flash_*, paginate, sort_th, csv_*." Some of those (csv_*) are more "presentation utility" than "view." Should it be `lib/presentation.php` (theme-name) or `lib/presentation.php` (broader)?
3. **Single auth file vs four.** I split auth into `auth.php` + `auth_password.php` + `auth_rate_limit.php` + `auth_recaptcha.php`. That's the highest function-count theme (~30 functions). Alternative: keep one `lib/auth.php` (~1,500 lines) for cohesion. Trade-off: cohesion vs PHPStan-per-file speed.
4. **Phasing aggression.** Should v3.30.0 do **all** of the v3.30.0-bucket modules above (11), or split into a v3.30.0 + v3.30.x point-release sequence the way ADR-001's encrypt-at-rest was split? Scope-sizing concern is real: v3.30.0 with ADR-001 + ADR-002 + 11 file extractions is genuinely large.

## References

- `docs/internal/roadmap.md` § 10 (locked 2026-05-11) — ADR-004 was the original "the big one."
- ADR-001 (accepted) — settings type system; informs `lib/settings.php` shape.
- ADR-002 (accepted) — user_preferences; introduces `lib/user_preferences.php` as a new module.
- ADR-003 (pending) — `$config` global; will inform `lib/config.php` if introduced.
- ADR-005 (pending) — `backup.php` separation; informs `lib/backup_codec.php` + `lib/backup_run.php`.
- `Simple-PHP-IPAM/lib.php` — 12,559 lines, 289 functions as of v3.29.0.
- `Simple-PHP-IPAM/lib/` — existing extraction destination (backup admin, step-up, install-key, vault, restore DSN).
- GH issues #907 (A11 lib.php split into ~12 sub-files), #908–#921 — concrete extraction tickets.
