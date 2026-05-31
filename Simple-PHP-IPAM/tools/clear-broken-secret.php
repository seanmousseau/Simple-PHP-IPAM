<?php
declare(strict_types=1);

/**
 * clear-broken-secret.php — recover from a rotated/lost app_secret (v3.36.1).
 *
 * When `app_secret` in config.php no longer matches the key used to encrypt
 * a managed settings envelope, ipam_setting() logs the failure and returns
 * the registry default (v3.36.1 behaviour; previously it threw and bricked
 * the boot path). The DB row is still there, still unreadable, and will
 * keep tripping the log every page load until it is either:
 *   1. Resaved through the Settings UI (which re-encrypts under the current
 *      app_secret) — the preferred path when the admin can log in, OR
 *   2. Nulled out with this tool — for headless / scripted recovery, or when
 *      you don't have the old plaintext to resave.
 *
 * Behaviour:
 *   - Loads the install's config.php and connects to its DB exactly the
 *     way init.php would.
 *   - For each --key, asserts the key is a registered, sensitive setting
 *     (refuses to touch anything else). Asserts the current row is actually
 *     undecryptable under the current app_secret (so an operator typo
 *     can't wipe a working secret). DELETEs the row and busts the cache.
 *   - --all-broken scans every sensitive key in the registry and clears
 *     each row whose envelope can't decrypt. Reports what it cleared.
 *   - --dry-run prints the action plan without touching the DB.
 *
 * The cleared row falls back to its registry default on next read. The
 * admin should then re-enter the secret through the Settings UI (or via
 * the normal config-import path) to restore the intended value.
 *
 * Exit codes:
 *   0  success (rows cleared, OR --dry-run completed, OR --all-broken found
 *      nothing broken).
 *   2  usage error / unknown key / non-sensitive key / not-broken key /
 *      DB connection failure.
 *
 * Examples:
 *   php tools/clear-broken-secret.php --key login_protection.secret_key
 *   php tools/clear-broken-secret.php --key smtp.auth_pass --key webhook.auth_secret
 *   php tools/clear-broken-secret.php --all-broken
 *   php tools/clear-broken-secret.php --all-broken --dry-run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "clear-broken-secret.php is a CLI-only tool.\n");
    exit(2);
}

$libPath = __DIR__ . '/../lib.php';
if (!is_file($libPath)) {
    fwrite(STDERR, "clear-broken-secret.php: cannot find lib.php at $libPath\n");
    exit(2);
}
require_once $libPath;

function cbs_die(string $msg, int $code = 2): never
{
    fwrite(STDERR, "clear-broken-secret.php: $msg\n");
    exit($code);
}

function cbs_usage(int $code = 2): never
{
    $out = $code === 0 ? STDOUT : STDERR;
    fwrite($out, <<<'EOF'
usage: php tools/clear-broken-secret.php [--key <name>]... [--all-broken] [--dry-run]

  --key <name>     Setting key to clear (e.g. login_protection.secret_key).
                   May be passed multiple times. Refuses non-sensitive keys
                   and keys whose current row decrypts cleanly.
  --all-broken     Scan every sensitive key in the registry and clear each
                   row whose envelope cannot be decrypted under the current
                   app_secret. Cannot be combined with --key.
  --dry-run        Report what would be cleared without modifying the DB.
  -h | --help      Show this help and exit 0.

Recovery: after running this tool, log in and resave the affected setting
through the Settings UI so it gets re-encrypted under the current
app_secret. Until then it falls back to its registry default (typically '').

EOF);
    exit($code);
}

/** @var list<string> $serverArgv */
$serverArgv = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$args = $serverArgv;
array_shift($args);

if ($args === []) {
    cbs_usage(2);
}

/** @var list<string> $keys */
$keys      = [];
$allBroken = false;
$dryRun    = false;

while ($args !== []) {
    $a = array_shift($args);
    switch ($a) {
        case '-h':
        case '--help':
            cbs_usage(0);
            // unreachable
        case '--key':
            $v = array_shift($args) ?? cbs_die('--key requires a value');
            $keys[] = $v;
            break;
        case '--all-broken':
            $allBroken = true;
            break;
        case '--dry-run':
            $dryRun = true;
            break;
        default:
            cbs_die("unknown argument: $a");
    }
}

if ($allBroken && $keys !== []) {
    cbs_die('--all-broken cannot be combined with --key');
}
if (!$allBroken && $keys === []) {
    cbs_usage(2);
}

// Boot the DB the same way init.php does. ipam_config() surfaces whatever
// is in the global-scope $config (which is $GLOBALS['config'] under PHP's
// auto-globalisation of top-level vars), so we have to require config.php
// ourselves at the script's top level — init.php is not on the call stack
// here. ADR-003 forbids direct $GLOBALS['config'] reads/writes; assigning
// $config at top level is the sanctioned form (init.php uses the same).
$configPath = __DIR__ . '/../config.php';
if (!is_file($configPath)) {
    cbs_die("cannot find config.php at $configPath");
}
/** @var IpamConfig $config */
$config = require $configPath;
ipam_config_invalidate_cache();

