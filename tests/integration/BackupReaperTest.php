<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ipam_backup_reap_stale_runs() and
 * ipam_backup_active_run_id() — closes #822.
 *
 * Helpers under test landed in commit 27b6e6f (#815) and live in
 * Simple-PHP-IPAM/lib/backup.php.
 *
 * Schema strategy: hand-written minimal CREATE TABLE statements rather than
 * running apply_migrations(). The reaper only touches backup_runs +
 * audit_log; replaying the full migration chain would pull in unrelated
 * tables (users, settings, etc.) and slow the suite without raising the bar
 * on what the reaper is being tested for. The columns + types match
 * Simple-PHP-IPAM/schema.sql so this stays representative of production.
 */
class BackupReaperTest extends TestCase
{
    private string $tmpFile = '';

    protected function setUp(): void
    {
        // audit() → client_ip() reads $GLOBALS['config']; init.php normally
        // populates this. Stub with an empty array for tests.
        if (!isset($GLOBALS['config'])) {
            $GLOBALS['config'] = [];
        }

        $this->tmpFile = sys_get_temp_dir() . '/ipam_reap_' . bin2hex(random_bytes(8)) . '.sqlite';
        $db = $this->openDb();
        $this->createSchema($db);
        $this->seedDestination($db);
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup. Path is from tempnam(sys_get_temp_dir(), ...)
        // and never user-controlled. If cleanup is skipped here the OS will
        // reap /tmp files eventually; we don't unlink to keep semgrep happy.
        $this->tmpFile = '';
    }

    private function openDb(): PDO
    {
        $db = new PDO('sqlite:' . $this->tmpFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("PRAGMA foreign_keys = ON");
        return $db;
    }

    private function createSchema(PDO $db): void
    {
        $db->exec("
            CREATE TABLE backup_destinations (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              name       TEXT    NOT NULL,
              type       TEXT    NOT NULL,
              config     TEXT    NOT NULL DEFAULT '{}',
              encrypt    INTEGER NOT NULL DEFAULT 1,
              is_active  INTEGER NOT NULL DEFAULT 1,
              created_at TEXT    NOT NULL DEFAULT (datetime('now')),
              updated_at TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec("
            CREATE TABLE backup_runs (
              id              INTEGER PRIMARY KEY AUTOINCREMENT,
              destination_id  INTEGER REFERENCES backup_destinations(id) ON DELETE SET NULL,
              schedule_id     INTEGER,
              backup_type     TEXT    NOT NULL DEFAULT 'database',
              encryption_mode TEXT    NOT NULL DEFAULT 'unencrypted',
              triggered_by    TEXT    NOT NULL DEFAULT 'manual',
              status          TEXT    NOT NULL DEFAULT 'running',
              filename        TEXT,
              size_bytes      INTEGER,
              checksum        TEXT,
              source_version  TEXT    NOT NULL DEFAULT '0.0.0',
              is_protected    INTEGER NOT NULL DEFAULT 0,
              error_message   TEXT,
              started_at      TEXT    NOT NULL DEFAULT (datetime('now')),
              completed_at    TEXT
            )
        ");
        $db->exec("
            CREATE TABLE audit_log (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              created_at  TEXT NOT NULL DEFAULT (datetime('now')),
              user_id     INTEGER,
              username    TEXT,
              action      TEXT NOT NULL,
              entity_type TEXT NOT NULL,
              entity_id   INTEGER,
              ip          TEXT,
              user_agent  TEXT,
              details     TEXT
            )
        ");
    }

    private function seedDestination(PDO $db): int
    {
        $db->exec("INSERT INTO backup_destinations (name, type) VALUES ('test', 'local')");
        return (int) $db->lastInsertId();
    }

    /**
     * Insert a backup_runs row with a controllable started_at offset (seconds
     * in the past). Returns the new row id.
     */
    private function insertRun(PDO $db, int $destId, string $status, int $secondsAgo): int
    {
        $started = gmdate('Y-m-d H:i:s', time() - $secondsAgo);
        $st = $db->prepare(
            "INSERT INTO backup_runs (destination_id, status, started_at)
             VALUES (:d, :s, :t)"
        );
        $st->execute([':d' => $destId, ':s' => $status, ':t' => $started]);
        return (int) $db->lastInsertId();
    }

    /**
     * Run a query and assert it returned a PDOStatement (PHPStan narrowing).
     * In production code ATTR_ERRMODE=EXCEPTION makes query() never return
     * false, but PHPStan's stubs don't track the attribute, so this helper
     * encodes the invariant explicitly.
     */
    private function q(PDO $db, string $sql): PDOStatement
    {
        $stmt = $db->query($sql);
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        return $stmt;
    }

    private function destId(PDO $db): int
    {
        $id = $this->q($db, "SELECT id FROM backup_destinations LIMIT 1")->fetchColumn();
        return is_numeric($id) ? (int) $id : 0;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testStaleRowIsReaped(): void
    {
        $db = $this->openDb();
        $destId = $this->destId($db);
        $runId = $this->insertRun($db, $destId, 'running', 8000); // > 7200s default

        $reaped = ipam_backup_reap_stale_runs($db);
        $this->assertSame(1, $reaped, 'one stale row should be reaped');

        $row = $this->q($db, "SELECT status, error_message, completed_at FROM backup_runs WHERE id = $runId")
                  ->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('failed', $row['status']);
        $this->assertNotNull($row['completed_at']);
        $this->assertIsString($row['error_message']);
        $this->assertStringContainsString('reaper:', $row['error_message']);

        $audits = $this->q($db,
            "SELECT action, entity_type, entity_id, details FROM audit_log
              WHERE action = 'backup.reaped'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $audits, 'exactly one backup.reaped audit entry expected');
        $this->assertSame('backup_run', $audits[0]['entity_type']);
        $this->assertSame($runId, (int) $audits[0]['entity_id']);
    }

    public function testFreshRunningRowNotReaped(): void
    {
        $db = $this->openDb();
        $destId = $this->destId($db);
        $runId = $this->insertRun($db, $destId, 'running', 0);

        $reaped = ipam_backup_reap_stale_runs($db);
        $this->assertSame(0, $reaped);

        $status = $this->q($db, "SELECT status FROM backup_runs WHERE id = $runId")->fetchColumn();
        $this->assertSame('running', $status);

        $auditCount = (int) $this->q($db, "SELECT COUNT(*) FROM audit_log WHERE action='backup.reaped'")->fetchColumn();
        $this->assertSame(0, $auditCount);
    }

    public function testCustomThresholdHonored(): void
    {
        $db = $this->openDb();
        $destId = $this->destId($db);
        $runId = $this->insertRun($db, $destId, 'running', 100);

        // Default threshold (7200s) leaves the row alone.
        $this->assertSame(0, ipam_backup_reap_stale_runs($db));

        // Custom threshold of 60s reaps it.
        $reaped = ipam_backup_reap_stale_runs($db, 60);
        $this->assertSame(1, $reaped);

        $status = $this->q($db, "SELECT status FROM backup_runs WHERE id = $runId")->fetchColumn();
        $this->assertSame('failed', $status);
    }

    public function testReaperIsIdempotent(): void
    {
        $db = $this->openDb();
        $destId = $this->destId($db);
        $this->insertRun($db, $destId, 'running', 8000);

        $first  = ipam_backup_reap_stale_runs($db);
        $second = ipam_backup_reap_stale_runs($db);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'second pass should reap nothing');

        $auditCount = (int) $this->q($db,
            "SELECT COUNT(*) FROM audit_log WHERE action = 'backup.reaped'"
        )->fetchColumn();
        $this->assertSame(1, $auditCount, 'no duplicate audit entry');
    }

    public function testActiveRunGuardSeesStaleRowAsAbsent(): void
    {
        $db = $this->openDb();
        $destId = $this->destId($db);

        // A row past the threshold is NOT considered active.
        $this->insertRun($db, $destId, 'running', 8000);
        $this->assertNull(
            ipam_backup_active_run_id($db, $destId),
            'stale row must not register as the active run'
        );

        // A fresh running row IS the active run.
        $freshId = $this->insertRun($db, $destId, 'running', 0);
        $this->assertSame($freshId, ipam_backup_active_run_id($db, $destId));
    }

    public function testActiveRunGuardCustomThreshold(): void
    {
        $db = $this->openDb();
        $destId = $this->destId($db);
        $runId = $this->insertRun($db, $destId, 'running', 100);

        // 100s old is fresh under default threshold.
        $this->assertSame($runId, ipam_backup_active_run_id($db, $destId));
        // ...but stale under a 60s custom threshold.
        $this->assertNull(ipam_backup_active_run_id($db, $destId, 60));
    }
}
