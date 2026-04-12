# Network Scanning — Simple PHP IPAM v2.3.0

Simple PHP IPAM includes a built-in network scanner that probes IP addresses via ICMP ping or TCP connect. Scanning requires no daemons, no root privileges (for TCP mode), and no PHP extensions beyond the standard `proc_open` / `fsockopen` functions.

---

## Tables

Two new tables are added by the `2.3.0-scanning` migration:

| Table | Purpose |
|-------|---------|
| `scan_schedules` | Per-subnet scan configuration (method, interval, active flag, last run time) |
| `scan_results` | One row per IP per scan run — records up/down status and latency |

Two new columns are added to `addresses`:

| Column | Type | Purpose |
|--------|------|---------|
| `last_seen_at` | `TEXT` (datetime) | Timestamp of last successful ICMP/TCP response |
| `is_stale` | `INTEGER` (0/1) | Auto-set by `ipam_mark_stale_addresses()` |

---

## Scan methods

| Method | How it works | Privilege required |
|--------|-------------|-------------------|
| `icmp` | System `ping` binary via `proc_open()`, 1 packet, 1-second timeout | Root / `cap_net_raw` on Linux; none on macOS |
| `tcp` | `fsockopen($ip, $port, timeout=1s)` | None |
| `both` | ICMP first; falls back to TCP on ICMP permission error | Depends on ICMP availability |

If ICMP probing fails with a permission error (exit code 2 on Linux), the scanner automatically falls back to TCP-only for that subnet.

---

## Scan schedules

Schedules are managed via the subnet list UI (write role required) or the REST API.

**UI:** On `subnets.php`, each subnet row has a "Scan Schedule" `<details>` section. Expand it to configure:
- **Method** — `icmp`, `tcp`, or `both`
- **TCP port** — port to probe when method is `tcp` or `both` (default 443)
- **Interval** — minutes between scan runs (minimum 1)
- **Active** — enable or disable the schedule

**API:**
```
GET    /api.php?resource=scan_schedules               # list all schedules
GET    /api.php?resource=scan_schedules&subnet_id=N   # get one schedule (null if none)
POST   /api.php?resource=scan_schedules               # create or update (upsert)
DELETE /api.php?resource=scan_schedules&subnet_id=N   # remove schedule
```

---

## CLI runner (cron)

`scan_run.php` is a CLI-only script. Running it from a web request returns HTTP 403.

```bash
# Scan all active scheduled subnets past their interval
php /path/to/Simple-PHP-IPAM/scan_run.php --all

# Scan a specific subnet immediately
php scan_run.php --subnet-id=42

# Dry run — show what would be scanned
php scan_run.php --all --dry-run

# Override method and stale threshold
php scan_run.php --all --method=tcp --port=22 --stale-threshold=5
```

**Cron example** (scan every 15 minutes):
```cron
*/15 * * * * php /var/www/html/Simple-PHP-IPAM/scan_run.php --all >> /var/log/ipam-scan.log 2>&1
```

The script outputs a JSON summary per subnet and an overall totals object. Exit code 0 on success, 1 on error.

---

## Auto-stale detection

After each subnet scan, `ipam_mark_stale_addresses()` is called automatically. It:

1. Counts the last N scan results per address in `scan_results`
2. Sets `addresses.is_stale = 1` for any address that has **N consecutive down results** (default threshold: 3)
3. Clears `is_stale = 0` for any address that now has an **up result** as its most recent scan

Stale addresses show a red "Stale" badge on the `addresses.php` page and are included in scan history. The `is_stale` flag is never set manually — it is always derived from scan result history.

To change the miss threshold globally, pass `--stale-threshold=N` to `scan_run.php`, or call `ipam_mark_stale_addresses($db, $subnetId, $threshold)` directly.

---

## ARP table import

`import_arp.php` lets you bulk-update MAC addresses from a copy-pasted ARP table.

**Supported formats:**
```
# Space-separated (most common)
192.168.1.1 aa:bb:cc:dd:ee:ff

# Tab-separated
10.0.0.5    00:11:22:33:44:55

# CSV
10.0.0.10,aa-bb-cc-dd-ee-ff

# Linux arp -a style
router (192.168.1.1) at aa:bb:cc:dd:ee:ff [ether] on eth0
```

Lines starting with `#` are ignored. Lines with an invalid IP or invalid MAC are skipped silently.

**Workflow:**
1. Navigate to **Admin → ARP Import**
2. Select the target subnet
3. Paste your ARP table into the textarea
4. Click **Preview** — shows parsed entries with an "In subnet?" column
5. Click **Apply** — updates `addresses.mac` for matching IPs, skips out-of-subnet entries
6. A success flash shows how many MACs were updated

Each apply is audit logged as `address.arp_import`.

---

## REST API — scan resources

See [`docs/api.md`](api.md) for full request/response examples.

| Method | Resource | Auth | Description |
|--------|----------|------|-------------|
| GET | `scan_results&subnet_id=N` | read | Last scan run results for a subnet |
| GET | `scan_history&subnet_id=N[&limit=50]` | read | Paginated scan run history |
| POST | `scan_run&subnet_id=N` | write | Trigger immediate synchronous scan (max /28) |
| GET | `scan_schedules` | read | List all schedules |
| GET | `scan_schedules&subnet_id=N` | read | Get schedule for one subnet (null if none) |
| POST | `scan_schedules` | write | Create or update a schedule (JSON body) |
| DELETE | `scan_schedules&subnet_id=N` | write | Remove a schedule |

**Synchronous scan limit:** `scan_run` is capped at /28 (16 IPs) to prevent HTTP request timeouts. For larger subnets, use the CLI runner.

---

## Security notes

- All IP addresses are validated through `normalize_ip()` (which calls `inet_pton()`) before being passed to `proc_open()` or `fsockopen()`. Raw `$_GET`/`$_POST` values never reach system calls.
- `scan_run.php` rejects web requests at the SAPI check (`php_sapi_name() !== 'cli'`).
- The `ipam-proc-open-safe` Semgrep rule enforces this pattern across the codebase.
