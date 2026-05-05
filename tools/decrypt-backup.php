<?php
declare(strict_types=1);

/**
 * decrypt-backup.php — standalone IPAMBKP* decrypt tool (#1043, v3.24.0).
 *
 * Decrypts a Simple-PHP-IPAM backup archive to plaintext WITHOUT requiring
 * a running install. Useful when:
 *   - The originating IPAM install is destroyed but you still have the
 *     `app_secret` (v1/v2) or `backup_vault_key` (v3 stored mode) and a
 *     copy of the archive.
 *   - Operator needs to inspect a passphrase-encrypted (v3 transitory)
 *     archive on a workstation, not on the server.
 *   - CI/audit-side tooling wants to verify a backup archive offline.
 *
 * Format auto-detected from the 8-byte magic. Required credential per
 * format:
 *   IPAMBKP1 / IPAMBKP2  →  --app-secret <hex>
 *   IPAMBKP3 stored      →  --vault-key  <base64-or-hex>
 *   IPAMBKP3 transitory  →  --passphrase <secret>      (read from
 *                           IPAM_BACKUP_PASSPHRASE env if --passphrase
 *                           is omitted; never echo on stdin)
 *   IPAMBKU1             →  no credential (integrity only)
 *
 * Exit codes:
 *   0  success
 *   2  usage error / missing arg
 *   3  decrypt failure (bad magic, HMAC mismatch, wrong key, ...)
 *
 * This tool is intentionally minimal-dep: it `require`s only the parts of
 * lib.php that define the codec helpers. No database, no session, no
 * webserver context.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "decrypt-backup.php is a CLI-only tool.\n");
    exit(2);
}

// Resolve repo layout: tools/ sits alongside Simple-PHP-IPAM/.
$libPath = __DIR__ . '/../Simple-PHP-IPAM/lib.php';
if (!is_file($libPath)) {
    fwrite(STDERR, "decrypt-backup.php: cannot find Simple-PHP-IPAM/lib.php at $libPath\n");
    exit(2);
}
require_once $libPath;

// ── argv parsing ─────────────────────────────────────────────────────────
function dbu_die(string $msg, int $code = 2): never
{
    fwrite(STDERR, "decrypt-backup.php: $msg\n");
    exit($code);
}

function dbu_usage(): never
{
    fwrite(STDERR, <<<'EOF'
usage: php tools/decrypt-backup.php --in <path> --out <path> [credentials]

  --in <path>           Input archive (IPAMBKP1/2/3 or IPAMBKU1).
  --out <path>          Output path for decrypted plaintext.
  --app-secret <hex>    Required for IPAMBKP1 / IPAMBKP2 archives.
  --vault-key <key>     Required for IPAMBKP3 stored-mode archives.
                        Accepts base64 (32 bytes decoded) or 64-char hex.
  --passphrase <pass>   Required for IPAMBKP3 transitory archives.
                        Falls back to $IPAM_BACKUP_PASSPHRASE env if unset.
  -h | --help           Show this help.

Exit codes: 0 success, 2 usage error, 3 decrypt failure.

EOF);
    exit(2);
}

$args = $argv;
array_shift($args); // script name

$opts = ['in' => null, 'out' => null, 'app_secret' => null, 'vault_key' => null, 'passphrase' => null];

while ($args !== []) {
    $a = array_shift($args);
    switch ($a) {
        case '-h':
        case '--help':
            dbu_usage();
            // unreachable
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
    dbu_usage();
}

// Pick up env-supplied passphrase if --passphrase wasn't given. Lets
// operators avoid the secret in argv (which leaks to /proc/<pid>/cmdline).
if ($opts['passphrase'] === null) {
    $env = getenv('IPAM_BACKUP_PASSPHRASE');
    if (is_string($env) && $env !== '') {
        $opts['passphrase'] = $env;
    }
}

$inPath  = $opts['in'];
$outPath = $opts['out'];
if (!is_file($inPath)) {
    dbu_die("input file not found: $inPath");
}

// ── Decode vault-key if supplied (accept base64 or hex) ──────────────────
$vaultKeyRaw = null;
if ($opts['vault_key'] !== null) {
    $candidate = $opts['vault_key'];
    // Try hex first if it's exactly 64 chars and pure hex.
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

// ── Dispatch ─────────────────────────────────────────────────────────────
try {
    backup_decrypt_to_path(
        $inPath,
        $outPath,
        is_string($opts['app_secret']) ? $opts['app_secret'] : '',
        $opts['passphrase'],
        $vaultKeyRaw
    );
} catch (IpamBackupKeyRequiredException $e) {
    if ($e->mode === BACKUP_V3_MODE_TRANSITORY) {
        dbu_die('archive is IPAMBKP3 transitory — supply --passphrase or set $IPAM_BACKUP_PASSPHRASE', 3);
    }
    dbu_die('archive is IPAMBKP3 stored — supply --vault-key', 3);
} catch (Throwable $e) {
    dbu_die('decrypt failed: ' . $e->getMessage(), 3);
}

// On success print a one-line summary to stdout (operator-readable, no
// content of the plaintext).
$bytes = (int) (@filesize($outPath) ?: 0);
fwrite(STDOUT, "decrypted $bytes bytes → $outPath\n");
exit(0);
