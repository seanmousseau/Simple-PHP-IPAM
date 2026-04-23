---
title: Backup & Restore
nav_order: 9
---

# Backup & Restore

Simple PHP IPAM includes built-in backup infrastructure for all supported database engines (SQLite, MySQL, PostgreSQL). Backups are created via a CLI script and tracked in a `backup_history` table. The **Admin → Database** page (`db_tools.php`) lets admins review backup history, verify integrity, and download backup files alongside the SQL export/import tools (SQLite only).

**There is intentionally no one-click web restore.** Restoration is a CLI-only operation to prevent accidental data loss.

---

## Configuration

All backup settings live in **Admin → Settings → Backup**:

| Setting | Default | Description |
|---------|---------|-------------|
| `backup.enabled` | `false` | Enable/disable scheduled backups |
| `backup.frequency` | `daily` | Backup frequency: `daily` or `weekly` |
| `backup.retention` | `7` | How many backups to keep (oldest are pruned automatically) |
| `backup.dir` | `''` (→ `data/backups/`) | Directory where backup files are stored; empty uses the default |

Enable backups by setting `backup.enabled = true` and configuring a cron schedule (see below).

---

## Creating a Backup

### On-demand (CLI)

```bash
php /path/to/Simple-PHP-IPAM/backup.php --force
```

The `--force` flag runs immediately regardless of schedule. Without it, the script exits if no backup is due. Output:

```
Starting backup...
Backup completed: ipam-2026-04-22-020000.sqlite
```

### Scheduled (cron)

Add a cron entry to run `cron.php` every 15 minutes. The backup task honours `backup.enabled` and the configured schedule — it will only run when due:

```cron
*/15 * * * * php /path/to/Simple-PHP-IPAM/cron.php >> /var/log/ipam-cron.log 2>&1
```

The default schedule runs a backup once per day. The frequency is controlled by the `backup.frequency` setting (`daily` or `weekly`), configurable via **Admin → Settings → Backup**.

---

## Backup Storage

Backup files are written to `data/backups/` (relative to the app root). The directory is created automatically on first backup. A `.htaccess` deny rule is written to the directory to block direct web access.

| Engine | File format | Example filename |
|--------|-------------|-----------------|
| SQLite | `.sqlite` file copy | `ipam-2026-04-22-020000.sqlite` |
| MySQL | `mysqldump` SQL dump | `ipam-2026-04-22-020000.sql` |
| PostgreSQL | `pg_dump` SQL dump | `ipam-2026-04-22-020000.sql` |

Each backup is verified with SHA-256 and recorded in `backup_history`.

---

## Verifying Integrity

In **Admin → Database**, each row in the history table has a **Verify** button (POST, CSRF-protected). This re-computes the SHA-256 hash of the file on disk and compares it to the recorded hash. A mismatch means the file may be corrupted or tampered with.

From the CLI:

```bash
sha256sum /path/to/Simple-PHP-IPAM/data/backups/ipam-2026-04-22-020000.sqlite
# Compare to the hash shown in Admin → Database
```

---

## Restoring a Database

### Step 1: Identify the backup file

Find the file path from **Admin → Database** (click "Restore…" for the CLI command pre-filled) or list files directly:

```bash
ls -lh /path/to/Simple-PHP-IPAM/data/backups/
```

### Step 2: Dry-run first (safe, no changes)

Always run with `--dry-run` first to confirm the plan:

```bash
php /path/to/Simple-PHP-IPAM/restore.php \
  --from=/path/to/backups/ipam-2026-04-22-020000.sqlite \
  --dry-run
```

Output shows what *would* happen without writing anything.

### Step 3: Apply the restore

```bash
php /path/to/Simple-PHP-IPAM/restore.php \
  --from=/path/to/backups/ipam-2026-04-22-020000.sqlite \
  --force
```

The `--force` flag is required to overwrite a non-empty target database. Without it, the script exits safely if the target already contains data.

The restore script:
1. Verifies the backup file exists
2. Checks `backup_history` for a matching SHA-256 record (informational)
3. For SQLite: creates a safety `.bak` copy of the current database, then copies the backup in place
4. Runs `apply_migrations()` to bring the restored database to the current schema version
5. Audits the restore operation

---

## Cross-Driver Restore

Restoring from a SQLite backup to MySQL (or vice versa) is not supported by `restore.php` directly. For cross-driver migration, use `migrate_db.php` which handles schema translation.

```bash
php /path/to/Simple-PHP-IPAM/migrate_db.php --from=sqlite --to=mysql
```

---

## Admin UI (db_tools.php)

> **v3.9.0:** The standalone `backups.php` page was merged into `db_tools.php` (Database Tools). Any existing bookmarks to `backups.php` redirect automatically via HTTP 301.

**Admin → Database** shows the **Backup History** tab with:

- Backup enabled/disabled status
- Retention count and backup directory
- Available disk space
- History table: filename, driver, size, SHA-256 (truncated), timestamp, duration, status badge (success / failed / pending)
- Per-row actions: Download, Verify, Restore instructions, Delete

Deleting a record also deletes the backup file from disk.

---

## Audit Log

All backup operations are logged to the audit log under the `backup` prefix:

| Action | When |
|--------|------|
| `backup.run` | Successful CLI or cron backup |
| `backup.failed` | Backup failed or lock not acquired |
| `backup.downloaded` | Admin downloaded a backup file |
| `backup.verified` | SHA-256 verified OK |
| `backup.verify_failed` | SHA-256 mismatch |
| `backup.deleted` | Backup record and file deleted |
| `restore.run` | Successful restore |
