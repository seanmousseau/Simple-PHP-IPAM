# Backup Destinations & Schedules

> **v3.17.0** introduces scheduled remote backups — write backup files to S3-compatible object storage, SFTP servers, or local paths, with GFS (Grandfather-Father-Son) retention, AES-256-GCM encryption, and browser-based history. This guide covers the new system. The legacy `backup.php` CLI (v3.7.0) still exists and continues to write SQLite files to `data/backups/`; see [Backup & Restore (legacy)](backup.md) for that workflow.

---

## Contents

- [Overview](#overview)
- [Concepts](#concepts)
- [Destinations](#destinations)
  - [S3-compatible](#s3-compatible)
  - [SFTP](#sftp)
  - [Local](#local)
- [Schedules](#schedules)
- [GFS retention](#gfs-retention)
- [Encryption](#encryption)
- [Cron integration](#cron-integration)
- [Manual run](#manual-run)
- [Notifications](#notifications)
- [Backup History page](#backup-history-page)
- [Audit log](#audit-log)
- [Engine support](#engine-support)

---

## Overview

v3.17.0 adds a destination-based backup system managed entirely from the admin UI:

1. **Destinations** (`destinations.php`) — named connection profiles for S3, SFTP, or a local path. Each destination is tested independently before use.
2. **Schedules** (`backup_history.php` → Schedule tab) — attach a destination to a cron-like schedule with GFS retention counts. Schedules are evaluated by `cron.php` every time it fires.
3. **Backup History** (`backup_history.php`) — paginated log of every backup and restore operation, with status badges and per-row download/verify actions.
4. **Remote Backup Browser** (`remote_backups.php`) — browse, verify, and delete files that are actually stored on the destination, independent of the local history log.
5. **Web Restore Wizard** (`restore_web.php`) — stage a remote file, run a dry-run preview, and apply a live restore — all from the browser. See [Restore from a backup](restore.md).

The legacy `backup.php` CLI and the `backup_history` rows it writes are unchanged. Both systems write to the same `backup_log` table (column `triggered_by` distinguishes them).

---

## Concepts

### Destinations

A destination is a named connection profile. One destination can be referenced by many schedules. Destinations are created, edited, and tested at **Admin → Backup → Destinations** (`destinations.php`).

Three destination types are supported:

| Type | Use case |
|------|----------|
| `s3` | AWS S3, MinIO, Backblaze B2, Wasabi, DigitalOcean Spaces, Cloudflare R2, any S3-compatible service |
| `sftp` | SFTP server — password or private key authentication |
| `local` | Directory on the same server as the IPAM install |

### Schedules

A schedule binds a destination to a frequency (hourly / daily / weekly / monthly), a time of day, and four GFS retention counts. Each schedule has an independent `next_run_at` timestamp that `cron.php` compares against `datetime('now')` to decide whether to fire.

### GFS retention

GFS (Grandfather-Father-Son) keeps a rolling window of backups across four tiers simultaneously: hourly, daily, weekly, and monthly. After each run, older files that exceed the tier count are pruned from the destination. The newest backup is always preserved.

### Encryption

Backups can be encrypted at rest. When encryption is enabled on a destination, the PHP runtime encrypts the dump with AES-256-GCM before uploading. Encrypted files have the `.enc` suffix. Decryption requires the same `app_secret` that was in `config.php` at encryption time.

---

## Destinations

### S3-compatible

S3 destinations work with any service that implements the AWS S3 REST API and Signature Version 4 (SigV4). Tested providers: AWS S3, MinIO, Backblaze B2, Wasabi, DigitalOcean Spaces, and Cloudflare R2.

| Field | Required | Description |
|-------|----------|-------------|
| `endpoint_url` | yes | Full URL including scheme, e.g. `https://s3.us-east-1.amazonaws.com` or `https://s3.example.com:9000` (MinIO). For AWS S3 you can use `https://s3.{region}.amazonaws.com`. |
| `region` | yes | AWS region string, e.g. `us-east-1`. Required by SigV4 even for non-AWS providers — use any value for providers that do not enforce it, e.g. `auto` for Cloudflare R2. |
| `bucket` | yes | Bucket name. The bucket must already exist; IPAM does not create it. |
| `prefix` | no | Key prefix for all uploaded objects, e.g. `ipam/backups/`. A trailing `/` is added automatically if omitted. Leave empty to write to the bucket root. |
| `access_key` | yes | S3 access key ID. |
| `secret_key` | yes | S3 secret access key. Masked in the UI after save. |
| `encrypt` | no | Enable AES-256-GCM encryption before upload. Encrypted files receive the `.enc` suffix. |

**Test connection** — the Test button (AJAX POST to `test_destination.php`) performs a lightweight `HEAD` request against the bucket root to verify credentials and connectivity without writing any data.

**Example — Backblaze B2:**

| Field | Value |
|-------|-------|
| `endpoint_url` | `https://s3.us-west-004.backblazeb2.com` |
| `region` | `us-west-004` |
| `bucket` | `my-ipam-backups` |
| `prefix` | `prod/` |
| `access_key` | *(your B2 key ID)* |
| `secret_key` | *(your B2 application key)* |

**Example — Cloudflare R2:**

| Field | Value |
|-------|-------|
| `endpoint_url` | `https://<account-id>.r2.cloudflarestorage.com` |
| `region` | `auto` |
| `bucket` | `ipam-backups` |
| `prefix` | *(empty)* |
| `access_key` | *(R2 access key ID)* |
| `secret_key` | *(R2 secret access key)* |

**Example — MinIO (self-hosted):**

```
endpoint_url  https://minio.internal:9000
region        us-east-1
bucket        ipam
prefix        backups/
```

---

### SFTP

SFTP destinations upload backup files over SSH File Transfer Protocol using `phpseclib/phpseclib` (bundled in the release tarball; no install step required).

| Field | Required | Description |
|-------|----------|-------------|
| `host` | yes | Hostname or IP address of the SFTP server. |
| `port` | no | TCP port. Default: `22`. |
| `username` | yes | SSH username. |
| `password` | cond. | Password for password-based auth. Either `password` or `private_key` is required. |
| `private_key` | cond. | PEM-encoded private key (RSA, ECDSA, or Ed25519). Paste the full key including `-----BEGIN ... PRIVATE KEY-----` headers. Stored encrypted at rest using `app_secret`. |
| `remote_path` | yes | Absolute path on the remote server where backup files are written. Must end with `/`, e.g. `/backups/ipam/`. The directory must already exist and be writable by the SFTP user. |
| `fingerprint` | no | Expected host fingerprint (SHA-256, hex). When set, the connection is aborted if the server presents a different fingerprint. Format: `SHA256:<base64>` or a bare hex string. Leave empty to accept any fingerprint (less secure — use only on trusted networks). |
| `encrypt` | no | Encrypt the backup file before upload (see [Encryption](#encryption)). |

**Test connection** — connects via SFTP and checks that `remote_path` is writable by attempting to write and delete a zero-byte probe file (`ipam-dest-test.tmp`). No backup data is written.

---

### Local

Local destinations write backup files to a directory on the same server as the IPAM install. This is useful for testing, for environments that handle off-site sync at the OS/volume level, or for quick daily snapshots before applying a migration.

| Field | Required | Description |
|-------|----------|-------------|
| `path` | yes | Absolute path, **or** a path relative to the IPAM web root (`Simple-PHP-IPAM/`). Relative paths are resolved against the app root. Must not traverse above the app root (path-traversal guard). The directory is created automatically on first backup if it does not exist and the web server user has permission. |
| `encrypt` | no | Encrypt the backup file before writing (see [Encryption](#encryption)). |

**Path-traversal guard** — the path is resolved with `realpath()` after normalisation. Any path that resolves to a directory above the IPAM web root is rejected with a validation error. For example, `../../etc` and `/etc/passwd` are rejected; `data/remote-backups/` and `/var/backups/ipam/` (outside the web root) are accepted.

**Recommended:** point local destinations to a directory outside the web root, or ensure `.htaccess` blocks HTTP access to the target directory. A destination that writes inside `data/` is protected by the existing `data/.htaccess` deny rule.

---

## Schedules

Schedules are created at **Admin → Backup → Schedules** (the Schedules tab on `backup_history.php`).

| Field | Required | Description |
|-------|----------|-------------|
| `destination_id` | yes | Which destination to write to. |
| `frequency` | yes | One of: `hourly`, `daily`, `weekly`, `monthly`. |
| `time_of_day` | no | `HH:MM` in UTC. Applies to `daily`, `weekly`, and `monthly` schedules. Ignored for `hourly`. Default: `02:00`. |
| `day_of_week` | no | Day for `weekly` schedules. Integer `0` (Sunday) through `6` (Saturday). Default: `0` (Sunday). |
| `day_of_month` | no | Day for `monthly` schedules. Integer `1` through `28`. Values above `28` are clamped to `28` to avoid month-end ambiguity. Default: `1`. |
| `retain_hourly` | no | How many hourly backup files to keep. Default: `24`. |
| `retain_daily` | no | How many daily backup files to keep. Default: `7`. |
| `retain_weekly` | no | How many weekly backup files to keep. Default: `4`. |
| `retain_monthly` | no | How many monthly backup files to keep. Default: `12`. |
| `is_active` | yes | Toggle the schedule on/off without deleting it. |

`next_run_at` is computed when the schedule is created or edited (any change to the timing fields recomputes it from `frequency` / `time_of_day` / `day_of_week` / `day_of_month`) and again after each run. `cron.php` sets `next_run_at` to the next fire time in UTC after a successful run; failed runs leave `next_run_at` unchanged so the next cron tick will retry rather than skipping ahead. Schema-level CHECK constraints on `backup_schedules` enforce valid values for `frequency`, `day_of_week` (0–6), `day_of_month` (1–28), and the retention counts (≥0); `time_of_day` is validated to `00:00–23:59` at the form layer.

---

## GFS retention

GFS (Grandfather-Father-Son) retention keeps separate rolling windows across four backup tiers. Each run is tagged with its tier (`hourly`, `daily`, `weekly`, `monthly`) based on when it fired. After a successful run, the GFS pruning pass examines the destination and removes the oldest files per tier until the count is within the configured limit.

**How tiers are assigned:**

- A backup triggered by an `hourly` schedule is always tagged `hourly`.
- A backup triggered by a `daily` schedule is tagged `daily`.
- A backup triggered by a `weekly` schedule is tagged `weekly` **and** `daily` (weekly backups also count as the daily backup for that day).
- A backup triggered by a `monthly` schedule is tagged `monthly`, `weekly`, and `daily`.

This means a single backup file may satisfy multiple tiers. When pruning, IPAM looks at which files *uniquely* satisfy each tier to avoid deleting a monthly backup because the daily count was exceeded.

**The newest backup is always preserved.** If a prune pass would delete the most-recent file, it is skipped. This prevents a misconfigured retention count from leaving the destination empty.

**Example — daily schedule with default retention:**

```
retain_daily   = 7
retain_weekly  = 4
retain_monthly = 12
```

After 14 days of daily backups, the 8th-oldest daily file is deleted, keeping the 7 most recent. After 5 weeks, the 5th-oldest Sunday backup is deleted. After 13 months, the 13th-oldest first-of-month backup is deleted.

---

## Encryption

When `encrypt` is enabled on a destination, the backup dump is encrypted with **AES-256-GCM** before upload. The encryption key is derived from `app_secret` (from `config.php`) using HKDF-SHA256 with the info string `'ipam-v3:backup'`.

**File format:**

```
[8 bytes magic: "IPAMBKP1"] [12 bytes random IV] [ciphertext] [16 bytes GCM tag]
```

The magic header (`IPAMBKP1`) identifies the format version. The decryption helper rejects any file that does not start with this header. Future format versions will use a different magic string, enabling clean detection and a clear error message.

**Encrypted files have the `.enc` suffix** appended to the normal filename, for example:

```
ipam-20260428-020000.sqlite.enc
```

**Prerequisites:**

- `app_secret` must be set in `config.php` before enabling encryption. Generate one with:
  ```bash
  php -r "echo bin2hex(random_bytes(32));"
  ```
- The same `app_secret` must be present at both encrypt and decrypt time.

**Key rotation warning:** rotating `app_secret` invalidates all existing encrypted backups. Files encrypted with the old key cannot be decrypted with the new one. Before rotating, either keep a copy of the old key or decrypt all existing backups first.

---

## Cron integration

Schedules are evaluated by `cron.php`. No additional cron entry is needed if you already have `cron.php` scheduled — the backup job runs as part of the normal housekeeping loop.

If you do not yet have a cron entry, add one:

```cron
*/5 * * * * php /path/to/Simple-PHP-IPAM/cron.php >> /var/log/ipam-cron.log 2>&1
```

`cron.php` checks all active schedules and runs any whose `next_run_at` is in the past. Finer cron granularity means backups fire closer to their scheduled time. `*/5` (every 5 minutes) is the recommended interval — it means a scheduled backup can be at most 5 minutes late.

**How the backup job runs:**

1. `cron.php` queries `backup_schedules` for active rows with `next_run_at <= datetime('now')`.
2. For each due schedule, it instantiates `BackupEngine` with the destination config.
3. `BackupEngine` calls `ipam_db_dump_stream()` to generate the SQL dump.
4. The dump is optionally encrypted, then uploaded to the destination.
5. A row is inserted into `backup_log` with status `success` or `failed`.
6. GFS retention pruning runs against the destination.
7. `next_run_at` is updated to the next fire time.

If the dump or upload fails, the `backup_log` row is marked `failed` and (if `backup.notify_on_failure` is enabled) a notification email is sent to `alert_email`. The schedule remains active and will retry at the next `next_run_at`.

---

## Manual run

The **Run Now** button on the Schedules table triggers an AJAX POST to `run_backup_now.php`. The browser polls for progress until the run completes and then refreshes the schedule row's last-run time and status badge.

Manual runs are recorded in `backup_log` with `triggered_by = 'manual'`. They do not affect `next_run_at` — the next scheduled run fires at the same time regardless.

---

## Notifications

Two settings control email notifications for backup runs. Configure them at **Admin → Settings → Data & Maintenance**:

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `backup.notify_on_failure` | bool | `true` | Send an email to `alert_email` when a scheduled or manual backup run fails. |
| `backup.notify_on_success` | bool | `false` | Send an email to `alert_email` when a scheduled or manual backup run succeeds. |

Notifications use the configured SMTP transport (Admin → Settings → Notifications) or the system `mail()` function if SMTP is not configured. The `alert_email` setting (Admin → Settings → Notifications) controls the recipient.

If `alert_email` is empty or SMTP is misconfigured, notification emails silently fail — check **Admin → Health** for SMTP diagnostics.

---

## Backup History page

**Admin → Backup → History** (`backup_history.php`) shows a paginated log of all backup and restore operations.

**Columns:**

| Column | Description |
|--------|-------------|
| When | Timestamp of the run (displayed in the user's configured timezone). |
| Destination | Linked destination name and type badge. |
| Type | `backup` or `restore` (restore entries are added by the web wizard). |
| Trigger | `cron`, `manual`, or `web_restore`. |
| Status | `success`, `failed`, or `running` (in-flight). |
| Size | Uncompressed dump size. |
| Duration | Wall-clock time for the backup job. |
| Actions | Download (re-fetch from destination), Verify (SHA-256 recheck), Delete (removes the log row; does not delete the file on the destination — use Remote Backup Browser for that). |

**Filters:** filter by destination, type (backup/restore), status, and date range. The URL reflects the active filters so pages can be bookmarked.

**Restore entries** appear in the same table. They are created by `restore_web.php` and carry `triggered_by = 'web_restore'`. The Status column shows `success` after the live apply completes.

---

## Audit log

All backup and destination operations are recorded in the audit log:

| Action | When |
|--------|------|
| `destination.create` | A new destination is saved. |
| `destination.update` | An existing destination is edited. |
| `destination.delete` | A destination is deleted (cascades to associated schedules). |
| `destination.test` | Test Connection is clicked; result (ok/fail) is in the detail. |
| `schedule.create` | A new schedule is saved (`next_run_at` is computed at create). |
| `schedule.update` | A schedule is edited (`next_run_at` is recomputed from the new timing fields). |
| `schedule.delete` | A schedule is deleted. |
| `backup.run` | A backup job completes successfully (via `BackupEngine` or legacy `backup.php`). |
| `backup.failed` | A backup job fails (via `BackupEngine` or legacy `backup.php`). |
| `backup.retention_pruned` | GFS pruning deleted one or more files from a destination. |
| `remote_backup.delete` | A file is deleted from the Remote Backup Browser. |
| `remote_backup.delete_failed` | A delete is requested but the destination reports the file did not exist or rejected the request. |
| `remote_backup.verify` | A file's checksum is verified from the Remote Backup Browser. |
| `remote_backup.download` | A file is downloaded via `download_remote_backup.php`. |
| `remote_backup.download_failed` | A remote file download fails. |

Restore-specific audit actions (`db.restore_stage`, `db.restore_dryrun`, `db.restore`) are documented in [Restore from a backup](restore.md#audit-log).

---

## Engine support

**v3.17.0 supports SQLite only** for the backup dump step. The dump is generated by the existing `ipam_db_dump_stream()` helper, which produces plain-SQL output from the SQLite file.

MySQL and PostgreSQL backup dumps are planned for a follow-up release.

The **destination**, **schedule**, **GFS retention**, **encryption**, and **history** infrastructure is fully engine-agnostic — MySQL and PostgreSQL installs can configure destinations and schedules today. When MySQL/PostgreSQL dump support ships, existing destination and schedule config will be used without change.

The legacy v3.7.0 `backup.php` CLI is unchanged and continues to write `.sqlite` files to `data/backups/`. It is not affected by this feature.
