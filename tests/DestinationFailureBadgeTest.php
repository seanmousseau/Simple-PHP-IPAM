<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup_admin_destinations.php';

use PHPUnit\Framework\TestCase;

/**
 * v3.27.8 PR 5 of 5 (#1172) — destination-card failure-reason badge.
 *
 * The Destinations admin tab previously only surfaced ad-hoc test
 * results in the Actions column; an operator could not tell from this
 * page that a destination's scheduled backups were failing on every
 * run. They had to switch to the History tab and scroll to find the
 * pattern.
 *
 * Fix: `ipam_destinations_load_state` annotates each destination row
 * with a `last_failure` shape when the newest `backup_runs` row for
 * that destination has `status='failed'`. The view renders a
 * `badge-failed` chip with the truncated error_message in the title
 * attribute, right next to the destination name.
 *
 * These tests assert the controller-side annotation only — the view
 * rendering itself is covered by Playwright visual-regression on the
 * backup-admin-destinations page.
 */
final class DestinationFailureBadgeTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql');
        $this->db->exec($schema);
        $this->db->exec('PRAGMA foreign_keys = ON');
        ensure_migrations_table($this->db);
        apply_migrations($this->db);

        // $config global is read by ipam_vault_key_status() which the
        // loader calls. Minimal shape — empty key means 'absent' state,
        // which the loader handles cleanly.
        $GLOBALS['config'] = ['app_secret' => ''];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
        $_SESSION = [];
    }

    private function seedDestination(string $name = 'wasabi'): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO backup_destinations (name, type, config, encrypt, is_active) "
            . "VALUES (:n, 's3', '{}', 1, 1)"
        );
        $stmt->execute([':n' => $name]);
        return (int) $this->db->lastInsertId();
    }

    /** Insert a backup_runs row and return its id. */
    private function seedRun(int $destId, string $status, string $errorMessage = '', string $filename = 'ipam-backup-test.enc'): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO backup_runs "
            . "(destination_id, backup_type, encryption_mode, triggered_by, status, filename, source_version, error_message, started_at) "
            . "VALUES (:d, 'database', 'stored', 'manual', :s, :f, '3.27.8', :em, datetime('now'))"
        );
        $stmt->execute([
            ':d'  => $destId,
            ':s'  => $status,
            ':f'  => $filename,
            ':em' => $errorMessage === '' ? null : $errorMessage,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function testLastFailureAnnotatedWhenNewestRunFailed(): void
    {
        $destId = $this->seedDestination();
        $this->seedRun($destId, 'success', '', 'old-success.enc');
        $this->seedRun($destId, 'failed', 'vault envelope unreadable', '(preflight-failed-abcd1234)');

        $state = ipam_destinations_load_state($this->db);
        $this->assertCount(1, $state['destinations']);
        $d = $state['destinations'][0];

        $this->assertArrayHasKey('last_failure', $d, 'newest run was failed; controller must attach last_failure');
        $this->assertSame('vault envelope unreadable', $d['last_failure']['error_message']);
        $this->assertSame('(preflight-failed-abcd1234)', $d['last_failure']['filename']);
        $this->assertNotSame('', $d['last_failure']['started_at']);
    }

    public function testNoLastFailureWhenNewestRunSucceeded(): void
    {
        $destId = $this->seedDestination();
        $this->seedRun($destId, 'failed', 'transient s3 error');
        $this->seedRun($destId, 'success', '', 'recovered.enc');

        $state = ipam_destinations_load_state($this->db);
        $d = $state['destinations'][0];

        $this->assertArrayNotHasKey(
            'last_failure',
            $d,
            'newest run was success; older failures must not bubble up to the card'
        );
    }

    public function testNoLastFailureWhenDestinationHasNoRuns(): void
    {
        $this->seedDestination();

        $state = ipam_destinations_load_state($this->db);
        $d = $state['destinations'][0];

        $this->assertArrayNotHasKey('last_failure', $d);
    }

    public function testPerDestinationIsolation(): void
    {
        $a = $this->seedDestination('alpha');
        $b = $this->seedDestination('bravo');
        $this->seedRun($a, 'failed', 'alpha failure', '(preflight-failed-aaaa)');
        $this->seedRun($b, 'success', '', 'bravo-ok.enc');

        $state = ipam_destinations_load_state($this->db);
        $byName = [];
        foreach ($state['destinations'] as $row) {
            $byName[$row['name']] = $row;
        }

        $this->assertArrayHasKey('last_failure', $byName['alpha']);
        $this->assertSame('alpha failure', $byName['alpha']['last_failure']['error_message']);
        $this->assertArrayNotHasKey('last_failure', $byName['bravo']);
    }
}
