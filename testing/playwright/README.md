# Simple PHP IPAM — Playwright test suite

End-to-end browser tests for Simple PHP IPAM. Runs against either a real dev-direct
install or a fully containerized local Apache+PHP instance. This directory is
contributor-facing only — it is not part of a release bundle.

## Layout

```
testing/playwright/
├── playwright.config.ts      # base URL, retries, timeouts, reporters
├── fixtures/ipam.ts          # helpers (login, CSRF, subnet/VLAN/tag CRUD), test constants
├── tests/                    # 32 spec files, one per feature area
├── bootstrap-app.sh          # starts the Dockerized IPAM instance used by CI and local runs
├── teardown-app.sh           # stops and removes the container
├── Dockerfile.apache         # php:8.2-apache with pdo_sqlite, mod_ssl, self-signed cert
├── scripts/flake-rate.mjs    # computes flake rate from playwright-report/results.json
├── MIGRATION_NOTES.md        # portability notes, target matrix, contributor checklist
├── SEED_AUDIT.md             # running log of seed/test mismatches discovered via nightly CI
└── README.md                 # this file
```

## Running the suite

### Containerized local (recommended for development)

Requires Docker. Builds a `php:8.2-apache` image with `pdo_sqlite`, `mod_ssl`, and
a self-signed cert; starts it on `https://127.0.0.1:8443`; seeds from `demo_seed.php`;
runs Playwright against it.

```bash
# One-time install (or after package-lock changes)
(cd testing/playwright && npm ci && npx playwright install chromium)

# Boot the app
bash testing/playwright/bootstrap-app.sh sqlite

# Run the suite
(cd testing/playwright && \
  IPAM_BASE_URL=https://127.0.0.1:8443 \
  IPAM_ADMIN_USER=demo \
  IPAM_ADMIN_PASS=demo \
  npx playwright test)

# Tear down
bash testing/playwright/teardown-app.sh
```

Run a single spec: `npx playwright test tests/auth.spec.ts`. Run a single test within
a spec: `npx playwright test tests/pages.spec.ts:42`. Add `--reporter=line` for
concise output during debugging.

The bootstrap overwrites `Simple-PHP-IPAM/config.php` with a test config and wipes
`Simple-PHP-IPAM/data/ipam.sqlite`. If you have a local dev database in that
location, back it up first.

### Manual against dev-direct

For scenarios the containerized harness can't cover — real-IdP OIDC, timezone
tests that SSH to the remote host, or any ad-hoc verification on the shared dev
server. See `CLAUDE.md` → "Running the test suites" → "Manual against dev-direct"
for the full invocation including the seven recurring footguns
(login_attempts cleanup, stale cron processes, password drift, etc.).

### CI

The nightly workflow `.github/workflows/playwright-nightly.yml` runs two jobs:

1. **`containerized-playwright`** — full suite against the Dockerized app.
   Matrix is `driver: [sqlite]` today; gains `mysql` in v2.10.0 and `pgsql` in
   v2.11.0. Target wall clock: under 40 minutes.
2. **`htaccess-subset`** — runs only `tests/htaccess.spec.ts` against the same
   image. Verifies the `.htaccess` deny rules that a `php -S` runner could not
   cover. Target wall clock: under 15 minutes.

Both jobs also run on PRs to `dev` or `main` that touch `Simple-PHP-IPAM/**`,
`testing/playwright/**`, or the workflow file.

## Writing a new test

- Import `adminTest` from `fixtures/ipam.ts` instead of bare `test` for anything
  that needs an authenticated session. It logs in as admin `beforeEach` and
  logs out `afterEach`.
- Prefer `baseURL`-relative paths (`page.goto('subnets.php')`) or `appUrl('…')`
  helpers over hardcoded absolute URLs. Never hardcode `dev-direct.seanmousseau.com`,
  `192.168.80.15`, `:8343`, or `/claude/ipam`.
- Use `ADMIN_USER` / `ADMIN_PASS` from `fixtures/ipam.ts`, not literal strings.
  Those constants read from `IPAM_ADMIN_USER` / `IPAM_ADMIN_PASS` env vars and
  fall back to `admin/admin` for dev.