try {
    $db = ipam_db($config);
} catch (\Throwable $e) {
    cbs_die('could not open DB: ' . $e->getMessage());
}

$definitions = ipam_setting_definitions();
$kc          = ipam_key_col();

/**
 * Resolve the global (tenant_id IS NULL) raw value for a key. Returns null
 * when the row does not exist.
 */
$readRaw = function (string $key) use ($db, $kc): ?string {
    $st = $db->prepare("SELECT value FROM settings WHERE tenant_id IS NULL AND {$kc} = :k");
    $st->execute([':k' => $key]);
    $row = $st->fetch();
    if (!is_array($row)) return null;
    $v = $row['value'] ?? null;
    return is_string($v) ? $v : null;
};

/**
 * True iff the stored raw value is an envelope that does not decrypt under
 * the current app_secret. A null raw value (row absent) is not "broken" —
 * there's nothing to clear.
 */
$isBroken = function (?string $raw): bool {
    if (!is_string($raw)) return false;
    if (!ipam_secret_is_envelope($raw)) return false;
    return ipam_secret_decrypt($raw) === null;
};

/** @var list<string> $targets */
$targets = [];

if ($allBroken) {
    // CR feedback: scan by the managed-secret list, not the sensitive flag.
    // Not every sensitive setting is encrypted via the IPAMSEC1 envelope —
    // `backup_vault_key` is sensitive but stored as a raw key value, so
    // $isBroken() would never flag it even though "sensitive" would include
    // it. Use ipam_secret_managed_keys() as the authoritative set.
    foreach (ipam_secret_managed_keys() as $key) {
        $raw = $readRaw($key);
        if ($isBroken($raw)) {
            $targets[] = $key;
        }
    }
    if ($targets === []) {
        fwrite(STDOUT, "clear-broken-secret.php: no broken managed envelopes found.\n");
        exit(0);
    }
} else {
    foreach ($keys as $key) {
        if (!array_key_exists($key, $definitions)) {
            cbs_die("unknown setting key: $key");
        }
        $def = $definitions[$key];
        if (empty($def['sensitive'])) {
            cbs_die("refusing to touch non-sensitive key: $key");
        }
        // CR feedback: explicitly reject sensitive-but-not-managed keys with
        // a clear error rather than the misleading "decrypts cleanly" path.
        // backup_vault_key is the canonical example — it is sensitive but
        // stored outside the IPAMSEC1 envelope, so this tool cannot help it.
        if (!ipam_secret_is_managed_key($key)) {
            cbs_die(
                "key $key is sensitive but not stored as a managed IPAMSEC1 envelope; "
                . "this tool only handles managed envelopes (see ipam_secret_managed_keys())"
            );
        }
        $raw = $readRaw($key);
        if ($raw === null) {
            cbs_die("no row to clear for $key (already at registry default)");
        }
        if (!$isBroken($raw)) {
            cbs_die("row for $key decrypts cleanly under the current app_secret — refusing to clear");
        }
        $targets[] = $key;
    }
}

// Plan summary.
fwrite(STDOUT, ($dryRun ? "[dry-run] would clear" : "clearing") . " " . count($targets) . " broken setting row(s):\n");
foreach ($targets as $key) {
    fwrite(STDOUT, "  - $key\n");
}

if ($dryRun) {
    exit(0);
}

// Apply. One transaction. ipam_setting_set() with NULL would write an
// envelope-of-null; we want the row gone so reads fall through to the
// registry default exactly as for an install that never set the key.
$ownTx = !$db->inTransaction();
if ($ownTx) $db->beginTransaction();
try {
    $st = $db->prepare("DELETE FROM settings WHERE tenant_id IS NULL AND {$kc} = :k");
    foreach ($targets as $key) {
        $st->execute([':k' => $key]);
        // CR feedback: audit every clear so recovery runs are visible in
        // incident forensics. CLI context: current_user() returns the
        // empty-id sentinel and client_ip() is empty — that's fine, the
        // action + entity + details (key name) are what an auditor needs.
        audit($db, 'setting.clear_broken_secret', 'setting', null, $key);
        ipam_setting_cache_bust($key);
    }
    if ($ownTx) $db->commit();
} catch (\Throwable $e) {
    if ($ownTx) {
        try { $db->rollBack(); } catch (\Throwable) {}
    }
    cbs_die('DB error during clear: ' . $e->getMessage());
}

fwrite(STDOUT, "done. Resave the affected key(s) through the Settings UI to restore the intended value.\n");
exit(0);
