# CI tiers

Simple PHP IPAM organizes its CI into four tiers. The structure exists so v2.10.0 (MySQL) and v2.11.0 (Postgres) have a predictable home, and so heavier round-trip migration tests do not bloat per-commit checks.

## Tier 1 — fast per-commit (blocking)

**Workflow:** `.github/workflows/php-qa.yml`

**Trigger:** every push to `dev`/`main`, every PR targeting `dev`/`main`.

**Target wall clock:** 5–8 minutes.

**What runs:**

- `composer validate --strict`
- `composer install` (with cached download dir)
- `composer audit --no-dev --locked` (fails on any CVE in a runtime dep)
- `php -l` on every PHP file under `Simple-PHP-IPAM/`
- `vendor/bin/phpstan analyse` (level 9)
- `vendor/bin/phpcs`
- `vendor/bin/phpunit`

**Engine matrix:**

| Engine | Version added |
|---|---|
| `sqlite` | shipped — v2.9.0 |
| `mysql` | placeholder — v2.10.0 (#384) |
| `pgsql` | placeholder — v2.11.0 (#388) |

The matrix uses `fail-fast: false` so one engine's failure does not cancel the others' runs. Each engine slot reports independently in the GitHub UI.

**Caching:** Composer's package cache is keyed on `composer.lock`. A no-dep PR reuses the prior cache entirely; a dep update pays one cold install and warms the cache for the next run.

**Concurrency:** in-progress runs for the same `ref` are cancelled when a new commit lands. Saves CI minutes on busy PRs without hiding the latest result.

## Tier 2 — path-filtered per-commit (blocking when applicable)

**Workflow:** does not exist yet — lands in v3.0.0 alongside `migrate_db.php` (#391).

**Trigger:** PRs that touch `migrate_db.php`, `dialects/`, `schema*.sql`, `migrations.php`, or `testing/samples/large-db-sample/`.

**What will run:**

- `migrate_db.php` 6-direction round-trip tests (SQLite ↔ MySQL ↔ Postgres × both directions)
- `MigrationTest` convergence: replay every migration on an empty DB and assert it matches `schema.X.sql`

**Why path-filtered, not nightly-only:** catches regressions on the PR that caused them. Unrelated PRs are not taxed with 5–10 minutes of I/O-heavy test work.

## Tier 3 — Playwright end-to-end (PR-triggered, non-blocking)

**Workflow:** `.github/workflows/playwright.yml`

**Trigger:** fires on PRs that touch `Simple-PHP-IPAM/**` or `testing/playwright/**` (path-filtered, separate from Tier 1) and on manual `workflow_dispatch`.

**What runs:**

- Containerized Playwright suite against `testing/playwright/Dockerfile.apache`
- `htaccess-subset` (HTTP-level deny rule verification)
- `api-tests` (#451 — wired in v2.9.0; runs `testing/scripts/test_api.sh` against the bootstrapped image)
- `composer outdated --direct` (informational, never blocks)

**Failure handling:** GitHub notification on red. No PR blocking. First task next morning is to investigate.

## Tier 4 — on-demand (manual)

Things that still require a real shared deployment and cannot easily containerize. Not in CI.

- Real-IdP OIDC flows (the bundled mock OIDC fixture covers code paths but not vendor-specific quirks)
- `timezone.spec.ts` (SSHes to patch remote config)
- Stress tests against a live MySQL/Postgres install
- Reverse-proxy CSP testing through dev-direct

These run from a developer machine following `CLAUDE.md` → "Manual against dev-direct". They are not automated and no plans exist to automate them further.

## How to add a new check

- **Always-on, fast:** add it to the `qa` job in `php-qa.yml`. Keep wall clock under 8 minutes per matrix slot.
- **Engine-specific:** add it to the same job — it inherits the matrix automatically and reports per engine.
- **Slow but conditional:** wait for Tier 2 (v3.0.0) and add path filters.
- **Best-effort, heavier:** add it to `playwright.yml` (runs on PRs that touch app or test code) and document it in this file.

## How to debug a failing matrix slot

The matrix's job name is `PHP QA (sqlite)` (or `(mysql)` / `(pgsql)` once those land). In the GitHub Actions UI, expand the matching slot to see the failing step. The first failure is almost always the right place to look — composer install / autoload errors cascade, but PHPStan / PHPCS / PHPUnit failures are independent.

If only one engine is red and the others are green, the bug is engine-specific — usually a SQL literal that differs across engines (something the v2.9.0 dialect refactor is supposed to prevent).
