# Test improvements — v3.28.0 operational plan

> **Status:** authoritative for v3.28.0 scope. References the binding `2026-05-08_Path_Forward.md` §1.5 (the three deliverables) and folds in concrete signals from Pass A (2026-05-08), Pass B (2026-05-09), and Pass C (2026-05-10).
>
> **Audience:** anyone scoping or implementing v3.28.0. Read this before opening any v3.28.0-milestoned issue.
>
> **Companion docs:** `2026-05-08_Path_Forward.md` (policy), `lessons-learned.md` §8 (root-cause patterns), `roadmap.md` §4 (release-level summary), `releases/ipam-3.27.6/regression-evidence/passC/PASS-C-SUMMARY.md` (Pass C findings backing this doc).

---

## 1. Why this doc exists

v3.21–v3.27 shipped 12 distinct bugs that passing CI + passing CodeRabbit + a passing 3-driver Playwright suite did not catch. Pass C surfaced 5 more contract-drift / observability gaps that were silently degrading the audit trail and a High-severity at-rest secret leak (webhook signing keys). Every one of these is in the **same shape**:

- A new helper / sentinel / event verb landed,
- A contract doc was authored describing how it should behave,
- The actual code did something subtly different (no caller, raw INSERT instead of helper, no audit row, wrong direction of check),
- No test exercised the contract *through the production code path*. Either no test existed, or the test used a fixture that bypassed the contract.

CI was green because the suite measured what the code *did*, not what the contract *promised*. v3.28.0 closes that gap.

## 2. The three deliverables (Path Forward §1.5)

### 2.1 Test fixture inventory + parallel-spec backfills

**Worked example:** `warmSudoGrant()` in `testing/playwright/tests/helpers/sudo.ts`. It minted a sudo grant directly in `$_SESSION` via a debug endpoint, so every step-up Playwright spec ran with sudo already satisfied. Bug X (sudo_once never consumed across 10 handlers), Bug Z (OIDC validator rejecting relative paths), and Bug T (6 of 11 invalidation events with no caller) were all invisible because the fixture skipped the production code path that would have exercised them.

**Deliverable A1 — fixture inventory.** Sweep `testing/playwright/tests/helpers/`, `tests/bootstrap.php`, `tests/Util*.php`, `tests/Migration*.php`, and `Simple-PHP-IPAM/testing/scripts/` for every helper that bypasses production code for test convenience. For each, document:

- Helper name + file path
- What production code path it bypasses
- Why bypass exists (speed, determinism, environment isolation)
- Whether ≥1 spec exists that exercises the bypassed path **without** the helper
- If not, what spec needs to be added

Output: `docs/internal/test-fixture-inventory.md`, one row per helper.

**Deliverable A2 — parallel-spec backfills.** For every helper in A1 with no non-bypassing parallel spec, write one. Naming convention: `<feature>-real.spec.ts` (the real path, no fixture) sits next to `<feature>.spec.ts` (the fixture-using path). The fixture spec stays — it exists for speed — but the real spec catches the contract failures the fixture hides.

**Known candidates from Pass C:**

| Fixture | Bypassed path | Real spec needed |
|---|---|---|
| `warmSudoGrant()` | Step-up dialog → submit → consume_once | `sudo-real-flow.spec.ts` (TOTP + relative-path return + invalidate-on-action) |
| Test-instance auto-login bootstrap | Login form → CSRF → reCAPTCHA → MFA branch | One spec that exercises the full login form per provider (local / OIDC / passkey) |
| `setTestSetting()` (Simple-PHP-IPAM/testing/scripts/set_test_setting.php) | The settings.php save handler (validation, audit, step-up gate) | `settings-save-real.spec.ts` — exercise the form path for each `ALLOWED_KEYS` entry |
| Direct DB seeding in Playwright globalSetup | The migration chain | Already covered by `tests/MigrationTest.php` — verify coverage extends to every release's new migration |

### 2.2 Three missing test classes

**Class 1: Round-trip tests** for every persistence path.

Pattern: *write through the writer, read through the reader, assert byte-or-row equality*.

| Path | Round-trip assertion |
|---|---|
| Encrypted backup (IPAMBKP2 / IPAMBKP3) | encrypt(plaintext) → restore → byte-equal to plaintext |
| Logical backup (IPAMBKL1) | dump(db_state_A) → restore(into fresh db) → row-by-row equal to db_state_A |
| Settings (per-key) | `ipam_setting_set('k', 'v')` → `ipam_setting('k')` → `'v'` (every type: bool/int/string/json/sensitive) |
| Custom field values | save with custom_fields={"a":1,"b":"x"} → reload → JSON byte-equal |
| Binary IP storage | `inet_pton(ip) → ipam_bind_binary → SELECT → inet_ntop` → equal to input (three vectors: 10.0.0.0, 2001:db8::, 255.255.255.255) |
| Webhook signing | sign(payload, secret) on PHP side → verify on a Node receiver fixture → match |
| Tag CASCADE | Subnet delete → address_tags rows for that subnet's addresses gone (Pass C Surface 4 already verified manually; promote to PHPUnit) |

