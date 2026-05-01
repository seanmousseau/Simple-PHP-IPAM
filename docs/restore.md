# Restore from a backup

> **v3.21.0** moves the restore wizard into the unified backup admin surface at **Admin → Backup & Restore → Restore** (`backup_admin.php?tab=restore`). The legacy `restore_web.php` URL still resolves but new bookmarks should use the unified surface. The CLI restore (`restore.php`) is unchanged.
>
> See **[Backups](backups.md)** for backup creation, destinations, schedules, and encryption.

---

## Contents

- [Overview](#overview)
- [Logical vs Database restore](#logical-vs-database-restore)
- [Cross-version policy](#cross-version-policy)
- [Prerequisites](#prerequisites)
- [Web restore — wizard walkthrough](#web-restore--wizard-walkthrough)
  - [Step 1: Stage a backup file](#step-1-stage-a-backup-file)
  - [Step 2: Dry-run preview](#step-2-dry-run-preview)
  - [Step 3: Live restore](#step-3-live-restore)
- [CLI restore](#cli-restore)
  - [SQLite](#sqlite)
  - [MySQL](#mysql)
  - [PostgreSQL](#postgresql)
- [Audit log](#audit-log)
- [Engine support](#engine-support)
- [Recovery scenarios](#recovery-scenarios)
- [Limits](#limits)

---

## Overview

There are two restore paths:

| Path | Where | When to use |
|---|---|---|
| **Web wizard** | `backup_admin.php?tab=restore` | Routine restores from a configured destination. Stage → dry-run → confirm → apply, all in the browser. |
| **CLI** | `restore.php` (and engine-native tools for Database backups) | Disaster recovery, automation, large databases, or when the IPAM web UI is not available. |

Both paths converge on the same `backup_runs` table — a row with `status = 'success'` and a `triggered_by` of `manual` (web) or `cli` (CLI) is written on completion.

The wizard requires **admin role** and enforces CSRF on every step. There is intentionally **no one-click restore** — the apply step requires typing `RESTORE` to confirm.

---

## Logical vs Database restore

A restore behaves differently depending on what the backup file contains:

| Backup type | What's in the file | Restore behaviour |
|---|---|---|
| **Logical** | Engine-neutral SQL representing the IPAM data model. | Restorable to any of SQLite / MySQL / PostgreSQL. The wizard applies the SQL through `BackupEngine::applyLogicalDump()`, which uses the project's SQL splitter (a real lexer; not regex — see [#806](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/806)) to split statements safely across `BEGIN/END` blocks, dollar-quoted bodies, and embedded comments. |
| **Database** | Engine-faithful native dump (`.sqlite` / `mysqldump` output / `pg_dump` output). | Restorable only to the same engine that produced it. SQLite Database backups apply through the wizard; MySQL and PostgreSQL Database backups currently require the [CLI path](#cli-restore) until the cross-engine wizard support in [#797 / F18](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/797) ships. |

**Rule of thumb:** if you are restoring to the same engine the backup came from, either type works and Database is faster. If you are migrating engines, you need a Logical backup.

---

## Cross-version policy

> **Same-or-newer target only.** A backup from IPAM version `X.Y.Z` can be restored into an install running `X.Y.Z` or any later version. Restoring into an *older* version is not supported.

When the target install is newer than the backup, `apply_migrations()` runs after the SQL is applied to bring the schema up to date. The dry-run preview shows how many migrations will be applied so the operator can see the upgrade path before confirming.

| Backup version | Target version | Allowed? | Behaviour |
|---|---|---|---|
| 3.20.0 | 3.20.0 | yes | Schema is already current; no migrations run. |
| 3.18.0 | 3.21.0 | yes | Backup is forward-migrated through every closure between v3.18 and v3.21 in natural-sort order. |
| 3.21.0 | 3.20.0 | no | Wizard refuses; downgrade is not supported. CLI does not bypass this check. |

If you must restore an older backup into a *new* install, the recommended path is: install the same version the backup was taken on, restore there, then run the IPAM upgrade procedure as documented in [Upgrading](upgrading.md).

---

## Prerequisites

- Admin role; readonly users cannot reach the wizard or POST handlers.
- At least one [backup destination](backups.md#destinations) configured with accessible files.
- For encrypted backups (`.enc` suffix), `app_secret` must be set in `config.php` and must match the key used at encryption time.
- For MySQL or PostgreSQL Database backups: the engine-native CLI tool (`mysql` / `psql`) must be on the same host as the IPAM install.
- For very large databases: enough free disk in `data/tmp/` to stage the file before applying.

---

## Web restore — wizard walkthrough

Open **Admin → Backup & Restore → Restore** (`backup_admin.php?tab=restore`).

### Step 1: Stage a backup file

Select a destination from the dropdown, then a filename from the list of files on that destination. Click **Stage**.

What happens server-side:

1. IPAM connects to the destination and downloads the selected file into `data/tmp/` under a random hex filename (e.g. `data/tmp/restore_a3f7c2b1.sqlite`).
2. A signed staging token (HMAC-SHA256 over the temp path + session ID) is stored in the session. The token is required for subsequent steps to prevent cross-request tampering.
3. If the filename ends with `.enc`, the file is decrypted before the token is issued. A missing or wrong `app_secret` fails at this step with a clear error.
4. A SHA-256 hash of the staged file is computed and stored in the session for comparison at apply time.

The audit action `db.restore_stage` is written. Staged files are cleaned up automatically by `tmp_cleanup.php` (run via `cron.php`) after one hour, or immediately on a successful restore or explicit cancellation.

### Step 2: Dry-run preview

After staging, the wizard automatically runs a dry-run. This reads from the staged file and the live database — **no data is changed**.

The preview shows:

**Table diff**

| Table | Current rows | Backup rows | Delta |
|-------|-------------|-------------|-------|
| `subnets` | 42 | 38 | −4 |
| `addresses` | 1,204 | 1,187 | −17 |
| `users` | 5 | 5 | 0 |
| … | … | … | … |

Negative deltas show rows that would be lost; positive deltas show rows that would be gained.

**Schema diff**

- Schema version in the backup (from `schema_migrations` max version).
- Current schema version.
- Number of pending migrations that would be applied after the SQL.

If the backup is from a *newer* version than the current install, the wizard shows a hard error and blocks Apply. See [Cross-version policy](#cross-version-policy).

**Warnings (informational, do not block):**

- Tables in the backup but not in the current schema (may indicate a plugin or custom migration).
- Row count deltas above 50% in any table.
- Backup file age over 24 hours.

The audit action `db.restore_dryrun` is written.

### Step 3: Live restore

Click **Proceed to restore**. A confirmation gate appears.

**Type `RESTORE` exactly** (case-sensitive) into the text field to enable the Apply button. This matches the GitHub repository-deletion pattern and prevents accidental clicks.

When confirmed:

1. **Pre-flight** — the staged file's SHA-256 is re-verified against the value saved at stage time. A mismatch (file modified between stage and apply) aborts the restore.
2. **Begin exclusive transaction.**
3. **Disable foreign keys** — `PRAGMA foreign_keys = OFF` is set outside the transaction boundary before `BEGIN`, as required by SQLite's FK pragma rules.
4. **Apply the dump** — the Logical SQL splitter (real lexer) tokenises the staged file and applies each statement; for a Database SQLite backup the file is restored as the new database directly.
5. **Re-enable foreign keys** — `PRAGMA foreign_keys = ON` is restored unconditionally in all exit paths.
6. **Run `apply_migrations()`** — pending migrations bring the schema to current.
7. **Commit** — on success, the staged temp file is deleted and the session token cleared.

If any step fails, the transaction rolls back and the database is left unchanged. The staged file remains in `data/tmp/` so the operator can re-attempt without re-downloading.

A `backup_runs` row is written with `triggered_by = 'manual'` and `status = 'success'` or `'failed'`. The audit action `db.restore` is written on success.

After a successful restore, the page shows a success banner and redirects to the dashboard after five seconds.

### Manual upload-and-restore

A future release ([#797 / F13](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/797), v3.24) will let an operator upload a backup file directly from the browser instead of staging from a destination — useful when the original destination is no longer reachable. Until then, copy the file to a Local destination's directory (or onto the IPAM server) and stage from there.

---

## CLI restore

The CLI restore is the only path for MySQL and PostgreSQL Database backups in v3.21.0, and is also the recommended path for very large SQLite databases or fully-automated disaster recovery.

```bash
php /path/to/Simple-PHP-IPAM/restore.php --from=<path> [--dry-run] [--force]
```

| Flag | Meaning |
|---|---|
| `--from=<path>` | Path to the backup file (Logical SQL or SQLite Database). Required. |
| `--dry-run` | Validate and report the restore plan without writing anything. |
| `--force` | Allow overwriting a non-empty target database. Without this, restore aborts if the target is not empty. |

Web requests to `restore.php` always return HTTP 403 Forbidden — the script is CLI-only.

### SQLite

Native dump (Database backup) — replace the live database file. Stop the web server first to release any open file handles:

```bash
sudo systemctl stop apache2          # or nginx / php-fpm
cp data/ipam.sqlite data/ipam.sqlite.before-restore
gunzip < /path/to/ipam-20260501.sqlite.gz > data/ipam.sqlite
chown www-data:www-data data/ipam.sqlite
sudo systemctl start apache2
```

Logical backup — restore through `restore.php`:

```bash
php Simple-PHP-IPAM/restore.php --from=/path/to/ipam-20260501.logical.sql --force
```

### MySQL

Database backup (`mysqldump` output):

```bash
mysql -h <host> -u <user> -p <database> < /path/to/ipam-20260501.mysql.sql
```

If the dump is encrypted (`.enc`), decrypt it first using the IPAM helper:

```bash
php Simple-PHP-IPAM/tools/backup-decrypt.php /path/to/ipam-20260501.mysql.sql.enc > /tmp/ipam.sql
mysql -h <host> -u <user> -p <database> < /tmp/ipam.sql
shred -u /tmp/ipam.sql
```

Logical backup — restore through `restore.php`:

```bash
php Simple-PHP-IPAM/restore.php --from=/path/to/ipam-20260501.logical.sql --force
```

### PostgreSQL

Database backup (`pg_dump` output, plain SQL):

```bash
psql -h <host> -U <user> -d <database> -f /path/to/ipam-20260501.pgsql.sql
```

If the dump is in custom format (`.dump`), use `pg_restore`:

```bash
pg_restore -h <host> -U <user> -d <database> --clean --if-exists /path/to/ipam-20260501.pgsql.dump
```

Logical backup — restore through `restore.php`:

```bash
php Simple-PHP-IPAM/restore.php --from=/path/to/ipam-20260501.logical.sql --force
```

After any CLI restore, run `apply_migrations()` once by visiting any IPAM page in the browser as an admin — the bootstrap path applies pending migrations automatically.

---

## Audit log

Every restore step writes an audit entry:

| Action | When |
|--------|------|
| `db.restore_stage` | A backup file is successfully staged from a destination into `data/tmp/`. Detail includes destination ID and the original filename. |
| `db.restore_dryrun` | A dry-run preview is generated from the staged file. Detail includes backup schema version and pending migration count. |
| `db.restore` | A live restore apply completes successfully. Detail includes backup filename, schema version before and after, and row counts for key tables. |

Failed restores are recorded in `backup_runs` with `status = 'failed'` and a detail string. No `db.restore` audit row is written for failed attempts (only on success); the failure row in History is the system of record for the failure.

---

## Engine support

| Engine | Logical backup restore | Database backup restore (web) | Database backup restore (CLI) |
|---|---|---|---|
| SQLite | yes (web + CLI) | yes (web + CLI) | yes |
| MySQL | yes (web + CLI) | not in v3.21.0 — use CLI | yes |
| PostgreSQL | yes (web + CLI) | not in v3.21.0 — use CLI | yes |

Cross-engine Database-backup restore in the web wizard is tracked in [#797 / F18](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/797) for v3.24.0.

---

## Recovery scenarios

### Encrypted backup, `app_secret` not set

```
Cannot decrypt backup: app_secret is not set in config.php.
```

Fix: add `app_secret` to `config.php` using the value that was in place when the backup was created. If you do not have the original key, the backup cannot be recovered.

### Checksum mismatch at apply time

```
Staged file integrity check failed. The file may have been modified.
Please stage the backup again.
```

The file in `data/tmp/` was modified between Stage and Apply. Stage the backup again from the destination.

### Dry-run reports a *newer* schema in the backup

The wizard refuses to proceed. Restoring into an older install is not supported. Either upgrade the install first, or set up a temporary install of the same version the backup was taken on, restore there, and then upgrade.

### Partial restore — transaction rollback on apply failure

If the SQL apply or the migration step fails mid-way, the transaction rolls back. The database is left in its pre-restore state. The error is shown on the wizard page and recorded in `backup_runs`. Check PHP's error log for the full exception. The internal [runbook](internal/backup-restore-runbook.md) lists common causes and fixes.

### Post-restore verification

After any restore — wizard or CLI — verify:

1. Sign in as the original admin (credentials in the backup).
2. Visit **Admin → Health** and confirm no red rows.
3. Spot-check 2–3 known subnets and addresses.
4. Confirm **Admin → Audit log** shows the `db.restore` row at the top.

---

## Limits

- **Live-restore round-trip is not in the default Playwright suite.** Enable it by setting `IPAM_PW_RESTORE_LIVE=1` before running the suite. Without this flag the suite tests staging and dry-run but skips live apply.
- **File size** — very large backups may hit PHP `memory_limit` or `max_execution_time` for the Logical apply path. The Database SQLite path is bounded by disk I/O only. For Logical SQL larger than the engine's reasonable transaction size, prefer the CLI.
- **Concurrent restore is not supported.** If a restore is in progress, do not start a second one in another tab — both will race on the staging directory and the live transaction.
