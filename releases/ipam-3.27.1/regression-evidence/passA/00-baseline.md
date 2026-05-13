# Pass A — Baseline (2026-05-08 21:16 EDT / 01:16 UTC)

## Environment

| Item | Value |
|---|---|
| Test instance | `https://dev-direct.seanmousseau.com:8343/testing/ipam/` |
| Container | `dev_seanmousseau_com-apache-php-1` on `192.168.80.15` |
| App path on host | `/opt/container_data/dev.seanmousseau.com/html/testing/ipam/` |
| App path in container | `/var/www/html/testing/ipam/` |
| Deployed version | **v3.27.0** |
| DB engine | SQLite |
| DB snapshot | `db-snapshots/baseline.sqlite` (copied from host `/tmp/ipam-passA-baseline-20260509-011600.sqlite`) |

## config.php key state

| Key | Status |
|---|---|
| `app_secret` | SET (32-byte hex) |
| `bootstrap_key` | **absent** |
| `backup_vault_key` | **absent** (legacy in-config; DB row also absent) |

## DB rows

| Table | Count / state |
|---|---|
| `users` | 3 — admin (no MFA), claude (no MFA, test admin), test (TOTP+EmailOTP, readonly) |
| `backup_destinations` | 2 — wasabi_ca-central-1 (s3, logical, unencrypted, encrypt=0), Legacy local backups (local, logical, unencrypted, encrypt=0) |
| `backup_schedules` | 2 — both daily 02:00, both active. Schedule id=1 has stale next_run_at=2026-04-30 |
| `backup_runs` | 13 rows, all `encryption_mode='unencrypted'` |
| `settings.backup_vault_key` | **absent** |
| `settings.bootstrap_key` | **absent** |
| Step-up policy | TTL=300s, all 4 methods allowed |
| `audit_log MAX(id)` | **100486** ← baseline; Pass A audit-diff = rows with id > 100486 |

## Test admin

- Username: `claude`
- Password: `ClaudeTesting123!`
- Role: admin
- MFA: none enrolled (so TOTP-based step-up tests will need enrollment first OR use password proof since `allow_provider_reauth=true`)

## Implications for the plan

- **§3 Backup write path:** the existing destinations are `unencrypted`. The bug won't trigger on them naturally. RT- destinations created in §3.1 with `encryption_mode=stored` will exercise the bug.
- **§2 Step-up:** can use **password** proof for `claude` (no TOTP needed). Will enroll TOTP on `claude` only if a specific test row requires it (Bug X §2.y verification works with any method since the consumption bug is method-agnostic).
- **§2.z Bug Y test:** the `test` user has BOTH TOTP and Email OTP enrolled. For the lockout test I need a user with ONLY ONE method. Will create `RT-20260508-mfauser` per the plan, enroll only TOTP.
- **§3.4/3.5 "neither key" / "both keys" branches:** need to temporarily flip `app_secret` and the DB vault key. Will save current state and restore in §13.