**Class 2: Contract enforcement tests.** Assert that every documented event / helper / sentinel has a code-side caller.

| Contract | Assertion |
|---|---|
| `audit-actions.md` action vocabulary | Every documented action string has ≥1 `audit($db, 'ACTION', …)` caller in the codebase. Pass C found `scan.schedule_create` documented with zero callers and `scan.stale_update` emitted with zero docs — both would fail this test. |
| `step-up-auth.md` invalidation events (11 listed) | Each event has its `ipam_sudo_invalidate()` call site (Pass A Bug T: 6 of 11 missing). |
| `runtime-dependency-policy.md` whitelist | Every Composer dep in `composer.json` is in the whitelist; every whitelist entry is in `composer.json`. Reverse-lookup detects drift. |
| Setting registry vs. consumer | Every key in `ipam_setting_definitions()` has ≥1 `ipam_setting('key')` reader; every direct `SELECT FROM settings WHERE key=…` in code is in the registry (no orphan settings). |
| Helper signature vs. caller | Every helper with `?int $tenantId = null` (per Path Forward §4 multi-tenancy-in-view rule) has at least one caller. Pass A Bug X: `ipam_sudo_consume_once()` defined v3.27.0 with zero callers. |

These are **grep-asserts**, not unit tests. Output is a CI script (PHP or shell) that emits a non-zero exit on drift.

**Class 3: Negative regression tests.** When a CR or security review tightens a validator/guard, the same PR adds a test that pins existing valid use cases.

Pattern: *when you make a regex stricter, add a test asserting the strings that should still match still do*.

| Worked example (from Pass A) | What the test does |
|---|---|
| Bug Z: OIDC return-path validator tightened | `tests/SudoOidcReauthValidatorTest.php` — 21 cases including all production-shape valid paths (`backup_admin.php?tab=destinations`, etc.) |
| Bug S: IPAMBKL1 dollar-quote parser tightened | `tests/RestoreDryRunTest.php` — input includes a bcrypt-hash-bearing INSERT |

**Rule for v3.28.0+ PRs (enforced by code review):** any commit message containing "tighten", "stricter", "reject", "validate" must touch a negative-regression test file. Honor-system at first; promote to a CI grep check in v3.29.0.

### 2.2.1 Known test flakes / footguns to resolve in v3.28.0

Specific failures the suite trips over today that v3.28.0 should fix at the source, not paper over in the test wrapper:

| Symptom | Where | Root cause | v3.28.0 fix |
|---|---|---|---|
| `RestoreDryRunTest::testIpambkl1ArchiveDoesNotErrorThroughSqlSplitter` fails with `SQLSTATE[HY000]: General error: 1 no such table: subnets` on a fresh `new PDO('sqlite::memory:')` that then calls `ipam_db_init()` | `tests/RestoreDryRunTest.php:149` | `ipam_db_init()` checks the on-disk `.db_initialized` sentinel before running migrations. The sentinel survives between phpunit invocations (and was set by an earlier app boot), so on an *in-memory* PDO the migrations get skipped — the disk sentinel is present, the in-memory schema is empty, the test sees no `subnets` table. Surfaced every Pass A / B / C / local-gate cycle so far. **Documented workaround today:** `rm Simple-PHP-IPAM/data/.db_initialized Simple-PHP-IPAM/data/ipam.sqlite` before `vendor/bin/phpunit` — that's a workaround, not a fix. | Either (a) `ipam_db_init()` accepts a `$force=true` parameter so tests can opt out of the sentinel skip-path; or (b) the sentinel becomes per-PDO (keyed on a connection identity) instead of a single on-disk file; or (c) `tests/bootstrap.php` invalidates the sentinel up front. Option (a) is the smallest blast radius and lands as part of v3.28.0's "round-trip test infrastructure" deliverable since round-trip tests will hit this same path. |

When v3.28.0 lands the fix, the row gets a `Fixed in v3.28.0` annotation but stays in the table as a regression-prevention reference — the worked example of "test suite trips over production code path that's correct in production but wrong in the test environment."

### 2.3 Contract-doc-vs-code linter

