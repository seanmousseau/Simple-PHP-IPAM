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
        ipam_config_invalidate_cache();
        $_SESSION = [];
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

    /**
     * Cross-subtype coverage: every managed secret key must round-trip
     * through the encrypt-at-rest pipeline. Catches a registry entry that
     * is flagged sensitive but mishandled by a subtype-specific setter/getter
     * path. Also asserts the raw row is a ciphertext envelope, not plaintext.
     */
    public function testRoundTripForEveryManagedKey(): void
    {
        $managed = ipam_secret_managed_keys();
        self::assertCount(4, $managed, 'expected exactly 4 managed secret keys');

        foreach ($managed as $i => $key) {
            $value = "secret-value-$i";
            ipam_secret_set($this->db, $key, $value);
            self::assertSame($value, ipam_secret_get($key), "round-trip failed for $key");

            $raw = $this->rawValue($key);
            self::assertIsString($raw, "no row stored for $key");
            self::assertStringStartsWith(
                IPAM_SECRET_ENVELOPE_PREFIX,
                $raw,
                "$key must be ciphertext at rest"
            );
            self::assertNotSame($value, $raw, "$key raw row must not be plaintext");
        }
    }

    public function testSecretGetRejectsNonManagedKey(): void
    {
        // branding.site_name is a non-sensitive registry key — reading it via
        // the managed-secret API must throw, not silently bypass encrypt-at-rest.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('branding.site_name');
        ipam_secret_get('branding.site_name');
    }

    public function testSecretSetRejectsNonManagedKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('branding.site_name');
        ipam_secret_set($this->db, 'branding.site_name', 'My IPAM');
    }

    public function testCorruptEnvelopeForManagedKeyFailsLoud(): void
    {
        // A well-formed IPAMSEC1 envelope (valid base64, long enough to clear
        // the nonce+MAC length floor) whose body is random bytes — not a valid
        // ciphertext for the current key, so the Poly1305 MAC check fails.
        $corrupt = IPAM_SECRET_ENVELOPE_PREFIX . base64_encode(random_bytes(40));
        $kc      = ipam_key_col();
        $st      = $this->db->prepare(
            "INSERT INTO settings (tenant_id, {$kc}, value, type) VALUES (NULL, :k, :v, 'string')"
        );
        $st->execute([':k' => 'smtp.auth_pass', ':v' => $corrupt]);
        ipam_setting_cache_bust();

        try {
            ipam_setting('smtp.auth_pass');
            self::fail('ipam_setting() must throw IpamSecretDecryptException on a corrupt managed envelope');
        } catch (IpamSecretDecryptException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString('smtp.auth_pass', $msg, 'message must name the key');
            self::assertStringNotContainsString($corrupt, $msg, 'message must not leak the envelope');
            self::assertStringNotContainsString(
                substr($corrupt, strlen(IPAM_SECRET_ENVELOPE_PREFIX)),
                $msg,
                'message must not leak the ciphertext body'
            );
        }
    }
}
