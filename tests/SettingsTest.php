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
        // v3.30.0 ADR-003 — ipam_setting() / ipam_setting_deprecated_keys()
        // now read config.php via the ipam_config() accessor, which memoises
        // $GLOBALS['config']. Tests that mutate $GLOBALS['config'] directly
        // must bump the generation counter so the accessor re-reads it.
        ipam_config_invalidate_cache();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db'], $GLOBALS['config']);
        // Clear the entire ipam_setting() cache, including tenant-keyed entries.
        // v3.30.0 #915 — ipam_setting_cache_storage() was split into the
        // get/set/clear trio; ipam_setting_cache_bust() delegates to _clear().
        ipam_setting_cache_bust();
    }

    // ------------------------------------------------------------------
    // Helper: seed a settings row directly (bypasses ipam_setting_set()).
    // ------------------------------------------------------------------
    private function seed(string $key, string $value, ?int $tenantId = null, string $type = 'string'): void
    {
        $this->db->prepare(
            "INSERT INTO settings (tenant_id, key, value, type) VALUES (:t, :k, :v, :ty)"
        )->execute([':t' => $tenantId, ':k' => $key, ':v' => $value, ':ty' => $type]);
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

    public function testRegistryDefaultWhenDbEmpty(): void
    {
        ipam_setting_cache_bust();
        $this->assertSame('Simple PHP IPAM', ipam_setting('branding.site_name'));
        $this->assertFalse(ipam_setting('oidc.enabled'));
        $this->assertSame('', ipam_setting('oidc.client_id'));
    }

    public function testDbValueOverridesRegistryDefault(): void
    {
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
        $this->db->exec("INSERT INTO settings (tenant_id, key, value, type) VALUES (NULL, 'test.obj', '{\"a\":1,\"b\":[2,3]}', 'json')");
        ipam_setting_cache_bust();

        $this->assertSame(['a' => 1, 'b' => [2, 3]], ipam_setting('test.obj'));
    }

    public function testInvalidJsonReturnsDefault(): void
    {
        $this->db->exec("INSERT INTO settings (tenant_id, key, value, type) VALUES (NULL, 'test.bad', 'not valid json', 'json')");
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

    public function testSettingSourceReportsDbOrDefault(): void
    {
        $this->assertSame('default', ipam_setting_source($this->db, 'branding.site_name'));

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

    public function testUniqueConstraintRejectsDuplicateTenantKey(): void
    {
        // uq_settings_tenant: two rows with the same non-null tenant_id + key must fail.
        $this->expectException(PDOException::class);
        $ins = $this->db->prepare("INSERT INTO settings (tenant_id, key, value, type) VALUES (:t, :k, :v, 'string')");
        $ins->execute([':t' => 5, ':k' => 'bogus.key', ':v' => 'x']);
        $ins->execute([':t' => 5, ':k' => 'bogus.key', ':v' => 'y']);
    }

    public function testUniqueConstraintRejectsDuplicateGlobalKey(): void
    {
        // uq_settings_global: two global rows (tenant_id IS NULL) with the same key must fail.
        // This is the partial unique index WHERE tenant_id IS NULL — the case that a plain
        // UNIQUE(tenant_id, key) would miss because SQL treats NULL as distinct from NULL.
        $this->expectException(PDOException::class);
        $ins = $this->db->prepare("INSERT INTO settings (tenant_id, key, value, type) VALUES (:t, :k, :v, 'string')");
        $ins->execute([':t' => null, ':k' => 'bogus.global', ':v' => 'x']);
        $ins->execute([':t' => null, ':k' => 'bogus.global', ':v' => 'y']);
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

    public function testOidcEnabledReadsThroughSettingsHelperFromDb(): void
    {
        // v2.7.0 #373: oidc_enabled() must stop reading $config['oidc'][...]
        // directly and instead go through ipam_setting() so DB-backed values
        // take precedence over config.php and the admin UI actually controls
        // the subsystem.
        $GLOBALS['config'] = [];
        $this->assertFalse(oidc_enabled([]), 'defaults to disabled');

        // Seed every required key in the DB and confirm it flips true.
        ipam_setting_set($this->db, 'oidc.enabled',       true);
        ipam_setting_set($this->db, 'oidc.client_id',     'cid');
        ipam_setting_set($this->db, 'oidc.client_secret', 'secret');
        ipam_setting_set($this->db, 'oidc.discovery_url', 'https://idp.example/');
        ipam_setting_set($this->db, 'oidc.redirect_uri',  'https://app.example/oidc_callback.php');
        ipam_setting_cache_bust();

        $this->assertTrue(oidc_enabled([]), 'enabled once every required DB key is set');

        // Flipping enabled to false in the DB must take effect even if
        // $config still has every OIDC key filled in. DB wins over config.
        $GLOBALS['config'] = [
            'oidc' => [
                'enabled'       => true,
                'client_id'     => 'cid',
                'client_secret' => 'secret',
                'discovery_url' => 'https://idp.example/',
                'redirect_uri'  => 'https://app.example/oidc_callback.php',
            ],
        ];
        ipam_setting_set($this->db, 'oidc.enabled', false);
        ipam_setting_cache_bust();
        $this->assertFalse(oidc_enabled([]), 'DB enabled=false overrides config enabled=true');
    }

    public function testAlertThresholdsFallbackChain(): void
    {
        // v2.7.0 #374: alerting thresholds must follow the DB → config.php →
        // registry default chain so an admin can override either way.
        $this->assertSame(80, ipam_setting('alert.util_warn_pct'), 'registry default');
        $this->assertSame(95, ipam_setting('alert.util_crit_pct'), 'registry default');
        $this->assertSame(3600, ipam_setting('alert.interval_seconds'), 'registry default');

        ipam_setting_set($this->db, 'alert.util_warn_pct', 60);
        ipam_setting_cache_bust();
        $this->assertSame(60, ipam_setting('alert.util_warn_pct'));
    }

    public function testOidcEnabledUsesDbSettings(): void
    {
        ipam_setting_cache_bust();
        $this->assertFalse(oidc_enabled([]), 'disabled by default');

        ipam_setting_set($this->db, 'oidc.enabled', true);
        ipam_setting_set($this->db, 'oidc.client_id', 'cid');
        ipam_setting_set($this->db, 'oidc.client_secret', 'secret');
        ipam_setting_set($this->db, 'oidc.discovery_url', 'https://idp.example/');
        ipam_setting_set($this->db, 'oidc.redirect_uri', 'https://app.example/oidc_callback.php');
        ipam_setting_cache_bust();
        $this->assertTrue(oidc_enabled([]));
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

    public function testDeprecatedKeysEmptyWhenNothingCustomised(): void
    {
        // v2.7.0 #376: a fresh install with empty $config and empty settings
        // table must not light up the banner for every registered key.
        $GLOBALS["config"] = [];
        ipam_setting_cache_bust();
        ipam_config_invalidate_cache();
        $this->assertSame([], ipam_setting_deprecated_keys());
    }

    public function testDeprecatedKeysSkipsDefaultValues(): void
    {
        // $config may carry every registered default literally (as v2.6.0
        // ipam_config_sync() does). None of those should be flagged — only
        // admin-customised values should.
        $GLOBALS['config'] = [
            'app_name'                => 'Simple PHP IPAM', // registry default
            'alert_util_warn_pct'     => 80,                // registry default
            'oidc'                    => ['enabled' => false],
        ];
        ipam_setting_cache_bust();
        $this->assertSame([], ipam_setting_deprecated_keys());
    }

    public function testDeprecatedKeysFlagsCustomisedConfigValues(): void
    {
        $GLOBALS['config'] = [
            'app_name'            => 'Acme IPAM',            // customised
            'alert_util_warn_pct' => 70,                     // customised
            'oidc'                => ['enabled' => true],    // customised
        ];
        ipam_setting_cache_bust();
        ipam_config_invalidate_cache();

        $deprecated = ipam_setting_deprecated_keys();
        $keys = array_column($deprecated, 'key');
        $this->assertContains('branding.site_name', $keys);
        $this->assertContains('alert.util_warn_pct', $keys);
        $this->assertContains('oidc.enabled', $keys);

        // Each row must carry the config_path and the current value so the
        // banner can render them.
        $row = null;
        foreach ($deprecated as $d) {
            if ($d['key'] === 'branding.site_name') { $row = $d; break; }
        }
        $this->assertNotNull($row);
        $this->assertSame('app_name', $row['config_path']);
        $this->assertSame('Acme IPAM', $row['current']);

        // Nested config_key flattens with '.' so the banner column stays
        // readable.
        foreach ($deprecated as $d) {
            if ($d['key'] === 'oidc.enabled') {
                $this->assertSame('oidc.enabled', $d['config_path']);
                break;
            }
        }
    }

    public function testDeprecatedKeysHidesRowsAlreadyInDb(): void
    {
        $GLOBALS['config'] = ['app_name' => 'Acme IPAM'];
        ipam_setting_set($this->db, 'branding.site_name', 'Acme IPAM');
        ipam_setting_cache_bust();
        $this->assertSame([], ipam_setting_deprecated_keys());
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

    // ------------------------------------------------------------------
    // v3.13.0 — tenant→global cascade tests (#711, #713)
    // ------------------------------------------------------------------

    public function testGlobalSettingReadWithNullTenantId(): void
    {
        $this->seed('foo.bar', 'global-value');
        $this->assertSame('global-value', ipam_setting('foo.bar', 'default', null));
    }

    public function testTenantSettingOverridesGlobal(): void
    {
        $this->seed('foo.bar', 'global-value', null);
        $this->seed('foo.bar', 'tenant-value', 42);
        $this->assertSame('tenant-value', ipam_setting('foo.bar', 'default', 42));
    }

    public function testFallsBackToGlobalWhenNoTenantRow(): void
    {
        $this->seed('foo.bar', 'global-value', null);
        $this->assertSame('global-value', ipam_setting('foo.bar', 'default', 99));
    }

    public function testFallsBackToCodeDefaultWhenNoRows(): void
    {
        $this->assertSame('my-default', ipam_setting('nonexistent.key', 'my-default', null));
    }

    public function testNullTenantIdReturnsGlobalOnly(): void
    {
        $this->seed('foo.bar', 'global-value', null);
        $this->seed('foo.bar', 'tenant-value', 1);
        // Calling with null tenantId must return global, NOT the tenant-1 row.
        $this->assertSame('global-value', ipam_setting('foo.bar', 'default', null));
    }

    public function testSetSettingWritesGlobalRow(): void
    {
        ipam_setting_set($this->db, 'foo.bar', 'new-value', null, null);
        $row = $this->db->query("SELECT value FROM settings WHERE key='foo.bar' AND tenant_id IS NULL")->fetch();
        $this->assertIsArray($row);
        $this->assertSame('new-value', $row['value']);
    }

    public function testSetSettingWritesTenantRow(): void
    {
        ipam_setting_set($this->db, 'foo.bar', 'tenant-new', null, 7);
        $row = $this->db->query("SELECT value, tenant_id FROM settings WHERE key='foo.bar' AND tenant_id=7")->fetch();
        $this->assertIsArray($row);
        $this->assertSame('tenant-new', $row['value']);
    }
}
