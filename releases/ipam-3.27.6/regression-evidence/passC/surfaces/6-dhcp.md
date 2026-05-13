# Surface 6 — DHCP configuration (subnet columns + pd_pools + dhcpd.conf export)

**Verdict:** ✅ **PASS WITH MINOR FINDINGS** (1 Medium, 2 Low). Validation, escaping, audit, and CSRF all clean.
**Date:** 2026-05-10
**Method:** Static audit via Claude Explore agent. Live exercise skipped — no DHCP-configured subnets in the test instance baseline and the read-only audit was decisive.

## Clean items

| # | Item | Evidence |
|---|---|---|
| 1 | DHCP IP-list validation — comma-separated IPs validated via `FILTER_VALIDATE_IP, FILTER_FLAG_IPV4`; rejects non-IPs and empty strings | `subnets.php:216-230` |
| 2 | dhcpd.conf string escaping — `dhcp_domain_name` regex-validated FQDN + double-quote escape; `dhcp_boot_filename` strips `;\n\r{}` then quotes | `lib.php:10535-10559` |
| 3 | Lease bounds — `dhcp_lease_default` / `dhcp_lease_max` ≥60s; max ≥ default | `subnets.php:235-243` |
| 4 (partial) | dhcpd.conf export endpoint — admin-write enforced, CSRF on POST, filename via `safe_export_filename()` not user-controlled, GET preview safe | `export_dhcp.php:5,8,50` |
| 5 | `pd_pools` admin role + UNIQUE(parent_subnet_id) | `pd_pools.php:5`, `schema.sql:394-403` |
| 6 | `pd_delegations` lifecycle (create / revoke) with dedicated audit actions | `pd_pools.php:79,93`; `schema.sql:405-414` |
| 8 | DHCP option save audits as `subnet.update`; PD pool ops have dedicated `pd_pool.create/delete/delegate/revoke` actions | `subnets.php:347`, `pd_pools.php:41,54,79,93` |
| 9 | CSRF + admin role on every PD pool form | `pd_pools.php:5,6,180,262,282,332` |
| 10 | DHCP option round-trip (NULL ↔ empty string handled, long strings stored as-is, ints via `to_int()`) | `subnets.php:206-214,319-325,428-431,605-611` |

## Findings

### F-S6-01 — Medium — Kea JSON export skips `dhcp_domain_name` validation

**Where:** `lib.php:10610-10611` (`ipam_render_kea_json()`).
**What:** The ISC dhcpd export path validates `dhcp_domain_name` against an FQDN regex at `lib.php:10535-10559` before quoting. The Kea JSON renderer passes the value through with `e()` / `json_encode()` but not the regex. JSON encoding handles quote escaping, so this is not an injection into the JSON itself — but a malformed value (e.g., a domain with embedded whitespace or control characters) that the ISC export would have rejected makes it into the Kea output verbatim.
**Risk:** Low for security (JSON encoding contains the value), medium for downstream parsing — Kea may reject the config silently and operators get bitten by a parse failure on deploy.
**Fix:** Apply the same FQDN regex check in `ipam_render_kea_json()` so the two exports have parity. Either reject the row outright or substitute a sanitized value with an export-side warning.
**Severity:** **Medium** — correctness gap, not a security bug.

### F-S6-02 — Low (forward-looking) — `export_dhcp.php` does not accept a VRF filter

**Where:** `ipam_dhcp_load_subnets()` at `lib.php:10666-10685` (and the calling endpoint at `export_dhcp.php`).
**What:** The query filters only by `ip_version = 4`, not by VRF. In v3.27.6 this is **not** a leak (there is no RBAC; every write-access user already sees every VRF in every other view), so the export is consistent with the rest of the app. But this surface will become a leak the moment RBAC-by-VRF lands in the v4.x stream (`docs/internal/v4-release-stream.md`).
**Risk:** None in v3.27.6. Latent issue when RBAC arrives.
**Fix:** When the RBAC migration lands, add `WHERE vrf_id IN (:rbac_allowed_vrfs)` to this query *and* to every other VRF-scoped read. Cross-reference with the RBAC scope-locking work — this is one of many places that will need the filter.
**Severity:** **Low (forward-looking)** — capture in the RBAC backlog, not v3.27.x.

### F-S6-03 — Low — No cron pruning of expired `pd_delegations`

**Where:** `cron.php` has no `pd_delegations` cleanup task; `pd_delegations.expires_at` is nullable but never inspected by a background job.
**What:** Expired delegations stay in the table indefinitely (UI shows them with an "expired" badge per `pd_pools.php:327`). Manual revocation required for cleanup.
**Risk:** Low — only operational cruft. No security or correctness bug.
**Fix:** Add `cron.php` task `pd_prune` that deletes `pd_delegations WHERE expires_at < datetime('now') AND <some retention window>`, configurable via `pd.expired_retention_days` setting. Audit one summary row per run.
**Severity:** **Low** — slot in v3.28.0 / v3.29.0 backlog.

## Design notes (not findings)

- **Asymmetric auth:** DHCP option save uses `require_write_access()`, PD pool create uses `require_role('admin')`. The asymmetry is intentional (a PD pool allocates address blocks; DHCP option entries don't). Document this in `auth-model.md` so a future reviewer doesn't try to "normalize" the two.
- **IPv6 DHCP not designed:** `dhcp_*` columns are IPv4-only. DHCPv6 is a future scope question, not a regression.
- **Per-column diff in audit detail:** `subnet.update` audit row currently has `normalized` flag in detail rather than the column-level before/after diff. Useful improvement for v3.28.0+ but not a finding.

## Test-instance state

No mutations made. No DHCP-configured subnets exist in the baseline; static audit was decisive.
