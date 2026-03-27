# Upgrading

## Contents

- [Overview](#overview)
- [Upgrade steps](#upgrade-steps)
- [upgrade.sh options](#upgradesh-options)
- [Environment variables](#environment-variables)
- [What the backup looks like](#what-the-backup-looks-like)
- [CLI utilities](#cli-utilities)

---

## Overview

Upgrades are handled by `upgrade.sh`, included in every release bundle. The script:

- Creates a **timestamped backup** of your current install (including the SQLite DB and WAL files)
- Syncs new application files into the target directory using `rsync`
- **Preserves `config.php`** and the entire `data/` directory
- Fixes file permissions after the sync
- Runs **database migrations automatically** (if the `php` CLI is available)
- Removes upgrade artefacts from the webroot

If the migration step fails, `upgrade.sh` automatically **restores from the backup** and exits with code `10`.

---

## Upgrade steps

```bash
# 1. Download and extract the new release bundle
tar -xzf ipam-0.11.tar.gz -C /tmp/

# 2. Run upgrade.sh, pointing it at your current install directory
bash /tmp/Simple-PHP-IPAM/upgrade.sh /var/www/ipam
```

The script confirms the version transition (e.g. `0.10 → 0.11`) before making any changes.

To skip the confirmation prompt (e.g. in a CI/CD pipeline):

```bash
bash /tmp/Simple-PHP-IPAM/upgrade.sh --yes /var/www/ipam
```

---

## upgrade.sh options

| Flag | Description |
|---|---|
| `--yes` | Non-interactive — skip confirmation prompts |
| `--force` | Allow reinstalling the same version |
| `--force-downgrade` | Allow downgrading (not recommended — may break the DB schema) |

---

## Environment variables

| Variable | Default | Description |
|---|---|---|
| `CLEANUP_ARTIFACTS` | `1` | Remove build/upgrade artefacts from the target webroot after success |
| `REMOVE_UPGRADE_SH_FROM_TARGET` | `1` | Also remove `upgrade.sh` from the target webroot |

Example — keep `upgrade.sh` in place after the upgrade:

```bash
REMOVE_UPGRADE_SH_FROM_TARGET=0 bash /tmp/Simple-PHP-IPAM/upgrade.sh --yes /var/www/ipam
```

---

## What the backup looks like

```
/var/www/ipam.backup.20260326-143000/   ← timestamped copy of entire install
    data/
        ipam.sqlite
        ipam.sqlite-wal   ← if present (WAL mode journal)
        ipam.sqlite-shm   ← if present
    config.php
    ... (all other app files)
```

The backup is left in place after a successful upgrade. You can remove it manually once you have verified the new version is working correctly.

---

## CLI utilities

These scripts are run from the application directory using the PHP CLI.

### Run database migrations manually

```bash
cd /var/www/ipam
php migrate.php
```

Applies any pending schema migrations. Migrations are also applied automatically on each web request (`ipam_db_init()`) and during upgrades via `upgrade.sh`, so this is only needed for scripted or manual deployments.

### Clean up stale temp files

```bash
cd /var/www/ipam
php tmp_cleanup.php
```

Deletes uploaded CSV files and import plan files in `data/tmp/` that are older than `tmp_cleanup_ttl_seconds` (default: 24 hours). This also runs automatically as part of lazy housekeeping on normal site traffic — a cron job is not required.
