<?php
declare(strict_types=1);

/**
 * generate.php -- regenerate the backup-format fixture library.
 *
 * Produces one real, large-DB backup archive per format Simple-PHP-IPAM has
 * ever produced (L0 legacy .sqlite, B-SQL .sql.gz, B-L1 .ipambkl1.gz,
 * P1 IPAMBKP1, P2 IPAMBKP2, P3-S IPAMBKP3 stored, P3-T IPAMBKP3 transitory,
 * U1 IPAMBKU1) plus a MANIFEST.md indexing them.
 *
 * Run INSIDE a dockerized, BULK-SEEDED app instance (bootstrap-app.sh sqlite +
 * testing/scripts/seed-large-db.php). The container doesn't mount the repo-root
 * tests/ dir, so this writes to a container-local path and the caller docker-cp's
 * it back out:
 *
 *   docker cp tests/fixtures/backup-library/generate.php ipam-pw-test:/tmp/generate-backup-library.php
 *   docker exec -e BACKUP_LIBRARY_OUT=/tmp/backup-library ipam-pw-test php /tmp/generate-backup-library.php
 *   docker cp ipam-pw-test:/tmp/backup-library/archives  tests/fixtures/backup-library/archives
 *   docker cp ipam-pw-test:/tmp/backup-library/MANIFEST.md tests/fixtures/backup-library/MANIFEST.md
 *
 * Output: <OUT>/archives/<stamp>.* and <OUT>/MANIFEST.md, where
 * OUT = getenv('BACKUP_LIBRARY_OUT') ?: '/tmp/backup-library'.
 *
 * Deterministic fixture credentials (throwaway -- never used by any real
 * install; mirrored in ~/.claude/dev-secrets.env under IPAM_BACKUP_LIBRARY_*):
 *   app_secret  = repeat("cafef00d", 8)               (64 hex chars)
 *   vault_key   = 32 bytes of 0xBE, base64-encoded
 *   passphrase  = "ipam-backup-library-v1-passphrase"
 */

require '/var/www/html/init.php';
/** @var PDO $db */

// -- Credentials (deterministic) -------------------------------------------
$appSecret   = str_repeat('cafef00d', 8);              // 64 hex chars
$vaultKeyRaw = str_repeat("\xBE", 32);                  // 32 raw bytes
$vaultKeyB64 = base64_encode($vaultKeyRaw);
$passphrase  = 'ipam-backup-library-v1-passphrase';

// -- Output layout ----------------------------------------------------------
$outRoot = getenv('BACKUP_LIBRARY_OUT') ?: '/tmp/backup-library';
$archDir = $outRoot . '/archives';
if (!is_dir($archDir) && !mkdir($archDir, 0700, true) && !is_dir($archDir)) {
    fwrite(STDERR, "cannot create $archDir\n");
    exit(1);
}

$stamp = 'ipam-backup-' . gmdate('Ymd-His');
$A = static fn (string $suffix): string => $archDir . '/' . $stamp . $suffix;

// Sanity: ensure the DB looks bulk-seeded.
$nSub = (int) $db->query('SELECT COUNT(*) FROM subnets')->fetchColumn();
$nAddr = (int) $db->query('SELECT COUNT(*) FROM addresses')->fetchColumn();
echo "DB: subnets=$nSub addresses=$nAddr\n";
if ($nSub < 1000) {
    fwrite(STDERR, "WARNING: DB doesn't look bulk-seeded (subnets=$nSub). Run seed-large-db.php first.\n");
}

$tmpFiles = [];
$cleanup = static function () use (&$tmpFiles): void {
    foreach ($tmpFiles as $f) {
        if (is_file($f)) {
            // nosemgrep -- fixed temp paths created by this script, no user input
            @unlink($f);
        }
    }
};

// records: [archive-path, format-label, credential-line, decrypt-invocation, inner-note]
$records = [];

$humanSize = static function (int $n): string {
    if ($n >= 1048576) {
        return round($n / 1048576, 2) . ' MiB';
    }
    if ($n >= 1024) {
        return round($n / 1024, 1) . ' KiB';
    }
    return $n . ' B';
};
$magic8 = static function (string $path): string {
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return '';
    }
    $b = fread($fh, 8);
    fclose($fh);
    return $b === false ? '' : implode(' ', array_map(static fn ($c) => sprintf('%02x', ord($c)), str_split($b)));
};

