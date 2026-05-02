# Lessons learned

Consolidated rollup of hard-won lessons across releases. **This doc is a curated index, not the source of truth.** Each entry points back to where the rule lives — `CLAUDE.md`, the per-release Memory MCP entity, or a `feedback_*.md` auto-memory file. Update it when a new lesson generalises across more than one release.

Last refreshed: 2026-05-02 (post v3.21.1 hotfix).

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

---

## 5. Authentication, sessions, and security

| Lesson | Source |
|---|---|
| Open-redirect canonicalisation must reject `\\` in addition to `//`. Backslash-in-URL is a real bypass vector (browsers normalise `\` to `/`). v3.15.1 stash sanitiser. | Memory MCP `release:v3.15.1` |
| `ipam_config_stale_keys()` only walks `array_keys($config)` (top-level). Dotted whitelist entries like `session.absolute_lifetime_minutes` are silent no-ops — use the top-level key (`session`). False positive caused the v3.6.0 security keys to be flagged for deletion in v3.15.0; would have nuked every TOTP enrollment. | Memory MCP `release:v3.15.1` |
| `app_secret` lives in `config.php`, never in the DB. Storing the key that protects DB data inside that same DB defeats the security model. Per-tenant keys in v4.0.0 are derived via HKDF from `app_secret` at runtime, not stored. | CLAUDE.md → `app_secret` and per-tenant key derivation |
| S3 SigV4 `canonicalRequest` bug — caught in production after v3.19.0 ship and hotfixed same day in v3.19.1. Auth + dump handling needed correction. **Validate signed requests against AWS reference vectors before shipping new signers.** | Memory MCP `release:v3.19.1`; `recent.md` 2026-04-29 |

---

## 6. Process / collaboration

| Lesson | Source |
|---|---|
| In multi-part design discussions (e.g. step-through review of an overhaul doc), do **not** record AGREED on a one-character / one-word reply. The user may still be typing. Acknowledge as a hint, ask before committing the edit. | `feedback_wait_for_full_reply.md` |
| Always invoke the `ui-ux-pro-max` skill before and during any UI/UX work, planning or execution. | `feedback_ui_ux_skill.md` |
| Prefer `gh` and `git` CLI over MCP GitHub tools for all GitHub operations. MCP is fallback only. | `feedback_gh_over_mcp.md` |
| Always set `git config user.email "sean@seanmousseau.com"` before committing — the dev server auto-configures `sean@devbox.seanmousseau.com` which leaks into the commit log. | `feedback_git_identity.md` |
| Memory MCP discipline: `open_nodes(["user:sean"])` + `search_nodes("project:simple-php-ipam")` at every session start; write observations as you work; review and update the project entity after every release; close milestones/issues that shipped. | `feedback_memory_mcp_discipline.md`; CLAUDE.md → Memory MCP |

---

## 7. Operational gotchas (cross-project)

| Lesson | Source |
|---|---|
| OneDrive paths are macOS file-provider mounts with TCC-gated `opendir()`. launchd agents can `creat()` files but cannot enumerate them — bash globs over `~/OneDrive/...` from a launchd-hosted script silently fail. Workaround: do filesystem enumeration inside `docker run alpine` (the Linux VM is not subject to macOS TCC). Applies to any future launchd job touching OneDrive. | `~/.claude/CLAUDE.md` → Memory MCP backup; Memory MCP `decision:onedrive-tcc-launchd` |

---

## How to use this doc

- **Reading:** scan the relevant section before starting work in that area. The "Source" column is where the authoritative version lives — go there for full context (incident details, line numbers, repro steps).
- **Adding a lesson:** only promote here when the same class of bug has bitten more than once, or when a single incident produced a generalisable rule. One-off bugs belong in their release entity in Memory MCP, not here.
- **Updating:** if a lesson is refined or invalidated, update the canonical source first (`CLAUDE.md`, the auto-memory file, or the release entity), then update the row here. This doc is a pointer index — keep it thin.
