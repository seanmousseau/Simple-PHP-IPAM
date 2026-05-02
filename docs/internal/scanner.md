# Scanner

> Reference for the network scanning subsystem (v2.3.0+). ICMP/TCP probing, scheduled scans, ARP import, stale-address marking. CLAUDE.md keeps only the IP-injection security rule (every session needs to know it); this doc is the implementation reference for sessions touching scan/probe code.

---

## Tables

| Table | Purpose |
|---|---|
| `scan_schedules` | Per-subnet scan configuration: `subnet_id` (UNIQUE FK), `method` (icmp\|tcp\|both), `tcp_port`, `interval_minutes`, `is_active`, `last_run_at` |
| `scan_results` | One row per IP per scan run: `subnet_id`, `address_id` (nullable FK), `ip`, `method`, `is_up`, `latency_ms`, `scanned_at` |

## Columns added to `addresses`

| Column | Purpose |
|---|---|
| `last_seen_at TEXT` | Datetime of last successful ping/TCP response |
| `is_stale INTEGER NOT NULL DEFAULT 0` | Set by `ipam_mark_stale_addresses()` |

---

## Pages

| Page | Auth | Description |
|---|---|---|
| `scan_history.php` | `require_login()` (no admin gate) | Read-only scan timeline |
| `import_arp.php` | `require_write_access()` + CSRF | ARP import wizard |
| `scan_run.php` | **CLI-only** — must `header('HTTP/1.1 403 Forbidden'); exit(1)` on non-CLI SAPI | Scheduled scan runner |

---

## Functions in `lib.php`

| Function | Notes |
|---|---|
| `ipam_probe_icmp(string $ip, int $timeoutMs): ?int` | IP **must** be pre-validated via `normalize_ip()` before this call. Uses `proc_open()` with system `ping`; OS-aware flags (`-W ms` macOS, `-W s` Linux) |
| `ipam_probe_tcp(string $ip, int $port, int $timeoutMs): ?int` | IP and port **must** be validated. Uses `fsockopen()` |
| `ipam_scan_subnet(PDO $db, int $subnetId, string $method, ?int $tcpPort): array{scanned:int,up:int,down:int,skipped:int,stale_marked:int}` | Enforces /28 cap for synchronous calls. Skips reserved IPs (network + IPv4 broadcast) via `ipam_subnet_reserved_bins()`; counted in `skipped` (v2.5.0 / #363) |
| `ipam_mark_stale_addresses(PDO $db, int $subnetId, int $missThreshold = 3): int` | Runs in a transaction; audit logged if rows changed. Skips reserved IPs so they never accrue a stale flag |
| `ipam_subnet_reserved_bins(PDO $db, int $subnetId): array{network:?string, broadcast:?string}` | Binary IPs excluded from scan/stale passes. Nulls for IPv6, /31, /32 |
| `ipam_compute_broadcast_bin(string $netBin, int $prefix): ?string` | Pure IPv4 broadcast calculation; unit-tested in `tests/UtilTest.php`. Returns null for IPv6, /31, /32 |
| `ipam_parse_arp_table(string $raw): array` | Uses `filter_var(FILTER_VALIDATE_IP)` + MAC regex; never trusts raw input |
| `ipam_apply_arp_import(PDO $db, array $entries, int $subnetId): array` | Returns `['matched', 'updated']` stats |

---

## Security patterns

These are load-bearing — getting them wrong creates command injection / privilege escalation.

- **IP injection guard.** Raw `$_GET` / `$_POST` IPs **must** go through `normalize_ip()` before reaching `proc_open()` or `fsockopen()`. Semgrep rule `ipam-proc-open-safe` enforces this. **(This is the one rule kept in CLAUDE.md.)**
- **CLI-only guard.** `scan_run.php` must `header('HTTP/1.1 403 Forbidden'); exit(1)` on non-CLI SAPI. Otherwise an attacker with web access can trigger arbitrary scheduled scans.
- **Sync cap.** `api_scan_run()` checks prefix ≤ 28 and returns HTTP 400 for larger subnets. Sync scans of large subnets exhaust PHP request time and lock the DB.

---

## Audit actions

```text
scan.run                scan.schedule_create
scan.schedule_update    scan.schedule_delete
address.arp_import
```

See `audit-actions.md` for the full action vocabulary.

---

## Nav surface

- **Admin sidebar** includes **ARP Import** (`import_arp.php`).
- **Subnet rows** include a **Scan History** action pill linking to `scan_history.php?subnet_id=...`.

---

## Cross-references

- `CLAUDE.md` → IP injection guard (load-bearing).
- `audit-actions.md` — `scan.*` and `address.arp_import` actions.
- `adding-an-api-endpoint.md` — `api_scan_run()` is the canonical example of a sync-capped action endpoint.
- `lessons-learned.md` §2 — binary IP storage rules apply to scan results.
- `docs/scanning.md` — user-facing documentation.
