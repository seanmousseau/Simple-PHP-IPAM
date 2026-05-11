<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib.php';
require_once __DIR__ . '/../Simple-PHP-IPAM/lib/vault.php';

/**
 * v3.27.8 Bug E — `ipam_vault_status()` must report a three-state result:
 *
 *   - absent     → no envelope row in `settings`
 *   - present    → envelope row + unwrap succeeds with current bootstrap_key
 *   - unreadable → envelope row exists but unwrap fails (e.g. bootstrap_key
 *                  rotated since the envelope was written)
 *
 * Pre-fix the helper signal was binary (present / not-present) and the
 * unreadable branch was indistinguishable from absent — the Destinations
 * tab silently said "No vault key configured yet" while the operator had
 * in fact set one, contradicting the per-destination "Stored key" badge.
 */
final class VaultStatusTest extends TestCase
{
    private \PDO $db;

    /** @var mixed */
    private $previousGlobalDb = null;
    private bool $hadGlobalDb = false;

    /** @var mixed */
    private $previousGlobalConfig = null;
    private bool $hadGlobalConfig = false;

    protected function setUp(): void
    {
        $this->hadGlobalDb      = array_key_exists('db', $GLOBALS);
        $this->previousGlobalDb = $this->hadGlobalDb ? $GLOBALS['db'] : null;
        $this->hadGlobalConfig      = array_key_exists('config', $GLOBALS);
        $this->previousGlobalConfig = $this->hadGlobalConfig ? $GLOBALS['config'] : null;

        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $schemaSql = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql');
        $this->db->exec($schemaSql);
        $GLOBALS['db'] = $this->db;

        // Bootstrap_key in $GLOBALS['config'] so ipam_bootstrap_key() resolves
        // without writing config.php from the test runner.
        $GLOBALS['config'] = ['bootstrap_key' => base64_encode(random_bytes(IPAM_BOOTSTRAP_KEY_LEN))];
    }

    protected function tearDown(): void
    {
        if ($this->hadGlobalDb) {
            $GLOBALS['db'] = $this->previousGlobalDb;
        } else {
            unset($GLOBALS['db']);
        }
        if ($this->hadGlobalConfig) {
            $GLOBALS['config'] = $this->previousGlobalConfig;
        } else {
            unset($GLOBALS['config']);
        }
    }

    public function testAbsentWhenNoEnvelopeRow(): void
    {
        $status = ipam_vault_status();
        $this->assertSame('absent', $status['state']);
        $this->assertFalse($status['envelope_present']);
        $this->assertNull($status['error_message']);
    }

    public function testPresentWhenEnvelopeWrappedWithCurrentBootstrap(): void
    {
        $rawVaultKey = random_bytes(BACKUP_VAULT_KEY_LEN);
        $envelope    = ipam_vault_wrap($rawVaultKey, ipam_bootstrap_key());
        ipam_setting_set($this->db, 'backup_vault_key', $envelope);

        $status = ipam_vault_status();
        $this->assertSame('present', $status['state']);
        $this->assertTrue($status['envelope_present']);
        $this->assertNull($status['error_message']);
    }

    public function testUnreadableWhenEnvelopeWrappedWithDifferentBootstrap(): void
    {
        $rawVaultKey = random_bytes(BACKUP_VAULT_KEY_LEN);
        // Wrap under a DIFFERENT bootstrap key than the one currently in
        // $config — simulates the rotated-bootstrap-key recovery scenario.
        $strandedBootstrap = random_bytes(IPAM_BOOTSTRAP_KEY_LEN);
        $envelope          = ipam_vault_wrap($rawVaultKey, $strandedBootstrap);
        ipam_setting_set($this->db, 'backup_vault_key', $envelope);

        $status = ipam_vault_status();
        $this->assertSame('unreadable', $status['state']);
        $this->assertTrue($status['envelope_present']);
        $this->assertIsString($status['error_message']);
        $this->assertNotSame('', $status['error_message']);
    }
}
