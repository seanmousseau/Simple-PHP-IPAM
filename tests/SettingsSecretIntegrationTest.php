<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

final class SettingsSecretIntegrationTest extends TestCase
{
    public function testManagedKeysAreSensitiveMinusVaultKey(): void
    {
        $managed = ipam_secret_managed_keys();
        self::assertContains('oidc.client_secret', $managed);
        self::assertContains('smtp.auth_pass', $managed);
        self::assertContains('login_protection.secret_key', $managed);
        self::assertContains('recaptcha_enterprise.api_key', $managed);
        self::assertNotContains('backup_vault_key', $managed);
    }

    public function testEverySensitiveRegistryEntryIsAccountedFor(): void
    {
        foreach (ipam_setting_definitions() as $key => $def) {
            if (!empty($def['sensitive']) && $key !== 'backup_vault_key') {
                self::assertContains($key, ipam_secret_managed_keys(), "$key must be a managed secret");
            }
        }
    }
}
