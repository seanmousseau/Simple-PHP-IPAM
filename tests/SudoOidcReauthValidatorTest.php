<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Bug Z (Pass A 2026-05-08, v3.27.1) — ipam_sudo_oidc_reauth_redirect_url
 * must accept relative-path callers.
 *
 * Pre-fix the validator at lib/auth_step_up.php:480-489 required
 * `$returnPath[0] === '/'` (absolute path). Every sudo-class handler
 * passes a relative path:
 *   - api_keys.php           → 'api_keys.php'
 *   - change_password.php    → 'change_password.php' (and #fragment variants)
 *   - db_tools.php           → 'db_tools.php'
 *   - settings.php           → 'settings.php?tab=...#group-step_up'
 *   - lib/backup_admin_destinations.php → '$redirectBase' (relative)
 * So every sudo-class action via OIDC re-auth fell back to the
 * hardcoded 'destinations.php'. After the IdP round-trip, the
 * operator landed on destinations.php and the original action's
 * POST was lost — silent action drop. Pass A reproduced this end-to-end
 * twice (vault_set + apikey.create).
 *
 * The mirror validator at step_up.php:30-49 ALREADY accepts relative
 * paths cleanly. The fix mirrors that logic in lib/auth_step_up.php.
 *
 * Tests are pure unit — exercise the validator's input/output via
 * its public side-effect ($_SESSION['sudo_oidc_reauth_return']). The
 * function returns '' when OIDC is unconfigured; we install the bare
 * minimum config needed to get past that guard, then the test can
 * read the stashed return path back from the session.
 */
final class SudoOidcReauthValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        // Minimal OIDC config so ipam_sudo_oidc_configured() returns true.
        $GLOBALS['config'] = [];
        // We need the settings table for ipam_setting() reads inside
        // ipam_sudo_oidc_configured(). The simplest harness: stub a
        // PDO and set a global so callers downstream can read.
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            "CREATE TABLE settings (tenant_id INTEGER, key TEXT NOT NULL, value TEXT, "
            . "type TEXT NOT NULL DEFAULT 'string', updated_at TEXT NOT NULL DEFAULT (datetime('now')), "
            . "updated_by INTEGER)"
        );
        $db->exec("CREATE UNIQUE INDEX uq_settings_global ON settings (key) WHERE tenant_id IS NULL");
        $db->exec("INSERT INTO settings (tenant_id, key, value) VALUES (NULL, 'oidc.enabled', '1')");
        $db->exec("INSERT INTO settings (tenant_id, key, value) VALUES (NULL, 'oidc.client_id', 'rt-test')");
        $db->exec("INSERT INTO settings (tenant_id, key, value) VALUES (NULL, 'oidc.client_secret', 'rt-test')");
        $db->exec("INSERT INTO settings (tenant_id, key, value) VALUES (NULL, 'oidc.discovery_url', 'https://example.test/.well-known/openid-configuration')");
        $db->exec("INSERT INTO settings (tenant_id, key, value) VALUES (NULL, 'oidc.redirect_uri', 'https://example.test/oidc_callback.php')");
        $GLOBALS['db'] = $db;
        ipam_setting_cache_bust();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($GLOBALS['db'], $GLOBALS['config']);
        ipam_setting_cache_bust();
    }

    /**
     * @dataProvider providerValidReturnPaths
     */
    public function testValidatorAcceptsValidRelativePaths(string $input): void
    {
        $url = ipam_sudo_oidc_reauth_redirect_url($input);
        $this->assertNotSame('', $url, 'OIDC must be configured for this test (setUp)');
        $stashed = $_SESSION['sudo_oidc_reauth_return'] ?? null;
        $this->assertSame(
            $input,
            $stashed,
            "Validator must accept relative path '$input' — Bug Z regression"
        );
    }

    /**
     * @return iterable<array{string}>
     */
    public static function providerValidReturnPaths(): iterable
    {
        // The actual paths every sudo-class handler currently passes:
        yield 'api_keys' => ['api_keys.php'];
        yield 'change_password' => ['change_password.php'];
        yield 'change_password fragment' => ['change_password.php#email-otp'];
        yield 'db_tools' => ['db_tools.php'];
        yield 'settings policy' => ['settings.php?tab=authentication#group-step_up'];
        yield 'backup admin destinations' => ['backup_admin.php?tab=destinations'];
        yield 'absolute install path' => ['/destinations.php'];
        yield 'absolute path with query' => ['/backup_admin.php?tab=destinations'];
    }

    /**
     * @dataProvider providerInvalidReturnPaths
     */
    public function testValidatorRejectsOpenRedirectAttempts(string $input): void
    {
        ipam_sudo_oidc_reauth_redirect_url($input);
        $stashed = $_SESSION['sudo_oidc_reauth_return'] ?? null;
        $this->assertSame(
            'destinations.php',
            $stashed,
            "Validator must reject '$input' (open-redirect attempt) and fall back to destinations.php"
        );
    }

    /**
     * @return iterable<array{string}>
     */
    public static function providerInvalidReturnPaths(): iterable
    {
        yield 'protocol-relative double-slash' => ['//evil.example/path'];
        yield 'http scheme' => ['http://evil.example'];
        yield 'https scheme' => ['https://evil.example'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'parent traversal' => ['../etc/passwd'];
        yield 'embedded traversal' => ['settings.php?x=../../../etc/passwd'];
        yield 'backslash trick' => ['\\\\evil.example\\path'];
        yield 'CR injection' => ["page.php\r\nLocation: //evil"];
        yield 'LF injection' => ["page.php\nSet-Cookie: x=y"];
        yield 'tab injection' => ["page.php\tLocation: //evil"];
        yield 'oversize string' => [str_repeat('a', 1025)];
        yield 'empty' => [''];
    }

    public function testReturnedUrlPointsToOidcLoginPhp(): void
    {
        $url = ipam_sudo_oidc_reauth_redirect_url('api_keys.php');
        $this->assertStringStartsWith('oidc_login.php?prompt=login&sudo=', $url);
        // Sudo state must be a hex string (32 chars from bin2hex(random_bytes(16))).
        $this->assertMatchesRegularExpression('/sudo=[a-f0-9]{32}$/', $url);
        $stashedState = $_SESSION['sudo_oidc_reauth_state'] ?? null;
        $this->assertIsString($stashedState);
        $this->assertNotEmpty($stashedState);
    }
}
