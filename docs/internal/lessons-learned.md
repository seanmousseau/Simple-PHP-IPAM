# Lessons learned

Consolidated rollup of hard-won lessons across releases. **This doc is a curated index, not the source of truth.** Each entry points back to where the rule lives — `CLAUDE.md`, the per-release Memory MCP entity, or a `feedback_*.md` auto-memory file. Update it when a new lesson generalises across more than one release.

Last refreshed: 2026-05-08 (post Pass A regression sweep; v3.27.1 hotfix scope locked).

---

## 1. Schema migrations (SQLite + multi-engine)

Most expensive bugs in this project's history involve migrations. Read `docs/internal/adding-a-migration.md` before writing one, and run the `migration-reviewer` subagent on the diff.

| Lesson | Source |
|---|---|
| `DROP TABLE` with `PRAGMA foreign_keys = ON` cascades child rows. SQLite executes a row-by-row DELETE before the drop. Root cause of the v2.2.1 data-loss bug that wiped every IP address on upgrade from v1.x. **Always disable FK enforcement before the migration transaction; restore unconditionally on every exit path.** | CLAUDE.md → Migration testing pitfalls (1) |
| `PRAGMA foreign_keys` cannot be changed inside a transaction. Set it **outside** `BEGIN`/`COMMIT` or it silently no-ops. | CLAUDE.md → Migration testing pitfalls (2) |
| `ALTER TABLE … RENAME TO` in SQLite ≥3.26 rewrites child FK references to the new name. The `legacy_alter_table` workaround is unreliable. The only safe approach is to disable FK enforcement before the transaction. | CLAUDE.md → Migration testing pitfalls (3) |
| SQLite UNIQUE treats NULLs as distinct. `UNIQUE(cidr, vrf_id)` does **not** prevent two rows where both `vrf_id IS NULL`. Test UNIQUE-on-nullable with a non-NULL value. | CLAUDE.md → Migration testing pitfalls (4) |
| MySQL `HY093` (param-reuse) — named placeholders cannot be referenced more than once in the same prepared statement on MySQL. Caught in production after v3.21.0 ship; fixed in v3.21.1 hotfix on the `schedule-unique` dedup migration. **Bind a distinct placeholder per occurrence, or restructure the query.** | Memory MCP `release:v3.21.1`; `recent.md` 2026-05-02 |
| Migrations run in **natural-sort order** (`ksort SORT_NATURAL`), not array order. File order in `migrations.php` is for human readability only. | CLAUDE.md → Schema migrations |
| Three schema files (`schema.sql`, `schema.mysql.sql`, `schema.pgsql.sql`) must stay in sync from v2.9.0 onward. `SchemaParityTest` and `DataDictionaryDriftTest` will fail the build on drift. **Run the `multi-engine-schema-parity` subagent and `php tools/generate-data-dictionary.php` after any schema edit.** | CLAUDE.md → Modifying the schema (multi-engine) |

---

## 2. Binary IP storage

