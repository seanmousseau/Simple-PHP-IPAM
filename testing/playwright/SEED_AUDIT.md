# Playwright seed audit

Log of findings from running the Playwright suite against a freshly-seeded database
(via `testing/playwright/bootstrap-app.sh`). This document is populated over time as
CI runs expose gaps between `demo_seed.php` and what the tests expect.

## Audit process

1. Tear down any prior container: `bash testing/playwright/teardown-app.sh`
2. Start fresh: `bash testing/playwright/bootstrap-app.sh sqlite`
3. Run suite: `IPAM_BASE_URL=https://127.0.0.1:8443 IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo CI=true npx playwright test`
4. For each failing test, categorize:
   - **A** — test bug: assumes state that should not exist, has a stale selector, or asserts a feature that was removed/never implemented
   - **B** — seed gap: test needs data that `demo_seed_data()` in lib.php does not produce
   - **C** — environment-specific: test requires SSH, a real IdP, or dev-direct-only capabilities
5. For category A: fix the test and note it here with the commit.
   For category B: extend `demo_seed_data()` in `lib.php` and note it here.
   For category C: add an `isDevServer()`-style skip guard and note it here.

## Initial audit — 2026-04-14 (v2.5.2 #432, first containerized run)

### Run 1 — baseline against fresh seed

```
Run date:        2026-04-14
Container image: ipam-pw-apache:local
Suite wall clock: 8.4 minutes
Total test instances: 339 (including retries)
Passed:  306
Failed:  15
Flaky:   1  (passed on retry)
Skipped: 17
```

All 15 failures were **category A test bugs** exposed by running against a
freshly seeded database — none were seed gaps (`demo_seed_data()` proved
sufficient) and none were real app bugs. This is the exact value #432 was
designed to deliver: the test suite's latent assumptions about dev-direct
state surfaced in a single pass.

### Run 2 — after category-A fixes

```
Run date:        2026-04-14
Container image: ipam-pw-apache:local
Suite wall clock: 1.6 minutes  (Apache+HTTPS local container, vs ~25-30 min on dev-direct)
Total test instances: 339
Passed:  318
Failed:  0
Flaky:   1
Skipped: 20
```

Green. The three extra skips versus run 1 are the deliberate `test.skip()`
calls on the `--topbar-h` tests (category A4 below).

### Findings

