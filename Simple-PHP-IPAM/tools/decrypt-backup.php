<?php
declare(strict_types=1);

/**
 * decrypt-backup.php — standalone IPAM backup decrypt tool (#1043, v3.24.0;
 * hardened in v3.28.0, #1165 — Pass-1 conformance run).
 *
 * Decrypts (or, for already-plaintext archives, passes through) a
 * Simple-PHP-IPAM backup archive WITHOUT requiring a running install.
 * Useful when:
 *   - The originating IPAM install is destroyed but you still have the
 *     `app_secret` (IPAMBKP1/IPAMBKP2) or `backup_vault_key` (IPAMBKP3
 *     stored mode) and a copy of the archive.
 *   - Operator needs to inspect a passphrase-encrypted (IPAMBKP3
 *     transitory) archive on a workstation, not on the server.
 *   - CI/audit-side tooling wants to verify a backup archive offline.
 *
 * From v4.0.0 the in-app reader stops accepting IPAMBKP1/IPAMBKP2/
 * SQLite-binary dumps — this tool becomes the *only* in-product way to
 * recover plaintext from a legacy archive.
 *
 * Format auto-detected from the leading magic. Credential required per
 * format:
 *   IPAMBKP1 / IPAMBKP2  →  --app-secret <hex>
 *   IPAMBKP3 stored      →  --vault-key  <base64-or-hex>
 *   IPAMBKP3 transitory  →  --passphrase <secret>      (read from
 *                           IPAM_BACKUP_PASSPHRASE env if --passphrase
 *                           is omitted; never echoed)
 *   IPAMBKU1             →  no credential (integrity only)
 *   bare gzip / IPAMBKL1 / SQL text / raw SQLite file → no credential (verbatim copy)
 *
 * Output:
 *   --out <path>   write decrypted plaintext to <path> (refuses to
 *                  overwrite an existing non-empty file unless --force).
 *   --out -        write decrypted plaintext to STDOUT.
 *
 * Exit codes:
 *   0  success
 *   2  usage error / missing arg / wrong-magic / wrong credential TYPE
 *      / output collision
 *   3  decrypt failure (HMAC/GCM mismatch, wrong key, truncated/corrupt
 *      ciphertext, missing key for the detected format)
 *
 * Intentionally minimal-dep: requires only lib.php (which pulls
 * lib/backup.php for the codec helpers). No database, no session, no
 * webserver context. The composer `vendor/` tree is NOT needed for any
 * of the formats this tool decrypts — lib.php tolerates a missing
 * vendor/ at require time (the vendored deps are only used by SMTP/MFA/
 * SFTP/S3 code paths that the codec never touches).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "decrypt-backup.php is a CLI-only tool.\n");
    exit(2);
}

// Resolve repo layout: tools/ sits alongside Simple-PHP-IPAM/.
$libPath = __DIR__ . '/../lib.php';
if (!is_file($libPath)) {
    fwrite(STDERR, "decrypt-backup.php: cannot find lib.php at $libPath\n");
    exit(2);
}
require_once $libPath;

// ── argv parsing ─────────────────────────────────────────────────────────
function dbu_die(string $msg, int $code = 2): never
{
    fwrite(STDERR, "decrypt-backup.php: $msg\n");
    exit($code);
}

function dbu_usage(int $code = 2): never
{
    $out = $code === 0 ? STDOUT : STDERR;
    fwrite($out, <<<'EOF'
usage: php tools/decrypt-backup.php --in <path> --out <path|-> [credentials] [--force]

  --in <path>           Input archive (IPAMBKP1/2/3, IPAMBKU1, or a bare
                        gzip / IPAMBKL1 / SQL / raw SQLite file).
  --out <path>          Output path for decrypted plaintext. Use "-" for STDOUT.
  --force               Allow --out to overwrite an existing non-empty file.
  --app-secret <hex>    Required for IPAMBKP1 / IPAMBKP2 archives.
  --vault-key <key>     Required for IPAMBKP3 stored-mode archives.
                        Accepts base64 (32 bytes decoded) or 64-char hex.
  --passphrase <pass>   Required for IPAMBKP3 transitory archives.
                        Falls back to $IPAM_BACKUP_PASSPHRASE env if unset.
  -h | --help           Show this help and exit 0.

Examples:
  # legacy app_secret-encrypted archive (IPAMBKP1 / IPAMBKP2)
  php tools/decrypt-backup.php --in backup.enc --out backup.sqlite --app-secret <hex>
  # IPAMBKP3 stored-mode (vault key)
  php tools/decrypt-backup.php --in backup.ipambkp3 --out plain.ndjson --vault-key <b64>
  # IPAMBKP3 transitory (passphrase via env, never on the command line)
  IPAM_BACKUP_PASSPHRASE=... php tools/decrypt-backup.php --in backup.ipambkp3 --out plain.ndjson
  # integrity-wrapped (IPAMBKU1) or bare archive — verbatim copy
  php tools/decrypt-backup.php --in backup.ipambku1 --out plain.ndjson
  # write to stdout
  php tools/decrypt-backup.php --in backup.enc --out - --app-secret <hex> | sqlite3 ...

Exit codes: 0 success, 2 usage / wrong-format / collision error, 3 decrypt failure.

EOF);
    exit($code);
}

// PHP guarantees $argv exists under CLI sapi (gated above) but PHPStan
// does not see that implication. Pull from $_SERVER so the static type
// is unambiguous (list<string>).
/** @var list<string> $serverArgv */
$serverArgv = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$args = $serverArgv;
array_shift($args); // script name

