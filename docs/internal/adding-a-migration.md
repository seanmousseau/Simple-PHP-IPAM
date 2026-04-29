# Adding a database migration

> Procedural walkthrough for adding a schema migration to `migrations.php`. The architectural rules and migration-history footguns live in `CLAUDE.md` under "Schema migrations" — read that first if you have not before. This doc is the *how*; CLAUDE.md is the *why*.

## When you need a migration

Any change that alters the runtime database structure on an existing install: new table, new column, new index, new trigger, dropped/renamed entity, FK addition, default change. Pure data backfills (no DDL) also belong here so they run once on upgrade.

## Procedure

1. **Pick the version key.** Use the next release's `vX.Y.Z` as the array key. `apply_migrations()` does `ksort($migrations, SORT_NATURAL)` before iterating, so array order in `migrations.php` does not matter — version sort order does.

2. **Write the closure.** Inside `ipam_migrations()`, return:
   ```php
   'X.Y.Z-short-slug' => function (PDO $db, Dialect $dialect) {
       // Idempotency guard — required for ALTER TABLE
       $cols = $db->query("PRAGMA table_info(addresses)")->fetchAll(PDO::FETCH_ASSOC);
       $hasNew = array_filter($cols, fn($c) => $c['name'] === 'new_col');
       if (!$hasNew) {
           $db->exec("ALTER TABLE addresses ADD COLUMN new_col TEXT");
       }
   },
   ```
   Use `Dialect` helpers (`$dialect->now()`, `$dialect->upsert()`, `$dialect->binary_type()`, etc.) for portable SQL across SQLite/MySQL/PostgreSQL.

3. **For table rebuilds (rare but high-risk) — read the SQLite footguns first.** `apply_migrations()` already disables FK enforcement (`PRAGMA foreign_keys = OFF`) outside the transaction; do not re-enable it inside. Never `DROP TABLE t_old` with FKs on, never rely on `legacy_alter_table` — both have caused production data loss. See CLAUDE.md "Schema migrations" → "Migration testing pitfalls" for the full list (4 distinct ways this has gone wrong before).

4. **Update all three schema files.** Fresh installs go through `schema.sql` / `schema.mysql.sql` / `schema.pgsql.sql`, not the migration chain. They must stay structurally equivalent. CI's `SchemaParityTest` will fail the build on any divergence (table set, column set, type class, nullability, default kind, FK target, FK on-delete action). Type names normalise — `BLOB` / `VARBINARY(16)` / `BYTEA` all map to `"binary"` — so you don't need exact-string parity, just semantic.

5. **Regenerate the data dictionary.**
   ```bash
   php tools/generate-data-dictionary.php
   ```
   Commit the refreshed `docs/internal/data-dictionary.md` alongside your schema changes. `DataDictionaryDriftTest` (PHPUnit) fails the build if the file is stale — it re-runs the generator in-process and asserts byte-for-byte parity with the committed copy.

6. **Extend `tests/MigrationTest.php` if you need to prove behaviour.** Standard cases: pre-existing data is preserved, idempotency (running the migration twice is a no-op), constraints behave as expected. Build the pre-migration DB state in-memory in the test setup; do not point at a fixture file.

7. **Run the local gate, then the 3-driver containerized harness:**
   ```bash
   vendor/bin/phpunit                              # MigrationTest + SchemaParityTest
   bash testing/playwright/bootstrap-app.sh sqlite && bash testing/playwright/teardown-app.sh
   bash testing/playwright/bootstrap-app.sh mysql  && bash testing/playwright/teardown-app.sh
   bash testing/playwright/bootstrap-app.sh pgsql  && bash testing/playwright/teardown-app.sh
   ```
   `bootstrap-app.sh` runs `migrate.php` against a fresh DB — if your migration is broken on any engine, it surfaces here before CI.

8. **Bump `version.php`** and add the migration mention to the release `## [X.Y.Z]` entry in `CHANGELOG.md` under either `Added` (new tables/columns) or `Changed` (modified existing).

## Pitfalls (already burned)

- **`DROP TABLE` with FKs ON cascades to children.** SQLite executes a row-by-row DELETE before dropping. v2.2.1 wiped every IP address on upgrade because of this. `apply_migrations()` now disables FKs around each migration; do not re-enable them inside your closure.
- **`PRAGMA foreign_keys` is silently ignored inside an active transaction.** Set it before `BEGIN`. The migration runner already does — your closure should not touch it.
- **`ALTER TABLE t RENAME TO t_old` rewrites child FK references** in SQLite ≥3.26. Renaming `subnets` to `subnets_old` automatically rewrites `addresses.subnet_id` to point at `subnets_old`. Then dropping `subnets_old` cascades. The disable-FK approach (point 1) is the only reliable workaround.
- **`UNIQUE(a, b)` treats `NULL` as distinct.** Two rows with `(cidr, vrf_id) = ('10.0.0.0/8', NULL)` are both allowed. Test UNIQUE constraints with non-NULL values.
- **Don't introduce a schema templating system.** Three plain SQL files + the parity test is the chosen design (evaluated and rejected templating during v2.9.0 planning).

## Cross-references

- CLAUDE.md "Schema migrations" — policy, multi-tenancy rules, multi-engine deployment model, runtime-deps policy.
- `docs/internal/data-dictionary.md` — generated cross-engine column/FK/UNIQUE reference. Refresh with `php tools/generate-data-dictionary.php` whenever you change a schema file.
- `tests/MigrationTest.php` — assertion patterns, in-memory DB setup.
- `tests/SchemaParityTest.php` — cross-engine parity assertions.
- `tests/DataDictionaryDriftTest.php` — drift detection for the generated dictionary.
- `dialects/*.php` — Dialect helper implementations per engine.
