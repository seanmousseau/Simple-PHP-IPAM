# Restore from a Backup

> **v3.17.0** adds a browser-based restore wizard (`restore_web.php`) that stages a backup file from a remote destination, shows a dry-run preview, and applies the restore after an explicit confirmation step. The legacy CLI restore (`restore.php`) is unchanged.

---

## Contents

- [Overview](#overview)
- [Prerequisites](#prerequisites)
- [Step 1: Stage a backup file](#step-1-stage-a-backup-file)
- [Step 2: Dry-run preview](#step-2-dry-run-preview)
- [Step 3: Live restore](#step-3-live-restore)
- [Audit log](#audit-log)
- [Remote Backup Browser](#remote-backup-browser)
- [Failure modes](#failure-modes)
- [Limits](#limits)

---

## Overview

The web restore wizard provides a three-step flow:

1. **Stage** — pick a destination and filename; IPAM downloads the file from the destination into a temporary staging area (`data/tmp/`).
2. **Dry-run** — inspect a table-level diff between the backup and the live database. No data is changed.
3. **Live restore** — type `RESTORE` to confirm, then apply the backup. The operation runs in a single database transaction.

The wizard requires **admin role** and enforces CSRF on every step.

---

## Prerequisites

- At least one [backup destination](backups.md#destinations) must be configured with accessible backup files.
- The backup file must be reachable from the server — IPAM downloads it server-side, not in the browser.
- Admin role is required; `readonly` users cannot access the wizard.
- For encrypted backups (`.enc` suffix), `app_secret` must be set in `config.php` and must match the key used at encryption time.
- SQLite database engine. MySQL/PostgreSQL live restore is not supported in v3.17.0.

---

## Step 1: Stage a backup file

Navigate to **Admin → Backup → Restore** (`restore_web.php`) or click **Restore…** on a `backup_log` history row.

Select a destination from the dropdown, then select the backup filename from the list of files on that destination. Click **Stage**.

What happens server-side:

1. IPAM connects to the destination and downloads the selected file into `data/tmp/` under a random hex filename (e.g. `data/tmp/restore_a3f7c2b1.sqlite`).
2. A signed staging token (HMAC-SHA256 over the temp path + session ID) is stored in the session. The token is required for subsequent steps to prevent cross-request tampering.
3. If the filename ends with `.enc`, the file is decrypted with `backup_decrypt()` before the token is issued. A missing or wrong `app_secret` fails at this step with a clear error.
4. A SHA-256 hash of the staged file is computed and stored in the session for comparison at apply time.

The audit action `db.restore_stage` is written at this point.

Staged files are cleaned up automatically by `tmp_cleanup.php` (runs via `cron.php`) after 1 hour, or immediately on a successful restore or explicit cancellation.

---

## Step 2: Dry-run preview

After staging, the wizard automatically runs a dry-run preview against the staged file. This reads from the staged SQLite file and the live database — **no data is changed**.

The preview shows:

### Table diff

| Table | Current rows | Backup rows | Delta |
|-------|-------------|-------------|-------|
| `subnets` | 42 | 38 | −4 |
| `addresses` | 1,204 | 1,187 | −17 |
| `users` | 5 | 5 | 0 |
| … | … | … | … |

Delta values highlight rows that would be lost (negative) or gained (positive) by the restore.

### Schema diff

If the backup was created at an older version of IPAM, `apply_migrations()` will be run after the restore to bring the schema up to date. The dry-run reports:

- Schema version in the backup (from `schema_migrations` max version).
- Current schema version.
- Number of pending migrations that would be applied.

If the backup is from a *newer* version than the current install, the dry-run shows a warning and blocks the live restore. Downgrading the schema is not supported.

### Warnings

Additional warnings are shown for:

- Tables present in the backup but missing from the current schema (may indicate data from a plugin or custom migration).
- Unusually large row count deltas (more than 50% change in a table).
- Backup file age exceeding 24 hours.

Warnings do not block the restore — they are informational. The admin decides whether to proceed.

The audit action `db.restore_dryrun` is written at this point.

---

## Step 3: Live restore

After reviewing the dry-run output, click **Proceed to restore**. A confirmation dialog appears.

**The confirmation gate requires typing `RESTORE` exactly** (case-sensitive) into a text field before the Apply button becomes active. This matches the GitHub repository-deletion pattern and prevents accidental one-click restores.

When confirmed, the following sequence runs in a single database transaction:

1. **Pre-flight** — the staged file's SHA-256 is re-verified against the value saved at stage time. A mismatch (file modified between stage and apply) aborts the restore.
2. **Begin exclusive transaction** — no other writes can occur during the restore.
3. **Disable foreign keys** — `PRAGMA foreign_keys = OFF` is set outside the transaction boundary before `BEGIN`, as required by SQLite's FK pragma rules.
4. **Apply dump SQL** — the staged `.sqlite` file is processed with `ipam_db_dump_stream()` in reverse: the existing tables are dropped and recreated from the backup SQL.
5. **Re-enable foreign keys** — `PRAGMA foreign_keys = ON` is restored unconditionally in all exit paths.
6. **Run `apply_migrations()`** — if the backup is from an older schema version, pending migrations are applied automatically. The result is a fully up-to-date schema containing the backup's data.
7. **Commit** — on success the transaction commits, the staged temp file is deleted, and the session token is cleared.

If any step fails, the transaction is rolled back and the database is left unchanged. The staged file remains in `data/tmp/` for re-attempt until the cleanup TTL expires.

A `backup_log` row is inserted with `triggered_by = 'web_restore'` and `status = 'success'` or `'failed'`. The audit action `db.restore` is written on success.

After a successful restore, the page shows a success banner and the admin is redirected to the dashboard after 5 seconds.

---

## Audit log

Every restore step writes an audit entry:

| Action | When |
|--------|------|
| `db.restore_stage` | A backup file is successfully staged from a destination into `data/tmp/`. Detail includes destination ID and the original filename. |
| `db.restore_dryrun` | A dry-run preview is generated from the staged file. Detail includes backup schema version and pending migration count. |
| `db.restore` | A live restore apply completes successfully. Detail includes backup filename, schema version before and after, and row counts for key tables. |

Failed restores are recorded in `backup_log` with `status = 'failed'` and a detail string. No `db.restore` audit row is written for failed attempts (only for success), but the `backup_log` failure row is visible in the Backup History page.

---

## Remote Backup Browser

**Admin → Backup → Remote Files** (`remote_backups.php`) lets you browse, verify, and delete files stored on a destination — independently of the `backup_log` history. This is useful when:

- You need to verify that a file is intact on the destination before staging it.
- A backup_log row was deleted but the file still exists on the destination.
- You want to clean up old files that were not pruned by GFS retention.

Actions available per file:

| Action | Description |
|--------|-------------|
| Verify | Computes the SHA-256 of the file on the destination and compares it to any matching `backup_log` row. Result shown inline. Audit action: `remote_backup.verify`. |
| Download | Downloads the file to the admin's browser via `download_remote_backup.php`. The file passes through the server — it is not a direct link to the storage backend. Audit action: `remote_backup.download`. |
| Delete | Deletes the file from the destination. Does not affect `backup_log` rows. Audit action: `remote_backup.delete`. Requires CSRF. |

---

## Failure modes

### Encrypted backup, `app_secret` not set

If you try to stage a `.enc` file and `app_secret` is missing from `config.php` (or is empty), the stage step fails immediately with:

```
Cannot decrypt backup: app_secret is not set in config.php.
```

Fix: add `app_secret` to `config.php` using the value that was in place when the backup was created. If you do not have the original key, the backup cannot be recovered.

### Checksum mismatch at apply time

If the staged file's SHA-256 at apply time does not match the hash recorded at stage time, the restore is aborted:

```
Staged file integrity check failed. The file may have been modified.
Please stage the backup again.
```

This indicates the file in `data/tmp/` was modified between the stage and apply steps. Stage the backup again.

### Dialog cancellation

Closing the confirmation dialog or navigating away from the page does not cancel the staged file — it remains in `data/tmp/` and the session token stays valid for 1 hour. You can return to `restore_web.php` and continue from the dry-run step without re-downloading.

### Transaction rollback on apply failure

If the SQL dump application or the migration step fails mid-way, the transaction is rolled back. The database is left in its pre-restore state. The error is displayed on the wizard page and written to `backup_log`. Check PHP's error log for detailed exception messages.

---

## Limits

- **SQLite only** in v3.17.0. The live restore path uses `ipam_db_dump_stream()` which is SQLite-specific. MySQL and PostgreSQL restore is planned for a follow-up release.
- **Live-restore round-trip** is not exercised in the default Playwright test suite. Enable it by setting `IPAM_PW_RESTORE_LIVE=1` before running the test suite. Without this flag the suite tests staging and dry-run but skips the live apply step.
- **File size** — very large backups (hundreds of MB) may hit PHP memory or execution time limits. Adjust `memory_limit` and `max_execution_time` in `php.ini` if needed.
