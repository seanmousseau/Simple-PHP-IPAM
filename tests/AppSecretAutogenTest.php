<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib.php';
require_once __DIR__ . '/../Simple-PHP-IPAM/lib/app_secret.php';

/**
 * v3.28.2 #1178 — `ipam_app_secret()` mirrors `ipam_bootstrap_key()`'s
 * lazy-auto-gen lifecycle:
 *
 *   - existing non-empty $config['app_secret'] → return verbatim, no write.
 *   - blank → generate base64(random_bytes(32)), persist into config.php,
 *             and surface an actionable RuntimeException if the file is
 *             read-only (mirrors ipam_bootstrap_key()'s "don't silently
 *             regenerate on every request" behaviour).
 *
 * The helper takes a $configPathOverride seam so tests can point at a
 * sandbox config without touching the live repo file.
 */
final class AppSecretAutogenTest extends TestCase
{
    private string $tmpDir = '';
    private string $configPath = '';

    /** @var mixed */
    private $previousGlobalConfig = null;
    private bool $hadGlobalConfig = false;

    protected function setUp(): void
    {
        $this->hadGlobalConfig      = array_key_exists('config', $GLOBALS);
        $this->previousGlobalConfig = $this->hadGlobalConfig ? $GLOBALS['config'] : null;

        $base = sys_get_temp_dir() . '/ipam_app_secret_test_' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0o700, true) && !is_dir($base)) {
            throw new RuntimeException('failed to create temp dir: ' . $base);
        }
        $this->tmpDir = $base;
        $this->configPath = $base . '/config.php';
    }

    protected function tearDown(): void
    {
        if ($this->configPath !== '' && is_file($this->configPath)) {
            @chmod($this->configPath, 0o600);
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- test-scope path under sys_get_temp_dir(), not user input
            @unlink($this->configPath);
        }
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            @chmod($this->tmpDir, 0o700);
            @rmdir($this->tmpDir);
        }
        if ($this->hadGlobalConfig) {
            $GLOBALS['config'] = $this->previousGlobalConfig;
        } else {
            unset($GLOBALS['config']);
        }
    }

    private function writeConfig(array $cfg): void
    {
        // Hand-rolled emitter that produces short-array syntax ending with
        // `];` — ipam_config_inject_or_replace_key()'s "no existing key"
        // injection branch greps for the last `];` to find the top-level
        // array close, so var_export()'s `array (...)` form won't do.
        $lines = ["<?php", "return ["];
        foreach ($cfg as $k => $v) {
            $lines[] = "    '" . $k . "' => " . var_export($v, true) . ",";
        }
        $lines[] = "];";
        $lines[] = "";
        file_put_contents($this->configPath, implode("\n", $lines));
        chmod($this->configPath, 0o600);
    }

    public function testReturnsExistingAppSecret(): void
    {
        $preset = 'preset-value-not-base64-and-thats-fine';
        $this->writeConfig(['session' => ['cookie' => 'ipam'], 'app_secret' => $preset]);
        $GLOBALS['config'] = ['session' => ['cookie' => 'ipam'], 'app_secret' => $preset];

        $before = file_get_contents($this->configPath);
        $result = ipam_app_secret($this->configPath);
        $after  = file_get_contents($this->configPath);

        $this->assertSame($preset, $result);
        $this->assertSame($before, $after, 'config.php must not be rewritten when app_secret already set');
    }

    public function testAutoGeneratesAndPersistsWhenBlank(): void
    {
        $this->writeConfig(['session' => ['cookie' => 'ipam']]);
        $GLOBALS['config'] = ['session' => ['cookie' => 'ipam']];

        $result = ipam_app_secret($this->configPath);

        $this->assertNotSame('', $result);
        $this->assertIsString($result);

        // Re-read the file to confirm persistence.
        /** @var array<string,mixed> $persisted */
        $persisted = include $this->configPath;
        $this->assertArrayHasKey('app_secret', $persisted);
        $this->assertSame($result, $persisted['app_secret']);
        $this->assertNotEmpty($persisted['app_secret']);
    }

    /**
     * Integration check: when the auto-gen path fires and a real PDO is
     * available via `global $db`, ipam_install_key_announce_record() must
     * write the audit row and flip the install_keys_announce.app_secret
     * flag to '1'. Caught a load-bearing bug in earlier Chunk A where the
     * helper guarded on a non-existent `db_connect()` and silently
     * no-op'd the entire announce pipeline.
     */
    public function testAutoGenAnnouncesViaGlobalDb(): void
    {
        $this->writeConfig(['session' => ['cookie' => 'ipam']]);
        $GLOBALS['config'] = ['session' => ['cookie' => 'ipam']];

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $ddl = [
            "CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                user_id INTEGER,
                username TEXT,
                action TEXT NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id INTEGER,
                details TEXT NOT NULL DEFAULT '',
                ip TEXT NOT NULL DEFAULT '',
                user_agent TEXT NOT NULL DEFAULT ''
            )",
            "CREATE TABLE settings (
                tenant_id INTEGER,
                key TEXT NOT NULL,
                value TEXT NOT NULL DEFAULT '',
                type TEXT NOT NULL DEFAULT 'string',
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by INTEGER
            )",
            "CREATE UNIQUE INDEX uq_settings_global ON settings(key) WHERE tenant_id IS NULL",
        ];
        foreach ($ddl as $stmt) {
            $pdo->prepare($stmt)->execute();
        }

        $prevDb = $GLOBALS['db'] ?? null;
        $GLOBALS['db'] = $pdo;
        try {
            ipam_app_secret($this->configPath);

            $auditCount = $pdo->query(
                "SELECT COUNT(*) FROM audit_log WHERE action = 'app_secret_autogenerated' AND entity_type = 'install_key'"
            )->fetchColumn();
            $this->assertSame(1, (int) $auditCount, 'auto-gen must record exactly one audit row');

            $flag = $pdo->query(
                "SELECT value FROM settings WHERE key = 'install_keys_announce.app_secret'"
            )->fetchColumn();
            $this->assertSame('1', $flag, 'auto-gen must set the announce flag to 1');
        } finally {
            $GLOBALS['db'] = $prevDb;
            if ($prevDb === null) {
                unset($GLOBALS['db']);
            }
        }
    }

    public function testRefusesToRegenerateWhenConfigUnwritable(): void
    {
        $this->writeConfig(['session' => ['cookie' => 'ipam']]);
        $GLOBALS['config'] = ['session' => ['cookie' => 'ipam']];
        chmod($this->configPath, 0o400);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/app_secret auto-generation/i');

        try {
            ipam_app_secret($this->configPath);
        } finally {
            // Restore for tearDown's unlink.
            chmod($this->configPath, 0o600);
        }
    }
}