// -- L0: legacy local SQLite backup (consistent copy of the live DB) -------
echo "L0  legacy .sqlite ...\n";
$l0 = $A('.sqlite');
if (is_file($l0)) {
    // nosemgrep -- fixed path
    @unlink($l0);
}
$vacuumOk = false;
$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver === 'sqlite') {
    try {
        $db->query('PRAGMA wal_checkpoint(TRUNCATE)');
        // VACUUM INTO with a quoted literal path (SQLite 3.27+).
        $db->query('VACUUM INTO ' . $db->quote($l0));
        $vacuumOk = is_file($l0) && filesize($l0) > 0;
    } catch (Throwable $e) {
        echo "  VACUUM INTO failed (" . $e->getMessage() . "); falling back to file copy\n";
    }
    if (!$vacuumOk) {
        $live = '/var/www/html/data/ipam.sqlite';
        if (is_file($live)) {
            copy($live, $l0);
            $vacuumOk = is_file($l0) && filesize($l0) > 0;
        }
    }
}
if (!is_file($l0) || filesize($l0) === 0) {
    fwrite(STDERR, "FATAL: could not produce L0 .sqlite copy\n");
    $cleanup();
    exit(1);
}
$records[] = [$l0, 'L0 -- legacy local SQLite backup (raw DB file, copied verbatim; no magic/compression/encryption)' . ($vacuumOk ? '' : ' [file-copy fallback]'),
    '(none)',
    'open directly with `sqlite3 ' . basename($l0) . '`   # NOTE: decrypt-backup.php only sniffs 9 bytes and does NOT recognise the 16-byte "SQLite format 3\\0" magic -> exits 2 on this file. The L0 fixture is here as a real legacy artefact; the in-app restore wizard never read it either.',
    'SQLite database file ("SQLite format 3\\0" magic; open with `sqlite3`)'];

// -- B-SQL: bare .sql.gz dump ----------------------------------------------
echo "B-SQL  .sql.gz ...\n";
$sqlGzTmp = ipam_backup_dump_to_tmp($db);  // returns a .sql.gz temp path
$tmpFiles[] = $sqlGzTmp;
$bsql = $A('.sql.gz');
copy($sqlGzTmp, $bsql);
$records[] = [$bsql, 'B-SQL -- bare SQL dump (`database` type, unencrypted): gzip stream over engine SQL `.dump`',
    '(none)',
    'php Simple-PHP-IPAM/tools/decrypt-backup.php --in ' . basename($bsql) . ' --out copy.sql.gz --force   # bare: verbatim copy; then `gunzip -c copy.sql.gz` -> SQL text',
    'gzip; `gunzip -c | head` -> "-- Simple PHP IPAM database dump" / PRAGMA ...'];

// -- B-L1: bare gzipped IPAMBKL1 logical dump ------------------------------
echo "B-L1  .ipambkl1.gz ...\n";
$l1Tmp = tempnam(sys_get_temp_dir(), 'ipambkl1_');
$tmpFiles[] = $l1Tmp;
ipam_backup_logical_dump($db, $l1Tmp);  // writes gzipped IPAMBKL1 NDJSON to this path
// Detect: 1f 8b -> already gzip; "IPAMBKL1\n" -> raw, gzip it ourselves.
$head = '';
$fh = fopen($l1Tmp, 'rb');
if ($fh !== false) {
    $head = (string) fread($fh, 2);
    fclose($fh);
}
$l1GzPath = $l1Tmp;
if ($head !== "\x1f\x8b") {
    echo "  (logical dump was RAW NDJSON; gzipping it ourselves)\n";
    $raw = (string) file_get_contents($l1Tmp);
    $gz = gzencode($raw, 9);
    if ($gz === false) {
        fwrite(STDERR, "gzencode failed\n");
        $cleanup();
        exit(1);
    }
    $l1GzPath = $l1Tmp . '.gz';
    file_put_contents($l1GzPath, $gz);
    $tmpFiles[] = $l1GzPath;
} else {
    echo "  (logical dump is already gzip -- good)\n";
}
$bl1 = $A('.ipambkl1.gz');
copy($l1GzPath, $bl1);
$records[] = [$bl1, 'B-L1 -- bare logical dump (`logical` type, unencrypted): gzip stream over IPAMBKL1 NDJSON',
    '(none)',
    'php Simple-PHP-IPAM/tools/decrypt-backup.php --in ' . basename($bl1) . ' --out copy.ipambkl1.gz --force   # bare: verbatim copy; `gunzip -c | head -c 9` -> "IPAMBKL1\\n"',
    'gzip; `gunzip -c | head -c 64` -> "IPAMBKL1\\n{...header JSON...}"'];

