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

    // ------------------------------------------------------------------
    // Encrypt-at-rest integration (v3.31.0 #1233, Task D2).
    // In-memory SQLite harness mirrors SettingsTest::setUp().
    // ------------------------------------------------------------------

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec("
            CREATE TABLE settings (
                tenant_id  INTEGER,
                key        TEXT NOT NULL,
                value      TEXT,
                type       TEXT NOT NULL DEFAULT 'string' CHECK(type IN ('string','int','bool','json')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by INTEGER
            )
        ");
        $this->db->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_settings_global ON settings (key) WHERE tenant_id IS NULL");
        $this->db->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_settings_tenant ON settings (tenant_id, key) WHERE tenant_id IS NOT NULL");
        $this->db->exec("
            CREATE TABLE audit_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at  TEXT NOT NULL DEFAULT (datetime('now')),
                user_id     INTEGER,
                username    TEXT,
                action      TEXT NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id   INTEGER,
                ip          TEXT,
                user_agent  TEXT,
                details     TEXT
            )
        ");

        $GLOBALS['db']     = $this->db;
        $GLOBALS['config'] = [];
        $_SESSION          = [];

        ipam_setting_cache_bust();
        ipam_config_invalidate_cache();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db'], $GLOBALS['config']);
        ipam_setting_cache_bust();
    }

    /** Read the raw, un-decoded settings.value for a global-scope key. */
    private function rawValue(string $key): ?string
    {
        $kc = ipam_key_col();
        $st = $this->db->prepare("SELECT value FROM settings WHERE tenant_id IS NULL AND {$kc} = :k");
        $st->execute([':k' => $key]);
        $row = $st->fetch();
        return is_array($row) && is_string($row['value'] ?? null) ? $row['value'] : null;
    }

    public function testSetEncryptsAtRestAndGetDecrypts(): void
    {
        ipam_secret_set($this->db, 'smtp.auth_pass', 'p@ssw0rd!');

        $raw = $this->rawValue('smtp.auth_pass');
        self::assertIsString($raw);
        self::assertStringStartsWith(
            IPAM_SECRET_ENVELOPE_PREFIX,
            $raw,
            'smtp.auth_pass must be ciphertext at rest'
        );
        self::assertNotSame('p@ssw0rd!', $raw, 'raw row must not be plaintext');

        self::assertSame('p@ssw0rd!', ipam_secret_get('smtp.auth_pass'));
        self::assertSame('p@ssw0rd!', ipam_setting('smtp.auth_pass'));
    }

    public function testNonSensitiveSettingIsNotEncrypted(): void
    {
        // branding.site_name is a non-sensitive registry key (the plan's
        // 'branding.title' is not in the registry).
        ipam_setting_set($this->db, 'branding.site_name', 'My IPAM');

        $raw = $this->rawValue('branding.site_name');
        self::assertSame('My IPAM', $raw, 'non-sensitive setting must be stored plaintext');
        self::assertStringStartsNotWith(IPAM_SECRET_ENVELOPE_PREFIX, (string) $raw);
    }
}