Concrete script: `tools/contract-doc-lint.php`. Run in CI alongside phpcs/phpstan/phpunit. Emits one line per drift, non-zero exit if any.

**Checks (initial set, extensible):**

1. **`audit-actions.md` action set ↔ `audit()` callers.**
   - Parse the table in `audit-actions.md` for action strings.
   - Grep the codebase for `audit($db, 'ACTION', …)` and raw `INSERT INTO audit_log` patterns.
   - Diff: documented-but-unemitted, emitted-but-undocumented.
   - **Pass C findings F-S2-02, F-S2-03 are direct drift this would catch.**

2. **`step-up-auth.md` invalidation event set ↔ `ipam_sudo_invalidate()` callers.**
   - Parse §"Invalidation triggers" for the documented events.
   - Grep call sites.
   - Cross-reference action verbs against the handlers that should call them.
   - **Pass A Bug T: 6 of 11 events with no caller.**

3. **`runtime-dependency-policy.md` whitelist ↔ `composer.json` ↔ shipped `vendor/`.**
   - Diff: in-policy-not-in-composer, in-composer-not-in-policy.
   - Run after `composer install --no-dev` in CI to verify the release tarball matches the policy.

4. **`ipam_setting_definitions()` registry ↔ direct `SELECT FROM settings` reads.**
   - Grep for any read of `settings` table not going through `ipam_setting()`.
   - **Architecture-with-multi-tenancy-in-view rule (Path Forward §4) forbids new direct reads;** the linter catches future violations.

5. **`page-inventory.md` table ↔ filesystem.**
   - Every `.php` page at the web root is in the inventory; every inventory row exists on disk.

6. **`data-dictionary.md` ↔ schema files.**
   - Already partially covered by `DataDictionaryDriftTest`; verify the linter doesn't duplicate.

7. *(extensible)* **CLAUDE.md whitelist tables ↔ codebase reality.**
   - The runtime dep table and the vendored frontend asset table in CLAUDE.md — keep them honest the same way.

**Output format:** one TSV line per drift. `CI` runs the linter, fails the build on any output. Local dev runs `php tools/contract-doc-lint.php` to see the current drift.

## 3. Pass C signals that shape v3.28.0 scope

Pass C confirmed the test-tooling deliverables above are necessary and surfaced two additional patterns:

**3.1 Audit-row contract drift is widespread.** F-S2-02 (`scan.schedule_create` documented, never emitted), F-S2-03 (`scan.stale_update` emitted, never documented + raw-INSERT bypass of `audit()`), F-S2-04 (cron scan path emits no audit row at all on a DB with 100 schedules + 679k scan_results) all point to the same gap: nobody is checking docs against code. The contract-doc linter (§2.3 check 1) is the direct response.

**3.2 Sub-agent output is unreliable enough to need a verification step.** Pass C Surface 8 saw an Explore agent report a false-positive Critical (off-by-2 line miscount claiming `export_dns.php:14` was missing `require_login()` when line 16 had it). Same class of risk as Pass A's "subagent claims test pass without naming the test file." v3.28.0 should add explicit guard discipline: **any subagent claim that becomes a finding must include the verification command and its actual output**, not the agent's restatement.

## 4. Pass C findings that are out-of-scope for v3.28.0 (per discipline)

v3.28.0 is **test tooling only** per the path forward. The Pass C findings below are **not** in v3.28.0 scope and remain to be slotted in a separate decision:

| Finding | Why out of v3.28.0 scope |
|---|---|
| F-S3-01 (High) — webhook signing secret plaintext at rest | Code change, not test tooling. Separate hotfix or v3.28.1 candidate. |
| F-S3-02 / F-S5-01 / F-S7-01 — step-up coverage sweep | Code change. Bundle into a single "step-up coverage" minor (likely v3.28.1 or v3.29.0). |
| F-S2-01 / F-S2-04 / F-S2-05 — scanner observability + IPv6 guard + threshold clamp | Code change. v3.28.1 / v3.29.0. |
| F-S5-02 + #1143 — atomic-dampener write race | Code change. Already slotted with #1143 deferral. |
| F-S6-01 — Kea/dhcpd FQDN regex parity | Code change. v3.29.0 candidate. |

**However**, three Pass C findings *are* tests that the v3.28.0 contract linter would catch — they should ship together with the linter so the linter's first run is green:

- F-S2-02: drop `scan.schedule_create` from docs OR add an emitter. The linter will flag this row on first run.
- F-S2-03: add `scan.stale_update` to docs AND convert raw INSERT to `audit()` helper. Same.
- F-S3-02 / F-S5-01 / F-S7-01: if the "step-up policy registry vs. caller" linter check lands in v3.28.0, the policy registry should already list these three callers, OR the linter should expect them as known-missing.

