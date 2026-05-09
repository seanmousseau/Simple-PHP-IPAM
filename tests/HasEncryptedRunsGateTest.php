<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup_admin_destinations.php';

use PHPUnit\Framework\TestCase;

/**
 * Bug W (Pass A 2026-05-08, v3.27.1) — has_encrypted_runs gate must
 * distinguish IPAMBKP2 (legacy, app_secret-protected) from IPAMBKP3
 * (modern, vault-key-protected) archives. Generating a NEW vault key
 * does NOT orphan IPAMBKP2 archives — they don't depend on the vault
 * key at all. The pre-fix gate refused vault_set Generate on every
 * install with any historical encrypted archive, even if those archives
 * were IPAMBKP2.
 *
 * Pass A repro: 2026-05-08 dev-direct test instance had 2 IPAMBKP2
 * `.enc` archives. Vault_set Generate refused with "Cannot generate a
 * new vault key while encrypted backups exist" until rows were stashed
 * out.
 *
 * Fix: gate query becomes
 *   `encryption_mode != 'unencrypted' AND filename LIKE '%.ipambkp3'`
 * IPAMBKP3 archives produced by v3.27.1+ orchestrator have `.ipambkp3`
 * suffix. IPAMBKP2 archives have `.enc`. Pre-existing rows fall through.
 */
final class HasEncryptedRunsGateTest extends TestCase
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
        $GLOBALS['config'] = [];
    }

    private function seedRun(string $encryptionMode, string $filename): void
    {
        $this->db->prepare(
            "INSERT INTO backup_runs (destination_id, triggered_by, status, encryption_mode, filename, started_at) "
            . "VALUES (NULL, 'manual', 'success', :em, :fn, :ts)"
        )->execute([
            ':em' => $encryptionMode,
            ':fn' => $filename,
            ':ts' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function testEmptyBackupRunsHasNoEncryptedRuns(): void
    {
        $status = ipam_vault_key_status($this->db);
        $this->assertFalse($status['has_encrypted_runs']);
    }

    public function testIpambkp2LegacyArchivesDoNotTriggerGate(): void
    {
        // Legacy IPAMBKP2 — encryption_mode='stored' but filename ends `.enc`.
        // These were produced by the pre-v3.27.1 orchestrator under app_secret.
        // They DO NOT depend on backup_vault_key, so vault_set Generate must
        // be allowed.
        $this->seedRun('stored', 'ipam-backup-20260506-203004-aabbccdd.enc');
        $this->seedRun('stored', 'ipam-backup-20260507-203004-eeff0011.enc');

        $status = ipam_vault_key_status($this->db);
        $this->assertFalse(
            $status['has_encrypted_runs'],
            'IPAMBKP2 archives must not block vault_set Generate — they do not depend on backup_vault_key'
        );
    }

    public function testIpambkp3ArchivesTriggerGate(): void
    {
        // Modern IPAMBKP3 — encryption_mode='stored' and filename ends `.ipambkp3`.
        // These DO depend on backup_vault_key, so generating a new vault key
        // would orphan them. Gate must fire.
        $this->seedRun('stored', 'ipam-backup-20260509-014032-deadbeef.ipambkp3');

        $status = ipam_vault_key_status($this->db);
        $this->assertTrue(
            $status['has_encrypted_runs'],
            'IPAMBKP3 archives must block vault_set Generate — replacing the vault key would orphan them'
        );
    }

    public function testMixedIpambkp2AndIpambkp3StillTriggersGate(): void
    {
        // Even one IPAMBKP3 archive in a sea of IPAMBKP2 must fire the gate.
        $this->seedRun('stored', 'ipam-backup-20260506-203004-aabbccdd.enc');
        $this->seedRun('stored', 'ipam-backup-20260507-203004-eeff0011.enc');
        $this->seedRun('stored', 'ipam-backup-20260509-014032-deadbeef.ipambkp3');
        $this->seedRun('stored', 'ipam-backup-20260508-203004-99887766.enc');

        $status = ipam_vault_key_status($this->db);
        $this->assertTrue($status['has_encrypted_runs']);
    }

    public function testUnencryptedArchivesNeverTriggerGate(): void
    {
        $this->seedRun('unencrypted', 'ipam-backup-20260506-203004.sql.gz');
        $this->seedRun('unencrypted', 'ipam-backup-20260506-203005.ipambkl1.gz');

        $status = ipam_vault_key_status($this->db);
        $this->assertFalse($status['has_encrypted_runs']);
    }
}
