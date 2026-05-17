<?php
declare(strict_types=1);

namespace Tests\Migration;

use PDO;

/**
 * v3.29.0 #902 — split from MigrationTest.
 *
 * Cluster: v2.6.0-settings and v3.13.0-settings-cascade — seed integrity,
 * tenant_id column add, and idempotency of the rebuild.
 */
final class SettingsCascadeTest extends Base
{
    public function testSettingsMigrationSeedsAndIsIdempotent(): void
    {
        $db = $this->makePreVrfDb();
        \apply_migrations($db);

        $tables = array_column(
            $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(),
            'name'
        );
        $this->assertContains('settings', $tables, '2.6.0-settings must create the settings table');

        $cols = array_column(
            $db->query("PRAGMA table_info(settings)")->fetchAll(),
            'name'
        );
        foreach (['key', 'value', 'updated_at', 'updated_by'] as $expected) {
            $this->assertContains($expected, $cols, "settings column {$expected} missing");
        }
        // v3.30.0 Task 3.3 (ADR-001): the legacy `type` column is dropped by
        // the 3.30.0-drop-settings-type migration — apply_migrations() above
        // replays it, so the column must be absent.
        $this->assertNotContains('type', $cols, 'settings.type must be dropped by 3.30.0-drop-settings-type');

        $registryKeys = array_keys(\ipam_setting_definitions());
        $seededCount  = (int)$db->query("SELECT count(*) FROM settings")->fetchColumn();
        $this->assertSame(count($registryKeys), $seededCount, 'Every registry key must be seeded on first run');

        $db->prepare("UPDATE settings SET value = :v WHERE key = :k")
           ->execute([':v' => 'Custom Name', ':k' => 'branding.site_name']);

        \apply_migrations($db);
        $preserved = $db->query("SELECT value FROM settings WHERE key = 'branding.site_name'")->fetchColumn();
        $this->assertSame('Custom Name', $preserved, 'Second migration run must not overwrite existing rows');
    }

    public function testSettingsCascadeMigrationIdempotent(): void
    {
        $db = $this->makePreVrfDb();

        \apply_migrations($db);

        $cols = $db->query("PRAGMA table_info(settings)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $this->assertContains('tenant_id', $cols, 'settings.tenant_id must exist after 3.13.0-settings-cascade migration');

        $countBefore = (int)$db->query("SELECT COUNT(*) FROM settings")->fetchColumn();
        $this->assertGreaterThan(0, $countBefore, 'settings rows must survive the migration');

        $nonNullCount = (int)$db->query("SELECT COUNT(*) FROM settings WHERE tenant_id IS NOT NULL")->fetchColumn();
        $this->assertSame(0, $nonNullCount, 'all settings rows must have tenant_id IS NULL after migration');

        \apply_migrations($db);
        $cols2 = $db->query("PRAGMA table_info(settings)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $this->assertContains('tenant_id', $cols2, 'tenant_id must still exist after second apply_migrations() call');

        $countAfter = (int)$db->query("SELECT COUNT(*) FROM settings")->fetchColumn();
        $this->assertSame($countBefore, $countAfter, 'second apply_migrations() must not change settings row count');

        $nonNullAfter = (int)$db->query("SELECT COUNT(*) FROM settings WHERE tenant_id IS NOT NULL")->fetchColumn();
        $this->assertSame(0, $nonNullAfter, 'tenant_id must remain NULL for all rows after idempotent re-run');
    }
}
