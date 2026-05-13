<!--
  Pull request template — Simple PHP IPAM
  Operating Mode §2 (binding) lives in docs/internal/roadmap.md and lessons-learned.md §8.
  Every box should be ticked OR have a one-line rationale why it doesn't apply.
-->

## Summary

<!-- 1–3 sentences. What changed and why. -->

## Type of change

<!-- Tick all that apply. -->

- [ ] Bug fix (`fix(scope):`)
- [ ] New feature (`feat(scope):`)
- [ ] Refactor (no behavioural change)
- [ ] Documentation (`docs(scope):`)
- [ ] CI / tooling (`ci(scope):` or `chore(scope):`)
- [ ] Test-only

## Mandatory pre-PR checklist (Operating Mode §2)

<!-- From `lessons-learned.md` §8. Each item must be ticked OR have a one-line "N/A because …" rationale. -->

- [ ] **Contract doc lists every event / site / sentinel** that participates in the new contract (or N/A: no new contract introduced)
- [ ] **Repo-wide grep for the new helper / sentinel / event verb** shows callers in every documented site, or rationale why a site is exempt (or N/A: no new helper / sentinel / verb)
- [ ] **At least one test exercises the contract end-to-end** — round-trip / event-to-effect / write-then-read — not just the units in isolation (or N/A: pure rename / docs / chore)
- [ ] **If a fixture exists that bypasses the new contract for test convenience**, at least one spec exercises the contract WITHOUT the fixture (or N/A: no bypass fixture)
- [ ] **When tightening a validator/guard**, a test pins the existing valid use cases with their actual production-shape inputs (or N/A: not tightening anything)
- [ ] **When introducing a sentinel value**, a test asserts production-shaped data carries the sentinel (not just that the sentinel is rejected when present) (or N/A: no sentinel)
- [ ] **Failure paths surface through `audit()` + `error_log()` + UI** — not just `stderr` (or N/A: no new failure path)

## Local gate (`docs/internal/test-suites.md`)

- [ ] `php -l` on every changed `.php` file
- [ ] `vendor/bin/phpstan analyse` green
- [ ] `vendor/bin/phpcs` green
- [ ] `vendor/bin/phpunit` green
- [ ] `semgrep --config=.semgrep/rules.yml Simple-PHP-IPAM/` — no new findings
- [ ] **3-driver dockerized Playwright pass** — `bootstrap-app.sh sqlite|mysql|pgsql` all green (never push without this; GH Action minutes are finite paid resource)

## Schema / migration changes (if any)

<!-- Skip this section if no schema/migration change. -->

- [ ] `migrations.php` closure added; `ALTER TABLE` guarded with `PRAGMA table_info()` check
- [ ] **All three schema files updated in sync** — `schema.sql` + `schema.mysql.sql` + `schema.pgsql.sql` (or `multi-engine-schema-parity` agent run clean)
- [ ] `php tools/generate-data-dictionary.php` rerun; `docs/internal/data-dictionary.md` committed
- [ ] `migration-reviewer` agent run clean (FK-cascade, PRAGMA-in-tx, RENAME, NULL-UNIQUE footguns)
- [ ] `IPAM_VERSION` bumped in `version.php`; `CHANGELOG.md` entry added; cache-buster `?v=X.Y.Z` updated in `page_header()` AND `demo_gate.php`

## Documentation

- [ ] `docs/` user-facing pages updated (or N/A)
- [ ] `docs/internal/` procedure docs updated (or N/A)
- [ ] `CLAUDE.md` updated if a new convention / gotcha was discovered (or N/A)
- [ ] Memory MCP observation added on the relevant `project:simple-php-ipam:*` entity with the short commit hash (or N/A: pre-merge state)

## Linked issues

<!-- "Closes #NNNN" / "Fixes #NNNN" / "Refs #NNNN". -->

## Test plan

<!-- How to verify this works. Bulleted markdown checklist of TODOs for testing. -->

-

## Notes for reviewers

<!-- Anything that's NOT in the diff but the reviewer should know — context, gotchas, deliberate non-changes, follow-up work that intentionally isn't in this PR. -->