if ($args === []) {
    dbu_usage(2);
}

$opts = [
    'in' => null, 'out' => null,
    'app_secret' => null, 'vault_key' => null, 'passphrase' => null,
    'force' => false,
];

while ($args !== []) {
    $a = array_shift($args);
    switch ($a) {
        case '-h':
        case '--help':
            dbu_usage(0);
            // unreachable
        case '--force':
            $opts['force'] = true;
            break;
        case '--in':
            $opts['in'] = array_shift($args) ?? dbu_die('--in requires a value');
            break;
        case '--out':
            $opts['out'] = array_shift($args) ?? dbu_die('--out requires a value');
            break;
        case '--app-secret':
            $opts['app_secret'] = array_shift($args) ?? dbu_die('--app-secret requires a value');
            break;
        case '--vault-key':
            $opts['vault_key'] = array_shift($args) ?? dbu_die('--vault-key requires a value');
            break;
        case '--passphrase':
            $opts['passphrase'] = array_shift($args) ?? dbu_die('--passphrase requires a value');
            break;
        default:
            dbu_die("unknown argument: $a");
    }
}

if ($opts['in'] === null || $opts['out'] === null) {
    dbu_usage(2);
}

// Reject conflicting credentials up front: each archive format takes
// exactly one credential type, so supplying two is operator error.
$credCount =
    ($opts['app_secret'] !== null && $opts['app_secret'] !== '' ? 1 : 0) +
    ($opts['vault_key']  !== null ? 1 : 0) +
    ($opts['passphrase'] !== null && $opts['passphrase'] !== '' ? 1 : 0);
if ($credCount > 1) {
    dbu_die('supply at most one of --app-secret / --vault-key / --passphrase (each archive format takes exactly one)');
}

// Pick up env-supplied passphrase if --passphrase wasn't given. Lets
// operators avoid the secret in argv (which leaks to /proc/<pid>/cmdline).
if ($opts['passphrase'] === null || $opts['passphrase'] === '') {
    $env = getenv('IPAM_BACKUP_PASSPHRASE');
    if (is_string($env) && $env !== '') {
        if ($credCount > 0) {
            // --app-secret or --vault-key already supplied — env passphrase
            // is ambiguous; surface it rather than silently picking one.
            dbu_die('IPAM_BACKUP_PASSPHRASE is set but --app-secret/--vault-key was also supplied — unset the env var or drop the flag');
        }
        $opts['passphrase'] = $env;
        $credCount = 1;
    }
}

$inPath  = $opts['in'];
$outPath = $opts['out'];
$toStdout = ($outPath === '-');

if (!is_file($inPath)) {
    dbu_die("input file not found: $inPath");
}

// Collision guard for file output. Streaming codecs would rename over an
// existing target; an operator pointing --out at the wrong path should not
// silently lose data. Empty files are treated as not-yet-written.
if (!$toStdout && is_file($outPath) && (int) (@filesize($outPath) ?: 0) > 0 && !$opts['force']) {
    dbu_die("output file already exists and is non-empty: $outPath (pass --force to overwrite)");
}

// ── Sniff the archive magic before we touch any credential ───────────────
$sfh = @fopen($inPath, 'rb');
if ($sfh === false) {
    dbu_die("cannot open input file: $inPath", 3);
}
// 16 bytes: enough for the 8-byte IPAMBKP1/2/3 / IPAMBKU1 magic plus the
// IPAMBKP3 mode byte at offset 8, gzip's 2-byte magic, and the 16-byte
// "SQLite format 3\0" header of a bare SQLite file (a raw `L0` legacy local
// backup). A 9-byte read truncated the SQLite header, so such files used to
// be rejected as "unrecognised" rather than passed through verbatim.
$head = (string) fread($sfh, 16);
fclose($sfh);

