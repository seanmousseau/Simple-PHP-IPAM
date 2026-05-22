# Testing guide

> **Audience:** developer writing or maintaining tests. Conceptual reference for the test layers, what each one is for, and the anti-patterns the suite has accumulated. The procedural "how to actually run them" lives in `test-suites.md` and is not restated here.

---

## Test layers

| Layer | Lives in | What it tests | Run command (full detail in `test-suites.md`) |
|---|---|---|---|
| PHP lint | — | Parse errors | `php -l <file>` per changed file |
| Static analysis | `phpstan.neon`, `phpstan-baseline.neon` | Type errors, undefined variables, dead code at level 9 | `vendor/bin/phpstan analyse --memory-limit=1G` |
| Style | `.phpcs.xml` | PSR-12 with documented project exclusions | `vendor/bin/phpcs` |
| Security taint | `.semgrep/rules.yml` | XSS, path traversal, SQLi, open redirect, IP injection | `semgrep --config=.semgrep/rules.yml Simple-PHP-IPAM/` |
| PHPUnit unit | `tests/UtilTest.php` and peers | Pure functions in `lib.php` with no DB/session dependency | `vendor/bin/phpunit` |
| PHPUnit integration | `tests/MigrationTest.php`, `tests/SchemaParityTest.php`, `tests/DataDictionaryDriftTest.php`, `tests/SudoInvalidateWiringTest.php`, etc. | Migrations on an in-memory DB; cross-engine schema parity; data-dictionary drift; contract enforcement across handlers | `vendor/bin/phpunit` |
| API smoke | `testing/scripts/test_api.sh` | End-to-end API surface against a running deployment | See `test-suites.md` → "test_api.sh" |
| Playwright E2E | `testing/playwright/specs/` | Browser flows against a containerized or dev-direct install | See `test-suites.md` → "Containerized" and "Playwright (dev-direct)" |

CI (`.github/workflows/php-qa.yml` + `playwright.yml`) runs all of the above on `dev`/`main` push and on PRs targeting `main`. **Locally exercising the same flow before push is mandatory** — see "Local gate" in `test-suites.md`. GH Action minutes are paid; the rule is do not push without a full local 3-driver pass when the change could touch any driver-specific code path.

---

## Unit vs Integration: where does my test go?

PHPUnit is split into two named testsuites. The rule is mechanical:

| Suite | Directory | Criterion |
|---|---|---|
| **Unit** | `tests/unit/` | Pure PHP — no DB, no file I/O outside the test class, no network |
| **Integration** | `tests/integration/`, `tests/Migration/`, `tests/Helpers/` | Touches `ipam_test_db_sqlite()`, `InMemoryDb`, `new PDO(…)`, any DSN literal, `apply_migrations`, `ipam_db_init`, or writes/reads files outside the test class (e.g. `sys_get_temp_dir()`, `data/tmp/`) |

**When in doubt, put it in Integration.** A false-positive integration test costs ~1 second in a longer suite. A false-positive unit test pollutes the fast suite and hides the dependency.

### Run commands

```bash
vendor/bin/phpunit --testsuite Unit         # ~2 s — safe to run on every save
vendor/bin/phpunit --testsuite Integration  # ~30 s — run before push
vendor/bin/phpunit                          # both, Unit first

# composer shortcuts
composer test:unit
composer test:integration
composer test          # both, Unit runs first (fast-fail)
```

### Example placements

| Test | Suite | Reason |
|---|---|---|
| `HkdfTest` — pure HKDF derivation | Unit | No I/O |
| `PasswordPolicyTest` — regex validator | Unit | No I/O |
| `MigrationTest` — applies closures to in-memory DB | Integration | `new PDO` |
| `LocalBackupClientTest` — writes to `data/tmp/` | Integration | File I/O under `data/` |
| `MigrationLinterTest` — writes fixture files to `sys_get_temp_dir()` | Integration | File I/O |
| `RestoreStagingPathTest` — creates files under `data/tmp/` | Integration | File I/O under `data/` |

---

## When to add what

| Change | New test? | Where |
|---|---|---|
| Pure utility function in `lib.php` (no DB, no session) | Unit test | `tests/UtilTest.php` |
| Migration closure | Integration test asserting the data shape before/after | `tests/MigrationTest.php` and `MigrationTest::testApplyConvergesToSchemaSql` covers parity-vs-schema-files |
| Schema edit | None needed — `SchemaParityTest` + `DataDictionaryDriftTest` are automatic | — |
| New API endpoint | API smoke spec + a Playwright happy-path | `testing/scripts/test_api.sh` + `testing/playwright/specs/api-*.spec.ts` |
| New audit action | Grep-style assertion that the verb has at least one call site | `tests/AuditActionContractTest.php` (add the verb to the expected-callsite list) |
| New sudo-class action | (1) Wiring assertion that `ipam_sudo_invalidate()` is called from state-changing handlers; (2) Playwright spec exercising the no-grant prompt path **without** the `warmSudoGrant` fixture | `tests/SudoInvalidateWiringTest.php` + `testing/playwright/specs/step-up-fan-out.spec.ts` |
| New runtime dependency | Round-trip test that exercises the library across the supported drivers | Where the dependency is consumed |
| New page | Playwright happy-path spec (auth, render, smoke action) | `testing/playwright/specs/<page>.spec.ts` |
| Bugfix | Regression test that fails before the fix and passes after. The PR description must quote the failure and the fix output side by side | Closest existing layer |

---

