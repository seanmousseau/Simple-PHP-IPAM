<?php
declare(strict_types=1);

namespace Tests\Migration;

use PDO;

/**
 * FreshInstallSeedParityTest — guards design-document invariant #22.
 *
 * THE BUG CLASS THIS CATCHES
 * --------------------------
 * A fresh install **never runs migrations**. `ipam_db_init()` builds the
 * database directly from `schema.*.sql` and then stamps every known migration
 * as already-applied; `apply_migrations()` runs **only** when an existing
 * install is upgraded. A migration is therefore a safe place for an
 * *upgrade-only* data change and nowhere else.
 *
 * The v3.30.0 `setting_definitions` regression was exactly this: the table was
 * created by `schema.*.sql` (so it existed on fresh installs) but populated
 * only by a data-seeding migration. Fresh installs got the table created and
 * empty — the seed data never arrived — while upgraded installs replayed the
 * migration and got the rows. The general failure mode: *a migration populates
 * a reference / seed table, but the fresh-install schema files do not carry
 * that seed data, so fresh installs silently lack it.*
 *
 * WHAT THIS TEST DOES
 * -------------------
 *   1. Builds a **fresh-install** database the real way — `ipam_db_init()` on
 *      an empty handle, which runs `schema.sql` and stamps migrations.
 *   2. Builds a **migrated** database — the v2.0.0 pre-vrf baseline (see
 *      Base::makePreVrfDb()) put through the full `apply_migrations()` chain,
 *      i.e. the upgrade path. Row counts are captured *before* and *after*
 *      `apply_migrations()` so the test isolates rows the migration chain
 *      itself inserted from sample rows the baseline fixture pre-loaded.
 *   3. For every table that a migration *populated* (post-migration count
 *      higher than pre-migration count), FAILS if the fresh DB has zero rows —
 *      that table carries seed / reference data on upgrades that fresh installs
 *      never receive.
 *
 * THE ALLOWLIST IS THE FORCING FUNCTION
 * -------------------------------------
 * Some tables are *legitimately* empty on a fresh install (runtime / user data,
 * or backfill-only targets) yet may be non-empty in the migrated baseline
 * because the baseline fixture seeds sample rows. Those — and only those — are
 * allowlisted below, each with a documented reason. A future migration that
 * seeds a new reference table without also adding the data to `schema.*.sql`
 * will fail this test until someone consciously either fixes the schema files
 * or adds the table to the allowlist with a justification.
 *
 * SQLite-only by design — `ipam_db_init()` reads `schema.sql` for SQLite.
 * Multi-engine fresh-install coverage lives in
 * MigrationFreshInstallMultiDriverTest.
 */
final class FreshInstallSeedParityTest extends Base
{
    /**
     * Tables that are allowed to have rows in the migrated baseline DB while
     * being empty on a fresh install. Each entry MUST document *why* it is a
     * legitimate exception — i.e. the table holds runtime / user / backfill
     * data, not seed or reference data that fresh installs need.
     *
     * Anything NOT on this list is treated as seed / reference data: if it is
     * populated on the upgrade path it MUST also be populated on fresh install.
     *
     * @var array<string, string>
     */
    private const ALLOWLIST = [
        // schema_migrations is bookkeeping, not application data. A fresh
        // install stamps every version; an upgrade INSERTs each version as the
        // chain replays — so the migration chain "populates" it by design.
        // It is data-engine state, never seed/reference data, and a fresh
        // install always has it populated. Excluded on principle.
        'schema_migrations' => 'Migration bookkeeping — populated on both paths, never seed data.',

        // users is user data. apply_migrations() may INSERT a seeded admin on
        // the upgrade path; a fresh install seeds exactly one bootstrap admin
        // via ipam_db_init_bootstrap_admin(). Either way the row(s) are
        // operator accounts, not reference data — fresh installs create their
        // own, so a fresh-vs-migrated row-presence gap here is not a defect.
        'users' => 'Bootstrap admin / operator accounts — runtime user data, fresh install seeds its own.',

        // audit_log is append-only event data, not seed/reference data. Some
        // migrations INSERT an audit row to record an upgrade-only data fixup
        // (e.g. the alert.recipient_user_ids auto-migration emits a
        // 'settings.auto_migrate_alert_email' row). Those rows describe an
        // event that, by definition, only happened on an upgrade — a fresh
        // install never had the legacy value to migrate. Zero audit rows on a
        // fresh install is correct; the application writes them at runtime.
        'audit_log' => 'Append-only event log — upgrade-fixup rows describe events that never occur on fresh installs.',

        // settings IS populated by a seed migration on the upgrade path, yet a
        // fresh install legitimately leaves it EMPTY. This is the subtle case
        // and the reason the allowlist demands a justification: setting values
        // have a PHP-defaults runtime fallback. ipam_setting() resolves any
        // absent key from ipam_setting_definitions() ($def['default']), so an
        // empty settings table is fully functional on a fresh install — the
        // PHP registry is the source of truth invariant #22 explicitly allows
        // ("...or produced by PHP defaults at runtime").
        //
        // CONTRAST WITH THE BUG THIS TEST GUARDS: the v3.30.0 setting_definitions
        // table had NO such runtime fallback — code read it straight from the
        // DB — so an empty table on a fresh install was a real defect. Such a
        // table is NOT allowlistable in good conscience and this test would
        // flag it. Only allowlist a migration-seeded table here if its data is
        // genuinely reproducible at runtime without the DB rows.
        'settings' => 'Seeded on upgrade, but ipam_setting() falls back to the PHP registry default for any absent key — empty table is functional on fresh install (invariant #22 "PHP defaults at runtime").',
    ];

