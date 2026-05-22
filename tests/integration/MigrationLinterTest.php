<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../testing/scripts/migration-linter.php';

/**
 * v3.29.0 #1101 — Driver tests for the migration linter.
 *
 * Drives `ipam_run_migration_linter()` directly (no shell-out, no
 * tmpfile-vs-CLI-output round-trip) and pins both ends of the contract:
 *
 *   - The live `Simple-PHP-IPAM/migrations.php` produces 0 findings on
 *     the current tree, so the lint won't false-positive against shipped
 *     migrations.
 *   - A synthetic fixture with an ungated PRAGMA / sqlite_master /
 *     AUTOINCREMENT / datetime('now') pattern flags + exits 1.
 *   - A synthetic fixture that mentions `$driver` or a `=== 'sqlite'`
 *     comparison is trusted (the linter deliberately does NOT try to
 *     second-guess multi-branch closures).
 *
 * Each test writes a small fixture to a tmp dir and asserts the linter's
 * exit code + stdout. tearDown deletes the fixtures.
 */
final class MigrationLinterTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ipam_migration_linter_test_' . bin2hex(random_bytes(6));
        if (!mkdir($this->tmpDir, 0o700, true) && !is_dir($this->tmpDir)) {
            throw new RuntimeException('failed to mkdir ' . $this->tmpDir);
        }
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- test-scope path under sys_get_temp_dir(), not user input
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
    }

    private function writeFixture(string $body): string
    {
        $path = $this->tmpDir . '/migrations.php';
        file_put_contents($path, $body);
        return $path;
    }

    public function testLiveMigrationsFileProducesZeroFindings(): void
    {
        $live = __DIR__ . '/../../Simple-PHP-IPAM/migrations.php';
        $r = ipam_run_migration_linter($live);
        $this->assertSame(
            0,
            $r['exit'],
            "migration-linter must pass on the current migrations.php:\n" . $r['stdout']
        );
        $this->assertStringContainsString('0 findings', $r['stdout']);
    }

    public function testUngatedPragmaIsFlagged(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return [
    '9.9.9-bad-pragma' => function(PDO $db): void {
        $cols = $db->query("PRAGMA table_info(users)")->fetchAll();
    },
];
PHP);
        $r = ipam_run_migration_linter($path);
        $this->assertSame(1, $r['exit']);
        $this->assertStringContainsString('PRAGMA pattern in ungated closure', $r['stdout']);
    }

    public function testUngatedSqliteMasterIsFlagged(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return [
    '9.9.9-bad-sqlite-master' => function(PDO $db): void {
        $exists = $db->query("SELECT 1 FROM sqlite_master WHERE name = 'users'")->fetchColumn();
    },
];
PHP);
        $r = ipam_run_migration_linter($path);
        $this->assertSame(1, $r['exit']);
        $this->assertStringContainsString('sqlite_master pattern in ungated closure', $r['stdout']);
    }

    public function testUngatedAutoincrementIsFlagged(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return [
    '9.9.9-bad-autoincrement' => function(PDO $db): void {
        $db->exec("CREATE TABLE foo (id INTEGER PRIMARY KEY AUTOINCREMENT)");
    },
];
PHP);
        $r = ipam_run_migration_linter($path);
        $this->assertSame(1, $r['exit']);
        $this->assertStringContainsString('INTEGER PRIMARY KEY AUTOINCREMENT', $r['stdout']);
    }

    public function testUngatedDatetimeNowIsFlagged(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return [
    '9.9.9-bad-datetime' => function(PDO $db): void {
        $db->exec("INSERT INTO audit_log (action, created_at) VALUES ('x', datetime('now'))");
    },
];
PHP);
        $r = ipam_run_migration_linter($path);
        $this->assertSame(1, $r['exit']);
        $this->assertStringContainsString("datetime('now') pattern in ungated closure", $r['stdout']);
    }

    public function testDriverAwareClosureIsSkipped(): void
    {
        // A closure that mentions $driver is trusted not to need linting.
        $path = $this->writeFixture(<<<'PHP'
<?php
return [
    '9.9.9-engine-aware' => function(PDO $db): void {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $cols = $db->query("PRAGMA table_info(users)")->fetchAll();
        }
    },
];
PHP);
        $r = ipam_run_migration_linter($path);
        $this->assertSame(0, $r['exit'], "engine-aware closure must not be flagged:\n" . $r['stdout']);
        $this->assertStringContainsString('0 findings', $r['stdout']);
    }

    public function testNegativeReturnGateIsSkipped(): void
    {
        $path = $this->writeFixture(<<<'PHP'
<?php
return [
    '9.9.9-negative-gate' => function(PDO $db): void {
        $driverRaw = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!is_string($driverRaw) || $driverRaw !== 'sqlite') return;
        $cols = $db->query("PRAGMA table_info(users)")->fetchAll();
    },
];
PHP);
        $r = ipam_run_migration_linter($path);
        $this->assertSame(0, $r['exit'], "negative-gate closure must not be flagged:\n" . $r['stdout']);
    }

    public function testUnreadableFileReturnsExitTwo(): void
    {
        $r = ipam_run_migration_linter($this->tmpDir . '/does-not-exist.php');
        $this->assertSame(2, $r['exit']);
        $this->assertStringContainsString('cannot read', $r['stderr']);
    }
}
