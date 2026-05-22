<?php
declare(strict_types=1);

/**
 * v3.29.0 #902 — sanity stub for the formerly-mega MigrationTest.php.
 *
 * The 22 test methods that used to live here were split into focused
 * suites under tests/Migration/. This stub stays so any external CI
 * script or contributor muscle-memory pointing at MigrationTest still
 * resolves to a real class; it just asserts the split files exist.
 *
 * If you're hunting for a specific migration test, check:
 *   - tests/Migration/SqliteOnlyClosuresTest.php — VRF rebuild + PRAGMA FK
 *   - tests/Migration/EngineParityTest.php       — idempotency / replay
 *   - tests/Migration/SettingsCascadeTest.php    — v2.6/v3.13 settings
 *   - tests/Migration/MfaTest.php                — passkeys, preferred MFA
 *   - tests/Migration/BackupTest.php             — v3.17+ backup chain
 *   - tests/Migration/IpStorageTest.php          — v2.9.0 BLOB affinity
 *   - tests/Migration/MiscTest.php               — devices, custom-fields, TOTP
 *
 * Extends Tests\Migration\Base so it can reuse makePreVrfDb() for the
 * C12 (#933) audit-trail check below.
 */
final class MigrationTest extends \Tests\Migration\Base
{
    public function testSplitFilesExist(): void
    {
        $dir = __DIR__ . '/../Migration';
        $files = [
            'Base.php',
            'SqliteOnlyClosuresTest.php',
            'EngineParityTest.php',
            'SettingsCascadeTest.php',
            'MfaTest.php',
            'BackupTest.php',
            'IpStorageTest.php',
            'MiscTest.php',
        ];
        foreach ($files as $f) {
            $this->assertFileExists($dir . '/' . $f, "split file $f must exist");
        }
    }

    /**
     * C12 (#933): material schema upgrades (TOTP, passkeys, devices,
     * webhooks, backup, account-lockout) must leave an audit_log row so
     * an operator can see when a security/data-surface migration applied.
     */
    public function test_material_migration_writes_audit_entry(): void
    {
        // makePreVrfDb() seeds a schema.sql-shaped audit_log and marks the
        // pre-VRF migrations applied; apply_migrations() then runs every
        // later migration, including the material ones listed in
        // ipam_material_migrations().
        $db = $this->makePreVrfDb();
        \apply_migrations($db);

        $rows = $db->query(
            "SELECT action FROM audit_log WHERE action LIKE 'migration.%'"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertNotEmpty(
            $rows,
            'a material migration must write a migration.* audit_log row'
        );

        // apply_migrations() must have emitted a migration.<key> row for
        // each material migration in ipam_material_migrations(). Other
        // migrations may write their own migration.* rows, so we assert
        // our keys are a subset of what landed rather than an exact match.
        $logged = array_map(
            static fn ($a): string => substr((string)$a, strlen('migration.')),
            $rows
        );
        foreach (\ipam_material_migrations() as $key) {
            $this->assertContains(
                $key,
                $logged,
                "material migration '$key' must write a migration.$key audit row"
            );
        }
    }
}
