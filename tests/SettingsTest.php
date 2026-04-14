<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the v2.6.0 settings subsystem: ipam_setting() / ipam_setting_set()
 * and their encoding/decoding helpers. All tests run against an in-memory SQLite
 * database so they have no dependency on the deployed app.
 */
class SettingsTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec("
            CREATE TABLE settings (
                key        TEXT PRIMARY KEY,
                value      TEXT,
                type       TEXT NOT NULL DEFAULT 'string'
                           CHECK(type IN ('string','int','bool','json')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by INTEGER
            )
        ");
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
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db'], $GLOBALS['config']);
        ipam_setting_cache_bust();
    }

    public function testRegistryIsNonEmptyAndWellShaped(): void
    {
        $defs = ipam_setting_definitions();
        $this->assertNotEmpty($defs);
        foreach ($defs as $key => $def) {
            $this->assertIsString($key);
            $this->assertArrayHasKey('type', $def);
            $this->assertArrayHasKey('group', $def);
            $this->assertArrayHasKey('default', $def);
            $this->assertContains($def['type'], ['string', 'int', 'bool', 'json']);
        }
    }

    public function testReadFallsBackToRegistryDefaultWhenDbAndConfigEmpty(): void
    {
        $this->assertSame('Simple PHP IPAM', ipam_setting('branding.site_name'));
        $this->assertSame(1800, ipam_setting('security.session_idle_seconds'));
        $this->assertFalse(ipam_setting('oidc.enabled'));
    }

    public function testReadFallsBackToConfigWhenDbEmpty(): void
    {
        $GLOBALS['config'] = [
            'app_name' => 'Acme IPAM',
            'oidc'     => ['enabled' => true, 'client_id' => 'abc123'],
        ];
        ipam_setting_cache_bust();

        $this->assertSame('Acme IPAM', ipam_setting('branding.site_name'));
        $this->assertTrue(ipam_setting('oidc.enabled'));
        $this->assertSame('abc123', ipam_setting('oidc.client_id'));
    }

    public function testDbValueOverridesConfigFallback(): void
    {
        $GLOBALS['config'] = ['app_name' => 'From Config'];
        ipam_setting_set($this->db, 'branding.site_name', 'From DB');
        ipam_setting_cache_bust();

        $this->assertSame('From DB', ipam_setting('branding.site_name'));
    }

    public function testStringTypeRoundTrip(): void
    {
        ipam_setting_set($this->db, 'branding.site_name', 'Hello, world');
        ipam_setting_cache_bust();
        $this->assertSame('Hello, world', ipam_setting('branding.site_name'));
    }

    public function testIntTypeRoundTrip(): void
    {
        ipam_setting_set($this->db, 'security.session_idle_seconds', 600);
        ipam_setting_cache_bust();
        $this->assertSame(600, ipam_setting('security.session_idle_seconds'));
    }

    public function testBoolTypeRoundTrip(): void
    {
        ipam_setting_set($this->db, 'oidc.enabled', true);
        ipam_setting_cache_bust();
        $this->assertTrue(ipam_setting('oidc.enabled'));

        ipam_setting_set($this->db, 'oidc.enabled', false);
        ipam_setting_cache_bust();
        $this->assertFalse(ipam_setting('oidc.enabled'));
    }

    public function testJsonTypeRoundTrip(): void
    {
        $this->db->exec("INSERT INTO settings (key, value, type) VALUES ('test.obj', '{\"a\":1,\"b\":[2,3]}', 'json')");
        ipam_setting_cache_bust();

        $this->assertSame(['a' => 1, 'b' => [2, 3]], ipam_setting('test.obj'));
    }

    public function testInvalidJsonReturnsDefault(): void
    {
        $this->db->exec("INSERT INTO settings (key, value, type) VALUES ('test.bad', 'not valid json', 'json')");
        ipam_setting_cache_bust();

        $prev = ini_set('error_log', '/dev/null');
        $result = ipam_setting('test.bad', ['fallback' => true]);
        if ($prev !== false) ini_set('error_log', $prev);

        $this->assertSame(['fallback' => true], $result);
    }

    public function testWriteProducesExactlyOneAuditEntryPerCall(): void
    {
        ipam_setting_set($this->db, 'branding.site_name', 'First');
        ipam_setting_set($this->db, 'branding.site_name', 'Second');

        $count = (int)$this->db->query("SELECT count(*) FROM audit_log WHERE action = 'setting.update'")->fetchColumn();
        $this->assertSame(2, $count);

        $row = $this->db->query("SELECT details FROM audit_log WHERE action = 'setting.update' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertIsArray($row);
        $details = json_decode((string)$row['details'], true);
        $this->assertIsArray($details);
        $this->assertSame('branding.site_name', $details['key']);
        $this->assertSame('First', $details['old']);
        $this->assertSame('Second', $details['new']);
    }

    public function testSensitiveKeysAreMaskedInAuditDetails(): void
    {
        ipam_setting_set($this->db, 'oidc.client_secret', 'super-secret-123');

        $row = $this->db->query("SELECT details FROM audit_log WHERE action = 'setting.update' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertIsArray($row);
        $details = json_decode((string)$row['details'], true);
        $this->assertIsArray($details);
        $this->assertSame('***', $details['new']);
        $this->assertStringNotContainsString('super-secret-123', (string)$row['details']);
    }

    public function testCacheIsBustedAfterWrite(): void
    {
        ipam_setting_set($this->db, 'branding.site_name', 'Before');
        $this->assertSame('Before', ipam_setting('branding.site_name'));

        $this->db->exec("UPDATE settings SET value = 'After' WHERE key = 'branding.site_name'");
        $this->assertSame('Before', ipam_setting('branding.site_name'), 'Cache should still hold previous value');

        ipam_setting_cache_bust('branding.site_name');
        $this->assertSame('After', ipam_setting('branding.site_name'));
    }

    public function testSettingSourceReportsDbConfigOrDefault(): void
    {
        $this->assertSame('default', ipam_setting_source($this->db, 'branding.site_name'));

        $GLOBALS['config'] = ['app_name' => 'Configged'];
        $this->assertSame('config', ipam_setting_source($this->db, 'branding.site_name'));

        ipam_setting_set($this->db, 'branding.site_name', 'From DB');
        $this->assertSame('db', ipam_setting_source($this->db, 'branding.site_name'));
    }

    public function testEncodeAndDecodeHelpersForEachType(): void
    {
        $this->assertSame('1', ipam_setting_encode(true, 'bool'));
        $this->assertSame('0', ipam_setting_encode(false, 'bool'));
        $this->assertSame('42', ipam_setting_encode(42, 'int'));
        $this->assertSame('hello', ipam_setting_encode('hello', 'string'));
        $this->assertSame('[1,2,3]', ipam_setting_encode([1, 2, 3], 'json'));

        $this->assertTrue(ipam_setting_decode('1', 'bool', false));
        $this->assertFalse(ipam_setting_decode('0', 'bool', true));
        $this->assertSame(42, ipam_setting_decode('42', 'int', 0));
        $this->assertSame([1, 2, 3], ipam_setting_decode('[1,2,3]', 'json', null));
    }

    public function testConfigFallbackHandlesFlatAndNestedKeys(): void
    {
        $config = [
            'flat'   => 'flat-val',
            'parent' => ['child' => 'nested-val'],
        ];
        $this->assertSame('flat-val',   ipam_setting_config_fallback($config, 'flat'));
        $this->assertSame('nested-val', ipam_setting_config_fallback($config, ['parent', 'child']));
        $this->assertNull(ipam_setting_config_fallback($config, 'missing'));
        $this->assertNull(ipam_setting_config_fallback($config, ['parent', 'missing']));
        $this->assertNull(ipam_setting_config_fallback($config, null));
    }

    public function testCheckConstraintRejectsUnsupportedTypeValue(): void
    {
        $this->expectException(PDOException::class);
        $ins = $this->db->prepare("INSERT INTO settings (key, value, type) VALUES (:k, :v, :t)");
        $ins->execute([':k' => 'bogus.key', ':v' => 'x', ':t' => 'date']);
    }

    public function testRegistryAdvertisesMinMaxOnKnownIntKeys(): void
    {
        // These keys must stay bounded in the registry so settings.php rejects
        // negative / out-of-range values that would break runtime behaviour.
        // See CodeRabbit review on PR #437.
        $defs = ipam_setting_definitions();
        foreach ([
            'security.session_idle_seconds',
            'security.login_max_attempts',
            'security.login_lockout_seconds',
            'alert.util_warn_pct',
            'alert.util_crit_pct',
            'alert.interval_seconds',
            'update_check.ttl_seconds',
        ] as $k) {
            $this->assertArrayHasKey($k, $defs, "registry missing {$k}");
            $this->assertArrayHasKey('min', $defs[$k], "{$k} should advertise a min");
        }
        // Percent thresholds must also advertise a max.
        $this->assertArrayHasKey('max', $defs['alert.util_warn_pct']);
        $this->assertArrayHasKey('max', $defs['alert.util_crit_pct']);
    }

    public function testLoginProtectionMethodAdvertisesStaticOptions(): void
    {
        // v2.7.0 #442: login_protection.method must declare a fixed option set
        // so settings.php can render a dropdown and reject out-of-set values.
        $defs = ipam_setting_definitions();
        $this->assertArrayHasKey('login_protection.method', $defs);
        $resolved = ipam_setting_options($defs['login_protection.method']);
        $this->assertIsArray($resolved);
        $this->assertArrayHasKey('', $resolved, "'' (off) must be one of the options");
        $this->assertArrayHasKey('recaptcha', $resolved);
        $this->assertArrayHasKey('turnstile', $resolved);
        $this->assertArrayHasKey('friendly_captcha', $resolved);
    }

    public function testBrandingTimezoneResolvesToPhpIdentifiers(): void
    {
        // v2.7.0 #442: branding.timezone uses the @timezone sentinel which
        // must resolve to PHP's zoneinfo list, including a few stable IDs.
        $defs = ipam_setting_definitions();
        $this->assertArrayHasKey('branding.timezone', $defs);
        $resolved = ipam_setting_options($defs['branding.timezone']);
        $this->assertIsArray($resolved);
        $this->assertArrayHasKey('UTC', $resolved);
        $this->assertArrayHasKey('America/Toronto', $resolved);
        $this->assertArrayHasKey('Europe/London', $resolved);
        // Values should be string labels (we default to value = label for the
        // timezone sentinel since zoneinfo has no human-friendly labels).
        $this->assertSame('UTC', $resolved['UTC']);
    }

    public function testSettingOptionsReturnsNullForFreeFormKeys(): void
    {
        // Settings without an explicit `options` entry must render as free
        // text — the resolver is the source of truth for "is this a dropdown".
        $defs = ipam_setting_definitions();
        $this->assertNull(ipam_setting_options($defs['branding.site_name']));
        $this->assertNull(ipam_setting_options($defs['oidc.client_id']));
    }

    public function testSettingOptionsAcceptsStaticArrayAndCallable(): void
    {
        $static = ipam_setting_options([
            'options' => ['a' => 'Alpha', 'b' => 'Beta'],
        ]);
        $this->assertSame(['a' => 'Alpha', 'b' => 'Beta'], $static);

        $callable = ipam_setting_options([
            'options' => fn(): array => ['x' => 'Xray', 'y' => 'Yankee'],
        ]);
        $this->assertSame(['x' => 'Xray', 'y' => 'Yankee'], $callable);
    }

    public function testCallerDefaultIgnoredForRegisteredKey(): void
    {
        // Registry default must win; caller-supplied $default only matters for
        // unregistered keys. Regression guard for the inverted precedence bug.
        $this->assertSame(
            'Simple PHP IPAM',
            ipam_setting('branding.site_name', 'caller-override')
        );
        ipam_setting_cache_bust('branding.site_name');

        // Unregistered key still honours the caller default.
        $this->assertSame('caller-default', ipam_setting('bogus.unregistered', 'caller-default'));
    }
}
