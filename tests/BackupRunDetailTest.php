<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib.php';

/**
 * Unit tests for ipam_render_backup_run_detail() — the drawer-body
 * partial for a single backup_runs row. Asserts the disabled-state
 * matrix from spec §4 (#803).
 *
 * The endpoint wrapper (backup_run_detail.php) is covered structurally
 * by BackupAdminRbacTest; this test exercises the rendering helper
 * directly so we can pin the contract per state.
 */
final class BackupRunDetailTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec((string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql'));
        $this->db->exec("INSERT INTO backup_destinations (id, name, type) VALUES (1, 'pw-local', 'local')");
        // Some sessions/CSRF helpers expect $GLOBALS['db']; not relevant here, but sane to set.
        $GLOBALS['db'] = $this->db;
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function seedRun(array $overrides = []): int
    {
        $row = array_merge([
            'destination_id'  => 1,
            'backup_type'     => 'logical',
            'encryption_mode' => 'stored',
            'triggered_by'    => 'schedule',
            'status'          => 'success',
            'filename'        => 'ipam-20260501-020000.logical.sql.enc',
            'size_bytes'      => 17_400_000,
            'checksum'        => '9f3c0000000000000000000000000000000000000000000000000000000000b2',
            'started_at'      => '2026-05-01 02:00:00',
            'completed_at'    => '2026-05-01 02:00:14',
            'is_protected'    => 0,
            'error_message'   => null,
            'source_version'  => '3.21.0',
        ], $overrides);
        $cols = array_keys($row);
        $sql = 'INSERT INTO backup_runs (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')';
        $st = $this->db->prepare($sql);
        $params = [];
        foreach ($row as $k => $v) {
            $params[':' . $k] = $v;
        }
        $st->execute($params);
        return (int) $this->db->lastInsertId();
    }

    public function testRendersFullPayloadForSuccessfulRun(): void
    {
        $id = $this->seedRun();
        $html = ipam_render_backup_run_detail($this->db, $id);
        $this->assertNotNull($html);
        $this->assertStringContainsString('Run #' . $id, $html);
        $this->assertStringContainsString('success', $html);
        $this->assertStringContainsString('logical', strtolower($html));
        $this->assertStringContainsString('Stored', $html);
        $this->assertStringContainsString('pw-local', $html);
        $this->assertStringContainsString('ipam-20260501-020000.logical.sql.enc', $html);
        $this->assertStringContainsString('9f3c', $html);
        $this->assertStringContainsString('data-action="verify"',   $html);
        $this->assertStringContainsString('data-action="download"', $html);
        $this->assertStringContainsString('data-action="delete"',   $html);
    }

    public function testFailedRunDisablesVerifyAndDownload(): void
    {
        $id = $this->seedRun([
            'status'        => 'failed',
            'filename'      => null,
            'completed_at'  => '2026-05-01 02:00:01',
            'error_message' => 'sigchild: child exited 0 but file 0 bytes',
        ]);
        $html = ipam_render_backup_run_detail($this->db, $id);
        $this->assertNotNull($html);
        $this->assertMatchesRegularExpression('/data-action="verify"[^>]*\bdisabled\b/',   $html);
        $this->assertMatchesRegularExpression('/data-action="download"[^>]*\bdisabled\b/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-action="delete"[^>]*\bdisabled\b/', $html);
        $this->assertStringContainsString('sigchild', $html);
    }

    public function testProtectedRunDisablesDelete(): void
    {
        $id = $this->seedRun(['is_protected' => 1]);
        $html = ipam_render_backup_run_detail($this->db, $id);
        $this->assertNotNull($html);
        $this->assertMatchesRegularExpression('/data-action="delete"[^>]*\bdisabled\b/', $html);
        $this->assertStringContainsString('protected', strtolower($html));
    }

    public function testRunningRunDisablesAllActions(): void
    {
        $id = $this->seedRun(['status' => 'running', 'completed_at' => null]);
        $html = ipam_render_backup_run_detail($this->db, $id);
        $this->assertNotNull($html);
        foreach (['verify', 'download', 'delete'] as $a) {
            $this->assertMatchesRegularExpression(
                '/data-action="' . $a . '"[^>]*\bdisabled\b/',
                $html,
                "Action $a should be disabled while run is in progress"
            );
        }
    }

    public function testUnknownRunReturnsNull(): void
    {
        $this->assertNull(ipam_render_backup_run_detail($this->db, 99999));
    }
}
