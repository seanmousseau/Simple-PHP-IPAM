---
name: add-migration
description: Scaffold a new schema migration in Simple-PHP-IPAM/migrations.php with the project's documented FK-safe patterns and idempotency guards. Use when adding a column, table, index, trigger, or data backfill that must run on upgrade. Reads docs/internal/adding-a-migration.md for the full procedure.
---

# Adding a database migration

This skill walks you through adding a migration to `Simple-PHP-IPAM/migrations.php`. The full procedural doc is at `docs/internal/adding-a-migration.md` and the architectural rules + footguns are in `CLAUDE.md` under "Schema migrations". Read both before starting if unfamiliar.

## Inputs you need from the user

1. **Target version** (`X.Y.Z`) — the next release this migration ships in. Check `Simple-PHP-IPAM/version.php` for current.
2. **Slug** — a short kebab-case description for the closure key (e.g. `vrf-fk`, `tenant-id-backfill`).
3. **What's changing** — new column? new table? index? backfill? table rebuild?

## Procedure (condensed — full version in `docs/internal/adding-a-migration.md`)

1. **Read first**: `docs/internal/adding-a-migration.md` and the "Migration testing pitfalls" section of `CLAUDE.md`. The four documented footguns (FK cascade on DROP, PRAGMA inside transaction, RENAME rewriting child FKs, UNIQUE NULL-distinct) have all caused production bugs — do not skip.

2. **Pick the version key** as `X.Y.Z-slug`. Array order in `migrations.php` does not matter; `apply_migrations()` does `ksort($migs, SORT_NATURAL)`.

3. **Write the closure** using the template below. Every `ALTER TABLE` must be guarded by a `PRAGMA table_info()` check so re-runs are no-ops. Use `Dialect` helpers (`$dialect->now()`, `$dialect->binary_type()`, `$dialect->upsert()`) for portable SQL across SQLite / MySQL / PostgreSQL.

4. **Update all three schema files** (`schema.sql`, `schema.mysql.sql`, `schema.pgsql.sql`) so fresh installs match. CI's `SchemaParityTest` will fail on drift. Spawn the `multi-engine-schema-parity` subagent after editing to verify. **Also add your new version key to the pre-seeded `INSERT INTO schema_migrations (...)` list in `schema.mysql.sql` AND `schema.pgsql.sql`** (SQLite has no pre-seed), then **bump the hardcoded count in `tests/MysqlSmokeTest.php` + `tests/PgsqlSmokeTest.php` (`testSchemaMigrationsPreseeded`) by 1** — those assertions only run when a non-SQLite DSN is set, so `vendor/bin/phpunit` against SQLite won't catch a stale value; a missed bump red-fails the `php-qa.yml` mysql/mariadb/pgsql jobs on the PR.

5. **Spawn the `migration-reviewer` subagent** before commit. It checks the four footguns automatically.

6. **Add a `tests/MigrationTest.php` case** if the migration touches user-authored data (`addresses`, `subnets`, `audit_log`). Build the pre-migration state in-memory, run `apply_migrations()`, assert row counts and constraints survive.

7. **Bump `version.php`** and add an entry to `CHANGELOG.md` under `Added` or `Changed`.

8. **Run the local gate**:
   ```bash
   vendor/bin/phpunit                              # SQLite
   bash testing/run-engine-phpunit.sh              # MySQL + PgSQL phpunit (incl. the *SmokeTest pre-seed-count gate)
   bash testing/playwright/bootstrap-app.sh sqlite && bash testing/playwright/teardown-app.sh
   bash testing/playwright/bootstrap-app.sh mysql  && bash testing/playwright/teardown-app.sh
   bash testing/playwright/bootstrap-app.sh pgsql  && bash testing/playwright/teardown-app.sh
   ```
   Never push without all three drivers green locally.

## Closure template — add column

```php
'X.Y.Z-slug' => function (PDO $db, Dialect $dialect) {
    $cols = $db->query("PRAGMA table_info(addresses)")->fetchAll(PDO::FETCH_ASSOC);
    $has = array_filter($cols, fn($c) => $c['name'] === 'new_col');
    if (!$has) {
        $db->exec("ALTER TABLE addresses ADD COLUMN new_col TEXT");
    }
},
```

## Closure template — new table (post-v4.0.0 needs `tenant_id`)

```php
'X.Y.Z-slug' => function (PDO $db, Dialect $dialect) {
    $db->exec("CREATE TABLE IF NOT EXISTS new_thing (
        id INTEGER PRIMARY KEY,
        tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
        name TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (" . $dialect->now() . ")
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_new_thing_tenant ON new_thing(tenant_id)");
},
```

> **v3.x note**: tables created in releases ≤ v4.0.0 do NOT take `tenant_id`. The v4.0.0 migration adds it to all pre-existing tables. The "new tables get `tenant_id` from day one" rule applies only to migrations whose version key sorts > v4.0.0.

## Table rebuilds — read this first

Table rebuilds (rename + recreate + copy + drop) are how you alter PK/FK or change column types in SQLite. They have caused **two production data-loss bugs** in this project. Before writing one:

- `apply_migrations()` already disables FK enforcement around each migration. Do NOT re-enable it inside your closure.
- Never `DROP TABLE x_old` while FKs are on — children with `ON DELETE CASCADE` will be wiped before the physical drop.
- `PRAGMA legacy_alter_table = ON` does NOT reliably prevent the SQLite ≥3.26 child-FK rewrite. The disable-FK-around-migration approach is the only safe path.
- Add a test in `tests/MigrationTest.php` that asserts pre-existing data survives. If you can't write that test, you do not yet understand the rebuild well enough to ship it.

## Cross-references

- `docs/internal/adding-a-migration.md` — full procedure
- `CLAUDE.md` § "Schema migrations" — policy and footguns
- `tests/MigrationTest.php` — test patterns
- `dialects/*.php` — Dialect helper implementations
- Subagent `migration-reviewer` — automated footgun check
- Subagent `multi-engine-schema-parity` — schema-file drift check
