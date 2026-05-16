<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * v3.30.0 ADR-001 Task 5.2b — coverage of the 11-value logical-type dispatch
 * layer in lib/settings.php. For every logical type this exercises:
 *
 *   - storage-type mapping (ipam_setting_storage_type)
 *   - a value round-trip (ipam_setting_set -> ipam_setting)
 *   - a validation-reject case (ipam_setting_validate returns a string)
 *
 * plus the enum options-array resolution path (ipam_setting_options).
 *
 * Runs against in-memory SQLite, mirroring SettingsTest's fixture so it has no
 * dependency on the deployed app.
 */
class SettingDispatchTest extends TestCase
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

    public function testStorageTypeMapsAllElevenLogicalTypes(): void
    {
        $this->assertSame('int',  ipam_setting_storage_type('int'));
        $this->assertSame('bool', ipam_setting_storage_type('bool'));
        $this->assertSame('json', ipam_setting_storage_type('json'));
        foreach (['string', 'enum', 'secret', 'url', 'email', 'timezone', 'cidr', 'datetime'] as $lt) {
            $this->assertSame('string', ipam_setting_storage_type($lt), "$lt should store as string");
        }
    }

    /** @return iterable<string, array{0:string, 1:mixed, 2:mixed, 3:array<string,mixed>}> */
    public static function validationCases(): iterable
    {
        yield 'int'      => ['int', 42, 'not-a-number', ['min' => 0, 'max' => 100]];
        yield 'int-min'  => ['int', 5, -1, ['min' => 0]];
        yield 'int-max'  => ['int', 5, 999, ['max' => 100]];
        yield 'bool'     => ['bool', true, false, []];
        yield 'json'     => ['json', ['a' => 1], "\x00not json", []];
        yield 'enum'     => ['enum', 'admin', 'wizard', ['options' => ['admin' => 'A', 'readonly' => 'R']]];
        yield 'secret'   => ['secret', 'hunter2', null, []];
        yield 'string'   => ['string', 'free text', null, []];
        yield 'url'      => ['url', 'https://example.com/path', 'not a url', []];
        yield 'email'    => ['email', 'ops@example.com', 'not-an-email', []];
        yield 'timezone' => ['timezone', 'America/Toronto', 'Mars/Olympus', []];
        yield 'cidr'     => ['cidr', '10.0.0.0/8', '999.0.0.0/8', []];
        yield 'datetime' => ['datetime', '2026-05-15 12:00:00', 'not-a-date', []];
    }

    /**
     * @dataProvider validationCases
     * @param array<string,mixed> $def
     */
    public function testValidateAcceptsGoodValue(string $logicalType, mixed $good, mixed $bad, array $def): void
    {
        $this->assertTrue(
            ipam_setting_validate($logicalType, $good, $def),
            "$logicalType should accept a valid value"
        );
    }

    /**
     * @dataProvider validationCases
     * @param array<string,mixed> $def
     */
    public function testValidateRejectsBadValue(string $logicalType, mixed $good, mixed $bad, array $def): void
    {
        if (in_array($logicalType, ['bool', 'secret', 'string'], true)) {
            $this->assertTrue(ipam_setting_validate($logicalType, $bad, $def));
            return;
        }
        $result = ipam_setting_validate($logicalType, $bad, $def);
        $this->assertIsString($result, "$logicalType should reject the bad value with an error string");
        $this->assertNotSame('', $result);
    }

    public function testValidateEmptyStringAllowedForOptionalTypes(): void
    {
        foreach (['url', 'email', 'timezone', 'cidr', 'datetime', 'string', 'secret'] as $lt) {
            $this->assertTrue(ipam_setting_validate($lt, '', []), "$lt should treat '' as unset/valid");
        }
    }

    public function testValidateCidrMultilineChecksEachLine(): void
    {
        $def = ['multiline' => true];
        $this->assertTrue(ipam_setting_validate('cidr', "10.0.0.0/8\n192.168.0.0/16", $def));
        $this->assertIsString(ipam_setting_validate('cidr', "10.0.0.0/8\nbogus", $def));
    }

    public function testRoundTripIntSetting(): void
    {
        ipam_setting_set($this->db, 'security.session_idle_seconds', 600);
        $this->assertSame(600, ipam_setting('security.session_idle_seconds'));
    }

    public function testRoundTripBoolSetting(): void
    {
        ipam_setting_set($this->db, 'oidc.enabled', true);
        $this->assertTrue(ipam_setting('oidc.enabled'));
        ipam_setting_set($this->db, 'oidc.enabled', false);
        $this->assertFalse(ipam_setting('oidc.enabled'));
    }

    public function testRoundTripJsonSetting(): void
    {
        ipam_setting_set($this->db, 'alert.recipient_user_ids', [1, 2, 3]);
        $this->assertSame([1, 2, 3], ipam_setting('alert.recipient_user_ids'));
    }

    public function testRoundTripStringSetting(): void
    {
        ipam_setting_set($this->db, 'branding.site_name', 'Acme IPAM');
        $this->assertSame('Acme IPAM', ipam_setting('branding.site_name'));
    }

    public function testRoundTripEnumSetting(): void
    {
        ipam_setting_set($this->db, 'oidc.default_role', 'netops');
        $this->assertSame('netops', ipam_setting('oidc.default_role'));
    }

    public function testRoundTripSecretSetting(): void
    {
        ipam_setting_set($this->db, 'oidc.client_secret', 's3cr3t');
        $this->assertSame('s3cr3t', ipam_setting('oidc.client_secret'));
    }

    public function testRoundTripTimezoneSetting(): void
    {
        ipam_setting_set($this->db, 'branding.timezone', 'America/Toronto');
        $this->assertSame('America/Toronto', ipam_setting('branding.timezone'));
    }

    public function testRoundTripUrlSetting(): void
    {
        ipam_setting_set($this->db, 'oidc.discovery_url', 'https://idp.example.com');
        $this->assertSame('https://idp.example.com', ipam_setting('oidc.discovery_url'));
    }

    public function testRoundTripEmailSetting(): void
    {
        ipam_setting_set($this->db, 'smtp.from_address', 'ipam@example.com');
        $this->assertSame('ipam@example.com', ipam_setting('smtp.from_address'));
    }

    public function testRoundTripCidrSetting(): void
    {
        ipam_setting_set($this->db, 'security.proxy_trust_cidrs', "10.0.0.0/8\n192.168.0.0/16");
        $this->assertSame("10.0.0.0/8\n192.168.0.0/16", ipam_setting('security.proxy_trust_cidrs'));
    }

    public function testRoundTripDatetimeSettingViaSyntheticKey(): void
    {
        // No registry setting uses the `datetime` logical type today, so this
        // test cannot round-trip one through ipam_setting_set/ipam_setting.
        // The unregistered key below round-trips via infer_type (string); the
        // two assertions after it directly exercise the `datetime` dispatch:
        // its storage type is `string` and validate() accepts a datetime value.
        ipam_setting_set($this->db, 'synthetic.datetime_key', '2026-05-15 09:00:00');
        $this->assertSame('2026-05-15 09:00:00', ipam_setting('synthetic.datetime_key'));
        $this->assertSame('string', ipam_setting_storage_type('datetime'));
        $this->assertTrue(ipam_setting_validate('datetime', '2026-05-15 09:00:00', []));
    }

    public function testEnumOptionsResolutionLiteralArray(): void
    {
        $defs = ipam_setting_definitions();
        $this->assertArrayHasKey('oidc.default_role', $defs);
        $opts = ipam_setting_options($defs['oidc.default_role']);
        $this->assertIsArray($opts);
        $this->assertArrayHasKey('netops', $opts);
        $this->assertArrayHasKey('admin', $opts);
        $this->assertArrayHasKey('readonly', $opts);
    }

    public function testEnumOptionsResolutionTimezoneSentinel(): void
    {
        $opts = ipam_setting_options(['options' => '@timezone']);
        $this->assertIsArray($opts);
        $this->assertArrayHasKey('UTC', $opts);
    }

    public function testEnumValidationUsesResolvedOptions(): void
    {
        $def = ['options' => ['readonly' => 'R', 'netops' => 'N', 'admin' => 'A']];
        $this->assertTrue(ipam_setting_validate('enum', 'admin', $def));
        $this->assertIsString(ipam_setting_validate('enum', 'superuser', $def));
    }
}
