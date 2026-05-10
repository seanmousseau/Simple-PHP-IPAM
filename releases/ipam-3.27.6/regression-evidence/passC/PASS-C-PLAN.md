# Pass C — manual regression sweep plan

**Authored:** 2026-05-10
**Driver:** Claude (Claude-driven mode per Sean's 2026-05-10 decision)
**Trigger:** Step 4 of `docs/internal/2026-05-08_Path_Forward.md`. v3.27.1 → v3.27.6 cluster shipped. Pass C is the only step from the path forward that hadn't started.
**Target:** SQLite test instance, v3.27.6.
**Baseline captured:** `00-baseline.md` + `db-snapshots/passC-start.sqlite.gz`.

## Surfaces, in risk order

| # | Surface | Why this is at risk | File |
|---|---|---|---|
| 1 | **api.php endpoints** | Sudo-class actions exposed via API key auth — does the API bypass the v3.27.0 step-up flow? Highest blast radius if bypass exists. | `surfaces/1-api-sudo.md` |
| 2 | **Scanner async cron** | v3.21+ rapid evolution; multi-path (sync run, async cron, ARP import, TCP scan). IP-injection guards must hold. | `surfaces/2-scanner.md` |
| 3 | **Webhook delivery + reaper** | v3.22 reaper + retry coordination. State machine for `destination_health` + delivery retries. | `surfaces/3-webhooks.md` |
| 4 | **Tag CASCADE behavior** | `subnet_tags` / `address_tags` join-table CASCADE on entity delete; engine-agnostic if PHP-mediated, but worth confirming. | `surfaces/4-tag-cascade.md` |
| 5 | **Notification dispatch state machine** | `destination_health` + `schedule_overdue` cooldowns; alert dampener. Cross-check v3.27.0 step-up changes didn't break this. | `surfaces/5-notifications.md` |
| 6 | **DHCP config (subnets cols + `pd_pools` + dhcpd.conf export)** | DHCP options as subnets columns (v3.4.0 #402). Re-exercise the export with mixed v4/v6 config. | `surfaces/6-dhcp.md` |
| 7 | **Custom fields** | Schema-change interactions; CSV import/export round-trip with custom values. | `surfaces/7-custom-fields.md` |
| 8 | **Reports / exports** | Recently surfaced in nav; CSV/JSON exports respecting VRF filter scope. | `surfaces/8-reports.md` |

## Per-surface deliverable

Each surface produces:

1. A checklist of test cases (numbered, with expected outcome).
2. Result column filled in with actual outcome + evidence reference (db row id, screenshot, audit row, log snippet).
3. Per-surface verdict: **pass / pass-with-findings / fail**.
4. Per-surface finding rows added to the rolling triage list.

## Triage at end of sweep

`PASS-C-SUMMARY.md` produces:

| Severity | Slot | Definition |
|---|---|---|
| **Blocker** | v3.27.7 hotfix | Data loss, auth bypass, regression from v3.27.6 baseline. |
| **High** | v3.27.7 or v3.28.0 | Correctness bug affecting documented behavior, no clean workaround. |
| **Medium** | v3.28.0 / v3.29.0 | Behavioral oddity, edge-case, ergonomic issue with workaround. |
| **Low / wontfix** | backlog | Cosmetic, doc-only, or by-design. |

## Ground rules (binding for this sweep)

1. **No code changes mid-sweep.** Findings get documented and triaged at the end. Exception: if a finding actively corrupts test-instance state mid-run, stop and surface to Sean.
2. **Every finding has reproduction steps + evidence.** Audit row id, screenshot, curl invocation, db row dump — whatever is decisive.
3. **Memory MCP observations as we go.** One per surface verdict, one for the overall triage.
4. **SQLite first.** Escalate to MySQL/Postgres only when the finding sniff-tests as engine-specific.
5. **No claim of "pass via tests"** without naming the test file. Pass B was burned by `warmSudoGrant()` bypassing the test surface; Pass C tests must exercise the production code path.

## Step out at the end

Pass C produces a triaged bug list, not a release. Slotting findings into v3.27.7 / v3.28.0 / v3.29.0 is a separate decision that comes after the summary lands.