if ($head === '') {
    dbu_die("input file is empty: $inPath");
}

$magic8   = substr($head, 0, 8);
$modeByte = strlen($head) >= 9 ? substr($head, 8, 1) : '';
$isGzip   = strlen($head) >= 2 && $head[0] === "\x1f" && $head[1] === "\x8b";

// Determine the format family and validate the supplied credential TYPE.
$family = null; // 'app_secret' | 'vault' | 'passphrase' | 'unenc' | 'bare'
if ($magic8 === BACKUP_MAGIC || $magic8 === BACKUP_MAGIC_V2) {
    $family = 'app_secret';
} elseif ($magic8 === BACKUP_MAGIC_V3) {
    $family = (strlen($modeByte) === 1 && ord($modeByte) === BACKUP_V3_MODE_TRANSITORY) ? 'passphrase' : 'vault';
} elseif ($magic8 === BACKUP_MAGIC_UNENC) {
    $family = 'unenc';
} elseif ($isGzip || str_starts_with($head, 'IPAMBKL1') || preg_match('/^(--|\/\*|PRAGMA |BEGIN |CREATE |INSERT |SELECT |SQLite format 3)/', $head) === 1) {
    $family = 'bare';
} else {
    dbu_die('unrecognised archive — leading bytes do not match IPAMBKP1/2/3, IPAMBKU1, gzip, IPAMBKL1, or SQL/SQLite. Is this an IPAM backup?');
}

// Credential-type cross-check (test plan case 6).
$wrongCredMsg = static function (string $need): string {
    return "this archive needs $need; you supplied a credential for a different format";
};
switch ($family) {
    case 'app_secret':
        if ($opts['vault_key'] !== null) {
            dbu_die($wrongCredMsg('--app-secret (IPAMBKP1/IPAMBKP2 archive)'));
        }
        if ($opts['passphrase'] !== null && $opts['passphrase'] !== '') {
            dbu_die($wrongCredMsg('--app-secret (IPAMBKP1/IPAMBKP2 archive)'));
        }
        if ($opts['app_secret'] === null || $opts['app_secret'] === '') {
            dbu_die('this archive is IPAMBKP1/IPAMBKP2 — supply --app-secret <hex>', 3);
        }
        break;
    case 'vault':
        if ($opts['app_secret'] !== null && $opts['app_secret'] !== '') {
            dbu_die($wrongCredMsg('--vault-key (IPAMBKP3 stored-mode archive)'));
        }
        if ($opts['passphrase'] !== null && $opts['passphrase'] !== '') {
            dbu_die($wrongCredMsg('--vault-key (IPAMBKP3 stored-mode archive)'));
        }
        if ($opts['vault_key'] === null) {
            dbu_die('this archive is IPAMBKP3 stored-mode — supply --vault-key <base64-or-hex>', 3);
        }
        break;
    case 'passphrase':
        if ($opts['app_secret'] !== null && $opts['app_secret'] !== '') {
            dbu_die($wrongCredMsg('--passphrase (IPAMBKP3 transitory archive)'));
        }
        if ($opts['vault_key'] !== null) {
            dbu_die($wrongCredMsg('--passphrase (IPAMBKP3 transitory archive)'));
        }
        if ($opts['passphrase'] === null || $opts['passphrase'] === '') {
            dbu_die('this archive is IPAMBKP3 transitory — supply --passphrase or set $IPAM_BACKUP_PASSPHRASE', 3);
        }
        break;
    case 'unenc':
    case 'bare':
        if ($credCount > 0) {
            dbu_die('this archive needs no credential (' . ($family === 'unenc' ? 'IPAMBKU1 integrity wrapper' : 'bare/plaintext archive') . ') — drop the credential flag');
        }
        break;
}

// ── Decode vault-key if supplied (accept base64 or hex) ──────────────────
$vaultKeyRaw = null;
if ($opts['vault_key'] !== null) {
    $candidate = $opts['vault_key'];
    if (strlen($candidate) === 64 && ctype_xdigit($candidate)) {
        $hex = hex2bin($candidate);
        if (is_string($hex) && strlen($hex) === BACKUP_VAULT_KEY_LEN) {
            $vaultKeyRaw = $hex;
        }
    }
    if ($vaultKeyRaw === null) {
        $b64 = base64_decode($candidate, true);
        if (is_string($b64) && strlen($b64) === BACKUP_VAULT_KEY_LEN) {
            $vaultKeyRaw = $b64;
        }
    }
    if ($vaultKeyRaw === null) {
        dbu_die('--vault-key must decode to ' . BACKUP_VAULT_KEY_LEN . ' bytes (base64 or hex)');
    }
}

