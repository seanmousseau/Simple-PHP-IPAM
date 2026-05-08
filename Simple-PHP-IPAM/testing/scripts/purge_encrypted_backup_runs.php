<?php
/**
 * Test-only CLI helper: deletes every backup_runs row whose encryption_mode
 * is anything other than 'unencrypted'.
 *
 * Why: vault_set + generate is gated by `has_encrypted_runs` (CR #1100, see
 * lib/backup_admin_destinations.php). Earlier specs in the suite — chiefly
 * backup-integration.spec.ts — leave encrypted rows behind, which then
 * blocks step-up-vault-flow + step-up-oidc-only's vault_set+generate path
 * with "Cannot generate a new vault key while encrypted backups exist."
 *
 * Tests that need a clean "no encrypted runs" precondition call this in
 * their beforeEach. CLI-only; nothing in production code path runs this.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

// CR PR #1117 #6: defence-in-depth. CLI-only is already a strong barrier in
// the deployed shape (the script lives under the webroot's testing/scripts
// directory, which Apache serves with a 403 .htaccess in production), but a
// stray `php testing/scripts/purge_encrypted_backup_runs.php` from a host
// shell against a real config would silently delete production backup
// metadata. Require an explicit env opt-in for destructive test helpers.
if (getenv('IPAM_ALLOW_DESTRUCTIVE_TEST_HELPERS') !== '1') {
    fwrite(STDERR, "Refusing to run: set IPAM_ALLOW_DESTRUCTIVE_TEST_HELPERS=1 for test runs.\n");
    exit(1);
}

$configPath = __DIR__ . '/../../config.php';
if (!file_exists($configPath)) $configPath = '/var/www/html/config.php';
$config = require $configPath;

$libPath = __DIR__ . '/../../lib.php';
if (!file_exists($libPath)) $libPath = '/var/www/html/lib.php';
require_once $libPath;

$db = ipam_db($config);

$st = $db->prepare("DELETE FROM backup_runs WHERE encryption_mode != 'unencrypted'");
$st->execute();
echo "deleted=" . $st->rowCount() . "\n";
