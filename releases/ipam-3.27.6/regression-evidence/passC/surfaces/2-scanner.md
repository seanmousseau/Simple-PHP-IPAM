# Surface 2 — scanner async cron

**Verdict:** ⚠️ **PASS WITH FINDINGS** (5 Important, 1 Nit). Security guards hold; observability + drift gaps present.
**Date:** 2026-05-10
**Method:** Static audit via `ip-binary-auditor` subagent + live audit-row counters on SQLite test instance (v3.27.6, 100 scan_schedules, 679,419 scan_results, 220 scan.stale_update rows in audit_log).

## Security properties — all clean

| Property | Result | Evidence |
|---|---|---|
| IP-injection guard (every `proc_open`/`fsockopen` callsite receives normalized IP) | ✅ | `lib.php:10091-10093` `normalize_ip()` interposes; `lib.php:9948,9951` uses argv form `['ping','-c1','-W…',$ip]` (no shell); `lib.php:10001` `fsockopen` fed `$validIp` only; `api.php:2549` and `scan_run.php` only accept integer `subnet_id`/`tcp_port` from the request body. No `popen/exec/shell_exec/passthru/system` in scanner code. |
| CLI-only guard on `scan_run.php` | ✅ | `scan_run.php:27-32` `php_sapi_name() !== 'cli'` check is the first executable code, before `require config.php` and `require lib.php` — no init/session/HTTPS side-effects on web request. `cron.php:28-33` matches. |
| Sync prefix cap (reject blocks > 16 hosts) | ✅ | `api.php:2564` `if (to_int($subnet['prefix']) < 28) api_error(400, …)` — direction is correct (prefix=24 rejected, prefix=28 allowed, prefix=30 allowed). |
| Cron concurrency lock | ✅ | `cron.php:58-82` `flock(LOCK_EX \| LOCK_NB)` on `data/cron.lock`; overlapping invocation emits `{"task":"cron","skipped":true,"reason":"another cron.php instance is still running"}` and exits 0. `register_shutdown_function()` releases on all exit paths. Soft 600s scanner budget (`IPAM_CRON_SCANNER_BUDGET_SECS`) complements the hard lock. |
| No auto-create of address rows from scan results | ✅ | `lib.php:10064` iterates pre-existing addresses (`SELECT id, ip, ip_bin FROM addresses WHERE subnet_id = :sid`); `ipam_apply_arp_import` (`lib.php:10273`) only updates `mac` on existing rows. Address creation paths (`addresses.php`, `api_addresses_*`, CSV import) all round-trip through `normalize_ip()` + binary `ip_bin`. |

## Findings

### F-S2-01 — Important — IPv6 not explicitly rejected from sync scan API

**Where:** `api.php:2564` (`api_scan_run`)
**What:** The prefix-cap check `prefix < 28 → reject` is correct for IPv4 (caps at 16 hosts). For IPv6, prefix=28 passes the check but represents 2^100 addresses. In practice `ipam_scan_subnet()` only iterates rows that already exist in `addresses` for that subnet, so the blast radius is bounded by `COUNT(addresses)` rather than by subnet size — but the CLAUDE-rule expectation per `docs/internal/scanner.md` is explicit IPv6 rejection from the sync path.
**Risk:** Low — bounded by existing address row count. But undocumented behavior could surprise an operator running a sync scan against a large IPv6 subnet with many pre-populated addresses.
**Fix:** Either (a) add `if ($subnet['ip_version'] == 6) api_error(400, 'Synchronous scan does not support IPv6 — use async cron');` or (b) document the actual semantic ("≥/28 prefix with existing-row count cap") and remove the IPv6 mention from the scanner doc.

### F-S2-02 — Important — `scan.schedule_create` documented but never emitted (drift)

