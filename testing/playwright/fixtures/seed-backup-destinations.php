<?php
declare(strict_types=1);

/**
 * CLI fixture: seed two backup_destinations rows for the Playwright integration
 * spec (#789). Idempotent — safe to re-run.
 *
 *   ci-minio (s3)   → http://minio:9000 (sidecar), bucket from
 *                     IPAM_TEST_MINIO_BUCKET (default ipam-backups), prefix ci/
 *   ci-local (local) → on-disk at data/tmp/ipam-backup-ci-local
 *
 * Credentials come from the bootstrap-app.sh sidecar env vars:
 *   IPAM_TEST_MINIO_USER   (default testkey)
 *   IPAM_TEST_MINIO_PASS   (default testsecret123)
 *   IPAM_TEST_MINIO_BUCKET (default ipam-backups)
 *
 * Encryption is OFF on both rows so the spec can verify SHA-256 round-trips
 * without having to know app_secret. A separate spec exercises encrypted dumps.
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
];

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
