# Investigating a CI failure

> Procedural guide for diagnosing failures in the GitHub Actions CI pipeline. The goal is always to **reproduce locally** before guessing — most "weird CI failures" reproduce verbatim with the right driver locally.

## Step 1 — find the failing run

```bash
# By PR number
gh pr checks <PR>

# By branch (current branch by default)
gh run list --branch <branch> --limit 5

# Open the failure in browser
gh run view <run-id> --web
```

## Step 2 — pull the failed-step log

```bash
gh run view <run-id> --log-failed
```

This dumps just the failed step's stdout/stderr — usually enough to identify the failure. Pipe to `less` if it's long.

For the full run log: `gh run view <run-id> --log`.

## Step 3 — identify the **first** failure

Failures cluster. If the auth fixture or the login flow fails, every dependent test fails too — the count is meaningless. Always look at the first red test or first non-zero exit code, not the total.

Common first-failure shapes:

| Symptom | Usual cause |
|---|---|
| `database is locked` partway through Playwright | Stale `cron.php` or scan process holding the SQLite write lock (dev-direct only — containerized runs are fresh per job) |
| Every Playwright test redirects to `/login.php` | Auth fixture broken — wrong creds, rate-limited, container down |
| `Too many failed login attempts` | `login_attempts` table was not cleared; usually cascades from a prior bad-creds run |
| `SchemaParityTest` red | One of the three `schema.X.sql` files diverged from the others. Diff the failing pair and re-sync |
| MigrationTest red | A migration changes pre-existing data; the test asserts row preservation. Read CLAUDE.md "Schema migrations" → "Migration testing pitfalls" before patching |
| Semgrep red on lines that look fine | Project rule (`.semgrep/rules.yml`) caught real issue. Registry-default rules can false-positive (post-edit hook), but the project gate is what matters |
| `composer audit` flagged a CVE | Pin or upgrade the affected package — see "Runtime dependencies" in CLAUDE.md for the policy |
| PHPStan red on lines you didn't touch | Pre-existing baseline drift. Check `phpstan-baseline.neon` — fix the new error if real, do not extend the baseline to suppress real bugs |
| `playwright.yml` `api-tests` job red but local `test_api.sh` passes | The CI uses `DOCKER_CONTAINER=ipam-pw-test`; reproduce with the same env var (see `docs/internal/test-suites.md`) |

## Step 4 — reproduce locally

The CI Playwright job runs `bootstrap-app.sh <driver>` against a fresh Docker image. To reproduce verbatim:

```bash
# Use the same driver as the failing matrix job
bash testing/playwright/bootstrap-app.sh sqlite   # or mysql / pgsql

# For API failures
DOCKER_CONTAINER=ipam-pw-test bash testing/scripts/test_api.sh https://127.0.0.1:8443

# For Playwright failures — narrow to the failing spec
(cd testing/playwright && \
  IPAM_BASE_URL=https://127.0.0.1:8443 IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
  npx playwright test <file>.spec.ts:<line> --project=chromium --reporter=line)

bash testing/playwright/teardown-app.sh
```

If the failure repros, debug locally with `--debug` or `--ui`. If it does not repro, suspect:
- The CI image has different package versions (`composer.lock` drift between local and CI)
- Timing issues (CI is slower; add `await page.waitForLoadState('networkidle')`)
- Concurrency — the CI matrix runs jobs in parallel, but each job is in its own container, so this is unlikely

## Step 5 — download the Playwright HTML report (if applicable)

For Playwright failures the workflow uploads an HTML report as an artifact:

```bash
gh run download <run-id> --name playwright-report
# Open the unpacked report — it has screenshots + traces of every failed test
open playwright-report/index.html
```

The trace viewer is gold for failures that repro on CI but not locally — you see the exact DOM state at every step.

## Step 6 — fix the bug, do not dismiss the failure

> **Non-negotiable rule:** A flaky failure is either an app bug or a test bug. Never dismiss as "flake." Fix the underlying cause.

If a test is genuinely time-sensitive (e.g. depends on a webhook delivery that completes asynchronously), the fix is usually `await page.waitForResponse()` or `expect(...).toPass({ timeout })` — not retries, not skips, not `.skip(true)`.

## Step 7 — push the fix

After fixing locally and getting the local 3-driver gate green, push the branch. CI re-runs automatically. Required reading before push: `docs/internal/test-suites.md` "Local gate" section.

## CI workflow files

For reference when you need to know what runs:

- `.github/workflows/php-qa.yml` — lint + PHPStan + PHPCS + PHPUnit on every push and PR
- `.github/workflows/playwright.yml` — full containerized Playwright + `test_api.sh` per driver matrix on every PR
- `.github/workflows/composer-audit.yml` (if present) — runtime-dep CVE check, scheduled nightly + on PR

## Cross-references

- `docs/internal/test-suites.md` — Local gate, how to run the containerized harness, dev-direct footguns.
- `CLAUDE.md` "Static analysis & testing" — tool-by-tool overview.
- `CLAUDE.md` "Schema migrations" → "Migration testing pitfalls" — required when MigrationTest is red.
