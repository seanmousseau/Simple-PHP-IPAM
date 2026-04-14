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

## Version-specific upgrade notes

### v2.7.0

**No schema changes.** v2.7.0 is entirely a runtime rewire — every registered setting was already seeded into the `settings` table by v2.6.0's migration. `upgrade.sh` just syncs files, runs `php migrate.php` (no-op), and you are done.

**What changes at runtime:**

- Every subsystem now reads through `ipam_setting()` instead of `$config[...]`. Edits in **⚙ Admin → Settings** take effect on the next request without a `config.php` change or a restart.
- `config.php` still works as a fallback for back-compat. Nothing in your existing `config.php` stops working on upgrade.
- Three new registered OIDC keys (`oidc.disable_local_login`, `oidc.disable_emergency_bypass`, `oidc.hide_emergency_link`) are now visible in the admin UI. If you already have these in `config.php` they keep working via the fallback chain.

**Dealing with the deprecation banner.** After upgrading, if you have **customised** any non-bootstrap value in `config.php` (i.e. the value differs from the registry default), the admin Settings page will show a **config.php settings to migrate** banner listing each such key. You have two options:

1. **Click Import to database** on each row in the banner. This copies the current `config.php` value into the `settings` table in one atomic write (audited), and the row disappears. You can then delete the key from `config.php` at your convenience.
2. **Leave it.** The fallback keeps working through v2.7.x. You can batch the migration later — the banner stays visible until every customised key is imported or matches the registry default.

The dashboard shows a matching admin warning card linking to the banner so you do not forget.

**Server log warning.** Once per hour (rate-limited via `data/tmp/deprecation_warning.txt`), `init.php` writes a single consolidated line to the PHP error log listing every registered key still being served from `config.php`. This is informational — nothing is broken. It is there so your log aggregator surfaces the migration work before v3.0.0 removes the fallback.

**Sensitive values in the banner.** Sensitive keys (`oidc.client_secret`, `login_protection.secret_key`, `recaptcha_enterprise.api_key`) are masked as `***` in the banner — the secret itself is never rendered into the HTML source. **Import to database** still imports the real value from `config.php`; the mask only affects the display.

**What stays in `config.php` forever:** the bootstrap keys — `db_path`, `session_name`, `proxy_trust`, `force_https` / `base_url`, and `bootstrap_admin`. These are loaded before the database is open and will never be in the `settings` table.

---

### v2.1.0

**New database tables:** `vrfs`, `contacts`

**Modified tables:**
- `subnets`: the `UNIQUE(cidr)` constraint is replaced with `UNIQUE(cidr, vrf_id)` to support the same CIDR in different VRFs. Existing subnets get `vrf_id = NULL` (global VRF) — no data is lost.
- `addresses`: new nullable column `owner_contact_id` (FK → `contacts.id`).

These changes are applied automatically by `upgrade.sh` via `php migrate.php`.

**New admin pages:** `vrfs.php` (VRF management), `contacts.php` (Contacts management) — both accessible from the Admin dropdown.

**New config keys** added automatically by `config_auto_populate` on first boot after upgrade:
- `api_max_attempts` (default 20)
- `api_lockout_seconds` (default 300)
- `api_bulk_limit` (default 500)
- `recaptcha_enterprise` block (disabled by default)

**API additions:** `resource=vrfs` (full CRUD), `resource=contacts` (CRUD + `?q=` search), `?vrf_id=` filter on subnets, `?contact_id=` filter on addresses, `owner_contact_id`/`owner_contact_name` fields in address responses, `vrf_id`/`vrf_name` fields in subnet responses, `search.php?format=json` for the ⌘K overlay.

---

### v2.0.0

**New database tables:** `vlans`, `tags`, `subnet_tags`, `address_tags`, `alert_state`

**Modified tables:**
- `subnets`: new columns `vlan_fk` (FK → `vlans.id`), `tags` (via join table `subnet_tags`).
- `addresses`: new columns `mac`, `expires_at`, `tags` (via join table `address_tags`).
- `sites`: new column `parent_id` (nullable FK → `sites.id`, self-referential for region/site hierarchy).
- `users`: new columns `name`, `email`, `last_login_at`, `password_changed_at`, `theme`.

These changes are applied automatically by `upgrade.sh` via `php migrate.php`.

**New admin pages:** `vlans.php` (VLAN management), `tags.php` (Tag management) — both accessible from the Admin dropdown.

**New config keys** added automatically on first boot after upgrade:
- `utilization_warn`, `utilization_critical` (subnet utilization thresholds)
- `auto_reserve_network_broadcast` (default `true`)
- `alert_email`, `alert_util_warn_pct`, `alert_util_crit_pct` (email alerts, disabled by default)
- `password_policy` block
- `login_protection` block (bot protection, disabled by default)
- `demo_mode` block (disabled by default)
- `oidc` block (disabled by default)

**API additions:** `vlan_name` and `tags[]` on subnet responses, `tags[]` on address responses, `resource=vlans` (full CRUD), `?tag=` / `?vlan_id=` / `?parent_id=` / `?site_id=` / `?ip_version=` / `?expired=1` filters, bulk write (`POST ?resource=addresses&bulk=1` / `POST ?resource=subnets&bulk=1`).

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
