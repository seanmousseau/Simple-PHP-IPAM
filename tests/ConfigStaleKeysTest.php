<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for ipam_config_stale_keys().
 *
 * The "config.php cleanup needed" banner must never flag a key that the
 * application still reads at runtime — telling an admin to remove such a key
 * breaks the install. bootstrap_key (the vault-wrapping key in config.php,
 * #1098/v3.26.0) was the concrete case: it is auto-generated into config.php
 * and is required to unwrap the backup_vault_key envelope, yet the original
 * whitelist omitted it, so every upgraded install nagged the admin to delete
 * it and any admin who complied lost the ability to read their backup
 * encryption key.
 */
class ConfigStaleKeysTest extends TestCase
{
    public function testBootstrapKeyIsNotFlaggedStale(): void
    {
        $stale = ipam_config_stale_keys(['bootstrap_key' => base64_encode(random_bytes(32))]);
        $this->assertNotContains('bootstrap_key', $stale);
    }

    public function testBackupVaultKeyIsNotFlaggedStale(): void
    {
        $stale = ipam_config_stale_keys(['backup_vault_key' => '']);
        $this->assertNotContains('backup_vault_key', $stale);
    }

    public function testAppSecretIsNotFlaggedStale(): void
    {
        $stale = ipam_config_stale_keys(['app_secret' => 'x']);
        $this->assertNotContains('app_secret', $stale);
    }

    public function testGenuinelyStaleKeyIsFlagged(): void
    {
        $stale = ipam_config_stale_keys(['alert_email' => 'admin@example.com']);
        $this->assertContains('alert_email', $stale);
    }

    public function testCleanConfigYieldsNoStaleKeys(): void
    {
        $config = [
            'db_driver'        => 'sqlite',
            'db_path'          => '/tmp/x.sqlite',
            'app_secret'       => 'x',
            'bootstrap_key'    => base64_encode(random_bytes(32)),
            'backup_vault_key' => '',
            'session'          => [],
            'auth'             => [],
            'api'              => [],
            'bootstrap_admin'  => [],
        ];
        $this->assertSame([], ipam_config_stale_keys($config));
    }
}