| # | Spec | Tests | Category | Root cause | Resolution |
|---|---|---|---|---|---|
| A1 | `tests/aggregates.spec.ts` | 7 | A | `TEST_CIDR = '10.200.0.0/8'` is not network-aligned; the app normalized it to `10.0.0.0/8` and all subsequent row-lookup assertions missed the row they had just created. The IPv6 fixture `'2001:db8:agg::/48'` contained non-hex characters (`g`) so creation failed outright. | Changed fixtures to `'10.0.0.0/8'` and `'2001:db8:a::/48'`; replaced literal-string assertions with references to the constants and a `TEST_CIDR_V6_MATCH` substring. |
| A2 | `tests/js-behaviour.spec.ts` | 2 | A | Test locator was `.search-overlay` (class); the real element is `#search-overlay` (id). | Selector corrected in all three occurrences. |
| A3 | `tests/js-behaviour.spec.ts` | 2 | A | Tests assert a `--topbar-h` CSS custom property set by a `ResizeObserver` — but `grep -r topbar-h Simple-PHP-IPAM` returns zero hits. The feature does not exist in `app.js` or anywhere else. | Marked both tests as `test.skip()` with a comment pointing at this audit note. Delete or re-enable them in a later release if the feature is implemented. |
| A4 | `tests/pages.spec.ts:87` | 1 | A | Same `--topbar-h` feature used inside a `Sticky headers` describe block on pages.spec.ts. | `adminTest.skip(...)` with matching comment. |
| A5 | `tests/pd-pools.spec.ts` | 2 | A | Test looked for `h1` containing `"PD Pool"`, but the page actually renders `"IPv6 Prefix Delegation Pools"`. Second test assumed `ipv6SubnetId === null` implied no IPv6 subnets on the page at all, but the demo seed ships three IPv6 subnets (`2001:db8::/32`, `/48`, `/64`). | H1 assertion changed to `"Prefix Delegation"`; parent-picker logic rewritten to check whether the empty-state is visible and, if not, assert the picker has at least one option. |
| A6 | `tests/scan-schedule.spec.ts:103` | 1 | A | Two distinct bugs in one test:<br>1. subnets.php contains **two** `a.action-pill` elements linking to `scan_history.php?subnet_id=N` (lines 671 and 742). The first is the plain "Scan History" link in the action toolbar; the second is "Scan History & Schedule" with the status badge. `.first()` matched the wrong one.<br>2. The test was written as if subnets.php still rendered the scan-schedule form inline, but that form was moved to `scan_history.php` in v2.3.0 (#356). subnets.php only shows an Active/Inactive badge appended to the second pill. | Rewrote the test to locate the pill via `.filter({ hasText: /Schedule/i })` and assert it contains `"Active"`. Dropped the obsolete `select[name="scan_method"]` probe entirely. |

All six fixes landed in the same commit as the v2.5.2 release.

## Known skipped specs (not failures)

| File / test | Reason |
|---|---|
| `tests/timezone.spec.ts` (all tests) | Dev-direct-only; SSHes to patch remote config.php. Skip gated by `isDevServer()`. |
| `tests/htaccess.spec.ts` — vendor/ and dialects/ cases | Files do not exist until v2.9.0. Tests self-skip via `fs.existsSync` guard. |
| `tests/js-behaviour.spec.ts` — `--topbar-h` + sticky thead | Feature not implemented in app.js; see finding A3. |
| `tests/pages.spec.ts:87` — sticky thead offset | Same feature; see A4. |

## Known flaky tests

| Test | Notes |
|---|---|
| `tests/subnets.spec.ts:172` — "scan schedule details element is present for admin" | Failed on attempt 1 of run 2, passed on retry. Same scan-schedule `<details>` interaction as A6 but in a different spec; likely also sensitive to whether the schedule was just POSTed vs. being re-rendered on a fresh GET. If it flakes a second time, apply the same `.filter({ hasText: /Schedule/i })` pattern and expand the details explicitly. |

## Seed drift watch

Things about `demo_seed_data()` in `lib.php` that are easy to miss when editing:

- The seed preserves specific IDs (sites 1..6, VRFs 1..2, VLANs 1..4, etc.) so
  tests can reference them directly without discovery. Do not renumber without
  updating dependent tests.
- `netops-user` and `readonly-user` have `password_hash='!disabled'`, which is
  intentional — they exist for UI display (the users table showing mixed roles),
  not for test login. Tests that need a working readonly account call
  `ensureRoUser()` in `fixtures/ipam.ts` to create `pw-readonly` on demand.
- The `demo_mode.enabled` flag controls runtime behavior (restricts login to
  `demo/demo`, disables destructive actions). `bootstrap-app.sh` flips it **on**
  only long enough to run `demo_seed.php`, then flips it **off** so the Playwright
  suite can exercise normal admin flows with the seeded `demo/demo` account.
- The seed includes historical audit log entries with relative dates like
  `-27 days`. These shift every time the seed runs — tests that assert on
  specific dates will break. Prefer assertions on count or ordering over exact
  dates.
- **Role enum drift:** CLAUDE.md currently lists roles as `admin|readonly`, but
  the seed and `users.php` also accept `netops`. CLAUDE.md is stale; the three
  valid roles are `admin`, `netops`, and `readonly`. This is a CLAUDE.md bug to
  fix in a later pass, not a seed bug.
- The seed includes subnet `10.0.0.0/8` (id=1). This did NOT conflict with the
  now-corrected aggregates test fixture (`TEST_CIDR = '10.0.0.0/8'`) because
  aggregates and subnets are separate tables with no shared uniqueness
  constraint. If that ever changes, the aggregates spec will need a different
  fixture.
