<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Helpers\InMemoryDb;

require_once __DIR__ . '/bootstrap.php';

/**
 * v3.35.0 #946 — TOTP backup-code lookup_key discriminator.
 *
 * Verifies that:
 *  1. Generated/saved codes populate lookup_key with a valid 8-hex-char value.
 *  2. Verify accepts a valid code and consumes it one-shot.
 *  3. The narrowed-SELECT path returns false for a code that matches no row.
 *  4. Legacy rows (NULL lookup_key) still verify via the fallback path.
 */
final class TotpBackupCodeLookupTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        // InMemoryDb::withMigrations() applies schema.sql then every registered
        // migration — including 3.35.0-totp-backup-lookup-key which adds the
        // lookup_key column. Without the migration the column is absent and the
        // test fails at setUp time, which is the expected RED state before Step 2.3.
        $this->db = InMemoryDb::withMigrations();
        $this->db->exec(
            "INSERT INTO users (username, password_hash, role, is_active) VALUES ('u', 'x', 'admin', 1)"
        );
    }

    public function testGeneratedCodesPopulateLookupKey(): void
    {
        $codes = ipam_totp_generate_backup_codes(8);
        ipam_totp_save_backup_codes($this->db, 1, $codes);

        $rows = $this->db
            ->query("SELECT lookup_key FROM totp_backup_codes WHERE user_id = 1")
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(count($codes), $rows);
        foreach ($rows as $k) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string) $k);
        }
    }

    public function testVerifyAcceptsValidCodeAndConsumesOneShot(): void
    {
        $codes = ipam_totp_generate_backup_codes(8);
        ipam_totp_save_backup_codes($this->db, 1, $codes);

        $this->assertTrue(ipam_totp_verify_backup_code($this->db, 1, $codes[0]));
        $this->assertFalse(
            ipam_totp_verify_backup_code($this->db, 1, $codes[0]),
            'one-shot consumption: second call on same code must return false'
        );
        $this->assertFalse(ipam_totp_verify_backup_code($this->db, 1, 'wrong-code-99'));
    }

    public function testVerifyHonoursLookupKeyNarrowing(): void
    {
        $codes = ipam_totp_generate_backup_codes(8);
        ipam_totp_save_backup_codes($this->db, 1, $codes);

        // A synthetic code whose lookup_key matches no row must safely return false
        // (confirms the narrowed-SELECT path does not crash or leak).
        $this->assertFalse(ipam_totp_verify_backup_code($this->db, 1, 'NEVER-MATCHES'));
    }

    public function testLegacyRowsWithNullLookupKeyStillVerify(): void
    {
        // Simulate a pre-3.35.0 row whose lookup_key is NULL (no backfill is performed).
        $plainCode = 'LEGACY-CODE-1234';
        $hash = password_hash($plainCode, PASSWORD_DEFAULT);
        $this->db->prepare(
            "INSERT INTO totp_backup_codes (user_id, code_hash, lookup_key) VALUES (1, :h, NULL)"
        )->execute([':h' => $hash]);

        $this->assertTrue(ipam_totp_verify_backup_code($this->db, 1, $plainCode));
        $this->assertFalse(
            ipam_totp_verify_backup_code($this->db, 1, $plainCode),
            'legacy row also consumed one-shot'
        );
    }
}
