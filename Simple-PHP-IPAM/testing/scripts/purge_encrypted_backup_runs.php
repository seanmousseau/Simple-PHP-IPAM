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
