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
- [Disaster recovery — back up your keys, not just your data](#disaster-recovery--back-up-your-keys-not-just-your-data)
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

Simple PHP IPAM ships with a destination-based backup system that writes scheduled (optionally encrypted) backups of the database to S3-compatible object storage, SFTP servers, or local paths. Backups are managed entirely from the admin UI; the legacy CLI still works for unattended cron jobs.

Two backup types are available — pick per destination:

| Type | What it captures | When to use |
|---|---|---|
| **Logical** (portable) | An engine-neutral representation of the IPAM data model (`IPAMBKL1` NDJSON, gzipped). Restorable across engines. Shipped in v3.23.0 (#824); **the default for new destinations**. | Migrating between SQLite / MySQL / PostgreSQL; long-term archival where the future restore engine is unknown; the recommended general-purpose choice. |
| **Database** (engine-faithful) | A native dump of the underlying database — a SQL dump for SQLite (`ipam_db_dump_stream()` output), `mysqldump` for MySQL, `pg_dump` for PostgreSQL. | Fastest restore on the *same* engine. Note: a Database-format restore is engine-specific (a MySQL `.sql.gz` only restores into MySQL), and the SQLite Database-format restore path is a full *replace* of the live schema + data. |

Both types share the same destination, schedule, encryption, and retention infrastructure, and the `Backup type` filter chip on the History tab.

Encryption is configured per destination. **As of v3.24.0** the encrypted format is `IPAMBKP3`, with three modes — each describing where the key comes from. TLS / SSH protect the wire path independent of mode.

| Mode | Key source | Recommended when |
|---|---|---|
| **Stored** | Server-managed `backup_vault_key` (separate from `app_secret`; set by an admin under **Admin → Backups → Destinations → "Set vault key"**, then stored *wrapped* in the `settings` table — see [`configuration.md` → backup_vault_key](configuration.md#backup_vault_key)). Used for every scheduled run by default. | Hands-free scheduled backups. Operator does not need to remember a passphrase. Restore unwraps the key automatically (needs `config.php`'s `bootstrap_key` + the DB). |
| **Transitory** | Operator-typed passphrase, never persisted anywhere. Server uses Argon2id (RFC 9106 v1.3) to derive the key with default parameters `t=3, m=64 MiB, p=1` (per-file random salt; parameters are header-embedded so future tuning is non-breaking). | **Restore-side only in v3.28.0.** The Restore wizard's upload step accepts an IPAMBKP3 transitory archive produced elsewhere (e.g. by `tools/decrypt-backup.php` in a future round-trip mode, or by another install once the write path is implemented) and prompts for the passphrase. There is no in-app flow that *creates* a transitory archive — see [`docs/internal/parked-features.md`](internal/parked-features.md#operator-passphrase-backup-creation-ipambkp3-transitory-write-path). |
| **Unencrypted** | No key. Files use the IPAMBKU1 wrapper format (magic + SHA-256 + plaintext) — integrity is still checked on restore but the contents are readable. | Trusted local destinations on hosts with full-disk encryption, or test installs where confidentiality is irrelevant. |

> **As of v3.28.0 the vault key is not auto-generated.** An encrypted scheduled backup with no `backup_vault_key` configured fails preflight with an actionable message — configure one (Stored mode) under Admin → Backups → Destinations, or switch the destination's encryption mode to `unencrypted` (Local destinations only). The legacy `app_secret`-derived backup-encryption *write* path was removed in v3.28.0; the *reader* for legacy `app_secret`-encrypted archives (IPAMBKP1/IPAMBKP2) is retained through the v3.x line — see [upgrading.md § v3.28.0](upgrading.md#v3280) for the migration path.

`backup_vault_key` is documented in [`configuration.md` → backup_vault_key](configuration.md#backup_vault_key). **Before you ever need a restore, read [Disaster recovery — back up your keys, not just your data](#disaster-recovery--back-up-your-keys-not-just-your-data) below.** Existing IPAMBKP1 (v3.17–v3.18) and IPAMBKP2 (v3.19–v3.23) archives remain restorable on v3.24+ — no migration is required and no operator action is forced. See [On-disk format](#on-disk-format) for the byte layout.

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

Encryption is configured per destination by selecting an [encryption mode](#overview). The byte format depends on the mode and on which IPAM version produced the file.

### v3.24+ formats (IPAMBKP3, IPAMBKU1)

**Stored mode** is the default for scheduled / destination-driven backups. The codec uses HKDF-SHA256 over `backup_vault_key` (a 32-byte secret separate from `app_secret`) with a per-file random salt to derive an AES-256-CTR encryption key and an HMAC-SHA256 MAC key. The body is streamed in 64-KiB chunks (memory bound is constant regardless of input size). The HMAC covers `header || ciphertext` in encrypt-then-MAC order.

**Transitory mode** is the IPAMBKP3 variant the Restore wizard's upload-and-restore path accepts when an operator brings an archive that was encrypted with a passphrase rather than the install's vault key. The codec runs Argon2id (RFC 9106 v1.3, parameters `t=3 / m=64 MiB / p=1` by default; per-file random salt) over the operator's passphrase to derive a 32-byte key, then HKDF-SHA256 + AES-256-CTR + HMAC-SHA256 as for stored mode. Argon2id parameters are header-embedded so future tuning is backwards-compatible. **As of v3.28.0 the in-app backup orchestrator does not produce transitory archives** — only the decrypt/restore side is wired. The write-side flow (manual "Run backup now" with an operator-typed passphrase) is parked; see [`docs/internal/parked-features.md`](internal/parked-features.md#operator-passphrase-backup-creation-ipambkp3-transitory-write-path).

**Unencrypted (IPAMBKU1)** wraps plaintext with a magic header + SHA-256 digest. Integrity is checked on restore but contents are readable. Used when an operator opts a destination out of encryption (typically a local destination on a full-disk-encrypted host).

#### Prerequisites and key lifecycle

- **Stored mode:** requires a `backup_vault_key`, configured by an admin under **Admin → Backups → Destinations → "Set vault key"**. It is stored *wrapped* in the `settings` table (libsodium `crypto_secretbox`, envelope `IPAMWK1.…`) under `bootstrap_key` (which lives in `config.php`, auto-generated on first use). It is **not** auto-generated as of v3.28.0 — an encrypted scheduled backup with no vault key configured fails preflight. See [`configuration.md` → backup_vault_key](configuration.md#backup_vault_key) for setup, rotation, and the full storage model, and [Disaster recovery](#disaster-recovery--back-up-your-keys-not-just-your-data) below for what to save off-site.
- **Transitory mode:** no server-side state. The operator types the passphrase on every backup AND every restore. Lose the passphrase → the archive is unrecoverable.
- **Argon2id parameters** are header-embedded, so future installs can tune `t`/`m` without breaking existing backups. Bounds: `t` in `[1, 16]`, `m` in `[8 KiB, 1 GiB]`, `p` is fixed at 1 (libsodium API constraint).

### Legacy formats (IPAMBKP1, IPAMBKP2)

- **IPAMBKP2 (v3.19–v3.23)**: streaming AES-256-CTR + HMAC-SHA256, key derived from `app_secret` via HKDF-SHA256 with a per-file random salt. Restore is fully supported on v3.24+. The IPAMBKP2 format is no longer produced for new backups when stored-mode IPAMBKP3 is selected, but existing archives remain readable.
- **IPAMBKP1 (v3.17–v3.18)**: single-shot AES-256-GCM with a 96-bit random IV. Decrypt loads the whole ciphertext into RAM (no streaming). Operators with multi-GB databases who hit the original OOM should take a fresh backup on v3.19+ to switch to streaming. Re-encrypting any remaining IPAMBKP1 archives as IPAMBKP3 is recommended over time.

#### Key rotation warnings

- Rotating `backup_vault_key` invalidates IPAMBKP3 stored-mode archives.
- Rotating `app_secret` invalidates IPAMBKP1 and IPAMBKP2 archives.
- IPAMBKP3 transitory archives are unaffected by either rotation (the key was the operator's passphrase, not the server's secret).

The full byte layout of every format is in [On-disk format](#on-disk-format). For offline decrypts (e.g. recovering an archive when the originating install is gone), `Simple-PHP-IPAM/tools/decrypt-backup.php` ships a CLI that takes the appropriate credential and produces plaintext without requiring a running install.

---

## Disaster recovery — back up your keys, not just your data

> **Pre-flight check:** Visit **Settings → Install keys** to confirm `app_secret`, `bootstrap_key`, and `backup_vault_key` are all set before relying on this disaster-recovery procedure.

A backup archive is only as recoverable as the key that decrypts it. **The application does not back up its own keys** — that's on you. If your only copy of the keys is the install you're trying to recover *from*, an encrypted archive is just noise. Treat this as part of your backup procedure, not an afterthought.

### What you must save (and where)

| Save this | Why | Where to keep it |
|---|---|---|
| **`config.php`** | Holds `app_secret` (decrypts legacy `app_secret`-encrypted `.enc` archives — IPAMBKP1/IPAMBKP2; also the TOTP/restore-staging key) **and** `bootstrap_key` (unwraps the DB-stored `backup_vault_key`). Lose or regenerate this file and every encrypted backup tied to it — and the stored-mode vault key — becomes unrecoverable. | Off-site, **separate from the backup archives** and from the database backup. Anyone holding both `config.php` *and* the archives has the plaintext. A password manager, secrets vault, or KMS entry is appropriate. |
| **The backup vault key** (Stored mode / `.ipambkp3`) | Since v3.26.0 it lives in the **`settings` table, wrapped** under `bootstrap_key` — the database holds ciphertext only. In a *full-loss* scenario (server **and** database gone) you can only reconstruct it from a saved copy. Use **Admin → Backups → Destinations → "Reveal vault key"** (rate-limited, audit-logged) to export the base64 value. | Off-site, **separate from both the archives and `config.php`**. (Putting all three together collapses the layering back to a single point of compromise.) |
| **The passphrase** (Transitory mode / passphrase `.ipambkp3`) | Never persisted anywhere — by design. The only copy is the one whoever encrypted the archive typed. Note: v3.28.0 has no in-app creator for this format; see [parked features](internal/parked-features.md#operator-passphrase-backup-creation-ipambkp3-transitory-write-path). | Your password manager. There is no recovery if it's lost. |
| *(nothing extra)* — **Unencrypted** archives (`.ipambkl1.gz`, `.ipambku1`, `.sql.gz`, legacy `.sqlite`) | No key required. Integrity is checked on restore (except bare `.sql.gz` / `.sqlite`, which have no integrity layer). | n/a — but anyone with the file has your data, so keep these on trusted storage. |

### "Can I recover this archive?" — quick reference

| Archive | Recoverable if you have… | Unrecoverable if… |
|---|---|---|
| `.enc` (IPAMBKP1 / IPAMBKP2) | `app_secret` (from `config.php`) | `app_secret` is lost or was rotated since the backup was taken |
| `.ipambkp3` **stored** | a DB backup (the wrapped envelope) **+** `config.php` (`bootstrap_key`) — **or** the separately-saved raw vault key | none of those survive, **or** the vault key was rotated/replaced (the "Replace vault key" UI warns and requires an explicit acknowledgement when this would strand existing archives) |
| `.ipambkp3` **transitory** | the passphrase that was used when the archive was encrypted (note: v3.28.0 does not include an in-app creator for this format — see [parked features](internal/parked-features.md#operator-passphrase-backup-creation-ipambkp3-transitory-write-path)) | the passphrase is lost |
| `.ipambkl1.gz` / `.ipambku1` / `.sql.gz` / `.sqlite` | nothing — they're unencrypted | (file corruption only; bare `.sql.gz` / `.sqlite` have no integrity check) |

For an *offline* recovery — when the originating install is gone entirely — `Simple-PHP-IPAM/tools/decrypt-backup.php` takes the appropriate credential (`--app-secret`, `--vault-key`, or `--passphrase`) and produces plaintext without a running app or database. See [On-disk format](#on-disk-format) and `tools/decrypt-backup.php --help`.

### Practical recommendations

- **Unencrypted on a trusted destination is a legitimate choice** — and it removes the key-loss failure mode entirely. If your destination already protects confidentiality (full-disk encryption, restricted access, a private bucket), `unencrypted` (the v3.28.0 default for new destinations) keeps recovery dead simple. The `IPAMBKU1` wrapper still gives you an integrity check on restore.
- **If you do encrypt, prefer Stored mode** *and* keep an exported copy of the vault key off-site, as above. Transitory/passphrase mode is for genuinely one-off exports, not your standing backup strategy.
- **Test a restore.** A backup you've never restored is a hypothesis. Periodically take a backup, decrypt/restore it into a throwaway instance, and confirm the row counts. See [Restore from a backup](restore.md).
- **`config.php` belongs in change control / your secrets store**, not only on the server. It's small, it changes rarely, and it's the linchpin of every encrypted-backup recovery path.

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

Database backups are supported on **all three engines** (SQLite, MySQL, PostgreSQL) in v3.21.x:

- **SQLite Database backup** — generated via `ipam_db_dump_stream()`.
- **MySQL Database backup** — generated by shelling out to `mysqldump`. Path is auto-detected; override via `backup.mysqldump_path` in config if needed.
- **PostgreSQL Database backup** — generated by shelling out to `pg_dump`. Override via `backup.pg_dump_path` if needed.

Logical backups (engine-neutral, cross-engine portable) **ship in v3.22.0**. The runner is not yet implemented in v3.21.x.

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

### v3 — `IPAMBKP3` (v3.24.0+, three-mode streaming)

```text
offset  size  field                       notes
0       8     magic = "IPAMBKP3"
8       1     mode                        1 = STORED, 2 = TRANSITORY
9       4     argon_time      (BE u32)    ignored when mode = STORED
13      4     argon_memory_kib (BE u32)
17      1     argon_parallelism           must be 1 (libsodium constraint)
18      2     reserved                    zero
20      16    argon_salt                  zero-filled when mode = STORED
36      16    hkdf_salt                   per-file random; HKDF-Extract input
52      16    ctr_iv                      AES-256-CTR initial counter
─── header total: 68 bytes ───
68      N     ciphertext                  AES-256-CTR streaming
68 + N  32    HMAC-SHA256                 over (header || ciphertext)
```

Key derivation:

- **STORED:** `kdf_input = backup_vault_key`. HKDF-SHA256 with info `"ipam-backup-v3:stored:enc-mac"` and the per-file `hkdf_salt` produces 64 bytes — first 32 = encryption key, last 32 = MAC key.
- **TRANSITORY:** `kdf_input = Argon2id(passphrase, argon_salt, argon_time, argon_memory_kib * 1024, parallelism=1, out_len=32)`. Same HKDF chain afterward, with info `"ipam-backup-v3:transitory:enc-mac"`.

Verification at decrypt is constant-time via a double-HMAC compare over a sub-derived verify key (`HKDF(mac_key, "ipam-backup-v3:verify", 32)`). The codec writes plaintext to a temporary `<dst>.decrypting.<rand>` file and only renames to `<dst>` after HMAC verification passes — a failed verify leaves no plaintext on disk.

### Unencrypted — `IPAMBKU1` (v3.24.0+, integrity wrapper)

```text
offset  size  field
0       8     magic = "IPAMBKU1"
8       32    sha256(plaintext)
40      N     plaintext
```

No key material; SHA-256 catches accidental corruption / disk bit-rot. Used when an operator opts a destination out of encryption.

### Format dispatch on restore

`backup_decrypt_to_path()` peeks the first 8 bytes (and, for `IPAMBKP3`, the 9th mode byte) and routes:

- `IPAMBKP3` mode = STORED → `backup_decrypt_stream_v3()` with the configured `backup_vault_key`.
- `IPAMBKP3` mode = TRANSITORY → `backup_decrypt_stream_v3()` with the operator's passphrase.
- `IPAMBKU1` → `backup_unencrypted_unwrap_stream()` (no credential).
- `IPAMBKP2` → `backup_decrypt_stream()` (legacy, `app_secret`).
- `IPAMBKP1` → load full file → `backup_decrypt()` → write (legacy back-compat).
- Anything else → `RuntimeException`.

When the dispatcher recognises an `IPAMBKP3` archive but the caller did not supply the matching credential, it raises `IpamBackupKeyRequiredException` carrying the mode flag. The manual upload-and-restore wizard uses this to render the correct prompt (passphrase for transitory, vault-key load for stored) without parsing exception text.

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