## Test classes the suite reaches for, by purpose

The Pass A regression sweep (2026-05-08) identified three classes the suite was missing pre-v3.27.1. They're now part of the expected shape of any new contract:

1. **Round-trip tests.** For every persistence path, write → read → byte-compare. Encrypt → restore → byte-equality. Dump → restore → row-count + checksum. Write setting → read setting → equality. The suite historically tested halves; the contract is now both halves wired together.
2. **Contract enforcement tests.** Every helper defined under `lib/<subsystem>.php` has at least one caller. Every action verb in `audit-actions.md` has at least one `audit()` call site. Every documented invalidation event has a corresponding handler call. These are cheap shell-or-PHPUnit grep assertions; they catch "function defined, never wired" bugs (Bug X, Bug T).
3. **Negative regression tests pinning existing valid use cases.** When a fix tightens a validator, the same PR adds tests asserting existing valid inputs still pass. The Bug Z arc — validator tightened per CodeRabbit, every relative-path caller broke silently — is the textbook case.

Adding these test classes as the contract introduces them prevents the "feature shipped at site A, sibling site B not updated" pattern documented in `lessons-learned.md` §8.

---

## Anti-patterns

### Don't bypass the path under test

The Playwright `warmSudoGrant()` fixture mints a sudo grant directly so step-up-gated specs can skip the prompt. This made the step-up specs faster and let several specs ship green — and also hid Bug Z, Bug X, and Bug T behind the fixture for an entire release.

**Rule:** when a fixture short-circuits a flow, the suite must contain at least one spec that exercises the flow without the fixture. Document the bypass explicitly in the fixture's docstring with a pointer to the non-bypassing spec.

### Don't rationalise transient failures

A test that fails "sometimes" is a real signal — either the test or the code under test has a race, an ordering dependency, or a leaked state. The v3.10.0 incident merged with 8 MySQL failures rationalised as "transient" and shipped a regression. Triage them; do not retry-and-pray.

### Don't share the dev DB between suites

The dev-direct path (`https://dev-direct.seanmousseau.com:8343/claude/ipam`) is shared and stateful. Before any suite run against it, work through the recurring footguns in `test-suites.md` → "Pre-flight cleanup":

- Kill stale `cron.php` / `ping` processes that hold the SQLite write lock.
- `DELETE FROM login_attempts` (the rate limiter is stateful).
- Verify admin password matches `~/.claude/dev-secrets.env`.
- Use `--data-urlencode` for passwords containing `!` in curl.
- Redirect Playwright output to a file; do not `tail` an interactive run.
- PHPStan needs `--memory-limit=1G`; default crashes parallel workers.

Containerized Playwright (`bootstrap-app.sh`) is the default — it avoids all of these. Use dev-direct only when the test requires a real deployment (real-IdP OIDC, timezone remote-config flow).

### Don't pin to one driver

Code paths that branch by engine (mysqldump vs pg_dump vs sqlite-stream; MYSQL_PWD vs PGPASSWORD vs `--defaults-extra-file`; `NOW()` vs `datetime('now')`) get tested under every driver they reach. Local SQLite + a single sanity-check is not enough — see `lessons-learned.md` §4 (the v3.22.0 destination orchestrator incident).

### Don't pin Docker package versions implicitly

If a Dockerfile depends on a flag whose support varies by package version (`--no-login-paths` works on MariaDB 11+, rejected on 10.x), either pin the package version in the Dockerfile, or use a runtime probe-and-cache helper to detect support and emit the flag conditionally. `apt-get install` is not reproducible across build moments — same Dockerfile, different pull moment, different package version. See `lessons-learned.md` §4 (the v3.22.1 `--no-login-paths` incident).

### Don't write a unit test for something that needs a contract test

A unit test for `ipam_sudo_consume_once()` that passes is necessary but not sufficient if no handler ever calls the function. The same rule applies to every helper, every event, every sentinel: unit tests cover the function; contract tests cover the wiring.

---

## CI configuration

| Workflow | Triggers | What runs |
|---|---|---|
| `.github/workflows/php-qa.yml` | push to `dev`/`main`; PR to `main` | php lint, PHPStan, PHPCS, PHPUnit |
| `.github/workflows/playwright.yml` | push to `dev`/`main`; PR to `main` | Containerized Playwright across SQLite, MySQL, PostgreSQL drivers; `api-tests` job runs `test_api.sh` |
| `.github/workflows/semgrep.yml` | push to `dev`/`main`; PR to `main` | Custom Semgrep ruleset |

A failing CI run can almost always be reproduced locally verbatim — the containerized Playwright job runs the same commands against the same image. If CI fails on something local passes, that's a state-leak signal; investigate before re-running.

`investigating-ci-failure.md` is the runbook when a check goes red on a PR.

---

## Cross-references

- `test-suites.md` — full procedure for running every suite locally (commands, env, footguns).
- `investigating-ci-failure.md` — playbook for red CI checks.
- `design-document.md` → invariants — what every test ultimately defends.
- `lessons-learned.md` §4 — testing-discipline lessons accumulated across releases.
- `coding-guide.md` → "PR-time gates" — the must-pass list before push.

---

## Update protocol

- New test class identified as missing → add to "When to add what" with the trigger.
- New anti-pattern surfaced in a regression → add to "Anti-patterns" with the originating incident.
- CI workflow added or changed → update the CI table.
- Procedural detail (commands, env vars, footguns) belongs in `test-suites.md`, not here. Keep this doc conceptual.
