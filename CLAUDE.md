# CLAUDE.md — Simple PHP IPAM

Hot-cache pointer set for AI assistants. Detail lives in `docs/internal/` — read those at the moment a task touches their area. Read this file fresh every session; it changes.

---

## Session start

```text
open_nodes(["user:sean"])
search_nodes("project:simple-php-ipam")
```

Two cheap calls. First loads global profile + Memory MCP rules from `~/.claude/CLAUDE.md`. Second loads everything scoped to this project. Project slug for entity naming: **`simple-php-ipam`**.

If Memory MCP is unavailable, surface it immediately — do not silently work without session state.

---

## Where to read next

The single-source-of-truth doc set is `docs/internal/README.md`. Use the routing table below; do not duplicate or paraphrase content from those docs in this file.

| Intent | Read |
|---|---|
| Architecture, invariants, "why does the code look this way" | `docs/internal/design-document.md` |
| Conventions, validation patterns, PR-time gates | `docs/internal/coding-guide.md` |
| Test layers, anti-patterns, CI | `docs/internal/testing-guide.md` (procedure in `test-suites.md`) |
| UI / CSS / JS conventions | `docs/internal/design-guide.md` |
| Threat model, trust boundaries | `docs/internal/security-model.md` |
| Login / OIDC / MFA / step-up implementation | `docs/internal/auth-model.md` |
| API versioning, response envelope, error codes | `docs/internal/api-contract.md` |
| Column / FK / index lookup across all 3 engines | `docs/internal/data-dictionary.md` |
| What every config knob does | `docs/internal/config-reference.md` |
| Production incident or deploy issue | `docs/internal/runbooks.md` |
| Cross-release lessons | `docs/internal/lessons-learned.md` |
| Cutting a release | `docs/internal/release-workflow.md` (or `hotfix-release.md`) |
| Adding a migration / page / setting / endpoint / dep | `docs/internal/adding-*.md` |
| `lib/` module layout, which module a function belongs in | `docs/internal/coding-guide.md` (cheat sheet) + `docs/internal/design-document.md` (Code organisation) |

---

## Hot-cache invariants (read every session)

These are the rules a fresh agent must hold in working memory before touching code. Full table of 22 invariants — each with the protected code location and originating incident — is in `docs/internal/design-document.md`.

1. **Binary IPs are stored at native length (4B/16B) and bound via `ipam_bind_binary()` with `PARAM_LOB`.** Anything else corrupts data on at least one engine.
2. **`apply_migrations()` brackets every migration with `PRAGMA foreign_keys = OFF`** (set before `BEGIN`). FK-on `DROP TABLE` cascades child rows — root cause of the v2.2.1 wipe.
3. **Three schema files (`schema.sql`, `schema.mysql.sql`, `schema.pgsql.sql`) stay structurally in lockstep.** After any schema edit run `php tools/generate-data-dictionary.php`; `SchemaParityTest` and `DataDictionaryDriftTest` fail CI on drift.
4. **CSRF (`csrf_require()`) on every browser POST; `e()` on every HTML output.** `api.php` is the only CSRF-exempt surface (stateless Bearer).
5. **`app_secret` lives in `config.php`, never the DB.** Per-tenant keys (v4+) HKDF-derive from it; vault key likewise stays out of DB.
6. **No `git push` or PR merge without explicit per-conversation authorisation.** Previous yes does not carry forward.
7. **Local 3-driver gate before push.** `bash testing/bootstrap-app.sh sqlite|mysql|pgsql` + Playwright all green. CI minutes are paid; don't use CI as the test runner.
8. **No `global $config;` in extracted `lib/*.php` modules — use the `ipam_config()` accessor (ADR-003).** `global $db` (runtime PDO handle) is still permitted. Full sweep tracked as #1207.

---

## Current shipped version

**v3.30.0** (see `Simple-PHP-IPAM/version.php`). Anything in the internal docs citing a version ≥ v4.0.0 is forward-looking design — do not apply to current code.

---

## Quick start (new session)

```bash
composer install                              # one-time, installs dev tools to vendor/
bash testing/bootstrap-app.sh sqlite          # spin up dockerized app + seed for local testing
vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=1G && vendor/bin/phpcs
```

Web root is `Simple-PHP-IPAM/` (the subdirectory, not the repo root). Bootstrap entry is `Simple-PHP-IPAM/init.php`.

For containerized Playwright + dev-direct fallback + the recurring footguns, read `docs/internal/test-suites.md` before pushing.

---

## Branching

- **All development happens on `dev`** (or feature branches off `dev`).
- **Never commit directly to `main`.**
- **PRs go `dev` → `main`** only. Do not target any other base branch unless explicitly told.
- Hotfixes branch off `main`; sync `dev ← main` after. Procedure: `docs/internal/hotfix-release.md`.

---

## In-repo Claude Code automations

Project-local agents, skills, hooks under `.claude/`. Use them — they encode procedure-doc rules.

**Subagents** (Agent tool with `subagent_type`):
- `migration-reviewer` — after editing `migrations.php`.
- `multi-engine-schema-parity` — after editing any `schema.*.sql` or migration that adds a table.
- `ip-binary-auditor` — diffs touching `ip_bin`/`network_bin`, IP parsing, subnet math, ping/scan, binary DB binds.
- `phpcs-style-fixer` — before commit on `.php` edits.

**Skills** (Skill tool or `/<name>`):
- `/add-migration` — scaffold a migration with FK-safe patterns.
- `/release-kickoff` — plan a release end-to-end (pulls in `release-workflow.md`, `marketing-site.md`, `test-suites.md`).
- `/release-gate` — local gate runner.

**Hooks** (auto on Edit/Write):
- `block-sensitive-paths.sh` — refuses edits to release tarballs, `data/`, `config.php`, `vendor/`, `node_modules/`.
- `php-lint.sh` — `php -l` on every edited `.php`.
- `phpstan-changed.sh` — single-file PHPStan L9 on edited files under `Simple-PHP-IPAM/`.

**MCP servers** (`.mcp.json` + user-scope `MCP_DOCKER`): SQLite (project-scoped to `data/ipam.sqlite`), Memory MCP, Semgrep, Playwright, Chrome DevTools, context7. Memory MCP is the agent session-state store — see `~/.claude/CLAUDE.md`.

---

## Commit style

```
feat(scope): short description
fix(scope): short description
docs(scope): short description
```

Always set `git config user.email "sean@seanmousseau.com"` before committing — dev-server auto-configures `sean@devbox.seanmousseau.com` otherwise.

Include `https://claude.ai/code/session_...` in commit body when working in Claude Code.

---

## When to update this file

This file is the hot-cache pointer set. Update only when:

- A new top-level invariant joins the table (it must already be a row in `design-document.md`).
- The routing table needs a new intent → doc mapping because a new internal doc was added.
- Branching policy, session-start procedure, or version constant changes.

**Do not** add per-subsystem detail here — put it in the appropriate internal doc and add a routing-table row. The trim-and-point pattern is what keeps this file useful across sessions.
