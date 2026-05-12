<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for #1175: ipam_db_init() on a fresh sqlite::memory: handle
 * must apply schema.sql before running migrations.
 *
 * The SQLite "fast path" in ipam_db_init() stats an on-disk db_path + sentinel
 * file to decide whether to skip the bootstrap probe. A :memory: handle does
 * not correspond to that on-disk file, so on a machine where the on-disk
 * data/.db_initialized sentinel exists and is fresh, the fast path would fire,
 * call apply_migrations() against the empty in-memory DB, and the first
 * migration that touches `subnets` would throw "no such table: subnets" —
 * exactly the failure RestoreDryRunTest::testIpambkl1ArchiveDoesNotErrorThroughSqlSplitter
 * hit (which calls ipam_db_init($src) on a :memory: handle). The fix guards the
 * fast path with a "does this handle actually have the schema?" probe.
 */
class DbInitMemoryTest extends TestCase
{
    public function testFreshMemoryDbGetsSchemaAndMigrations(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->query('PRAGMA foreign_keys = ON');

        ipam_db_init($db);

        $subnetCols = $db->query("PRAGMA table_info(subnets)")->fetchAll();
        $this->assertNotEmpty($subnetCols, 'ipam_db_init() on a fresh :memory: db must create the subnets table');

        $migCount = (int) $db->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();
        $this->assertGreaterThan(0, $migCount, 'migrations must be stamped/applied on a fresh :memory: db');

        // Bootstrap admin should also have been created (schema-creation branch).
        $userCount = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $this->assertGreaterThan(0, $userCount, 'bootstrap admin should exist after init on a fresh db');
    }

    public function testSecondInitIsIdempotent(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->query('PRAGMA foreign_keys = ON');
        ipam_db_init($db);
        $u1 = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        ipam_db_init($db); // must not throw, must not duplicate the admin
        $u2 = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $this->assertSame($u1, $u2, 'second ipam_db_init() must be idempotent');
    }
}
