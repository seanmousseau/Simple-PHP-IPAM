<?php
/**
 * Test-only CLI helper: deletes the `backup_vault_key` row from settings,
 * leaving the install in the "no vault key configured" state that
 * step-up-vault-flow.spec.ts and step-up-oidc-only.spec.ts assume.
 *
 * Why: a spec that mints a vault key as part of its setup leaves an
 * install-global key behind for later specs (CR PR #1117 #8). Calling
 * this in afterEach restores the precondition. CLI-only.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

if (getenv('IPAM_ALLOW_DESTRUCTIVE_TEST_HELPERS') !== '1') {
    fwrite(STDERR, "Refusing to run: set IPAM_ALLOW_DESTRUCTIVE_TEST_HELPERS=1.\n");
    exit(1);
}

$configPath = __DIR__ . '/../../config.php';
if (!file_exists($configPath)) $configPath = '/var/www/html/config.php';
$config = require $configPath;

$libPath = __DIR__ . '/../../lib.php';
if (!file_exists($libPath)) $libPath = '/var/www/html/lib.php';
require_once $libPath;

$db = ipam_db($config);

$st = $db->prepare("DELETE FROM settings WHERE k = :k");
$st->execute([':k' => 'backup_vault_key']);
echo "deleted=" . $st->rowCount() . "\n";