// Final output path. For stdout we decrypt into a private tempfile first
// (so codec atomicity / collision semantics are unchanged) then stream it
// out and remove the temp.
$finalTmp = $toStdout
    ? (sys_get_temp_dir() . '/ipam-decrypt-' . bin2hex(random_bytes(6)))
    : $outPath;

// Did the operator-named --out path already exist before this run? If so we
// must not clobber it on a failure path (--force only authorises a *success*
// overwrite). For the stdout case $finalTmp is a fresh private temp we own.
$outPreExisted = (!$toStdout) && is_file($outPath);

// Paths this run created; @unlink()'d on any failure path before dbu_die().
/** @var list<string> $cleanupOnFailure */
$cleanupOnFailure = [];
if ($toStdout) {
    $cleanupOnFailure[] = $finalTmp;
}
$dbuCleanup = static function () use (&$cleanupOnFailure): void {
    foreach ($cleanupOnFailure as $p) {
        if ($p !== '' && is_file($p)) {
            // nosemgrep -- tool-generated output/temp paths created this run, no user input drives the value
            @unlink($p);
        }
    }
};

// ── Dispatch ─────────────────────────────────────────────────────────────
try {
    if ($family === 'bare') {
        // Verbatim copy with no decode. Stream so a huge plain archive does
        // not blow memory; write to a sibling temp then rename for atomicity.
        $copyTmp = $finalTmp . '.copying.' . bin2hex(random_bytes(4));
        $cleanupOnFailure[] = $copyTmp;
        if (!$toStdout && !$outPreExisted) {
            $cleanupOnFailure[] = $finalTmp;
        }
        // soft-open: failure is handled immediately below with cleanup and RuntimeException
        $in  = @fopen($inPath, 'rb');
        // soft-open: failure is handled immediately below with cleanup and RuntimeException
        $out = @fopen($copyTmp, 'wb');
        if ($in === false || $out === false) {
            if ($in !== false) {
                fclose($in);
            }
            if ($out !== false) {
                fclose($out);
            }
            throw new RuntimeException('cannot open files for verbatim copy');
        }
        try {
            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('read failed during verbatim copy');
                }
                if ($chunk === '') {
                    continue;
                }
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('short write during verbatim copy');
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }
        // atomic rename; failure is fatal for verbatim copy path
        if (!@rename($copyTmp, $finalTmp)) {
            // nosemgrep -- $copyTmp is a tool-generated sibling temp path, no user input
            @unlink($copyTmp);
            throw new RuntimeException('rename of verbatim copy failed');
        }
    } else {
        // backup_decrypt_to_path() writes (and may rename over) $finalTmp.
        // If it throws partway, clean up the partial output unless the
        // operator-named --out path already existed before this run.
        if (!$toStdout && !$outPreExisted) {
            $cleanupOnFailure[] = $finalTmp;
        }
        backup_decrypt_to_path(
            $inPath,
            $finalTmp,
            is_string($opts['app_secret']) ? $opts['app_secret'] : '',
            $opts['passphrase'],
            $vaultKeyRaw
        );
    }
} catch (IpamBackupKeyRequiredException $e) {
    $dbuCleanup();
    if ($e->mode === BACKUP_V3_MODE_TRANSITORY) {
        dbu_die('archive is IPAMBKP3 transitory — supply --passphrase or set $IPAM_BACKUP_PASSPHRASE', 3);
    }
    dbu_die('archive is IPAMBKP3 stored — supply --vault-key', 3);
} catch (Throwable $e) {
    $dbuCleanup();
    dbu_die('decrypt failed: ' . $e->getMessage(), 3);
}

// ── Emit ─────────────────────────────────────────────────────────────────
if ($toStdout) {
    // soft-open: failure is handled immediately below with cleanup and dbu_die
    $fh = @fopen($finalTmp, 'rb');
    if ($fh === false) {
        // nosemgrep -- $finalTmp is a tool-generated temp path, no user input
        @unlink($finalTmp);
        dbu_die('could not re-open decrypted temp for streaming to stdout', 3);
    }
    while (!feof($fh)) {
        $chunk = fread($fh, 65536);
        if ($chunk === false) {
            break;
        }
        fwrite(STDOUT, $chunk);
    }
    fclose($fh);
    // nosemgrep -- $finalTmp is a tool-generated temp path, no user input
    @unlink($finalTmp);
    // No summary line on stdout — the stream IS the output.
    exit(0);
}

$bytes = (int) (@filesize($outPath) ?: 0);
fwrite(STDOUT, "decrypted $bytes bytes → $outPath\n");
exit(0);
