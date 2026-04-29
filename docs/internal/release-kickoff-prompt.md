# Release kickoff prompt

> Paste this into Claude Code at the start of a release. Replace `<X.Y.Z>` with the target version. Tightened from the longer original to remove duplication while preserving every load-bearing rule.

---

Plan and execute release **v\<X.Y.Z\>** for Simple-PHP-IPAM.

**Read first:** `docs/internal/release-workflow.md`, `docs/internal/marketing-site.md`, `docs/internal/test-suites.md`, and the GitHub milestone for v\<X.Y.Z\> + Memory MCP entity `project:simple-php-ipam:roadmap:v<X.Y.Z>`. Identify orphan issues (no milestone, or from earlier milestones still open) and propose inclusion/deferral decisions before locking scope.

**Procedure docs to consult during execution (don't read upfront, reach for them when relevant):**
- `docs/internal/adding-a-migration.md` — every release that touches schema
- `docs/internal/adding-a-page.md` — every release that adds new PHP pages
- `docs/internal/investigating-ci-failure.md` — when CI goes red
- `docs/internal/hotfix-release.md` — only if this release is a hotfix off `main` instead of regular `dev → main`

**Use:** `superpowers:writing-plans` to plan, `superpowers:subagent-driven-development` to execute, `ui-ux-pro-max` for any UI/UX work, `documentation` for any README / API doc / ADR / user-guide writing, Memory MCP for state, semgrep MCP and `gh`/`git` CLIs throughout. Keep Memory MCP updated as you go (one observation per meaningful change, with short commit hash).

**Non-negotiable:**

- Follow `docs/internal/release-workflow.md` exactly. No shortcuts on the release gate.
- All local test failures must be fixed before committing — never dismiss as flake. A "flaky" failure is either an app bug or a test bug; resolve it, don't justify it.
- Docs (in `docs/`, `CHANGELOG.md`, `README.md`, `CLAUDE.md`) must be fully accurate before the PR opens.
- **No PR creation or merge without my explicit per-action approval.** Not inferred, not assumed.
- Close every milestone issue and clean up stale branches at the end.
- Prefer commands that don't require approval prompts; batch related ones into single tool calls when possible.
- The marketing website must be fully accurate and any new documentation added to it when the release is created on GitHub.