| Lesson | Source |
|---|---|
| IPs are stored at **native length** (4 bytes IPv4, 16 bytes IPv6) — never left-pad IPv4 to 16 bytes. Decided during v2.8.0 planning (#410, #379); revisit those threads before changing it. | CLAUDE.md → Binary IP storage |
| All binary binding goes through `ipam_bind_binary()` with `PDO::PARAM_LOB`. On SQLite, `PARAM_STR` produces TEXT affinity which sorts greater than every BLOB regardless of bytes — breaks `ORDER BY ip_bin` and every range query. On MySQL it string-escapes high bytes and truncates at NUL. On Postgres it corrupts BYTEA. **Non-negotiable.** | CLAUDE.md → Binary IP storage |
| Test vectors any new driver must round-trip: `10.0.0.0` (NULs after first byte), `2001:db8::` (mostly NULs), `255.255.255.255` (all high bytes). | CLAUDE.md → Binary IP storage |
| Run the `ip-binary-auditor` subagent on any diff that touches `ip_bin` / `network_bin` / IP parsing / subnet math / ping/scan / DB binds for binary columns. | `.claude/agents/ip-binary-auditor.md` |

---

## 3. Releases and deployment

| Lesson | Source |
|---|---|
| Deploy via the **release tarball + `upgrade.sh`**, never raw `rsync` from the working tree. `rsync` honours `.gitignore`, so `vendor/` is silently skipped and any page using a Composer class breaks at runtime. | `feedback_deploy_tarball.md` |
| If you must rsync ad-hoc to a testing instance, sync `vendor/` separately. Composer is not installed on the dev server. | `feedback_vendor_deploy.md` |
| After any rsync to `192.168.80.15`, `chown -R www-data:www-data` the deployed path or Apache cannot write SQLite (`attempt to write a readonly database`). | `feedback_dev_deploy_perms.md` |
| Release bundles live under `releases/ipam-X.Y.Z/`, not at the repo root. `make_releases.sh` writes to CWD — run from repo root then `mv ipam-X.Y.Z releases/`. | `feedback_release_bundle_location.md` |
| README "What's new" carries **only the most recent release** — replace in-place; do not append. Full history lives in `CHANGELOG.md`. | `feedback_readme_whats_new.md` |
| When a release ships new `docs/*.md`, link them from the relevant feature card on `website/front-page.php` and verify the URLs resolve before purging cache. Cache purge is **QUIC.cloud via the LSCache WP plugin** (not Cloudflare) for `simplephpipam.com`; Cloudflare is for `demo.simplephpipam.com` only. | `feedback_marketing_docs_links.md`; `docs/internal/marketing-site.md` |
| When `dev` and `main` diverge (release went direct to `main`, dependabot piled up on `dev`), branch the hotfix off `main` and sync `dev ← main` after the release ships. v3.15.1 used this pattern. | Memory MCP `release:v3.15.1`; `docs/internal/hotfix-release.md` |

---

## 4. Testing discipline

| Lesson | Source |
|---|---|
| **Never `git push` before a full local pass on all three dockerized drivers** (sqlite, mysql, pgsql) when the change could touch any of them. GH Action minutes are a finite paid resource. Reinforced after v3.10.0 was merged with 8 MySQL failures rationalised as "transient." | `feedback_no_push_before_local_pass.md` |
| **Never `git push` or merge a PR without explicit per-conversation authorisation** — even when tests are green and the PR is approved. A previous yes does not carry forward. | `feedback_push_merge_requires_permission.md` |
| Before running `test_api.sh` or Playwright against `dev-direct`, work through the seven recurring footguns in `docs/internal/test-suites.md`: (1) `BASIC_AUTH=user:pass` env var (the combined form), (2) `pkill -f cron.php` to release the SQLite lock, (3) `DELETE FROM login_attempts`, (4) verify admin password against `~/.claude/dev-secrets.env`, (5) `--data-urlencode` for passwords containing `!`, (6) redirect Playwright to a file (don't `tail`), (7) PHPStan `--memory-limit=1G`. | `feedback_dev_test_runs.md`; `docs/internal/test-suites.md` |
| `PHPMailer` `Encoding = 'base64'` will break test parsers that grep the raw body for plain text. Set `CharSet = 'UTF-8'` and leave `Encoding` at its default. Caught by the alerts-smtp Playwright spec in v3.15.1. | Memory MCP `release:v3.15.1` |
| **Local 3-driver Playwright gate is necessary but NOT sufficient** when the change touches a multi-driver helper. The v3.22.0 #820 destination-orchestrator extension (`lib/backup.php::ipam_backup_native_cmd` → temp-file cred routing) passed local SQLite + PostgreSQL but blew up MySQL + MariaDB in CI with `fclose(): Argument #1 ($stream) must be of type resource, null given` on every dump-exercising test. The error was non-reproducible from local SQLite + PG runs. **Rule:** when refactoring a helper that has different code paths per driver (mysqldump vs pg_dump vs sqlite-stream, MYSQL_PWD vs PGPASSWORD vs `--defaults-extra-file`), the local gate must literally exercise every driver the change could touch — not just SQLite + a single sanity-check. Reverted in commit `ead5a4a`; tracked for v3.22.1 hotfix as #1075 with a bisect plan. | Memory MCP `release:v3.22.0`; #1075 |
| **`apt-get install` is not reproducible across build moments** — when adding a flag to a tool whose package version isn't pinned in the Dockerfile, verify support against the **lowest-common-denominator client version** the project supports, not just the dev's local install. v3.22.1 PR #1080 attempted to add `--no-login-paths` to `mysql`/`mysqldump` invocations. The dev's freshly-rebuilt apache-php image had MariaDB 11.8.6 (which supports it); CI's same Dockerfile + same `apt-get install --no-install-recommends default-mysql-client` line resolved Debian 12's package to MariaDB 10.11 (which rejects it with "unknown option"). Same Dockerfile, different pull moments, different package versions. **Mitigation:** either pin the client package version in the Dockerfile, OR use a runtime probe-and-cache helper to detect support and emit the flag conditionally. Tracked as #1081. | Memory MCP `release:v3.22.1`; #1081 |
| **Tactical unit testing without contract testing.** v3.21–v3.27 shipped 12 bugs that passing CI, passing CodeRabbit reviews, and passing 3-driver Playwright did not catch. The pattern: every individual unit had a passing test, but the contracts BETWEEN units were untested. Examples: `SudoVerifyTest` covers verify branches but never asserts `ipam_sudo_consume_once()` is called from any handler (Bug X); the IPAMBKP3 codec has unit tests but no end-to-end "encrypt → restore → byte-compare" test wires the orchestrator to the codec (encrypt-write-path bug + Bug S); CodeRabbit caught Bug Z's open-redirect concern, the fix tightened the validator, no test asserted existing relative-path callers still passed validation. **Add three test classes the suite is missing:** (1) Round-trip tests for every persistence path — encrypt→restore, dump→restore, write→read, with byte-equality assertions; (2) Contract enforcement tests — every documented event has a call site, every defined helper has at least one caller, every validator accepts what its callers actually pass; (3) Negative regression tests pinning existing valid use cases when a fix tightens a validator/guard. | Pass A regression sweep 2026-05-08; v3.27.1 hotfix plan §6 |
| **Test fixtures must not bypass the path they test.** The Playwright `warmSudoGrant()` fixture mints a session sudo grant directly so step-up-gated specs can skip the prompt. This made step-up specs faster and let `step-up-fan-out.spec.ts` and `step-up-vault-flow.spec.ts` ship green — but it also meant the actual resume mechanism (proof submit → handler verify → fall-through to action) was never exercised by any spec. Bug Z (OIDC validator), Bug X (consume_once), Bug T (invalidation triggers), Bug Z's full scope across 8 sudo handlers — all hidden behind the fixture. **Rule:** when a fixture short-circuits a flow, the suite must contain at least one spec that exercises the flow without the fixture. Document the bypass explicitly in the fixture's docstring with a pointer to the non-bypassing spec. | Pass A regression sweep 2026-05-08 §2; `warmSudoGrant` fixture |
| **Disaster recovery bugs go in hotfix scope by default.** v3.27.0 shipped with IPAMBKL1 restore broken on every backup containing bcrypt password hashes (Bug S, `lib/backup.php:2014` `ipam_restore_split_sql_statements` doesn't track quote state — `$2y$` inside a string literal looks like a PostgreSQL dollar-quote opener). Backups landed successfully with checksums; restores failed. **Default disposition for "backup looks fine, restore broken" or "configuration looks saved, doesn't actually persist": HOTFIX, not next minor.** Operator faith in their backup pipeline is hard-won and slow to rebuild after even one failed-restore incident. | Pass A regression sweep 2026-05-08 §J.12; v3.27.1 hotfix scope |

---

## 5. Authentication, sessions, and security

| Lesson | Source |
|---|---|
| Open-redirect canonicalisation must reject `\\` in addition to `//`. Backslash-in-URL is a real bypass vector (browsers normalise `\` to `/`). v3.15.1 stash sanitiser. | Memory MCP `release:v3.15.1` |
| `ipam_config_stale_keys()` only walks `array_keys($config)` (top-level). Dotted whitelist entries like `session.absolute_lifetime_minutes` are silent no-ops — use the top-level key (`session`). False positive caused the v3.6.0 security keys to be flagged for deletion in v3.15.0; would have nuked every TOTP enrollment. | Memory MCP `release:v3.15.1` |
| `app_secret` lives in `config.php`, never in the DB. Storing the key that protects DB data inside that same DB defeats the security model. Per-tenant keys in v4.0.0 are derived via HKDF from `app_secret` at runtime, not stored. | CLAUDE.md → `app_secret` and per-tenant key derivation |
| S3 SigV4 `canonicalRequest` bug — caught in production after v3.19.0 ship and hotfixed same day in v3.19.1. Auth + dump handling needed correction. **Validate signed requests against AWS reference vectors before shipping new signers.** | Memory MCP `release:v3.19.1`; `recent.md` 2026-04-29 |
| **Step-up auth subsystem (v3.27.0) shipped with multiple invalidation triggers missing.** Pass A regression discovered that 6 of the 11 documented `ipam_sudo_invalidate()` events had no corresponding call site: role downgrade (`users.php:143`), `oidc_sub` link/unlink (`users.php:180`/`:194`), TOTP enroll (`totp_enroll.php:75`), Email OTP enroll (`change_password.php:272`), passkey add (`passkey_register.php`). Sudo grants outlive every MFA enrollment change and `oidc_sub` change in v3.27.0. **Rule:** when documenting a list of invalidation events in a contract doc (`auth-model.md` "Step-up auth" section), add a contract-enforcement test that grep-asserts each event's handler calls the invalidator. Same pattern caught Bug T as caught Bug X (function defined, never called). | Pass A regression 2026-05-08 §J.10 (Bug T); v3.27.1 hotfix scope |
| **OIDC re-auth return path validator rejects every relative path that callers actually use.** `lib/auth_step_up.php:480-489` requires `$returnPath[0] === '/'` and falls back to a hardcoded `'destinations.php'`. CodeRabbit round 3 flagged the open-redirect concern; the fix that landed protects against absolute-path escapes but rejects relative paths — and **every sudo handler passes a relative path** (`api_keys.php`, `change_password.php`, `db_tools.php`, `settings.php?tab=...`, `backup_admin.php?tab=destinations`). Net effect: every sudo-class action via OIDC re-auth silently drops on v3.27.0. The mirror validator at `step_up.php:30-49` accepts relative paths just fine — two parallel sanitisers with divergent rules. **Rule:** when CR suggests tightening a validator or guard, the same PR must add a test asserting existing valid use cases still pass. When validators with overlapping responsibilities exist, rationalise to a single shared helper or test the same input through both. | Pass A regression 2026-05-08 §J.8 (Bug Z); v3.27.1 hotfix scope |
| **OIDC auto-provision creates users with real password hashes instead of `!disabled` sentinel.** `claude-oidc` had `password_hash = '$2y$12$YNUoYOAF...'` — a usable bcrypt hash. The OIDC-only-admin lockout protection model in v3.27.0 (#1098) assumes the sentinel; auto-provisioned users bypass it. The regression test `SudoVerifyTest::testLockedPasswordHashIsNeverAcceptedAsProof` covers the sentinel rejection path but never fires in production because no auto-provisioned user has the sentinel. **Rule:** when introducing a sentinel value, audit every writer that creates a row of that type. Add a test that asserts production-shaped data carries the expected sentinel — not just that the sentinel is rejected when present. | Pass A regression 2026-05-08 §J.9 (Bug U); v3.27.2 |
| **`sudo_once` flag was defined but never consumed.** `ipam_sudo_consume_once()` exists at `lib/auth_step_up.php:91-94` and is documented as the consumption mechanism for `ttl_seconds=0` ("re-prompt every action"). Repo-wide grep finds zero callers. Effect: TTL=0 grants persist as session-long warm grants, weaker than even the 60-second timed grant. **Reproduced live with hard audit-log evidence in Pass A.** | Pass A regression 2026-05-08 §J.4 (Bug X); v3.27.1 hotfix scope |
| **A DB restore wipes data-table metadata but leaves remote artefacts.** Restoring a DB dump that pre-dates a backup run wipes the `backup_runs` row for that run while the corresponding file on S3 / SFTP / local-FS persists. Result is a "remote file with no DB row" pattern that looks like an orchestrator bug but is structural. The audit log doesn't help either — it's also wiped by the restore. v3.27.8 added `backup.run_recorded` audit at orchestrator post-INSERT and `db.restore` audit on the IPAMBKL1 logical-restore path so future investigations can distinguish restore-induced orphans from genuine orchestrator orphans (when audit_log is captured out-of-band). Generalisable rule: **any DB-only restore leaves out-of-band artefacts (remote files, sent emails, dispatched webhooks, scanner state) untouched** — restore-impact assessment must include those external systems, not just the schema. | Memory MCP `release:v3.27.8` Bug D verdict; `docs/superpowers/plans/2026-05-11_v3.27.8.md` |
| Three-state status reporting beats two-state when an envelope/credential can exist but be unreadable. Bug E in v3.27.8 showed a "Stored key present" badge contradicting a "No vault key configured" card — both used different two-state truth functions (`envelope_exists?` vs `unwrap_succeeded?`). Fix: `ipam_vault_status()` returns `{state: 'absent'\|'present'\|'unreadable', error_message}` and every UI site routes through it. **When the absence-vs-presence test is cheap but the readability test can fail with rich error detail, expose both axes.** | Memory MCP `release:v3.27.8` Bug E |

---

## 6. Process / collaboration

| Lesson | Source |
|---|---|
| In multi-part design discussions (e.g. step-through review of an overhaul doc), do **not** record AGREED on a one-character / one-word reply. The user may still be typing. Acknowledge as a hint, ask before committing the edit. | `feedback_wait_for_full_reply.md` |
| Always invoke the `ui-ux-pro-max` skill before and during any UI/UX work, planning or execution. | `feedback_ui_ux_skill.md` |
| Prefer `gh` and `git` CLI over MCP GitHub tools for all GitHub operations. MCP is fallback only. | `feedback_gh_over_mcp.md` |
| Always set `git config user.email "sean@seanmousseau.com"` before committing — the dev server auto-configures `sean@devbox.seanmousseau.com` which leaks into the commit log. | `feedback_git_identity.md` |
| Memory MCP discipline: `open_nodes(["user:sean"])` + `search_nodes("project:simple-php-ipam")` at every session start; write observations as you work; review and update the project entity after every release; close milestones/issues that shipped. | `feedback_memory_mcp_discipline.md`; CLAUDE.md → Memory MCP |
| **Verify the plan's threat model against the current code before scoping a PR.** v3.27.8's plan called for "drop silent plaintext fallback (Security category)" — a real concern when the plan was drafted on 2026-05-11 morning, but `ipam_backup_resolve_encrypt_to_tmp()` had already been hardened in v3.27.1 (Pass A 2026-05-08) and had no plaintext fallback by the time PR 5 came around. PR 5 was reframed mid-flight to a UX-only "destination-card failure badge" change. **Spend 10 minutes diffing the plan's premise against the current code before writing PRs to address it.** Plans drift fast when releases ship daily. | Memory MCP `release:v3.27.8` PR 5 scope analysis; `docs/superpowers/plans/2026-05-11_v3.27.8.md` |

---

## 6.5. CI vs local-gate divergence (rare but real)

| Lesson | Source |
|---|---|
| **PHPStan baseline entries can rot when code moves between files.** `phpstan-baseline.neon` ignores match by pattern AND file path. When a refactor extracts a code path into a new file (or deletes it), the old baseline entry no longer matches anything and CI fails with `ignore.unmatched (non-ignorable)` — local re-run reproduces it. The local gate WILL catch this if you re-run `vendor/bin/phpstan analyse` (full, not single-file) after any refactor that moves a code path; the single-file pre-commit hook does not. v3.28.1 #1177 hit this when `restore.php`'s discrete `$gConf['db_host']`-style reads moved into `lib/restore_dsn.php` and 5 baseline entries became orphans. Full procedure in `coding-guide.md § Static analysis tools`. | `docs/internal/coding-guide.md` § Static analysis tools; Memory MCP `roadmap:v3.28.1` |

## 7. Operational gotchas (cross-project)

| Lesson | Source |
|---|---|
| OneDrive paths are macOS file-provider mounts with TCC-gated `opendir()`. launchd agents can `creat()` files but cannot enumerate them — bash globs over `~/OneDrive/...` from a launchd-hosted script silently fail. Workaround: do filesystem enumeration inside `docker run alpine` (the Linux VM is not subject to macOS TCC). Applies to any future launchd job touching OneDrive. | `~/.claude/CLAUDE.md` → Memory MCP backup; Memory MCP `decision:onedrive-tcc-launchd` |

---

## 8. Architectural pattern: "feature added at one site, propagation to adjacent sites missed"

**This is the single most expensive lesson from the v3.21–v3.27 stream.** Pass A regression on 2026-05-08 surfaced 12 distinct bugs. Reviewed together, every single one is a variant of the same pattern: a feature was added at one site quickly, and the adjacent sites that needed to participate in the new contract were never updated.

The shape:

```
   [feature shipped at site A]  →  works in isolation, has tests
                                      but the caller / writer / sibling
   [site B still uses old contract] → tests don't exercise the contract
                                      between A and B; bug ships silently
```

Concrete instances from v3.21–v3.27:

| Bug | "Site A" (new) | "Site B" (not updated) |
|---|---|---|
| Encrypt-write-path | IPAMBKP3 codec (v3.24.0) + vault key infrastructure (v3.26.0) | Orchestrator at `lib/backup.php:396-408` still calls `ipam_backup_encrypt_to_tmp` with `app_secret` — never reads `ipam_backup_vault_key_get_raw()` |
| Restore-side dispatcher | Format-detecting dispatcher (`lib/backup.php:1241-1264`) | Was migrated; no propagation issue here. (Counter-example showing read-side migration WAS done correctly.) |
| Bug X — `sudo_once` consumption | Helper `ipam_sudo_consume_once()` defined (v3.27.0) | Zero handler call sites — function exists, never called |
| Bug Y — MFA-disable lockout guard | `ipam_sudo_policy_lockout_check()` for policy save (v3.27.0) | `change_password.php` MFA-disable handlers don't call the equivalent precondition |
| Bug Z — OIDC validator | Validator tightened per CR (v3.27.0) | Every existing relative-path caller now fails; mirror validator at `step_up.php:30` accepts them just fine |
| Bug T — Sudo invalidation | Contract documented in `auth-model.md` "Step-up auth" section (v3.27.0) | 6 of 11 listed events have no `ipam_sudo_invalidate()` call |
| Bug U — OIDC auto-prov password | OIDC-only admin model assumes `!disabled` sentinel (v3.27.0) | OIDC auto-provisioner writes a real bcrypt hash |
| Bug V — Settings toggle wipe | Per-key toggle path (v3.27.0 step-up policy registry) | Group-form text fields share the page; toggling submits per-key, page reloads, group fields lost |
| Bug W — `has_encrypted_runs` gate | `encryption_mode` column added v3.25.0 | Gate query treats every encrypted row as IPAMBKP3-equivalent; doesn't distinguish IPAMBKP2 (app_secret) from IPAMBKP3 (vault_key) |
| Bug S — IPAMBKL1 restore | Format added v3.23.0 with parser `ipam_restore_split_sql_statements` | Parser doesn't track string-literal state; `$2y$` in bcrypt hash inside `'…'` reads as a dollar-quote opener |
| Five observability gaps (O1–O5) | Cron unification + concurrency guards (v3.21–v3.22) | Audit/error_log/UI surface didn't follow; silent failures by construction |
| Legacy IPAMBKP2 nudge (v3.27.2 backlog) | Vault key adopted as the modern path | UI didn't surface "you're on the legacy path" to operators |

**Why it's correlated with v3.2x.x specifically:** rapid feature development with backwards-compat carry-over. The architectural complexity that creates "read migrated, write not" only emerges when there's BOTH a legacy path AND a new path being kept simultaneously. v3.21–v3.27 packed this kind of work into 3 weeks. Earlier code (v3.0–v3.20) had simpler architecture per release — fewer parallel paths, fewer migration windows. Bug density correlates with development velocity, not code age.

### How to spot the pattern in advance

When introducing a feature that creates a new contract between sites, ask:

1. **Read side AND write side?** If A reads via the new contract but writers still produce the old shape, you have an integration-test gap. (Encrypt-write-path; Bug U; Bug W)
2. **Function defined AND function called?** Repo-wide grep for callers of every new helper. Zero callers = the feature isn't actually wired. (Bug X)
3. **Documented event AND event-handler call sites?** Cross-reference the contract doc against handler greps. (Bug T)
4. **Validator tightened AND existing callers verified?** When CR or a security review tightens a guard, add a test pinning every existing valid use case. (Bug Z)
5. **Sentinel value AND every writer of that row type?** When introducing `!disabled` / `tenant_id=NULL` / similar markers, audit every writer that creates a row of that type. (Bug U)
6. **One UI surface AND one form path?** When two visually-adjacent controls submit through different paths, document explicitly or rationalise. (Bug V)
7. **Audit row AND backup_runs row AND error_log AND UI flash?** A failure event needs to surface through all the channels operators use to detect failure. Stderr alone doesn't count when prod cron is `>/dev/null 2>&1`. (Five observability gaps)

### Mandatory pre-PR checklist for feature work going forward

- [ ] Contract doc lists every event/site/sentinel that participates in the new contract
- [ ] Repo-wide grep for the new helper / sentinel / event verb shows callers in every documented site (or rationale why a site is exempt)
- [ ] At least one test exercises the contract end-to-end (round-trip / event-to-effect / write-then-read), not just the units in isolation
- [ ] If a fixture exists that bypasses the new contract for test convenience, at least one spec exercises the contract WITHOUT the fixture
- [ ] When tightening a validator/guard, a test pins the existing valid use cases with their actual production-shape inputs
- [ ] When introducing a sentinel value, a test asserts production-shaped data carries the sentinel (not just that the sentinel is rejected when present)
- [ ] Failure paths surface through audit + error_log + UI; not just stderr

### Test classes the suite is missing today

Adding these post-v3.27.1 prevents the next round of this pattern:

1. **Round-trip tests** for every persistence path. Encrypt → restore → byte-compare. Dump → restore → row-count + checksum. Write setting → read setting → equality. Currently the suite tests halves but rarely both halves wired together.
2. **Contract enforcement tests.** PHPStan-or-grep-style assertions: every helper defined under `lib/auth_step_up.php` has at least one caller; every action verb in `audit-actions.md` has at least one `audit()` call; every documented invalidation event has a corresponding handler call. These can be cheap shell scripts in CI.
3. **Negative regression tests** that pin existing valid use cases when a fix lands. The Bug Z arc — CR caught the open-redirect concern, fix tightened the validator, no test asserted relative paths still passed — is the textbook case for this missing class.

---

## How to use this doc

- **Reading:** scan the relevant section before starting work in that area. The "Source" column is where the authoritative version lives — go there for full context (incident details, line numbers, repro steps).
- **Adding a lesson:** only promote here when the same class of bug has bitten more than once, or when a single incident produced a generalisable rule. One-off bugs belong in their release entity in Memory MCP, not here.
- **Updating:** if a lesson is refined or invalidated, update the canonical source first (`CLAUDE.md`, the auto-memory file, or the release entity), then update the row here. This doc is a pointer index — keep it thin.
