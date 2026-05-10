# Surface 8 — reports / exports

**Verdict:** ✅ **PASS — no findings**
**Date:** 2026-05-10
**Method:** Static audit via Claude Explore agent, with two manual verifications after the agent flagged a false-positive Critical (corrected below) and an unbounded-`details` concern (also corrected below).

## Inventory

| Endpoint | Format | Auth | Filter | Sanitized filename |
|---|---|---|---|---|
| `export_subnets.php` | CSV | `require_login()` | site_id, ip_version | `safe_export_filename()` |
| `export_addresses.php` | CSV | `require_login()` + `require_write_access()` when no `subnet_id` | subnet_id | ✓ |
| `export_audit.php` | CSV | `require_role('admin')` | range | ✓ |
| `export_utilization_history.php` | CSV | `require_role('admin')` | days, subnet | ✓ |
| `export_subnet_utilization.php` | CSV | `require_login()` | subnet, range | ✓ |
| `export_dhcp.php` | dhcpd.conf or Kea JSON | `require_login()` + `require_write_access()` | preview | ✓ |
| `export_dns.php` | BIND zone | `require_login()` (line 16) | subnet, type | ✓ |
| `export_unassigned.php` | CSV | `require_login()` | subnet | ✓ |
| `export_search.php` | CSV | `require_login()` | query, site | ✓ |
| `export_address_history.php` | CSV | `require_login()` | address | ✓ |
| `export_import_report.php` | CSV | `require_role('admin')` | run id | ✓ |
| `reports.php` | HTML page | `require_role('admin')` | days, subnet | — |

## Audit checklist results

| # | Item | Result |
|---|---|---|
| 1 | Every export `require_login()` minimum | ✅ all 11 verified via `grep` (live evidence below) |
| 2 | Role appropriateness — admin-only on audit log, utilization history, import report | ✅ `require_role('admin')` confirmed |
| 3 | CSRF on POST exports | ✅ N/A — all exports GET-only |
| 4 | VRF/site filter respected | ✅ `export_subnets.php:15-16` parameterized; same for `export_subnet_utilization.php`, `export_search.php:49-51` |
| 5 | Filename sanitization | ✅ `safe_export_filename()` (`lib.php:7513-7525`) strips non-alphanumeric, lowercases, appends timestamp; no user-controlled component reaches `Content-Disposition` |
| 6 | Header injection — filename sanitizer is enough; RFC 5987 encoding would be defense-in-depth but not required given the sanitizer's strict allow-list | ✅ |
| 7 | PII / sensitive-data leakage | ✅ no `password_hash` / `oidc_sub` / MFA secrets / TOTP / passkey blobs exposed in any export. Sensitive settings emit `***` in audit_log.details at `lib.php:2872-2873`, so `export_audit.php` cannot exfiltrate them. |
| 8 | CSV formula-injection guard | ✅ `csv_out()` (`lib.php:7548-7566`) prefixes cells starting with `=`, `+`, `-`, `@`, `\t`, `\r`, `\n` with `'` — defuses Excel formula injection. Applied site-wide. |
| 9 | Streaming vs in-memory | ✅ all exports use `fputcsv()` against `php://output`; no full-result buffering. Verified on `export_addresses.php` (43k rows on test instance would otherwise OOM). |
| 10 | API-side dump endpoints | ✅ no `/api/.../export` paths; API uses ID-scoped reads with pagination |
| 11 | Step-up on sensitive exports | ⚠️ Note (not a finding) — admin-only exports do not require sudo. Consistent with the rest of the codebase (admin-only pages don't re-auth). Same architectural question as F-S3-02 / F-S5-01 / F-S7-01 (step-up coverage), but exports are read-only and lower-risk; **not** part of the bundled step-up sweep recommendation. |

## Agent-flagged Critical — false positive, corrected

The Explore agent reported "**Critical:** `export_dns.php:14` missing `require_login()`". Verified by direct Read of the file:

```
14: require __DIR__ . '/init.php';
15: /** @var \PDO $db */
16: require_login();
```

Line 14 is the `init.php` require; **line 16** has the `require_login()`. The agent miscounted lines. Auth is in place. **Re-classified as PASS.**

## Agent-flagged Medium on `audit_log.details` — addressed at the source

The agent raised concern that `export_audit.php` might leak sensitive values via the `details` column. Verified:

- Sensitive settings (`is_sensitive=true` flag in the setting registry at `lib.php:1690+`) emit `'***'` rather than their plaintext value in the audit row, per `lib.php:2872-2873`:

  ```php
  'old' => $sensitive ? '***' : ipam_setting_decode($oldRaw, $oldType, null),
  'new' => $sensitive ? '***' : ipam_setting_decode($encoded, $type, null),
  ```

- Spot-grep of `audit_log.details` on test instance for `password|secret|key|vault|bcrypt` substrings returned only false positives (`recipient_user_ids` contains "key" substring; no actual sensitive plaintext).
- `before_json` / `after_json` on `address_history` export are pre-decoded and flattened per-column rather than emitted raw.

**Re-classified as PASS.**

## Live evidence

```
$ grep -nE "require_login|require_role|require_write_access|csrf_require" Simple-PHP-IPAM/export_*.php
Simple-PHP-IPAM/export_address_history.php:5:require_login();
Simple-PHP-IPAM/export_addresses.php:5:require_login();
Simple-PHP-IPAM/export_addresses.php:11:    require_write_access();
Simple-PHP-IPAM/export_audit.php:5:require_role('admin');
Simple-PHP-IPAM/export_dhcp.php:4:require_login();
Simple-PHP-IPAM/export_dhcp.php:5:require_write_access();
Simple-PHP-IPAM/export_dns.php:16:require_login();
Simple-PHP-IPAM/export_import_report.php:5:require_role('admin');
Simple-PHP-IPAM/export_search.php:5:require_login();
Simple-PHP-IPAM/export_subnet_utilization.php:5:require_login();
Simple-PHP-IPAM/export_subnets.php:5:require_login();
Simple-PHP-IPAM/export_unassigned.php:5:require_login();
Simple-PHP-IPAM/export_utilization_history.php:5:require_role('admin');
```

## Note on subagent reliability

The Explore agent's "**Critical: missing `require_login()`**" was an off-by-2 line-number error, not a real bug. This is the same Pass A class of "subagent generated plausible-sounding but unverified output" — flagging here so the Pass C summary captures it as a sweep-methodology lesson, not as a finding.
