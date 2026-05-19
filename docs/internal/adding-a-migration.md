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

4. **Update all three schema files** *and* their `schema_migrations` pre-seed. Fresh installs go through `schema.sql` / `schema.mysql.sql` / `schema.pgsql.sql`, not the migration chain. They must stay structurally equivalent — CI's `SchemaParityTest` fails on any divergence (table set, column set, type class, nullability, default kind, FK target, FK on-delete action; type names normalise — `BLOB` / `VARBINARY(16)` / `BYTEA` all map to `"binary"` — so it's semantic, not exact-string). **`schema.mysql.sql` and `schema.pgsql.sql` also carry a pre-seeded `INSERT INTO schema_migrations (...)` list** so a fresh MySQL/Postgres install doesn't replay every historical migration; add your new version key to that list in both files. (`schema.sql` / SQLite has no pre-seed — fresh installs replay all migrations — so this only affects the two non-SQLite files.) **Then bump the hardcoded count assertion in `tests/MysqlSmokeTest.php` and `tests/PgsqlSmokeTest.php` (`testSchemaMigrationsPreseeded`) by 1** — those tests assert the pre-seed row count, only run when a non-SQLite DSN is set (so `vendor/bin/phpunit` against SQLite won't catch a stale value), and a missed bump is exactly what turns the `php-qa.yml` mysql/mariadb/pgsql jobs red on the PR. Run `bash testing/run-engine-phpunit.sh` locally to verify before pushing.

5. **Regenerate the data dictionary.**
   ```bash
   php tools/generate-data-dictionary.php
   ```
   Commit the refreshed `docs/internal/data-dictionary.md` alongside your schema changes. `DataDictionaryDriftTest` (PHPUnit) fails the build if the file is stale — it re-runs the generator in-process and asserts byte-for-byte parity with the committed copy.

6. **Extend `tests/MigrationTest.php` if you need to prove behaviour.** Standard cases: pre-existing data is preserved, idempotency (running the migration twice is a no-op), constraints behave as expected. Build the pre-migration DB state in-memory in the test setup; do not point at a fixture file.

7. **Run the local gate, then the 3-driver containerized harness:**
   ```bash
   vendor/bin/phpunit                              # MigrationTest + SchemaParityTest + DataDictionaryDriftTest (SQLite)
   bash testing/run-engine-phpunit.sh              # MySQL + PgSQL phpunit — incl. the *SmokeTest pre-seed-count gate
   bash testing/playwright/bootstrap-app.sh sqlite && bash testing/playwright/teardown-app.sh
   bash testing/playwright/bootstrap-app.sh mysql  && bash testing/playwright/teardown-app.sh
   bash testing/playwright/bootstrap-app.sh pgsql  && bash testing/playwright/teardown-app.sh
   ```
   `bootstrap-app.sh` runs `migrate.php` against a fresh DB — if your migration is broken on any engine, it surfaces here before CI. `run-engine-phpunit.sh` catches the engine-only phpunit failures (the `schema_migrations` pre-seed count chief among them) that the SQLite-only `vendor/bin/phpunit` can't.

8. **Bump `version.php`** and add the migration mention to the release `## [X.Y.Z]` entry in `CHANGELOG.md` under either `Added` (new tables/columns) or `Changed` (modified existing).

## Policies

### One CREATE TABLE per migration closure

Every **new** migration closure must create at most one logical table (one `CREATE TABLE` statement per engine arm, i.e. one call to `migration_create_table()`).

**Rationale.** MySQL issues an implicit commit on DDL statements. The `START TRANSACTION` that `apply_migrations()` opens is therefore a no-op for DDL on MySQL — it provides no rollback protection. A closure that creates two or more tables and fails partway through leaves partial schema on MySQL with no way to roll back.

**Anti-pattern.** `'3.17.0-backup'` in `migrations.php` (≈ 200 lines, creates `backup_destinations`, `backup_schedules`, and `backup_log` in a single closure) is the canonical example of what not to do. Each of those tables should have been a separate migration key.

**Forward-looking only.** Already-shipped multi-table closures are **not** re-split. Splitting a shipped closure would require renaming or removing its key from `schema_migrations`, corrupting upgrade history on every existing install. Add a `// NOTE: this closure pre-dates the one-table-per-closure policy` comment if you touch such a closure (see the `3.3.0-devices` comments for the pattern).

**Enforcement checkpoint.** The `migration-reviewer` subagent (`.claude/agents/`) reviews every edit to `migrations.php`. It will flag a new closure that calls `migration_create_table()` more than once.

**Preferred implementation.** Use `migration_create_table(PDO $db, array $ddlByEngine)` — the three-arm driver-dispatch helper defined at the top of `migrations.php` — to execute the single `CREATE TABLE` per engine. This collapses the copy-pasted `if ($driver === 'sqlite') ... elseif ('mysql') ...` pattern into one call.

## Version-key naming

### Two key forms

Migration keys appear in two forms in `migrations.php`:

| Form | Example | When to use |
|---|---|---|
| `<semver>` (two-part) | `3.6.0` | Legacy — not used for new migrations |
| `<semver>-<slug>` (three-part) | `3.6.0-lockout` | **All new migrations** |

For every new migration, use the three-part `<semver>-<slug>` form. The slug is a short, lowercase, hyphen-separated description of what the migration does (e.g. `3.33.0-audit-log`, `3.33.0-mfa-tokens`). If a release ships more than one migration, each gets a distinct slug under the same semver prefix.

### Execution order

`apply_migrations()` in `lib/db.php` calls `ksort($migrations, SORT_NATURAL)` before iterating. Execution order is determined solely by the key's semver prefix — physical array position in `migrations.php` is cosmetic. A late-arriving migration should be keyed with the semver of the release it **actually ships in**, not the release cycle it was conceived in.

### Keys are immutable once shipped

**A migration key, once present in a shipped release, must never be renamed.**

`apply_migrations()` matches keys by exact string against the `schema_migrations` table with no aliasing. Renaming a shipped key causes every existing install to treat the migration as unapplied, re-run it, and insert a duplicate row in `schema_migrations`. The impact is data corruption on upgrade (at minimum duplicate rows; at worst a failed DDL that leaves a partially-applied schema).

If a shipped key carries the "wrong" semver (e.g. it was keyed `3.6.0-lockout` but actually shipped in v3.7.0), document the discrepancy with a code comment — do **not** rename the key. See `3.6.0-lockout` and `3.0.0-subnet-contacts` in `migrations.php` for the established comment pattern:

```php
// NOTE (C5, #929): this key's '3.6.0' semver predates its array position …
// The key is INTENTIONALLY NOT renamed: migration keys are persisted per-install
// in schema_migrations and apply_migrations() keys solely on exact string match …
'3.6.0-lockout' => function (PDO $db): void {
```

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
