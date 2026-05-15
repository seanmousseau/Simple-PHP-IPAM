<?php
declare(strict_types=1);

namespace Tests\Migration;

use PDO;

/**
 * v3.29.0 #902 — split from MigrationTest.
 *
 * Cluster: v3.17.0+ backup chain. backup_destinations/schedules shape,
 * v3.21.0 unified backup_runs row-copy, and schedule-unique dedup.
 */
final class BackupTest extends Base
{
    public function testBackupTablesAfterFullChain(): void
    {
        $db = $this->makePreVrfDb();
        \apply_migrations($db);

        $cols = array_column(
            $db->query("PRAGMA table_info(backup_destinations)")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        foreach (['id', 'name', 'type', 'config', 'encrypt', 'is_active', 'created_at', 'updated_at'] as $c) {
            $this->assertContains($c, $cols, "backup_destinations missing column: $c");
        }

        $cols = array_column(
            $db->query("PRAGMA table_info(backup_schedules)")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        foreach ([
            'id', 'destination_id', 'frequency', 'time_of_day',
            'day_of_week', 'day_of_month',
            'retention_hourly', 'retention_daily', 'retention_weekly', 'retention_monthly',
            'is_active', 'last_run_at', 'next_run_at', 'created_at',
            ] as $c) {
            $this->assertContains($c, $cols, "backup_schedules missing column: $c");
        }

        $cols = array_column(
            $db->query("PRAGMA table_info(backup_runs)")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        foreach ([
            'id', 'destination_id', 'schedule_id', 'backup_type', 'encryption_mode',
            'triggered_by', 'status', 'filename', 'size_bytes', 'checksum',
            'source_version', 'is_protected', 'error_message',
            'started_at', 'completed_at',
            ] as $c) {
            $this->assertContains($c, $cols, "backup_runs missing column: $c");
        }

        $tables = array_column(
            $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        $this->assertNotContains('backup_log',     $tables, 'backup_log must be dropped by 3.21.0-backup-runs');
        $this->assertNotContains('backup_history', $tables, 'backup_history must be dropped by 3.21.0-backup-runs');
    }

    public function testV321BackupRunsRowCopy(): void
    {
        $db = $this->makePreVrfDb();
        \apply_migrations($db);

        $db->exec("DROP TABLE backup_runs");
        $db->exec("DELETE FROM schema_migrations WHERE version = '3.21.0-backup-runs'");

        $db->exec("CREATE TABLE backup_log (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            destination_id  INTEGER,
            schedule_id     INTEGER,
            triggered_by    TEXT NOT NULL DEFAULT 'manual',
            type            TEXT NOT NULL DEFAULT 'backup',
            status          TEXT NOT NULL DEFAULT 'pending',
            filename        TEXT,
            size_bytes      INTEGER,
            checksum        TEXT,
            error_message   TEXT,
            started_at      TEXT NOT NULL DEFAULT (datetime('now')),
            completed_at    TEXT
        )");
        $db->exec("CREATE TABLE backup_history (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            filename      TEXT NOT NULL,
            size_bytes    INTEGER,
            sha256        TEXT,
            db_driver     TEXT NOT NULL,
            started_at    TEXT NOT NULL,
            completed_at  TEXT,
            duration_ms   INTEGER,
            target        TEXT NOT NULL DEFAULT 'local',
            target_path   TEXT,
            status        TEXT NOT NULL DEFAULT 'pending',
            error         TEXT,
            created_at    TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        $db->exec("INSERT INTO backup_log (triggered_by, status, filename, size_bytes, checksum, started_at, completed_at) VALUES ('schedule', 'success', 'log-a.sql.gz', 1024, 'abc', '2026-04-01 00:00:00', '2026-04-01 00:00:05')");
        $db->exec("INSERT INTO backup_log (triggered_by, status, filename, started_at) VALUES ('manual', 'pending', 'log-b.sql.gz', '2026-04-02 00:00:00')");
        $db->exec("INSERT INTO backup_log (triggered_by, status, filename, started_at) VALUES ('cron', 'failed', 'log-c.sql.gz', '2026-04-03 00:00:00')");

        $db->exec("INSERT INTO backup_history (filename, size_bytes, sha256, db_driver, status, started_at, completed_at) VALUES ('hist-a.sql.gz', 2048, 'def', 'sqlite', 'success', '2026-04-04 00:00:00', '2026-04-04 00:00:03')");
        $db->exec("INSERT INTO backup_history (filename, db_driver, status, started_at, error) VALUES ('hist-b.sql.gz', 'sqlite', 'failed', '2026-04-05 00:00:00', 'bad permissions')");

        \apply_migrations($db);

        $count = (int) $db->query("SELECT COUNT(*) FROM backup_runs")->fetchColumn();
        $this->assertSame(5, $count, 'backup_runs must hold all migrated rows');

        $logRow = $db->query("SELECT encryption_mode, backup_type, triggered_by, status FROM backup_runs WHERE filename = 'log-a.sql.gz'")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('stored',   $logRow['encryption_mode']);
        $this->assertSame('database', $logRow['backup_type']);
        $this->assertSame('schedule', $logRow['triggered_by']);
        $this->assertSame('success',  $logRow['status']);

        $staleStatus = $db->query("SELECT status FROM backup_runs WHERE filename = 'log-b.sql.gz'")->fetchColumn();
        $this->assertSame('failed', $staleStatus);

        $coerced = $db->query("SELECT triggered_by FROM backup_runs WHERE filename = 'log-c.sql.gz'")->fetchColumn();
        $this->assertSame('manual', $coerced);

        $histRow = $db->query("SELECT encryption_mode, triggered_by, checksum FROM backup_runs WHERE filename = 'hist-a.sql.gz'")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('unencrypted', $histRow['encryption_mode']);
        $this->assertSame('cli',         $histRow['triggered_by']);
        $this->assertSame('def',         $histRow['checksum'], 'sha256 must map to checksum');

        $tables = array_column(
            $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        $this->assertNotContains('backup_log',     $tables);
        $this->assertNotContains('backup_history', $tables);
    }

    public function testV321ScheduleUniqueDedupRepointsBackupRuns(): void
    {
        $db = $this->makePreVrfDb();

        \apply_migrations($db);
        $db->exec("DROP INDEX IF EXISTS uq_backup_schedules_destination");
        $db->exec("DELETE FROM schema_migrations WHERE version = '3.21.0-schedule-unique'");

        $db->exec("INSERT INTO backup_destinations (id, name, type) VALUES (1, 'pw-local', 'local')");
        $db->exec("INSERT INTO backup_schedules (id, destination_id, frequency, time_of_day) VALUES (10, 1, 'daily', '02:00'), (11, 1, 'daily', '03:00'), (12, 1, 'daily', '04:00')");

        $db->exec("INSERT INTO backup_runs (id, destination_id, schedule_id, backup_type, status, started_at) VALUES (100, 1, 10, 'database', 'success', '2026-05-01 02:00:00'), (101, 1, 11, 'database', 'success', '2026-05-01 03:00:00'), (102, 1, 12, 'database', 'success', '2026-05-01 04:00:00')");

        $applied = \apply_migrations($db);
        $this->assertContains('3.21.0-schedule-unique', $applied);

        $remainingIds = array_column(
            $db->query("SELECT id FROM backup_schedules WHERE destination_id = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
            'id'
        );
        $this->assertSame([12], array_map('intval', $remainingIds), 'dedup must keep the highest-id schedule');

        $repointedIds = array_column(
            $db->query("SELECT schedule_id FROM backup_runs WHERE destination_id = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
            'schedule_id'
        );
        $this->assertSame([12, 12, 12], array_map('intval', $repointedIds), 'backup_runs.schedule_id must be repointed to the surviving schedule before losers are deleted');

        $idxs = $db->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'backup_schedules'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('uq_backup_schedules_destination', $idxs);
    }
}
