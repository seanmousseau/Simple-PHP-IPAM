# Upgrade Snapshots

This directory holds SQL dumps that represent the database at historical schema
checkpoints. They are used by the upgrade-path test suite to verify that
`apply_migrations()` correctly upgrades old databases to the current schema.

## Contents

| File | Schema era | Highest migration stamp |
|------|-----------|------------------------|
| `pre-v2.0.0.sql` | v1.19.0 | `1.19.0` |

## How snapshots are used

The Playwright upgrade test (`testing/playwright/tests/upgrade.spec.ts`) and the
shell script (`testing/scripts/test_upgrade.sh`) both use these SQL dumps.

**Playwright flow:**
1. Export the current DB so it can be restored later.
2. Import the snapshot via `db_tools.php`.
3. Navigate to any app page — `init.php` calls `apply_migrations()` automatically.
4. Verify the app loads without PHP errors and that all v2.x pages are accessible.
5. Restore the original DB.

**Shell script flow:**
1. Back up the live DB on the dev server.
2. POST the snapshot SQL to `db_tools.php` using curl.
3. curl the app root to trigger migrations.
4. Verify key pages return HTTP 200.
5. Restore the backup.

## Adding a new snapshot

Run the app at the desired version, log in, and export the DB via
`db_tools.php → Export SQL`. Save the file here with a descriptive name
(e.g. `pre-v3.0.0.sql`).
