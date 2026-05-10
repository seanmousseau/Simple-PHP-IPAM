# Surface 5 — notification dispatch state machine

**Verdict:** ⚠️ **PASS WITH FINDINGS** (2 Medium, 1 Low). Cooldowns + dampeners + send paths all clean.
**Date:** 2026-05-10
**Method:** Static audit via Claude Explore agent over the three notification subsystems: `destination_health`, `schedule_overdue_state`, and `alert_state` (utilization).

## Clean items

| # | Item | Evidence |
|---|---|---|
| 1 | `destination_health` cooldown (6h, `last_alerted_at`, fires only on healthy→failing transition) | `cron.php:331-355` |
| 2 | `destination_health` recovery: silent transition by design (`audit-actions.md` only documents `backup.connection_test_failed`, no `*.recovered` action) | `cron.php:289` + `audit-actions.md` |
| 3 | `schedule_overdue` cooldown (keys on exact `next_run_at`, default 5min grace, clamped via `max(5, …)`) | `backup.php:2471, 2511`; O5 path adds last-run-failed detection at `backup.php:2522, 2555` |
| 4 | `alert_state` utilization dampener (24h cooldown, per-level rows, upsert only on successful send) | `lib.php:3985, 4006, 4028-4036` |
| 5 | Email send path (PHPMailer SMTP when `smtp.enabled=true`, fallback `mail()`, recipients from settings — never hardcoded) | `lib.php:3845, 6518-6543` |
| — | Email-header injection guard: `\r\n` stripped from subject + recipient before `ipam_send_mail()` | `lib.php:4012, 4031` |
| 8 | State-transition audit rows present | `cron.php:349` (`destination.test`); `backup.php:2516, 2590` (`backup.schedule_overdue`); `lib.php:4023` (`alert.send`) |
| 10 | Cron context vs. step-up: cron emits audit with `user_id=null, username=null` — no interaction with sudo (correct, by design) | `cron.php` audit calls |

## Findings

### F-S5-01 — Medium — No step-up on `backup.notify_recipients` setting save

**Where:** `settings.php` general setting-save handler; `backup.notify_recipients` is editable through the same form path as any other backup setting and is not sudo-gated.
**What:** v3.27.0 added step-up to vault key, MFA, db_tools, settings_reveal, api_keys, backup destinations. The recipient list for backup-failure / schedule-overdue / utilization alerts is a setting and goes through the regular `settings.php` save path — no `ipam_sudo_require()` call.
**Risk:** A compromised admin session can redirect all alert email to an attacker-controlled address. Combined with the backup-success email leaking failure context (filename + path of the run), this is a covert exfil/blinding vector.
**Fix:** Treat `backup.notify_recipients`, `alert.email`, `smtp.*` keys as sudo-class. Either add a step-up gate per-key in the setting save handler, or move them to a dedicated admin page with `ipam_sudo_require()`.
**Severity:** **Medium** — same class as F-S3-02 (no step-up on webhook admin) and worth bundling with that finding for any v3.28.0 step-up-coverage sweep.

### F-S5-02 — Medium (mitigated) — Write-side race on `destination_health` / `schedule_overdue_state` JSON blobs

**Where:** `ipam_setting_set()` at `lib.php:2768`; called from `cron.php:392` (`destination_health` update) and `backup.php:2630` (`schedule_overdue_state` update).
**What:** Both state machines use a read-modify-write pattern against a single JSON blob in `settings.value`. `ipam_setting_set()` has MySQL-specific locking (`GET_LOCK`, `lib.php:2816`) and an in-function transaction (`lib.php:2829`) but no `BEGIN IMMEDIATE` or `SELECT … FOR UPDATE` to prevent lost-update on SQLite/PG read-modify-write. Two simultaneous cron ticks could read the same blob, mutate independently, and the last writer wins (losing the other tick's mutations).
**Mitigation in place:** `cron.php:64` `flock($cronLockHandle, LOCK_EX | LOCK_NB)` prevents concurrent cron.php invocations, so the race only manifests if one tick hangs past the flock timeout window or if the same key is written from cron AND from an HTTP request simultaneously.
**Relationship to #1143:** Issue #1143 (deferred to v3.28.0) was about the read-side atomic dampener. The write-side gap surfaced here is the natural sibling — both should land together in v3.28.0's atomic-update fix.
**Fix:** Either (a) tighten `ipam_setting_set()` to use `BEGIN IMMEDIATE` + conditional update on SQLite and `SELECT … FOR UPDATE` on PG, OR (b) introduce a key-scoped compare-and-swap helper specifically for state-machine JSON blobs (recommended — cheaper, locking-scope is narrower).
**Severity:** **Medium** — mitigated by cron self-lock; worth fixing in v3.28.0 alongside #1143.

### F-S5-03 — Low — No "send a test alert" dispatch path

**Where:** No CLI tool, admin button, or API endpoint exercises the full notification dispatch path (settings resolution → recipient list → SMTP/mail() → audit row → cooldown bookkeeping).
**What:** Adjacent test paths exist:
- The destination "Test connection" button (`backup_admin_destinations.php`) calls `ipam_destination_test_now()` which exercises the test side but only fires an alert if the destination is already in failing state (the transition-only guard).
- The utilization alert path can be exercised only by physically crossing a threshold.

No one-click "send me a test email so I can confirm SMTP is wired up and recipients are right" affordance. After #418 (PHPMailer) + #1107 (step-up) + the v3.24-v3.27 vault relocation, the dispatch pipeline has many moving parts that operators cannot validate end-to-end without faking a failure condition.
**Risk:** Operators set up alerting, never see a real alert because their threshold is never crossed, then in a real outage discover SMTP auth was wrong six months ago. Classic "alerting works → it works" assumption.
**Fix:** Add `tools/test-alert-dispatch.php` CLI + a "Send test alert" button on the Notifications settings page. Bind to the same dispatch helper that real alerts use so a successful test means real alerts will also fire.
**Severity:** **Low** — ergonomic gap; nothing breaks today, but it's the kind of thing that prevents the next "alerts were silently broken for 90 days" post-mortem.

## Cross-cuts with other surfaces

- F-S5-01 + F-S3-02 (Surface 3): **same architectural pattern** — admin-only state mutations that meaningfully change blast radius of an account compromise should be sudo-class, not just admin-class. Bundle into a single "step-up coverage sweep" backlog item for v3.28.0.
- F-S5-02 ↔ #1143 (deferred from v3.27.4): **same fix target**. The atomic dampener work scheduled for v3.28.0 should include the write-side race fix for `destination_health` and `schedule_overdue_state`.

## Notes

- `ipam_resolve_backup_notify_recipients()` (lib.php) correctly applies the per-schedule → per-tab → legacy `alert.email` fallback ordering. CSV-split per-recipient loop at `lib.php:4009`.
- The audit-row emission for alert-state cleanup (when a subnet drops back below threshold and the `alert_state` row is deleted) is **silent** — no audit row. Sub-finding under F-S5-03 if completeness matters.
