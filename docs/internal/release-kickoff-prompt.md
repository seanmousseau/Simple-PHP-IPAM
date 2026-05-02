# Release kickoff prompt

> Paste this into Claude Code at the start of a release. Replace `<X.Y.Z>` with the target version. Tightened from the longer original to remove duplication while preserving every load-bearing rule.

---

Plan and execute release **v\<X.Y.Z\>** for Simple-PHP-IPAM.

**Read first:** `docs/internal/release-workflow.md`, `docs/internal/marketing-site.md`, `docs/internal/test-suites.md`, `docs/internal/lessons-learned.md`, and the GitHub milestone for v\<X.Y.Z\> + Memory MCP entity `project:simple-php-ipam:roadmap:v<X.Y.Z>`. Identify orphan issues (no milestone, or from earlier milestones still open) and propose inclusion/deferral decisions before locking scope. `lessons-learned.md` exists so we don't re-ship known classes of bug — read it before scope-lock.

**Re-validate every milestone issue body against current code before locking scope.** Issue bodies are written at filing time and may not reflect post-CR-round reality — multiple v3.18.0 #762 line items had been silently shipped during v3.17 CR rounds. For each open milestone issue, grep for the named function / file / line ranges and confirm the described problem still exists. Propose closing already-shipped line items as part of scope-lock. Do not assume the issue body is authoritative.

**Procedure docs to consult during execution (don't read upfront, reach for them when relevant):**
- `docs/internal/adding-a-migration.md` — every release that touches schema
- `docs/internal/adding-a-page.md` — every release that adds new PHP pages
- `docs/internal/adding-a-setting.md` — when introducing a tunable setting (registry shape, sensitive handling, cascade)
- `docs/internal/adding-an-api-endpoint.md` — when extending api.php (handler shape, readonly-key check, OpenAPI spec)
- `docs/internal/adding-a-runtime-dependency.md` — when adding a Composer package or vendored asset
- `docs/internal/auth-model.md` — when touching login, OIDC, MFA, or session config
- `docs/internal/audit-actions.md` — when adding a new audit action
- `docs/internal/deploy-targets.md` — Phase 4 deploys; covers all 7 targets + rollback
- `docs/internal/investigating-ci-failure.md` — when CI goes red
- `docs/internal/incident-response.md` — only if a regression surfaces post-merge or post-deploy
- `docs/internal/hotfix-release.md` — only if this release is a hotfix off `main` instead of regular `dev → main`
- `docs/internal/coderabbit-config.md` — when CR's pre-merge checks use the wrong threshold (early-access inheritance gotcha)

**Use:** `superpowers:writing-plans` to plan, `superpowers:subagent-driven-development` to execute, `ui-ux-pro-max` for any UI/UX work, `documentation` for any README / API doc / ADR / user-guide writing, Memory MCP for state, semgrep MCP and `gh`/`git` CLIs throughout. Keep Memory MCP updated as you go (one observation per meaningful change, with short commit hash). **At release-end:** close milestone issues, update the project entity's "Current version" observation, write the RELEASED observation with tag commit + bundle SHA256 — the `feedback_memory_mcp_discipline.md` rule.

**Non-negotiable:**

- Follow `docs/internal/release-workflow.md` exactly. No shortcuts on the release gate.
- All local test failures must be fixed before committing — never dismiss as flake. A "flaky" failure is either an app bug or a test bug; resolve it, don't justify it. **Run all three drivers (sqlite, mysql, pgsql) when the release touches migrations, schema, dialects, or any DB binding.** v3.21.1 (MySQL HY093 param-reuse) shipped because the failing path was only exercised on SQLite locally.
- **Hand-rolled cryptographic signers (request signing, HMAC, JWT, KDF) require reference-vector tests** using values from the relevant spec, not just round-trip self-tests. v3.19.1 (S3 SigV4) shipped because the canonicalRequest divergence was outside our test surface.
- When changing settings/auth POST semantics, sweep specs for sibling-bool re-assertion bandages (`grep -rn "k_mfa__\|group: 'mfa'" testing/playwright/tests/`) — those bandages often encode load-bearing side-effect dependencies on the legacy group-cascade, not just self-contained setup. See `docs/internal/test-suites.md` "Tests that depend on cascade side-effects".
- Each spec that creates subnets at module scope MUST claim a unique CIDR (see `docs/internal/test-suites.md` "Each spec needs a unique CIDR"). Do not introduce a new spec that uses the legacy shared `TEST_CIDR2`.
- Always `git restore Simple-PHP-IPAM/config.php` and remove `*.prebootstrap-backup` / `data/sessions/` before any commit during a release session — `bootstrap-app.sh` overwrites `config.php` and these artifacts will land in commits otherwise.
- Docs (in `docs/`, `CHANGELOG.md`, `README.md`, `CLAUDE.md`) must be fully accurate before the PR opens.
- **No PR creation or merge without my explicit per-action approval.** Not inferred, not assumed.
- Close every milestone issue and clean up stale branches at the end.
- Prefer commands that don't require approval prompts; batch related ones into single tool calls when possible.
- Marketing website must be fully accurate before the GitHub release publishes. For every new `docs/<slug>.md`, link it from the relevant feature card on `front-page.php` (per `feedback_marketing_docs_links.md`) and run the **4-step cache purge sequence** (OPcache → cachedata wipe → `wp rewrite flush` → `wp litespeed-purge all`) — partial purges leave QUIC.cloud serving stale 404s.
