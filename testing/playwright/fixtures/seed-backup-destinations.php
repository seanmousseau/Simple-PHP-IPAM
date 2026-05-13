<?php
declare(strict_types=1);

/**
 * CLI fixture: seed two backup_destinations rows for the Playwright integration
 * spec (#789). Idempotent — safe to re-run.
 *
 *   ci-minio (s3)   → http://minio:9000 (sidecar), bucket from
 *                     IPAM_TEST_MINIO_BUCKET (default ipam-backups), prefix ci/
 *   ci-local (local) → on-disk at data/tmp/ipam-backup-ci-local
 *   ci-sftp  (sftp)  → sftp://sftp:2222 (linuxserver/openssh-server sidecar),
 *                      key auth from the committed ed25519 fixture; remote
 *                      path /config/backups
 *
 * Credentials come from the bootstrap-app.sh sidecar env vars:
 *   IPAM_TEST_MINIO_USER   (default testkey)
 *   IPAM_TEST_MINIO_PASS   (default testsecret123)
 *   IPAM_TEST_MINIO_BUCKET (default ipam-backups)
 *   IPAM_TEST_SFTP_USER    (default ipam)
 *   IPAM_TEST_SFTP_PASS    (default ipam-sftp-fixture-pass)
 *   IPAM_TEST_SFTP_DIR     (default /config/backups)
 *   IPAM_TEST_SFTP_KEYFILE (default /tmp/ipam_pw_sftp — mounted by bootstrap)
 *
 * The `encrypt` column is 0 on all rows, but the orchestrator drives off
 * `default_encryption_mode` (schema default `'stored'`), and remote
 * destinations are force-`'stored'` regardless (#851). So the encrypted-
 * backup specs (backup-integration, backup_run_now, backups admin Run-now)
 * actually exercise the IPAMBKP3 stored-mode path — and since v3.28.0 #1164
 * removed the `app_secret` write fallback, that requires a configured
 * `backup_vault_key`. We seed one below so those specs work end-to-end.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

// Resolve the IPAM web root regardless of where this fixture is mounted.
// In the playwright bootstrap container the app lives at /var/www/html.
$appRoot = getenv('IPAM_APP_ROOT') ?: '/var/www/html';
require $appRoot . '/init.php';
/** @var \PDO $db */

$minioUser   = getenv('IPAM_TEST_MINIO_USER')   ?: 'testkey';
$minioPass   = getenv('IPAM_TEST_MINIO_PASS')   ?: 'testsecret123';
$minioBucket = getenv('IPAM_TEST_MINIO_BUCKET') ?: 'ipam-backups';

$sftpUser    = getenv('IPAM_TEST_SFTP_USER')    ?: 'ipam';
$sftpPass    = getenv('IPAM_TEST_SFTP_PASS')    ?: 'ipam-sftp-fixture-pass';
$sftpDir     = getenv('IPAM_TEST_SFTP_DIR')     ?: '/config/backups';
$sftpKeyfile = getenv('IPAM_TEST_SFTP_KEYFILE') ?: '/tmp/ipam_pw_sftp';
$sftpKeyPem  = is_readable($sftpKeyfile) ? (string) file_get_contents($sftpKeyfile) : '';
if ($sftpKeyPem === '') {
    fwrite(STDERR, "seed-backup-destinations: SFTP key fixture missing at $sftpKeyfile\n");
    // Fail soft — bootstrap may not have mounted the fixture (e.g. older
    // local runs before #833). Fall back to password auth so the row still
    // works for spec coverage; the SFTP integration spec exercises both.
}

$localPath = $appRoot . '/data/tmp/ipam-backup-ci-local';
if (!is_dir($localPath)) {
    if (!@mkdir($localPath, 0o775, true) && !is_dir($localPath)) {
        fwrite(STDERR, "seed-backup-destinations: failed to create $localPath\n");
        exit(1);
    }
}

$rows = [
    [
        'name'   => 'ci-minio',
        'type'   => 's3',
        'config' => [
            'endpoint'   => 'http://minio:9000',
            'region'     => 'us-east-1',
            'bucket'     => $minioBucket,
            'prefix'     => 'ci/',
            'access_key' => $minioUser,
            'secret_key' => $minioPass,
        ],
    ],
    [
        'name'   => 'ci-local',
        'type'   => 'local',
        'config' => [
            'path' => $localPath,
        ],
    ],
    [
        'name'   => 'ci-sftp',
        'type'   => 'sftp',
        'config' => array_filter([
            'host'        => 'sftp',
            'port'        => 2222,
            'username'    => $sftpUser,
            'password'    => $sftpPass,
            'private_key' => $sftpKeyPem !== '' ? $sftpKeyPem : null,
            'remote_path' => $sftpDir,
        ], static fn($v) => $v !== null),
    ],
];

// v3.28.0 #1164: encrypted scheduled backups now require a backup vault key
// (the legacy app_secret write fallback was removed). Seed one — wrapped
// under this install's bootstrap_key — so the encrypted-backup specs can
// run end-to-end. Idempotent; left in place if already present.
if (function_exists('ipam_setting') && function_exists('ipam_vault_wrap') && function_exists('ipam_bootstrap_key') && defined('BACKUP_VAULT_KEY_LEN')) {
    $existingVk = ipam_setting('backup_vault_key');
    if (!is_string($existingVk) || $existingVk === '') {
        try {
            $rawVk = random_bytes(BACKUP_VAULT_KEY_LEN);
            $env   = ipam_vault_wrap($rawVk, ipam_bootstrap_key());
            ipam_setting_set($db, 'backup_vault_key', $env);
            echo "seed-backup-destinations: seeded backup_vault_key\n";
        } catch (Throwable $vkErr) {
            fwrite(STDERR, "seed-backup-destinations: backup_vault_key seed failed — encrypted-backup specs may fail: {$vkErr->getMessage()}\n");
        }
    } else {
        echo "seed-backup-destinations: backup_vault_key already present\n";
    }
}

try {
    $db->beginTransaction();

    $sel = $db->prepare('SELECT id FROM backup_destinations WHERE name = :name');
    $ins = $db->prepare(
        'INSERT INTO backup_destinations (name, type, config, encrypt, is_active) '
      . 'VALUES (:name, :type, :config, 0, 1)'
    );
    $upd = $db->prepare(
        'UPDATE backup_destinations SET type = :type, config = :config, '
      . 'encrypt = 0, is_active = 1 WHERE id = :id'
    );

    foreach ($rows as $row) {
        $sel->execute([':name' => $row['name']]);
        $existingId = $sel->fetchColumn();

        $params = [
            ':type'   => $row['type'],
            ':config' => json_encode($row['config'], JSON_UNESCAPED_SLASHES),
        ];

        if ($existingId === false) {
            $ins->execute($params + [':name' => $row['name']]);
            echo "seed-backup-destinations: inserted '{$row['name']}'\n";
        } else {
            $upd->execute($params + [':id' => (int) $existingId]);
            echo "seed-backup-destinations: refreshed '{$row['name']}' (id={$existingId})\n";
        }
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "seed-backup-destinations: failed: {$e->getMessage()}\n");
    exit(1);
}

exit(0);