// -- P1: IPAMBKP1 (app_secret, one-shot, in-memory GCM) wrapping .sql.gz ---
echo "P1  .ipambkp1.enc ...\n";
$p1 = $A('.ipambkp1.enc');
$plain = (string) file_get_contents($sqlGzTmp);
file_put_contents($p1, backup_encrypt($plain, $appSecret));
unset($plain);
$records[] = [$p1, 'P1 -- IPAMBKP1 (`app_secret`, one-shot AES-256-GCM, whole file in memory). Wraps a B-SQL `.sql.gz`.',
    'app_secret = ' . $appSecret,
    'php Simple-PHP-IPAM/tools/decrypt-backup.php --in ' . basename($p1) . ' --out plain.sql.gz --app-secret ' . $appSecret . ' --force   # then `gunzip -c plain.sql.gz` -> SQL dump',
    'gzip (1f 8b); inner = a B-SQL dump'];

// -- P2: IPAMBKP2 (app_secret, streaming AES-CTR + HMAC) wrapping .sql.gz --
echo "P2  .ipambkp2.enc ...\n";
$p2 = $A('.ipambkp2.enc');
backup_encrypt_stream($sqlGzTmp, $p2, $appSecret);
$records[] = [$p2, 'P2 -- IPAMBKP2 (`app_secret`, streaming AES-256-CTR + HMAC-SHA256). Wraps a B-SQL `.sql.gz`.',
    'app_secret = ' . $appSecret,
    'php Simple-PHP-IPAM/tools/decrypt-backup.php --in ' . basename($p2) . ' --out plain.sql.gz --app-secret ' . $appSecret . ' --force   # then `gunzip -c plain.sql.gz` -> SQL dump',
    'gzip (1f 8b); inner = a B-SQL dump'];

// -- P3-S: IPAMBKP3 stored (backup_vault_key) wrapping .ipambkl1.gz --------
echo "P3-S  .stored.ipambkp3 ...\n";
$p3s = $A('.stored.ipambkp3');
backup_encrypt_stream_v3($l1GzPath, $p3s, BACKUP_V3_MODE_STORED, null, $vaultKeyRaw);
$records[] = [$p3s, 'P3-S -- IPAMBKP3 stored (`backup_vault_key`, 32 raw bytes / 44-char base64). Wraps a B-L1 `.ipambkl1.gz`.',
    'vault_key (base64) = ' . $vaultKeyB64 . '   |   vault_key (hex) = ' . bin2hex($vaultKeyRaw),
    'php Simple-PHP-IPAM/tools/decrypt-backup.php --in ' . basename($p3s) . ' --out plain.ipambkl1.gz --vault-key ' . $vaultKeyB64 . ' --force   # then `gunzip -c plain.ipambkl1.gz | head -c 9` -> "IPAMBKL1\\n"',
    'gzip (1f 8b); inner = a B-L1 IPAMBKL1 dump'];

// -- P3-T: IPAMBKP3 transitory (passphrase) wrapping .ipambkl1.gz ----------
echo "P3-T  .transitory.ipambkp3 ...\n";
$p3t = $A('.transitory.ipambkp3');
backup_encrypt_stream_v3($l1GzPath, $p3t, BACKUP_V3_MODE_TRANSITORY, $passphrase, null);
$records[] = [$p3t, 'P3-T -- IPAMBKP3 transitory (passphrase, Argon2id-derived key; default cost). Wraps a B-L1 `.ipambkl1.gz`.',
    'passphrase = ' . $passphrase,
    "IPAM_BACKUP_PASSPHRASE='" . $passphrase . "' php Simple-PHP-IPAM/tools/decrypt-backup.php --in " . basename($p3t) . ' --out plain.ipambkl1.gz --force   # (or --passphrase ...)',
    'gzip (1f 8b); inner = a B-L1 IPAMBKL1 dump'];

// -- U1: IPAMBKU1 (unencrypted integrity wrapper) wrapping .ipambkl1.gz ----
echo "U1  .ipambku1 ...\n";
$u1 = $A('.ipambku1');
backup_unencrypted_wrap_stream($l1GzPath, $u1);
$records[] = [$u1, 'U1 -- IPAMBKU1 (unencrypted integrity wrapper: magic + SHA-256 + plaintext). Wraps a B-L1 `.ipambkl1.gz`.',
    '(none -- integrity check only)',
    'php Simple-PHP-IPAM/tools/decrypt-backup.php --in ' . basename($u1) . ' --out plain.ipambkl1.gz --force   # then `gunzip -c plain.ipambkl1.gz | head -c 9` -> "IPAMBKL1\\n"',
    'IPAMBKU1 magic; unwrapped inner = a B-L1 `.ipambkl1.gz`'];

