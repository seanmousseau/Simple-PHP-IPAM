# Pass C — baseline

**Captured:** 2026-05-10 ~16:40 EDT
**Target:** SQLite test instance `/var/www/html/testing/ipam/` (host: `dev_seanmousseau_com-apache-php-1`)
**URL:** https://dev-direct.seanmousseau.com:8343/testing/ipam/
**App version:** v3.27.6
**Schema migration head:** `3.7.0-backup-history`

## Row counts at start of Pass C

| Table | Count |
|---|---:|
| `audit_log` | 19,670 (max id 110947) |
| `users` | 5 |
| `subnets` | 500 |
| `addresses` | 43,100 |
| `tags` | 5 |
| `subnet_tags` | 100 |
| `address_tags` | 4,310 |
| `scan_schedules` | 100 |
| `scan_results` | 679,419 |
| `webhooks` | 0 — must seed before Surface 3 |
| `webhook_deliveries` | 0 |
| `api_keys` | 5 |
| `backup_runs` | 23 |
| `alert_state` | 50 |
| `custom_field_defs` | 0 — must seed before Surface 7 |
| `pd_pools` | 0 |

## Surface inventory note

The path-forward doc lists "DHCP pools" as Surface 6. The codebase has no dedicated DHCP pool table; DHCP options live as columns directly on `subnets` (`dhcp_routers`, `dhcp_dns_servers`, etc., added v3.4.0 #402) and IPv6 prefix delegation uses `pd_pools` (currently empty). Surface 6 will exercise both: DHCP option columns on subnets + `pd_pools` + the `dhcpd.conf` export.

## Methodology

- Risk-first order: api.php → scanner → webhooks → tag CASCADE → notifications → DHCP → custom fields → reports/exports.
- One sweep, one triaged backlog.
- SQLite-only by default; escalate to MySQL/Postgres only if a finding hints at engine-specific behavior.
- Each surface gets `surfaces/N-<slug>.md` with the per-test result table.
- Findings rolled up into `PASS-C-SUMMARY.md` at the end with severity + recommended release slot.

## Pass C ground rules

- No code changes during the sweep. Findings get triaged after, not mid-sweep.
- If a surface uncovers a clear blocker (data loss, auth bypass), document it then continue — full sweep before deciding hotfix-vs-defer.
- Memory MCP observations recorded per surface as I go.
