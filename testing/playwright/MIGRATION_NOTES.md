# Playwright suite — migration and portability notes

Context: during v2.5.2 planning (issue #428) the suite was audited for dev-direct-only
assumptions in preparation for the containerized CI harness (#429, #430). The CDP →
`@playwright/test` rewrite originally scoped into #428 was found to already be
complete — the suite is on `@playwright/test` v1.52 and has been since an earlier
release. This file records what the audit actually found and what contributors need
to know when running against different targets.

## Targets the suite supports

| Target | `IPAM_BASE_URL` | HTTP Basic Auth | Admin creds |
|---|---|---|---|
| Local containerized (`bootstrap-app.sh`) | `http://127.0.0.1:8080` | off (no `IPAM_BASIC_USER`) | `demo` / `demo` (seeded by `demo_seed.php`) |
| CI nightly (`playwright-nightly.yml`) | `http://127.0.0.1:8080` | off | `demo` / `demo` |
| Dev-direct manual | `https://dev-direct.seanmousseau.com:8343/claude/ipam` | on (from `~/.claude/dev-secrets.env`) | `IPAM_ADMIN_USER` / `IPAM_ADMIN_PASS` from secrets |

The choice is entirely driven by environment variables — nothing is hardcoded in
the config file. `playwright.config.ts:4` defaults `baseURL` to `http://localhost:8080`
and `playwright.config.ts:23-25` makes `httpCredentials` conditional on
`IPAM_BASIC_USER` being set. `fixtures/ipam.ts:8-23` mirrors the same logic for
fetch-based calls inside `page.evaluate()`.

## Dev-direct-only tests

One spec file is intentionally dev-direct-only because it requires SSH to mutate
`config.php` on the remote host:

- `tests/timezone.spec.ts` — guarded by `isDevServer()` at `timezone.spec.ts:54-57`.
  Skips every test when `IPAM_BASE_URL` does not contain `192.168.80.15` or
  `dev-direct.seanmousseau.com`. Containerized runs will see these tests as
  skipped, not failed.

Everything else is target-agnostic and runs against any `IPAM_BASE_URL`.

## No CDP remnants

The original CDP suite referenced by the `cdp_test.py` comments in several spec
headers is gone. No `chrome-remote-interface` or `ws` dependencies remain in
`package.json`; no shared `claude-chrome` container is dialed; no CDP protocol
calls exist in fixtures. The `cdp_test.py` file still lives at
`testing/scripts/cdp_test.py` as a standalone Python harness unrelated to the
Playwright suite — do not confuse the two.

## What containerized runs will expose (handled in #432)

The bootstrap script in #429 runs `migrate.php` + `demo_seed.php` on every CI run.
`demo_seed.php` creates:

- `demo` / `demo` — admin, active
- `readonly-user` — role `readonly`, password `!disabled` (cannot log in)
- `netops-user` — role `netops`, password `!disabled` (cannot log in)

Tests that need a working readonly login (`access-control.spec.ts` for example)
currently call `ensureRoUser()` in `fixtures/ipam.ts:194` to create `pw-readonly`
on demand from an admin session, so that path already works on a fresh seed. Any
test that assumes a pre-existing non-admin login without going through
`ensureRoUser()` is a bug to fix under #432.

Hardcoded test data (CIDRs like `10.99.0.0/24`, VLAN 99, etc.) is declared in
`fixtures/ipam.ts:39-70` and used by tests as "data I create and then delete."
None of it assumes pre-seeded state. The only things the tests need from the seed
are (a) a working admin login and (b) enough data variety that pagination and
filter tests have something to render. `demo_seed.php` currently provides both,
but #432 will run the suite against a fresh seed and log any remaining gaps.

## Contributor checklist when adding a new spec

- Do not hardcode `dev-direct.seanmousseau.com`, `192.168.80.15`, `:8343`, or
  `/claude/ipam` — use `appUrl()` or a `baseURL`-relative `page.goto(...)` call.
- Do not hardcode admin credentials — use `ADMIN_USER` / `ADMIN_PASS` imports from
  `fixtures/ipam.ts`.
- Do not assume pre-seeded data unless `demo_seed.php` documents it. Prefer
  creating your own fixtures inside the test and cleaning up in `afterAll`.
- If the test requires a dev-direct-only capability (SSH, shared container, real
  IdP), add an `isDevServer()`-style skip guard at the top.
