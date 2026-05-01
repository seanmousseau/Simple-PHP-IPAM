<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for ipam_restore_proc_check() — the sigchild-aware
 * verdict helper used by mysql / psql restore in restore.php.
 *
 * Mirrors the v3.19.1 #783 testing pattern (the dump path's file-size
 * fallback) for the symmetric restore path (#805 / B-P0-3). proc_open
 * itself cannot be unit-tested in PHPUnit so we exercise the pure
 * helper directly with the four exit-code states.
 */
final class RestoreProcCheckTest extends TestCase
{
    private function makeDb(bool $withSchemaMigrations = true, int $migrationRows = 1): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($withSchemaMigrations) {
            $db->exec('CREATE TABLE schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT)');
            $stmt = $db->prepare('INSERT INTO schema_migrations(version, applied_at) VALUES (?, ?)');
            for ($i = 1; $i <= $migrationRows; $i++) {
                $stmt->execute(["v0.{$i}", '2026-04-30T00:00:00Z']);
            }
        }
        return $db;
    }

    public function testExitZeroIsAlwaysSuccess(): void
    {
        // When the tool reports clean exit, the check trusts it without
        // touching the DB at all (post-condition check is reserved for
        // sigchild ambiguity).
        $db = $this->makeDb(withSchemaMigrations: false);
        $r  = ipam_restore_proc_check(0, 'mysql', '', $db);
        $this->assertTrue($r['ok']);
        $this->assertSame('exit=0', $r['verdict']);
        $this->assertSame('', $r['message']);
    }

    public function testPositiveExitIsAlwaysFailure(): void
    {
        $db = $this->makeDb();
        $r  = ipam_restore_proc_check(1, 'mysql', "ERROR 1045 (28000): Access denied", $db);
        $this->assertFalse($r['ok']);
        $this->assertSame('exit=1', $r['verdict']);
        $this->assertStringContainsString('mysql restore failed (exit=1)', $r['message']);
        $this->assertStringContainsString('Access denied', $r['message']);
    }

    public function testSigchildAmbiguousWithMigrationsAdvancedIsSuccess(): void
    {
        // exit=-1 on sigchild builds; schema_migrations advanced past the
        // pre-restore count is the canonical post-condition signal.
        // (CR feedback PR #1054: compare pre/post, not absolute count.)
        $db = $this->makeDb(migrationRows: 5);
        $r  = ipam_restore_proc_check(-1, 'mysql', '', $db, /*preMigCount*/ 0);
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('exit=-1 (sigchild)', $r['verdict']);
        $this->assertStringContainsString('post-condition OK (pre=0, post=5)', $r['verdict']);
        $this->assertSame('', $r['message']);
    }

    public function testSigchildAmbiguousWithEmptySchemaMigrationsIsFailure(): void
    {
        // Empty schema_migrations and pre=0 means the dump never wrote it —
        // restore produced no usable post-state. Fail safe.
        $db = $this->makeDb(migrationRows: 0);
        $r  = ipam_restore_proc_check(-1, 'mysql', 'silent stderr', $db, /*preMigCount*/ 0);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('schema_migrations not advanced', $r['verdict']);
        $this->assertStringContainsString('did not produce expected post-state', $r['message']);
    }

    public function testSigchildAmbiguousWithUnchangedCountIsFailure(): void
    {
        // CR feedback PR #1054: pre=5, post=5 means the restore tool died
        // before touching the DB but the table was already populated. The
        // old absolute-count check would call this success; the pre/post
        // compare correctly fails it.
        $db = $this->makeDb(migrationRows: 5);
        $r  = ipam_restore_proc_check(-1, 'mysql', 'silent stderr', $db, /*preMigCount*/ 5);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('not advanced (pre=5, post=5)', $r['verdict']);
    }

    public function testSigchildAmbiguousWithMissingSchemaMigrationsTableIsFailure(): void
    {
        // No schema_migrations table at all — query throws — fail safely.
        $db = $this->makeDb(withSchemaMigrations: false);
        $r  = ipam_restore_proc_check(-1, 'psql', 'connection lost', $db);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('schema_migrations check failed', $r['verdict']);
        $this->assertStringContainsString('psql restore', $r['message']);
    }

    public function testToolNameAppearsInFailureMessage(): void
    {
        // Ensures both call sites (mysql, psql) get distinguishable diagnostics.
        $db = $this->makeDb();
        $rMysql = ipam_restore_proc_check(2, 'mysql', '', $db);
        $rPsql  = ipam_restore_proc_check(2, 'psql', '', $db);
        $this->assertStringContainsString('mysql', $rMysql['message']);
        $this->assertStringContainsString('psql', $rPsql['message']);
        $this->assertStringNotContainsString('psql', $rMysql['message']);
        $this->assertStringNotContainsString('mysql', $rPsql['message']);
    }
}