// -- Write MANIFEST.md ------------------------------------------------------
echo "Writing MANIFEST.md ...\n";
$m = [];
$m[] = '# Backup format library -- MANIFEST';
$m[] = '';
$m[] = '> Generated ' . gmdate('Y-m-d H:i:s') . ' UTC by `tests/fixtures/backup-library/generate.php`.';
$m[] = '> One real, large-DB backup archive per format Simple-PHP-IPAM has ever produced.';
$m[] = '> Source DB at generation: subnets=' . $nSub . ', addresses=' . $nAddr . '. Format catalogue: `docs/internal/backup-formats-matrix.md`.';
$m[] = '';
$m[] = '## Fixture credentials (deterministic -- throwaway, never used by any real install)';
$m[] = '';
$m[] = '| Credential | Value |';
$m[] = '|---|---|';
$m[] = '| `app_secret` (IPAMBKP1 / IPAMBKP2) | `' . $appSecret . '` |';
$m[] = '| `backup_vault_key` base64 (IPAMBKP3 stored) | `' . $vaultKeyB64 . '` |';
$m[] = '| `backup_vault_key` hex | `' . bin2hex($vaultKeyRaw) . '` |';
$m[] = '| passphrase (IPAMBKP3 transitory) | `' . $passphrase . '` |';
$m[] = '';
$m[] = 'These are mirrored in `~/.claude/dev-secrets.env` (`IPAM_BACKUP_LIBRARY_APP_SECRET`, `IPAM_BACKUP_LIBRARY_VAULT_KEY_B64`, `IPAM_BACKUP_LIBRARY_PASSPHRASE`).';
$m[] = '';
$m[] = '## Archives';
$m[] = '';
$m[] = '| File | Format | Magic (first 8 bytes) | Size | SHA-256 | Credential | Decrypt / verify |';
$m[] = '|---|---|---|---|---|---|---|';
foreach ($records as [$path, $label, $cred, $invoke, $innerNote]) {
    $sz = (int) filesize($path);
    $m[] = '| `' . basename($path) . '` | ' . $label . ' | `' . $magic8($path) . '` | ' . number_format($sz) . ' B (' . $humanSize($sz) . ') | `' . hash_file('sha256', $path) . '` | ' . $cred . ' | `' . $invoke . '`<br>Expect: ' . $innerNote . ' |';
}
$m[] = '';
$m[] = '## Notes';
$m[] = '';
$m[] = '- For the `*.enc` (P1/P2) and `*.ipambkp3` (P3-S/P3-T) and `*.ipambku1` archives, `decrypt-backup.php` writes the *decrypted plaintext* to `--out`; that plaintext is itself a gzip stream (the `.sql.gz` for P1/P2, the `.ipambkl1.gz` for P3-S/P3-T/U1). Run `gunzip -c` on the output to see the SQL / NDJSON.';
$m[] = '- For the bare `.sql.gz` / `.ipambkl1.gz` archives, `decrypt-backup.php` detects "no envelope" and copies the bytes verbatim (it does **not** gunzip). Exit 0.';
$m[] = '- **Gap:** `decrypt-backup.php` reads only the first 9 bytes, so it does NOT match the 16-byte `SQLite format 3\\0` magic — the L0 `.sqlite` archive exits 2 ("unrecognised archive") through the tool. That matches reality (the in-app restore wizard never had a "restore from local .sqlite backup" path either); open the L0 file directly with `sqlite3`. (`docs/internal/backup-formats-matrix.md`\'s cheat sheet currently implies the tool copies bare `.sqlite` verbatim — it does not, given the 9-byte sniff window.)';
$m[] = '- Negative check: running a `*.enc` with the wrong `--app-secret` exits 3 (decrypt failure) and writes no partial output.';
file_put_contents($outRoot . '/MANIFEST.md', implode("\n", $m) . "\n");

$cleanup();

echo "\nDone. Archives in $archDir :\n";
foreach ($records as [$path]) {
    echo "  " . basename($path) . "  " . number_format((int) filesize($path)) . " B\n";
}
$total = 0;
foreach (glob($archDir . '/*') ?: [] as $f) {
    $total += (int) filesize($f);
}
echo "Total library size: " . number_format($total) . " B (" . round($total / 1048576, 1) . " MiB)\n";
echo "MANIFEST: $outRoot/MANIFEST.md\n";