**Where:** `docs/internal/audit-actions.md:80` (documented) vs. all callers (none).
**What:** Audit-actions vocabulary lists `scan.schedule_create`, but every schedule-save path emits `scan.schedule_update` even on the initial INSERT (the upsert helper doesn't distinguish create-vs-update). Live evidence on test instance: `SELECT count(*) FROM audit_log WHERE action='scan.schedule_create'` → **0 rows in 19,670**.
**Risk:** Operator audit-trail confusion ("when was this schedule created?" returns nothing). Also: exactly the kind of contract-doc-vs-code drift that the path-forward doc §1 step 5c contract linter is meant to catch.
**Fix:** Either remove `scan.schedule_create` from the docs OR split the upsert path so the first save emits `scan.schedule_create`. Recommend the latter — meaningful for audit-log queries.

### F-S2-03 — Important — `scan.stale_update` emitted but undocumented + uses raw INSERT

**Where:** `lib.php:10200` (raw `INSERT INTO audit_log …`) vs. `docs/internal/audit-actions.md` (action not listed).
**What:** Live evidence: 220 `scan.stale_update` rows exist in test-instance audit_log, but the action string is not in the vocabulary docs. Additionally, the row is written via a hand-rolled INSERT, not via `audit($db, …)`, bypassing the helper's IP/UA capture.
**Risk:** Same contract-drift class as F-S2-02. Also breaks the "every audit-row goes through `audit()`" invariant that the contract-doc linter (step 5c) would assert.
**Fix:** Add `scan.stale_update` to `audit-actions.md`; convert `lib.php:10200` to `audit($db, 'scan.stale_update', 'subnet', $subnetId, "marked=$changed threshold=$missThreshold");`.

### F-S2-04 — Important — Cron scan path emits no audit row

**Where:** `cron.php:575-607` (cron scan dispatch loop).
**What:** The unattended cron-driven scan emits JSONL to stdout (`{"task":"scan","subnet_id":…,"scanned":…,…}`) but never calls `audit()` for the run itself. Only `scan.stale_update` (conditional, see F-S2-03) and `cron.task_failed` (on exception) reach the audit log. Compare `api.php:2581` which audits every sync run with `audit($db, 'scan.run', 'subnet', …)`. Live evidence: `SELECT count(*) FROM audit_log WHERE action='scan.run'` → **0 rows in 19,670**, on a DB with 100 schedules + 679,419 scan_results (so the cron has clearly been running, but no audit trail).
**Risk:** Medium — operator cannot reconstruct cron-scan history from the audit log. JSONL stdout is captured by the wrapper log (per `deploy-targets.md` cron wrapper) but is rotated separately from audit_log and not surfaced in the in-app audit viewer.
**Fix:** Add `audit($db, 'scan.run', 'subnet', $subnetId, "method=$method scanned=… up=… down=… stale_marked=… via=cron");` after each `ipam_scan_subnet()` call at `cron.php:583`.

### F-S2-05 — Important (defensive) — `ipam_mark_stale_addresses` does not clamp `$missThreshold` to ≥1

**Where:** `lib.php:10135-10215`.
**What:** `scan_run.php:53` clamps via `max(1, to_int(...))` at the CLI entry point, and `ipam_scan_subnet()` defaults `$staleThreshold = 3` (`lib.php:10046`). But `ipam_mark_stale_addresses()` itself does not clamp. Any future caller passing 0 or negative would hit `LIMIT :thresh` at `lib.php:10153` returning 0 rows (silent no-op) OR — driver-dependent — error. The `is_stale` computation at `lib.php:10182` (`$misses >= $missThreshold`) would also mark every address with `last_up=0` as stale on threshold ≤ 0.
**Risk:** Low today (no offending callers), but the defensive gap matches the F-S2 category of "internal helpers trust their callers — fine until they don't."
**Fix:** Add `if ($missThreshold < 1) $missThreshold = 1;` at the top of `ipam_mark_stale_addresses()`.

### N-S2-06 — Nit — `scan.stale_update` raw INSERT (subsumed by F-S2-03)

Already captured above. Fold into the F-S2-03 fix.

## Live evidence

**Audit-action counts on test instance v3.27.6 (snapshot at 2026-05-10 17:09):**

| Action | Count | Note |
|---|---:|---|
| `scan.run` | 0 | Zero rows in 19,670 audit rows despite 100 schedules + 679k scan_results → confirms F-S2-04 |
| `scan.schedule_create` | 0 | Documented action with no emitter → confirms F-S2-02 |
| `scan.schedule_update` | (not directly counted, > 0 implied by schedule activity) | |
| `scan.stale_update` | 220 | Undocumented action with raw-INSERT emitter → confirms F-S2-03 |

## Test-instance state at end of Surface 2

No mutations made beyond a single `UPDATE scan_schedules SET last_run_at='2026-05-09 00:00:00' WHERE id=1` (cron didn't pick it up — separate observation, not a Pass C finding). Audit_log max id 110960 (delta of 8 rows from baseline 110952 — all from cron's backup_destination + schedule_overdue tasks, none scanner-related).
