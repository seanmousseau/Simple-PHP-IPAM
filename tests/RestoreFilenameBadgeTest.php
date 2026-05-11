<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup_admin_restore.php';

/**
 * v3.27.8 Bug B+C — Restore-tab Encryption + Type badges must reflect
 * backup_runs ground truth, not filename suffix. Orphan files (listed in
 * the destination but missing from backup_runs) fall back to the filename
 * heuristic and are flagged so the UI can label the inference.
 *
 * Pure-function tests on the extracted helper
 * ipam_restore_browse_entry_derive(); no PDO + no BackupClientInterface
 * mock required.
 */
final class RestoreFilenameBadgeTest extends TestCase
{
    /** @return array{name:string,size:int,last_modified:string} */
    private function obj(string $name, int $size = 1024, string $lm = '2026-05-11T00:00:00Z'): array
    {
        return ['name' => $name, 'size' => $size, 'last_modified' => $lm];
    }

    public function testRunRowEncryptedStoredEncSuffix(): void
    {
        $entry = ipam_restore_browse_entry_derive(
            $this->obj('ipam-2026-05-11-stored.sql.gz.enc'),
            ['id' => 7, 'filename' => 'ipam-2026-05-11-stored.sql.gz.enc',
             'encryption_mode' => 'stored', 'backup_type' => 'database', 'checksum' => 'abc']
        );
        $this->assertTrue($entry['is_encrypted']);
        $this->assertSame('stored', $entry['encryption_mode']);
        $this->assertSame('database', $entry['backup_type']);
        $this->assertFalse($entry['is_orphan']);
        $this->assertSame(7, $entry['run_id']);
        $this->assertSame('abc', $entry['checksum']);
    }

    public function testDbWinsWhenEncSuffixButRecordedUnencrypted(): void
    {
        // Mismatch case central to Bug B: file ends with .enc but the run
        // row says unencrypted. DB must win, not the filename.
        $entry = ipam_restore_browse_entry_derive(
            $this->obj('weird-name.enc'),
            ['id' => 8, 'filename' => 'weird-name.enc',
             'encryption_mode' => 'unencrypted', 'backup_type' => 'database', 'checksum' => '']
        );
        $this->assertFalse($entry['is_encrypted']);
        $this->assertSame('unencrypted', $entry['encryption_mode']);
        $this->assertFalse($entry['is_orphan']);
    }

    public function testDbWinsWhenPlainSuffixButRecordedStored(): void
    {
        // Other-direction mismatch: file lacks .enc but run row says stored.
        $entry = ipam_restore_browse_entry_derive(
            $this->obj('renamed.sql.gz'),
            ['id' => 9, 'filename' => 'renamed.sql.gz',
             'encryption_mode' => 'stored', 'backup_type' => 'database', 'checksum' => '']
        );
        $this->assertTrue($entry['is_encrypted']);
        $this->assertSame('stored', $entry['encryption_mode']);
        $this->assertFalse($entry['is_orphan']);
    }

    public function testTransitoryModeIsEncrypted(): void
    {
        $entry = ipam_restore_browse_entry_derive(
            $this->obj('passphrased.sql.gz.enc'),
            ['id' => 10, 'filename' => 'passphrased.sql.gz.enc',
             'encryption_mode' => 'transitory', 'backup_type' => 'database', 'checksum' => '']
        );
        $this->assertTrue($entry['is_encrypted']);
        $this->assertSame('transitory', $entry['encryption_mode']);
    }

    public function testOrphanWithEncSuffixInfersEncrypted(): void
    {
        $entry = ipam_restore_browse_entry_derive(
            $this->obj('stranded.sql.gz.enc'),
            null
        );
        $this->assertTrue($entry['is_encrypted']);
        $this->assertSame('unknown', $entry['encryption_mode']);
        $this->assertSame('unknown', $entry['backup_type']);
        $this->assertTrue($entry['is_orphan']);
        $this->assertSame(0, $entry['run_id']);
        $this->assertSame('', $entry['checksum']);
    }

    public function testOrphanWithPlainSuffixInfersPlaintext(): void
    {
        $entry = ipam_restore_browse_entry_derive(
            $this->obj('stranded.sql.gz'),
            null
        );
        $this->assertFalse($entry['is_encrypted']);
        $this->assertSame('unknown', $entry['encryption_mode']);
        $this->assertSame('unknown', $entry['backup_type']);
        $this->assertTrue($entry['is_orphan']);
    }

    public function testLogicalBackupTypePreserved(): void
    {
        $entry = ipam_restore_browse_entry_derive(
            $this->obj('ipam-logical.ipambkl1.gz'),
            ['id' => 11, 'filename' => 'ipam-logical.ipambkl1.gz',
             'encryption_mode' => 'unencrypted', 'backup_type' => 'logical', 'checksum' => 'def']
        );
        $this->assertSame('logical', $entry['backup_type']);
        $this->assertFalse($entry['is_encrypted']);
        $this->assertFalse($entry['is_orphan']);
    }
}
