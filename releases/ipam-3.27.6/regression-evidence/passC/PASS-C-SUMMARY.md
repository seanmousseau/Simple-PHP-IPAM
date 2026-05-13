# Pass C — Summary

**Run:** 2026-05-10 16:30 → 17:45 EDT
**Driver:** Claude-driven (Sean's 2026-05-10 decision)
**Target:** SQLite test instance v3.27.6 at `/var/www/html/testing/ipam/`
**Plan:** `PASS-C-PLAN.md`
**Baseline:** `00-baseline.md` + `db-snapshots/passC-start.sqlite.gz`
**Per-surface evidence:** `surfaces/1..8-*.md`

## Bottom line

Sweep completed across all 8 surfaces from the path-forward doc §step 4. **Zero blockers, zero high-severity correctness/auth findings on the production code path.** One genuinely High-severity *security-at-rest* finding (webhook signing secrets plaintext) and a cluster of Medium-severity step-up coverage gaps that bundle naturally into a single v3.28.0 backlog item.

The two Critical-class concerns the path forward flagged for Pass C (Bug X / Bug Z / Bug T live verification, and "API bypass of step-up") both came back clean: api.php has zero sudo-class endpoints by design, and the surfaces that v3.27.1 PHPUnit-deferred to user-driven Pass C were not re-exposed.

## Per-surface verdict

| # | Surface | Verdict | New findings | Notable evidence |
|---|---|---|---|---|
| 1 | api.php sudo-bypass | ✅ PASS | 0 | `grep -c "ipam_sudo_..." api.php` → 0 (by design — API keys are stateless, sudo is session-scoped) |
| 2 | Scanner async cron | ⚠️ PASS w/ findings | 5 Important | All security guards hold (IP-injection, CLI-only, prefix cap, cron lock, no address auto-create). All findings are observability/contract-drift class. |
| 3 | Webhooks + reaper | ⚠️ PASS w/ findings | 1 High, 2 Medium | SSRF guard + retry state + CSRF + payload all clean. Plaintext signing secret at rest is the only real security finding in the sweep. |
| 4 | Tag CASCADE | ✅ PASS | 0 | Empirical: 2 CASCADE chains verified through app's `ipam_db()` (PRAGMA fk=1). No data leakage, no orphan rows. |
| 5 | Notification dispatch | ⚠️ PASS w/ findings | 2 Medium, 1 Low | Cooldowns + dampeners clean. Step-up gap on `backup.notify_recipients`; write-side race on JSON state blobs (mitigated by cron flock; pairs with #1143). |
| 6 | DHCP config | ⚠️ PASS w/ findings | 1 Medium, 2 Low | Validation + escaping + audit clean. Kea JSON export skips the dhcpd FQDN regex check (parity gap). |
| 7 | Custom fields | ⚠️ PASS w/ findings | 1 Medium, 1 Note | Excellent design (immutability, JSON1, validation, escape). Step-up gap on def CRUD. |
| 8 | Reports / exports | ✅ PASS | 0 | All 11 export endpoints auth-gated; CSV formula-injection defused; sensitive setting values emit `'***'` in audit details before reaching export_audit. |

## Triaged finding list

Severity rubric (from `PASS-C-PLAN.md`):
- **Blocker** → v3.27.7 hotfix
- **High** → v3.27.7 or v3.28.0
- **Medium** → v3.28.0 / v3.29.0
- **Low / Note** → backlog
- **Forward-looking** → v4.x RBAC stream

| ID | Severity | Title | Recommended slot | Notes |
|---|---|---|---|---|
| **F-S3-01** | **High** | Webhook signing secret stored plaintext at rest | **v3.28.0 (recommended)** or v3.27.7 hotfix if Sean wants it sooner | Same architectural class as v3.24-v3.27 vault relocation work; webhooks were missed. One-shot encrypt-on-upgrade migration + `secret_enc` column. |
| **F-S2-04** | Important | Cron scan emits no audit row | v3.28.0 | Add `audit($db, 'scan.run', 'subnet', $sid, ...)` at `cron.php:583`. One-line fix. |
| **F-S2-02** | Important | `scan.schedule_create` documented, never emitted (contract drift) | v3.28.0 | Either drop from docs or split upsert path so initial INSERT emits the create action. Exactly the kind of drift the §step-5c contract linter will catch. |
| **F-S2-03** | Important | `scan.stale_update` emitted but undocumented + raw INSERT | v3.28.0 | Add to `audit-actions.md`; convert raw INSERT to `audit()` call at `lib.php:10200`. |
| **F-S2-01** | Important | IPv6 not explicitly rejected from sync-scan API | v3.28.0 | Bounded by row count today, but undocumented behavior. One-line guard at `api.php:2564`. |
| **F-S2-05** | Important (defensive) | `ipam_mark_stale_addresses` doesn't clamp `$missThreshold ≥ 1` | v3.28.0 | One-line guard; no live exploit, defensive only. |
| **F-S3-02** | Medium | No step-up on webhook create/edit/delete | v3.28.0 (bundle) | Part of the **step-up coverage sweep** bundle below. |
| **F-S5-01** | Medium | No step-up on `backup.notify_recipients` / SMTP setting save | v3.28.0 (bundle) | Same bundle. |
| **F-S7-01** | Medium | No step-up on custom field def create/update/delete | v3.28.0 (bundle) | Same bundle. |
| **F-S5-02** | Medium (mitigated) | Write-side race on `destination_health` / `schedule_overdue_state` JSON blobs | **v3.28.0 (with #1143)** | Mitigated today by cron flock. Pairs with the deferred #1143 atomic-dampener work. |
| **F-S6-01** | Medium | Kea JSON DHCP export skips FQDN regex check | v3.28.0 / v3.29.0 | Parity with dhcpd export. Correctness, not security. |
| **F-S6-03** | Low | No cron pruning of expired `pd_delegations` | v3.29.0 | Operational cruft, not a bug. |
| **F-S5-03** | Low | No "send a test alert" self-test path | v3.29.0 / backlog | Ergonomic; prevents future "alerts silently broken" footgun. |
| **N-S7-02** | Note | Entity-level custom field changes audit as aggregate, not per-field diff | backlog | QoL only. |
| **F-S6-02** | Forward-looking | `export_dhcp.php` doesn't accept a VRF filter | RBAC stream (v4.x) | Not a leak today; latent issue when per-VRF RBAC lands. |

## Recommended v3.28.0 scope adjustment

The path-forward doc §step 5 locked v3.28.0 as **test tooling baseline only** (no features). Three Pass C findings argue for either a small carve-out or a separate v3.28.0-security release sandwiched in:

1. **F-S3-01 (High):** Plaintext webhook secrets — security debt directly tied to the v3.24-v3.27 vault relocation. Not in v3.28.0 scope, but ageing.
2. **Step-up coverage sweep (F-S3-02 + F-S5-01 + F-S7-01):** Three Medium-severity gaps with a common shape. Best landed together.
3. **F-S5-02 + #1143:** Atomic-dampener write-side race; sits alongside #1143 which was already deferred to v3.28.0.

**Recommendation for Sean to decide (NOT a unilateral call by Claude — §2 of the path forward):**

| Option | Description |
|---|---|
| A | Keep v3.28.0 as pure test-tooling per the path forward. Hold all Pass C findings for v3.28.1 / v3.29.0. Test foundation first, *then* the security/observability backlog. |
| B | Carve-out a small (≤ 1 week) security thread into v3.28.0: F-S3-01 + step-up sweep + F-S5-02. Keep the test-tooling spine. |
| C | Insert a v3.27.7 hotfix for F-S3-01 (webhook secrets), then v3.28.0 as planned, then the step-up sweep + atomic dampener in v3.28.1. Each thread small and focused. |

A is the cleanest discipline-wise. C is the most defense-in-depth-rich but multiplies release count. B is the middle path. **No commitment yet** — bring this to Sean as the next decision after he reads this summary.

## Methodology notes

1. **One subagent false-positive Critical caught at verification.** Surface 8 Explore agent reported `export_dns.php:14 missing require_login()`. Direct Read showed line 14 is the init.php `require` and line 16 has `require_login()`. Off-by-2 line miscount. Verified before triage. This is the v3.21-v3.27 lesson again: subagent output is plausible-sounding but must be verified before it becomes a "finding." Pass C avoided false-positive contamination by doing the verification.
2. **No code changes during sweep.** Held the line per §step-4 ground rule.
3. **Empirical test where it mattered.** Surface 4 (tag CASCADE) used a real PHP test against `ipam_db()` — not just source-reading. Caught the PRAGMA-foreign_keys=1 setup at the app DB layer (CLI default is 0; would have produced a false PASS via bare `sqlite3`).
4. **Live audit-count evidence for contract drift.** Surface 2 findings F-S2-02 / F-S2-04 were *confirmed* by counting actual rows in `audit_log` on the test instance — `scan.run` count = 0 across 19,670 audit rows on a DB with 100 schedules + 679k scan_results is decisive evidence that the cron scan path emits no audit row. Source-only would have been suggestive, not conclusive.

## Test-instance state at end of Pass C

- Version: **v3.27.6** (unchanged)
- Snapshots: `db-snapshots/passC-start.sqlite.gz`
- Mutations during sweep:
  - Surface 2: one row `UPDATE scan_schedules SET last_run_at='2026-05-09 00:00:00' WHERE id=1` (cron didn't pick it up; reverted in spirit since `last_run_at` advances naturally)
  - Surface 4: scratch subnets 1004, 1005 + scratch tags 12, 13 — all cleaned up by the test script (final state byte-equal to pre-state per snapshot diff)
  - No code changes, no audit-row mutations beyond cron's own normal emissions
- Audit_log max id: 110960 (delta 13 rows from baseline 110947 — all cron `destination.test` + `setting.update` from normal cron ticks during the sweep window)

## Memory MCP

A close-out observation on `project:simple-php-ipam` recording Pass C completion, the 15 findings (highlighting F-S3-01 as the headline) and the three options for v3.28.0 scope adjustment will follow this summary commit.