This is the "every helper lands with a caller" rule applied to the *linter itself*: don't ship a linter that's red on day one with 8 known drift rows in a queue. Either fix the drift in v3.28.0, or land the linter with explicit "known drift, scheduled for v3.28.1" allowlist that empties over time.

## 5. Scope-locking v3.28.0

Concrete locked scope (binding once Sean approves):

1. **A1: Test fixture inventory** → `docs/internal/test-fixture-inventory.md`.
2. **A2: Parallel-spec backfills for the four known candidates** (warmSudoGrant, setTestSetting, login bootstrap, migration coverage check).
3. **Round-trip tests** (Class 1) for: backup encrypt/restore (already partially exists), logical backup (IPAMBKL1), settings, custom fields, binary IP, tag CASCADE.
4. **Contract enforcement tests** (Class 2) wired into CI.
5. **Contract-doc linter checks 1–4** (audit, step-up, runtime deps, settings registry). Checks 5–7 deferred to v3.28.1 unless trivial.
6. **Companion code fixes** for the three contract-drift items that would otherwise leave the linter red on day one: F-S2-02, F-S2-03.
7. **Webhook signing round-trip test** with a Node receiver fixture (sets up the test infrastructure that v3.28.1's encrypt-secret migration will use).
8. **Documentation:** every new test class added has a one-paragraph README entry in `tests/README.md` (does not exist yet — create it).

**Out of scope:**
- Any code refactor (that's v3.29.0+).
- Migrating webhook secrets to encrypted at rest (that's v3.28.1 / hotfix candidate).
- Step-up coverage expansion (bundle for v3.28.1).

## 6. Risk register for v3.28.0

| Risk | Likelihood | Mitigation |
|---|---|---|
| Test infrastructure adds CI runtime past the GH Actions budget | Medium | Profile each new class; cap round-trip tests to representative vectors, not exhaustive |
| Round-trip test for IPAMBKL1 surfaces another writer/reader hash-domain mismatch (Pass B follow-up) | Medium | Treat that as in-scope discovery; fix or document as known limitation |
| Contract linter exposes more drift than expected | High | Allowlist with explicit "fix-by-vX.Y.Z" annotation; empty the list as it ships |
| `warmSudoGrant`-replacement specs are slow (real TOTP code generation in tests) | Medium | Use deterministic time + secret-injection for TOTP — already done in `sudo-real-flow.spec.ts` candidate |
| Scope creep: someone proposes adding "while we're in here" code refactors | High | Hard line per the path forward: v3.28.0 is test tooling only. New tests are fine; new app code is not (except the contract-drift fixes in §5 item 6). |

## 7. What success looks like

v3.28.0 ships when:

- `tests/test-fixture-inventory.md` exists, every helper has a row, every "bypassed path" with no parallel spec has a scheduled real-spec issue.
- `tools/contract-doc-lint.php` exists and is green in CI.
- Round-trip + contract-enforcement test classes are wired into `phpunit.xml` and run in CI.
- `tests/README.md` exists explaining the test taxonomy.
- The audit-actions + step-up-auth contract drift items (F-S2-02, F-S2-03, Pass A Bug T residual if any) are closed.
- A v3.28.0 retrospective is appended to `lessons-learned.md`: "did the new tests prevent the v3.28.1 release from re-introducing any v3.21–v3.27 class bug?" — answer is unverifiable on day one but the question itself becomes the v3.28.1 entry criterion.

## 8. Open questions for Sean before scope-lock

These are explicit decision-review-gate questions (Path Forward §2). Bring to Sean before v3.28.0 starts:

1. **Do we ship the Pass C contract-drift code fixes (F-S2-02, F-S2-03) inside v3.28.0** (so the linter is green) **or in v3.28.1** (and the linter ships with an allowlist)?
2. **Webhook signing round-trip:** Node receiver fixture is a Node dev-dep — the project policy is no npm in production, but dev tooling under `testing/` does use npm. Confirm that's still the lane.
3. **Linter check 4 (`ipam_setting()` enforcement) will fail loudly** for every direct `SELECT FROM settings` in current code. Allowlist with fix-by-version? Or fix all direct reads in v3.28.0 itself?
4. **Round-trip test runtime budget.** A full encrypt → restore → byte-compare per IPAMBKP2 / IPAMBKP3 with realistic data is seconds, not milliseconds. Cap at small fixtures, or run a bigger set nightly?

These are not blockers — they're scope-shaping questions. v3.28.0 starts the moment they're decided.
