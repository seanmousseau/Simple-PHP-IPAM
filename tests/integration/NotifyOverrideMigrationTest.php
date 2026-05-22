<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Simple-PHP-IPAM/lib.php';
require_once __DIR__ . '/../../Simple-PHP-IPAM/migrations.php';

/**
 * 3.23.0-notify-overrides — column shape + idempotency (#825 / F21).
 *
 * The cross-engine parity is already covered by SchemaParityTest +
 * MigrationTest's schema-vs-migrations assertion. This test pins the
 * load-bearing per-row contract:
 *
 *   - notify_override defaults to 0 ("use global") so existing rows
 *     keep the v3.20.0 behaviour after upgrade.
 *   - notify_on_failure / notify_on_success / notify_recipients are
 *     nullable so an admin can override a subset.
 *   - Re-running the migration is a no-op (idempotent).
 */
class NotifyOverrideMigrationTest extends TestCase
{
    public function testNewColumnsAreAddedWithCorrectDefaults(): void
    {
        $db = $this->freshSqliteWithoutNotifyMigration();

        // Insert a pre-migration row to pin the upgrade behaviour.
        $db->exec(
            "INSERT INTO backup_destinations (name, type, config, is_active)
             VALUES ('local', 'local', '{}', 1)"
        );
        $destId = (int) $db->lastInsertId();
        $stmt = $db->prepare(
            "INSERT INTO backup_schedules (destination_id, frequency, time_of_day)
             VALUES (:d, 'daily', '02:00')"
        );
        $stmt->execute([':d' => $destId]);
        $schedId = (int) $db->lastInsertId();

        // Apply the notify-overrides migration.
        ipam_migrations()['3.23.0-notify-overrides']($db);

        // Existing row inherits "use global" semantics.
        $row = $db->query(
            "SELECT notify_override, notify_on_failure, notify_on_success, notify_recipients
               FROM backup_schedules WHERE id = $schedId"
        )?->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame(0, (int) $row['notify_override'], 'pre-migration rows must default to use-global');
        $this->assertNull($row['notify_on_failure']);
        $this->assertNull($row['notify_on_success']);
        $this->assertNull($row['notify_recipients']);
    }

    public function testMigrationIsIdempotent(): void
    {
        $db = $this->freshSqliteWithoutNotifyMigration();
        ipam_migrations()['3.23.0-notify-overrides']($db);
        // Second invocation must not throw — no "duplicate column" error.
        ipam_migrations()['3.23.0-notify-overrides']($db);
        $this->assertTrue(true);
    }

    /**
     * Build a fresh sqlite DB with the full schema *minus* the new
     * notify-override columns, simulating an installation that ran every
     * prior migration but hasn't yet seen 3.23.0. Easiest path: apply the
     * shipped schema.sql then DROP the columns we want to test the
     * migration adds.
     */
    private function freshSqliteWithoutNotifyMigration(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = (string) file_get_contents(__DIR__ . '/../../Simple-PHP-IPAM/schema.sql');
        $db->exec($schema);
        $db->exec('PRAGMA foreign_keys = ON');

        // Roll back to the pre-3.23.0 column shape using SQLite's column
        // drop (3.35+). The columns must not exist for the migration to
        // exercise its add-column branch.
        foreach (['notify_recipients', 'notify_on_success', 'notify_on_failure', 'notify_override'] as $c) {
            $db->exec("ALTER TABLE backup_schedules DROP COLUMN $c");
        }
        return $db;
    }
}
