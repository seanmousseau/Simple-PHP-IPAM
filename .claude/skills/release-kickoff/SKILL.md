---
name: release-kickoff
description: Plan and execute a Simple-PHP-IPAM release end-to-end. Use at the start of any release work — pulls in the release-workflow, marketing-site, and test-suites procedure docs, identifies orphan issues against the GitHub milestone, and binds the non-negotiable rules (no PR/merge without explicit approval, full local 3-driver gate before push, marketing site accuracy).
---

# Release kickoff

Plan and execute release **v\<X.Y.Z\>** for Simple-PHP-IPAM. Replace `<X.Y.Z>` with the target version when invoking.

## Inputs

- **Target version** (`X.Y.Z`) — required.
- **Release type** — regular (`dev → main`) or hotfix (off `main`)?

## Read first

- `docs/internal/release-workflow.md` — Phase 1–4 procedure
- `docs/internal/marketing-site.md` — simplephpipam.com update flow
- `docs/internal/test-suites.md` — local gate, 3-driver dockerized harness, dev-direct fallback footguns
- GitHub milestone for v\<X.Y.Z\> (`gh api repos/seanmousseau/Simple-PHP-IPAM/milestones`)
- Memory MCP entity `project:simple-php-ipam:roadmap:v<X.Y.Z>` (`search_nodes` first)

Identify orphan issues (no milestone, or in earlier milestones still open) and propose include/defer decisions before locking scope.

## Procedure docs (consult during execution, not upfront)

- `docs/internal/adding-a-migration.md` — every release that touches schema
- `docs/internal/adding-a-page.md` — every release that adds new PHP pages
- `docs/internal/investigating-ci-failure.md` — when CI goes red
- `docs/internal/hotfix-release.md` — only if this is a hotfix off `main`

## Tools to lean on

- `superpowers:writing-plans` to plan the release
- `superpowers:subagent-driven-development` to execute phases
- `ui-ux-pro-max` for any UI/UX work
- `documentation` skill for any README / API doc / ADR / user-guide writing
- Memory MCP (`mcp__MCP_DOCKER__*`) for state — one observation per meaningful change with short commit hash
- semgrep MCP throughout (taint analysis is project policy)
- `gh` and `git` CLIs (NOT GitHub MCP tools — see `feedback_gh_over_mcp`)
- Subagents: `migration-reviewer`, `multi-engine-schema-parity`, `phpcs-style-fixer`, `ip-binary-auditor`

## Non-negotiables

1. **Follow `docs/internal/release-workflow.md` exactly.** No shortcuts on the release gate.
2. **No PR creation, merge, or push without explicit per-action approval.** Not inferred, not assumed. Always ask.
3. **All local test failures must be fixed before commit** — never dismiss as flake. A "flaky" failure is either an app bug or a test bug; resolve it.
4. **Never push before the full 3-driver dockerized pass locally.** GH Action minutes are a finite paid resource. Run `bootstrap-app.sh sqlite|mysql|pgsql` all green first.
5. **Docs must be fully accurate before the PR opens** — `docs/`, `CHANGELOG.md`, `README.md` (latest release only — replace in place, do not append), `CLAUDE.md`.
6. **Marketing site accuracy is part of the release.** New `docs/*.md` must be linked from the relevant feature card on `website/front-page.php`. Cloudflare cache purge after deploy.
7. **Close every milestone issue and clean up stale branches** at the end.
8. **Memory MCP must be updated** as work happens (one observation per change with `git rev-parse --short HEAD`), and a final close-out observation on `project:simple-php-ipam:release:vX.Y.Z` after deploy with tag, merge commit, and bundle SHA256.
9. **Batch tool calls** that don't have ordering deps. Prefer commands that don't trigger approval prompts.

## Phase outline (full detail in `release-workflow.md`)

- **Phase 1** — Land content on `dev` (features merged, migrations green, docs current).
- **Phase 2** — Prep checklist + bundle build (`./releases/make_releases.sh`, SHA256SUMS, GH release draft).
- **Phase 3** — Review-comment handling + bundle rebuild if any code changed.
- **Phase 4** — Merge `dev → main`, tag, GitHub release publish, deploy to demo + prod + 4 testing instances, marketing site update + Cloudflare purge, milestone close, Memory MCP close-out.

## Output

Produce a written plan first (`writing-plans` skill), get approval, then execute via `subagent-driven-development`. Memory MCP gets the plan reference as an observation on the roadmap entity at kickoff and a release marker entity at close-out.