- Create your own fixtures inside the test and clean up in `afterAll`. Do not
  assume pre-seeded state beyond what `SEED_AUDIT.md` documents.
- Use test data CIDRs from `fixtures/ipam.ts` (`TEST_CIDR1`, etc.) — they are
  chosen to not overlap the `demo_seed.php` subnets.
- If a test requires a dev-direct-only capability (SSH, real IdP, shared Chrome
  container), gate it with an `isDevServer()`-style skip at the top of the
  test body, following the pattern in `tests/timezone.spec.ts`.

## Debugging a CI failure

1. Open the failing run in GitHub Actions.
2. Download the `playwright-report-<driver>` artifact — it contains an HTML
   report with screenshots, traces (on retry), and the JSON reporter output.
3. Open `index.html` in a browser. Click any failing test to see the error,
   stack trace, and the trace viewer for visual playback.
4. If the failure is a cold-start race (container not ready, DB locked during
   seed), check the `Container logs on failure` step output in the workflow.
5. Reproduce locally with the same command the workflow uses:
   ```bash
   bash testing/playwright/bootstrap-app.sh sqlite
   (cd testing/playwright && \
     IPAM_BASE_URL=https://127.0.0.1:8443 IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
     CI=true npx playwright test tests/<failing>.spec.ts --retries=2)
   ```

## Flake policy

Containerized runs are inherently flakier than warm dev-direct runs — cold
caches, variable service startup, slower I/O. The suite tolerates this without
burning contributors out via three mechanisms:

### 1. Automatic retries in CI

`playwright.config.ts` sets `retries: process.env.CI ? 2 : 0`. A test that fails
on attempt 1 gets two more tries. It only fails the build if all three attempts
fail. Local runs retry zero times so flakes surface immediately.

Traces and screenshots are kept only for failed retries (`trace: 'retain-on-failure'`),
keeping CI artifact sizes reasonable.

### 2. Flake tagging

Tests that are known to be flaky can be annotated so reviewers can distinguish
"under investigation" from "new flake":

```typescript
test('sometimes races with the cron scanner', async ({ page }) => {
  test.info().annotations.push({
    type: 'flaky',
    description: 'races with scan_run when nightly cron overlaps — see #NNN',
  });
  // ... test body
});
```

The annotation is metadata only. It doesn't skip or change how the test runs.

### 3. Flake budget

Run `node testing/playwright/scripts/flake-rate.mjs` after each nightly to print
a flake rate and exit with a traffic-light code:

| Zone | Rate | Exit | Action |
|---|---|---|---|
| Green | < 2% | 0 | No action needed |
| Yellow | 2–5% | 1 | Investigate the top 3 flaky tests, tag or fix them |
| Red | > 5% | 2 | Pause new feature work; investigate root causes |

The rate is computed as `(tests that passed only after retry) / total tests`.
Tests that fail on every attempt are real failures, not flakes.

The weekly release-cadence review should include the current flake rate and
any tagged flaky tests. When a tagged test is fixed, remove the annotation in
the same commit.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `IPAM_BASE_URL` | `http://localhost:8080` | App root. Set to `https://127.0.0.1:8443` for containerized, or `https://dev-direct.seanmousseau.com:8343/claude/ipam` for dev-direct. |
| `IPAM_ADMIN_USER` | `admin` | App admin username. `demo` for containerized (seeded by `demo_seed.php`). |
| `IPAM_ADMIN_PASS` | `admin` | App admin password. `demo` for containerized. |
| `IPAM_BASIC_USER` | _(unset)_ | HTTP Basic Auth user for the `/claude/` gateway (dev-direct only). |
| `IPAM_BASIC_PASS` | _(unset)_ | HTTP Basic Auth password (dev-direct only). |
| `IPAM_TEST_PORT` | `8443` | Host port the Dockerized container binds its `:443` to. |
| `IPAM_TEST_IMAGE` | `ipam-pw-apache:local` | Docker image tag built by `bootstrap-app.sh`. |
| `IPAM_TEST_NAME` | `ipam-pw-test` | Docker container name. |
| `CI` | _(unset)_ | Set by GitHub Actions. Enables `retries: 2` and retained traces. |
