# Backups

> **v3.21.0** consolidates every backup and restore screen into a single admin surface at **Admin → Backup & Restore** (`backup_admin.php`). Six legacy pages (`db_tools.php`'s backup half, `destinations.php`, `backup_history.php`, `remote_backups.php`, `restore_web.php`, and the Backups section of `settings.php`) are retired. Their URLs still resolve for now (legacy bookmarks 301 to the new location) but no documentation should reference them.
>
> Restore is documented separately in **[Restore from a backup](restore.md)**.

---

## Contents

- [Overview](#overview)
- [The unified surface](#the-unified-surface)
- [Concepts](#concepts)
- [Destinations](#destinations)
  - [S3-compatible](#s3-compatible)
  - [SFTP](#sftp)
  - [Local](#local)
- [Schedules and GFS retention](#schedules-and-gfs-retention)
- [Encryption](#encryption)
- [Cron integration](#cron-integration)
- [Manual run](#manual-run)
- [Notifications](#notifications)
- [History](#history)
- [Audit log](#audit-log)
- [Engine support](#engine-support)
- [On-disk format](#on-disk-format)
- [`backup.php` CLI](#backupphp-cli)

---

## Overview

Simple PHP IPAM ships with a destination-based backup system that writes encrypted, scheduled backups of the database to S3-compatible object storage, SFTP servers, or local paths. Backups are managed entirely from the admin UI; the legacy CLI still works for unattended cron jobs.

Two backup types are supported:

| Type | What it captures | When to use |
|---|---|---|
| **Database** (engine-faithful) | A native dump of the underlying database — `.sqlite` for SQLite, `mysqldump` output for MySQL, `pg_dump` output for PostgreSQL. | Disaster recovery on the same engine. The fastest restore path. |
| **Logical** (portable) | An engine-neutral SQL representation of the IPAM data model. Restorable across engines. | Migrating between SQLite, MySQL, and PostgreSQL. Long-term archival where the future restore engine is unknown. |

> Both types share the same destination, schedule, encryption, and retention infrastructure. Logical backups are the v3.21.0 default; Database backups are recommended only when an engine-faithful restore on the same engine is required.

Three encryption modes are available per destination:

| Mode | Behaviour | Recommended when |
|---|---|---|
| **Stored** | Backup is encrypted with a key derived from `app_secret` and written encrypted at rest. The destination cannot read the contents. | Untrusted destinations (third-party S3, shared SFTP). Default. |
| **Transitory** | Backup is encrypted in transit (TLS to S3 / SSH to SFTP) but written in plaintext at the destination. | The destination is fully trusted (e.g. an internal NFS mount with disk encryption already). |
| **Unencrypted** | No encryption at any layer. | Local destination on the same host, or when an external system handles encryption (e.g. an LVM-encrypted volume). |

The full byte format of encrypted files is documented in [On-disk format](#on-disk-format) below. v3.24.0's IPAMBKP3 format will replace IPAMBKP2 with a key-rotation-friendly envelope; this guide is updated when that ships.

---

## The unified surface

`backup_admin.php` has five tabs:

| Tab | Purpose |
|---|---|
| **Backup** | Run an on-demand backup against a chosen destination. Inline progress, no page reload. |
| **Restore** | Stage a backup from a destination, run a dry-run, and apply it. See [Restore from a backup](restore.md). |
| **Destinations** | Create, edit, test, and delete destinations. The schedule for each destination is edited inline on the destination's row. |
| **Notifications** | Toggle email-on-failure / email-on-success and view the active SMTP transport. |
| **History** | Paginated log of every backup and restore run, with per-row download / verify / delete actions. |

Tabs are rendered via `backup_admin.php?tab=<slug>`. Direct links to specific tabs work for bookmarks (e.g. `backup_admin.php?tab=history`).

---

## Concepts

### Destinations

A destination is a named connection profile. Three types are supported: S3-compatible, SFTP, and Local. One destination can be referenced by many schedules — but in practice each destination usually owns exactly one schedule, and the schedule is edited inline on the destination row.

### Schedules

A schedule binds a destination to a frequency (hourly / daily / weekly / monthly), a time of day, and four GFS retention counts. Each schedule has an independent `next_run_at` timestamp that `cron.php` compares against `datetime('now')` to decide whether to fire.

### Runs

Every backup attempt — scheduled, manual, or CLI — produces one row in the `backup_runs` table. The row records the destination, the trigger (`schedule` / `manual` / `cli`), the backup type and encryption mode, the start and finish timestamps, the file size and checksum, and the success or failure detail. The History tab is a view onto this table.

`backup_runs` consolidates two earlier tables (`backup_history` and `backup_log`) that were merged in v3.21.0. Migrations populate the new schema from the legacy rows; no operator action is required.

---

## Destinations

The Destinations tab is at `backup_admin.php?tab=destinations`. Click **+ Add destination**, choose a type, fill in the fields, and Save. The Test button runs a low-impact connectivity check before any backup data is written.

### S3-compatible

S3 destinations work with any service that implements the AWS S3 REST API and Signature Version 4 (SigV4). Tested providers: AWS S3, MinIO, Backblaze B2, Wasabi, DigitalOcean Spaces, and Cloudflare R2.

| Field | Required | Description |
|-------|----------|-------------|
| `endpoint_url` | yes | Full URL including scheme, e.g. `https://s3.us-east-1.amazonaws.com` or `https://s3.example.com:9000` (MinIO). For AWS S3 you can use `https://s3.{region}.amazonaws.com`. |
| `region` | yes | AWS region string, e.g. `us-east-1`. Required by SigV4 even for non-AWS providers — use `auto` for Cloudflare R2. |
| `bucket` | yes | Bucket name. The bucket must already exist; IPAM does not create it. |
| `prefix` | no | Key prefix for all uploaded objects, e.g. `ipam/backups/`. A trailing `/` is added automatically if omitted. |
| `access_key` | yes | S3 access key ID. |
| `secret_key` | yes | S3 secret access key. Masked in the UI after save. |

**Test connection** runs a lightweight `HEAD` against the bucket root to verify credentials and connectivity without writing any data.

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
| `access_key` | *(R2 access key ID)* |
| `secret_key` | *(R2 secret access key)* |

### SFTP

SFTP destinations upload backup files over SSH File Transfer Protocol using `phpseclib/phpseclib` (bundled in the release tarball; no install step required).

| Field | Required | Description |
|-------|----------|-------------|
| `host` | yes | Hostname or IP address of the SFTP server. |
| `port` | no | TCP port. Default: `22`. |
| `username` | yes | SSH username. |
| `password` | cond. | Password for password-based auth. Either `password` or `private_key` is required. |
| `private_key` | cond. | PEM-encoded private key (RSA, ECDSA, or Ed25519). Stored encrypted at rest using `app_secret`. |
| `remote_path` | yes | Absolute path on the remote server where backup files are written. Must end with `/`. |
| `fingerprint` | no | Expected host fingerprint (`SHA256:<base64>` or hex). When set, the connection aborts on a mismatch. Leave empty to accept any fingerprint (less secure — use only on trusted networks). |

**Test connection** connects via SFTP and writes-then-deletes a zero-byte probe file (`ipam-dest-test.tmp`) at `remote_path` to confirm the directory is writable.

### Local

Local destinations write backup files to a directory on the same server as the IPAM install. Useful for testing, or for environments that handle off-site sync at the OS / volume level.

| Field | Required | Description |
|-------|----------|-------------|
| `path` | yes | Absolute path, **or** a path relative to the IPAM web root. Relative paths are resolved against the app root. Cannot traverse above the app root. The directory is created on first backup if missing and the web server user has permission. |

**Recommendation:** point local destinations to a directory outside the web root, or ensure `.htaccess` blocks HTTP access to the target directory. A destination that writes inside `data/` is protected by the existing `data/.htaccess` deny rule.

---

## Schedules and GFS retention

A schedule is edited inline on its destination row in the Destinations tab. Click **Edit schedule** on the row to open the drawer.

| Field | Description |
|-------|-------------|
| `frequency` | One of `hourly`, `daily`, `weekly`, `monthly`. |
| `time_of_day` | `HH:MM` in UTC. Applies to `daily`, `weekly`, `monthly`. Ignored for `hourly`. |
| `day_of_week` | Day for `weekly` schedules. `0` (Sunday) through `6` (Saturday). |
| `day_of_month` | Day for `monthly` schedules. `1` through `28`. Values above 28 are clamped. |
| `retain_hourly` | How many hourly backups to keep. Default: `24`. |
| `retain_daily`  | How many daily backups to keep. Default: `7`. |
| `retain_weekly` | How many weekly backups to keep. Default: `4`. |
| `retain_monthly` | How many monthly backups to keep. Default: `12`. |
| `is_active` | Toggle the schedule on/off without deleting it. |

`next_run_at` is computed when the schedule is created or edited (any timing change recomputes it) and again after each run. `cron.php` advances `next_run_at` to the next fire time after a successful run; failed runs leave it unchanged so the next cron tick retries rather than skipping ahead.

**GFS retention** keeps separate rolling windows across four tiers. A backup triggered by a `weekly` schedule satisfies both `weekly` and `daily`; a `monthly` backup satisfies `monthly`, `weekly`, and `daily`. Pruning examines which files *uniquely* satisfy each tier so a monthly file is not deleted because the daily count was exceeded. **The newest backup is always preserved**, even if a misconfigured retention count would otherwise empty the destination.

---

## Encryption

Encryption is configured per destination by selecting an [encryption mode](#overview). When **Stored** mode is enabled, the dump is encrypted before upload using a key derived from `app_secret` via HKDF-SHA256. Encrypted files have the `.enc` suffix.

**Prerequisites for Stored mode:**

- `app_secret` must be set in `config.php`. Generate one with:
  ```bash
  php -r "echo bin2hex(random_bytes(32));"
  ```
- The same `app_secret` must be present at both encrypt and decrypt time.

**Key rotation warning:** rotating `app_secret` invalidates all existing encrypted backups. Files encrypted with the old key cannot be decrypted with the new one. Before rotating, either keep a copy of the old key, decrypt all existing backups first, or take a fresh backup with the new key.

The full byte format of IPAMBKP1 (v3.17–v3.18) and IPAMBKP2 (v3.19+) is in [On-disk format](#on-disk-format). v3.24.0 introduces IPAMBKP3 with key-rotation support; this section is updated when it ships.

---

## Cron integration

Schedules are evaluated by `cron.php`. No additional cron entry is needed if `cron.php` is already scheduled — the backup job runs as part of the normal housekeeping loop.

If you do not yet have a cron entry, add one:

```cron
*/5 * * * * php /path/to/Simple-PHP-IPAM/cron.php >> /var/log/ipam-cron.log 2>&1
```

`*/5` (every 5 minutes) is recommended — a scheduled backup will be at most 5 minutes late.

**How the backup job runs:**

1. `cron.php` queries `backup_schedules` for active rows with `next_run_at <= datetime('now')`.
2. For each due schedule, it instantiates `BackupEngine` with the destination config.
3. `BackupEngine` generates the dump (Logical or Database, per the destination configuration).
4. The dump is optionally encrypted, then uploaded to the destination.
5. A row is inserted into `backup_runs` with `status = 'success'` or `'failed'`.
6. GFS retention pruning runs against the destination.
7. `next_run_at` is updated to the next fire time.

If the dump or upload fails, the `backup_runs` row is marked `failed` and (if `backup.notify_on_failure` is enabled) a notification email is sent to `alert_email`. The schedule remains active and retries at the next `next_run_at`.

---

## Manual run

The **Backup** tab shows a destination dropdown and a **Run backup now** button. Click it to trigger an immediate backup. Progress is reported inline (no page reload) and the destination's history row updates when the run completes.

Manual runs are recorded in `backup_runs` with `triggered_by = 'manual'`. They do not affect `next_run_at` — the next scheduled run still fires at its original time.

---

## Notifications

The **Notifications** tab toggles email alerts for backup outcomes:

| Setting | Default | Description |
|---|---|---|
| `backup.notify_on_failure` | `true` | Send email to `alert_email` when a backup run fails. |
| `backup.notify_on_success` | `false` | Send email to `alert_email` when a backup run succeeds. |

`alert_email` and the SMTP transport are configured under **Admin → Settings → Notifications**. The Notifications tab shows the current `alert_email` and SMTP status as a read-only summary so you can confirm where notifications will go.

If `alert_email` is empty or SMTP is misconfigured, notification emails silently fail — check **Admin → Health** for SMTP diagnostics.

---

## History

The **History** tab is a paginated view of every row in `backup_runs`.

**Columns:**

| Column | Description |
|--------|-------------|
| When | Started-at timestamp, in your configured timezone. |
| Destination | Destination name and type badge. |
| Type | `database` or `logical`. |
| Trigger | `schedule`, `manual`, or `cli`. |
| Status | `running`, `success`, `failed`, or `retention_pruned`. |
| Size | Uncompressed dump size. |
| Duration | Elapsed time between started_at and completed_at. |
| Actions | Download (re-fetch from destination), Verify (SHA-256 recheck), Delete (removes both the `backup_runs` row AND the artifact on the destination, with a literal `DELETE` confirmation). |

**Per-row detail drawer ([#803](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/803), shipped v3.21.0).** Click any history row to open a drawer showing the full `backup_runs` payload (started/completed timestamps, size, checksum, error message) plus three actions: **Verify** re-downloads the artifact and SHA-256-compares against the stored checksum; **Download** is a signed link via `download_remote_backup.php`; **Delete** requires typing the literal string `DELETE` and removes both the row and the remote artifact (audited as `remote_backup.delete` / `remote_backup.delete_failed`). Disabled buttons carry `title=` tooltips explaining why (e.g. retained-by-policy rows, missing artifact, in-flight runs).

**Filter chips ([#804](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/804), shipped v3.21.0).** Three chip rows above the history table — **Status** (All / Running / Success / Failed / Retention pruned), **Backup type** (All / Database / Logical), **Time** (All time / Last 24h / Last 7d / Last 30d). Each chip is a plain link that mutates one URL parameter (`status`, `backup_type`, `since`); a **Clear all** chip appears when any filter is non-default. Custom date ranges and the destination dropdown remain available below the chips for fine-grained control.

---

## Audit log

Every backup-surface operation is recorded in the audit log. See [Restore from a backup → Audit log](restore.md#audit-log) for the restore-specific actions.

| Action | When |
|--------|------|
| `destination.create` | A new destination is saved. |
| `destination.update` | An existing destination is edited. |
| `destination.delete` | A destination is deleted (cascades to associated schedules). |
| `destination.test` | Test Connection is clicked; result (ok / fail) is in the detail. |
| `schedule.create` | A new schedule is saved (`next_run_at` computed at create time). |
| `schedule.update` | A schedule is edited (`next_run_at` recomputed). |
| `schedule.delete` | A schedule is deleted. |
| `backup.run` | A backup job completes successfully. |
| `backup.failed` | A backup job fails. |
| `backup.retention_pruned` | GFS pruning deleted one or more files from a destination. |
| `notification.update` | Email-on-failure or email-on-success setting changed. |

---

## Engine support

Logical backups and Database backups are supported on **all three engines** (SQLite, MySQL, PostgreSQL).

- **SQLite Database backup** — generated via `ipam_db_dump_stream()`.
- **MySQL Database backup** — generated by shelling out to `mysqldump`. Path is auto-detected; override via `backup.mysqldump_path` in config if needed.
- **PostgreSQL Database backup** — generated by shelling out to `pg_dump`. Override via `backup.pg_dump_path` if needed.
- **Logical backup** — engine-neutral; identical SQL on all three engines.

Restore-side engine support (which restore paths run from the browser vs. CLI) is documented in [Restore from a backup → Engine support](restore.md#engine-support).

---

## On-disk format

Encrypted backups carry an 8-byte magic header that identifies the format.

### v2 — `IPAMBKP2` (v3.19.0+, streaming)

```text
offset  size   field
0       8      magic  = "IPAMBKP2"
8       16     salt   = random_bytes(16)        ; per-file HKDF salt
24      16     iv     = random_bytes(16)        ; AES-256-CTR initial counter block
40      N      ciphertext = AES-256-CTR(enc_key, iv, plaintext) streamed
40+N    32     hmac   = HMAC-SHA256(mac_key, magic || salt || iv || ciphertext)
```

- Per-file salt fed to HKDF as the RFC 5869 `salt` parameter (HKDF-Extract): `ipam_hkdf_sha256($appSecret, info='ipam-v3:backup-v2', length=64, salt=$salt)`. First 32 bytes → enc_key; last 32 bytes → mac_key.
- Encrypt-then-MAC; HMAC covers magic + salt + iv + ciphertext, so an attacker cannot tamper with the header.
- Restore is single-pass: each chunk is decrypted into a temp file (`$dstPath . '.decrypting.<rand>'`) while the HMAC accumulates over the same ciphertext. The temp file is atomically renamed to `$dstPath` only after the trailing MAC matches; on any failure path the temp is unlinked.
- 64 KiB streaming chunks; AES-CTR counter block is advanced manually between chunks.
- Memory-bound by `BACKUP_STREAM_CHUNK` (64 KiB) regardless of payload size.

### v1 — `IPAMBKP1` (v3.17–v3.18, single-shot AES-256-GCM)

```text
offset  size  field
0       8     magic = "IPAMBKP1"
8       12    iv
20      16    GCM auth tag
36      N     ciphertext
```

v1 backups are restored via the legacy `backup_decrypt()` path. Restoring a v1 backup loads the whole ciphertext into RAM — operators with multi-GB databases who hit the original OOM should take a fresh backup on v3.19+ to switch to the streaming v2 format.

### Format dispatch on restore

`backup_decrypt_to_path()` peeks the first 8 bytes:

- `IPAMBKP2` → `backup_decrypt_stream()` (streaming).
- `IPAMBKP1` → load full file → `backup_decrypt()` → write (back-compat).
- Anything else → `RuntimeException`.

### v3 — `IPAMBKP3` (planned, v3.24.0)

`IPAMBKP3` introduces an envelope structure where a per-file data-encryption key is wrapped with a key-encryption key derived from `app_secret`. This enables key rotation without re-encrypting every backup file. This section is updated when it ships.

---

## `backup.php` CLI

The legacy `backup.php` CLI runner is unchanged and continues to work for unattended cron jobs, systemd timers, or monitoring agents. It writes through the same `BackupEngine` and `backup_runs` table as the scheduled job; CLI runs appear in the History tab with `triggered_by = 'cli'`.

```bash
php /path/to/Simple-PHP-IPAM/backup.php [-f|--force]
```

Web requests to `backup.php` always return HTTP 403 Forbidden — the script is CLI-only.

**Exit codes** (stable contract):

| Exit | Meaning |
|------|---------|
| 0 | Backup ran and completed successfully. |
| 1 | DB / config / dump failure, **or** another process holds the backup lock. The orchestrator currently collapses both into the same return code; structured exit codes are tracked in [#797](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/797). |
| 2 | Reserved for "already running" (split from 1 in a follow-up release). |
| 3 | Backup not due — either `backup.enabled = false` in settings, or no schedule is currently due and `--force` was not passed. |

The CLI accepts `-f`, `--force`, and `--force=1` interchangeably.

For incident response — diagnosing failed runs, recovering from corrupt backups, dealing with a stuck `running` row — see the internal [Backup & Restore runbook](internal/backup-restore-runbook.md).