    public function testFreshInstallCarriesEverySeedTableThatUpgradesGet(): void
    {
        $fresh = $this->buildFreshInstallDb();

        // Build the migrated DB capturing row counts BEFORE and AFTER the
        // migration chain. The "before" snapshot is the sample data the
        // pre-vrf baseline fixture pre-loads (e.g. demo subnets/addresses);
        // the "after" snapshot reflects what the migration chain added on top.
        // Comparing the two isolates rows that migrations themselves INSERTed —
        // which is exactly the seed/reference data a fresh install must also
        // receive from schema.*.sql.
        $migrated      = $this->makePreVrfDb();
        $beforeCounts  = $this->tableRowCounts($migrated);
        \apply_migrations($migrated);
        $afterCounts   = $this->tableRowCounts($migrated);

        $freshCounts = $this->tableRowCounts($fresh);

        $gaps = [];
        foreach ($afterCounts as $table => $afterRows) {
            $beforeRows = $beforeCounts[$table] ?? 0;
            // Only consider tables the migration chain itself populated:
            // post-migration count strictly greater than pre-migration count.
            // Tables the baseline fixture pre-seeded (subnets, addresses,
            // audit_log sample rows) show no migration-driven growth and are
            // ignored — they are fixture noise, not migration seed data.
            if ($afterRows <= $beforeRows) {
                continue;
            }
            if (array_key_exists($table, self::ALLOWLIST)) {
                continue; // legitimately runtime/user data — documented above
            }
            $freshRows = $freshCounts[$table] ?? 0;
            if ($freshRows === 0) {
                $gaps[] = sprintf(
                    "  - %s: migration chain inserted %d row(s), fresh-install has 0",
                    $table,
                    $afterRows - $beforeRows
                );
            }
        }

        $this->assertSame(
            [],
            $gaps,
            "Fresh-install seed-data gap (design-document invariant #22).\n"
            . "The following tables are populated on the migrated-upgrade path "
            . "but EMPTY on a fresh install:\n"
            . implode("\n", $gaps) . "\n\n"
            . "A fresh install never runs migrations — it builds from schema.*.sql "
            . "and stamps migrations as applied. A migration that seeds a "
            . "reference/seed table therefore never runs on fresh installs.\n"
            . "Fix: add the seed data to schema.sql / schema.mysql.sql / "
            . "schema.pgsql.sql (or produce it from PHP defaults at runtime). "
            . "If the table genuinely holds runtime/user data, add it to "
            . "FreshInstallSeedParityTest::ALLOWLIST with a documented reason."
        );
    }

    /**
     * Sanity guard: the comparison is only meaningful if the migrated baseline
     * actually populates at least one seed table. If this ever drops to zero
     * the fixture has drifted and the parity test above would pass vacuously.
     */
    public function testMigratedBaselineActuallyHasSeedData(): void
    {
        $migrated = $this->buildMigratedDb();
        $settings = (int) $migrated
            ->query('SELECT COUNT(*) FROM settings')
            ->fetchColumn();

        $this->assertGreaterThan(
            0,
            $settings,
            "Migrated baseline has no seeded settings rows — the pre-vrf "
            . "fixture or the settings-seed migration has drifted, and "
            . "testFreshInstallCarriesEverySeedTableThatUpgradesGet would "
            . "pass vacuously."
        );
    }

    /**
     * Build a fresh-install database the production way: an empty SQLite
     * handle put through ipam_db_init(), which runs schema.sql and stamps
     * every migration. This is the exact code path a brand-new deployment
     * takes — migrations are NEVER executed.
     */
    private function buildFreshInstallDb(): PDO
    {
        $db = $this->makeDb();

        $hadConfig  = array_key_exists('config', $GLOBALS);
        $prevConfig = $GLOBALS['config'] ?? null;
        // ipam_db_init_bootstrap_admin() reads the bootstrap admin config.
        $GLOBALS['config'] = [
            'proxy_trust'     => false,
            'bootstrap_admin' => ['username' => 'admin', 'password' => 'changeme-test-123!'],
        ];
        try {
            \ipam_db_init($db);
        } finally {
            if ($hadConfig) {
                $GLOBALS['config'] = $prevConfig;
            } else {
                unset($GLOBALS['config']);
            }
        }
        return $db;
    }

    /**
     * Build a migrated database: the v2.0.0 pre-vrf baseline (Base::makePreVrfDb)
     * put through the full apply_migrations() chain — i.e. the upgrade path an
     * existing install takes.
     */
    private function buildMigratedDb(): PDO
    {
        $db = $this->makePreVrfDb();
        \apply_migrations($db);
        return $db;
    }

    /**
     * Row count for every user table in the SQLite database, keyed by table
     * name. Tables that fail to count (should not happen) are skipped.
     *
     * @return array<string, int>
     */
    private function tableRowCounts(PDO $db): array
    {
        /** @var list<string> $tables */
        $tables = $db
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(PDO::FETCH_COLUMN);

        $out = [];
        foreach ($tables as $table) {
            $out[$table] = (int) $db
                ->query('SELECT COUNT(*) FROM "' . str_replace('"', '', $table) . '"')
                ->fetchColumn();
        }
        ksort($out);
        return $out;
    }
}
