<?php
declare(strict_types=1);

function ipam_db(string $path): PDO
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("PRAGMA journal_mode = WAL;");
    $pdo->exec("PRAGMA foreign_keys = ON;");
    return $pdo;
}

function ipam_db_init(PDO $db): void
{
    $st = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
    $st->execute();
    $hasUsers = (bool)$st->fetch();

    if (!$hasUsers) {
        $schema = file_get_contents(__DIR__ . '/schema.sql');
        if ($schema === false) throw new RuntimeException("Cannot read schema.sql");
        $db->exec($schema);

        $config = require __DIR__ . '/config.php';
        $u = $config['bootstrap_admin']['username'];
        $p = $config['bootstrap_admin']['password'];

        $hash = password_hash($p, PASSWORD_DEFAULT);
        $ins = $db->prepare("INSERT INTO users (username, password_hash, role, is_active) VALUES (:u,:h,'admin',1)");
        $ins->execute([':u' => $u, ':h' => $hash]);

        // Stamp all known migrations as already satisfied by the fresh schema
        ensure_migrations_table($db);
        require_once __DIR__ . '/migrations.php';
        $stamp = $db->prepare("INSERT OR IGNORE INTO schema_migrations (version) VALUES (:v)");
        foreach (array_keys(ipam_migrations()) as $ver) {
            $stamp->execute([':v' => $ver]);
        }
        return;
    }

    ensure_migrations_table($db);
    apply_migrations($db);

    $st = $db->prepare("SELECT COUNT(*) AS c FROM users");
    $st->execute();
    $count = (int)$st->fetch()['c'];
    if ($count === 0) {
        $config = require __DIR__ . '/config.php';
        $u = $config['bootstrap_admin']['username'];
        $p = $config['bootstrap_admin']['password'];
        $hash = password_hash($p, PASSWORD_DEFAULT);
        $ins = $db->prepare("INSERT INTO users (username, password_hash, role, is_active) VALUES (:u,:h,'admin',1)");
        $ins->execute([':u' => $u, ':h' => $hash]);
    }
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------------- CSRF ---------------- */

function csrf_token(): string { return $_SESSION['csrf'] ?? ''; }

function csrf_require(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $sent = $_POST['csrf'] ?? '';
    $real = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($real, $sent)) {
        http_response_code(400);
        exit('Bad CSRF token');
    }
}

/* ---------------- Auth / RBAC ---------------- */

function is_logged_in(): bool { return !empty($_SESSION['uid']); }

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
    $idle = (int)(($GLOBALS['config'] ?? [])['session_idle_seconds'] ?? 1800);
    if (isset($_SESSION['last_active']) && (time() - (int)$_SESSION['last_active']) > $idle) {
        logout_user();
        header('Location: login.php?timeout=1');
        exit;
    }
    $_SESSION['last_active'] = time();

    // Password expiry check — local accounts only, skip on change_password / logout pages
    $policy  = (array)(($GLOBALS['config'] ?? [])['password_policy'] ?? []);
    $maxAge  = (int)($policy['max_password_age_days'] ?? 0);
    if ($maxAge > 0) {
        $page = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if (!in_array($page, ['change_password.php', 'logout.php'], true)) {
            try {
                $db = $GLOBALS['db'] ?? null;
                if ($db instanceof PDO) {
                    $st = $db->prepare("SELECT oidc_sub, password_changed_at FROM users WHERE id = :id");
                    $st->execute([':id' => (int)($_SESSION['uid'] ?? 0)]);
                    $row = $st->fetch();
                    if ($row && $row['oidc_sub'] === null) {
                        $changedAt = (string)($row['password_changed_at'] ?? '');
                        $cutoff    = date('Y-m-d H:i:s', time() - $maxAge * 86400);
                        if ($changedAt === '' || $changedAt < $cutoff) {
                            header('Location: change_password.php?expired=1');
                            exit;
                        }
                    }
                }
            } catch (Throwable) {
                // Column may not exist yet on pre-1.4 installs — silently skip
            }
        }
    }
}

/**
 * Validate a password against the configured policy.
 * Returns an empty array on success, or an array of all violation messages.
 */
function validate_password_complexity(string $password, array $policy): array
{
    $errors = [];
    $min = max(1, (int)($policy['min_length'] ?? 12));
    if (strlen($password) < $min) {
        $errors[] = "Password must be at least {$min} characters.";
    }
    if (!empty($policy['require_uppercase']) && !preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter (A–Z).';
    }
    if (!empty($policy['require_lowercase']) && !preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter (a–z).';
    }
    if (!empty($policy['require_number']) && !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number (0–9).';
    }
    if (!empty($policy['require_symbol']) && !preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character.';
    }
    return $errors;
}

function current_user(): array
{
    return [
        'id' => (int)($_SESSION['uid'] ?? 0),
        'username' => (string)($_SESSION['username'] ?? ''),
        'role' => (string)($_SESSION['role'] ?? ''),
    ];
}

function require_role(string $role): void
{
    require_login();
    if (current_user()['role'] !== $role) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function require_write_access(): void
{
    require_login();
    if (current_user()['role'] === 'readonly') {
        http_response_code(403);
        exit('Read-only account');
    }
}

function login_user(int $uid, string $username, string $role): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    $_SESSION['username'] = $username;
    $_SESSION['role'] = $role;
    $_SESSION['last_active'] = time();
}

/* ---------------- Login rate limiting ---------------- */

function login_rate_limited(PDO $db, string $ip, int $maxAttempts, int $windowSeconds): bool
{
    $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);
    $st = $db->prepare("SELECT COUNT(*) AS c FROM login_attempts WHERE ip = :ip AND attempted_at >= :cutoff");
    $st->execute([':ip' => $ip, ':cutoff' => $cutoff]);
    return (int)$st->fetch()['c'] >= $maxAttempts;
}

function record_login_failure(PDO $db, string $ip): void
{
    $db->prepare("INSERT INTO login_attempts (ip) VALUES (:ip)")
       ->execute([':ip' => $ip]);
}

function clear_login_failures(PDO $db, string $ip): void
{
    $db->prepare("DELETE FROM login_attempts WHERE ip = :ip")
       ->execute([':ip' => $ip]);
}

function purge_old_login_attempts(PDO $db, int $windowSeconds): void
{
    $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);
    $db->prepare("DELETE FROM login_attempts WHERE attempted_at < :cutoff")
       ->execute([':cutoff' => $cutoff]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"], $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/* ---------------- Audit ---------------- */

function client_ip(): string
{
    if (!empty($GLOBALS['config']['proxy_trust']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts     = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        $candidate = $parts[0] ?? '';
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return $candidate;
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
}

function audit(PDO $db, string $action, string $entityType, ?int $entityId, string $details = ''): void
{
    $u = current_user();
    $st = $db->prepare("INSERT INTO audit_log (user_id, username, action, entity_type, entity_id, ip, user_agent, details)
                        VALUES (:uid,:un,:ac,:et,:eid,:ip,:ua,:dt)");
    $st->execute([
        ':uid' => $u['id'] ?: null,
        ':un'  => $u['username'] ?: null,
        ':ac'  => $action,
        ':et'  => $entityType,
        ':eid' => $entityId,
        ':ip'  => client_ip() ?: null,
        ':ua'  => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ':dt'  => $details,
    ]);
}

function audit_export(PDO $db, string $what, string $details = ''): void
{
    audit($db, "export.$what", 'system', null, $details);
}

/* ---------------- History ---------------- */

function history_log_address(PDO $db, string $action, int $subnetId, string $ip, ?int $addressId, ?array $before, ?array $after): void
{
    $u = current_user();
    $st = $db->prepare("
        INSERT INTO address_history
          (address_id, subnet_id, ip, action, user_id, username, client_ip, user_agent, before_json, after_json)
        VALUES
          (:aid, :sid, :ip, :ac, :uid, :un, :cip, :ua, :bj, :aj)
    ");
    $st->execute([
        ':aid' => $addressId,
        ':sid' => $subnetId,
        ':ip'  => $ip,
        ':ac'  => $action,
        ':uid' => $u['id'] ?: null,
        ':un'  => $u['username'] ?: null,
        ':cip' => client_ip() ?: null,
        ':ua'  => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ':bj'  => $before ? json_encode($before, JSON_UNESCAPED_SLASHES) : null,
        ':aj'  => $after ? json_encode($after, JSON_UNESCAPED_SLASHES) : null,
    ]);
}

/* ---------------- Migrations ---------------- */

function ensure_migrations_table(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        version TEXT NOT NULL UNIQUE,
        applied_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
}

function applied_migrations(PDO $db): array
{
    $st = $db->prepare("SELECT version FROM schema_migrations");
    $st->execute();
    return array_map(fn($r) => (string)$r['version'], $st->fetchAll());
}

function apply_migrations(PDO $db): array
{
    ensure_migrations_table($db);
    require_once __DIR__ . '/migrations.php';

    $migs = ipam_migrations();
    ksort($migs, SORT_NATURAL);

    $done = array_flip(applied_migrations($db));
    $appliedNow = [];

    foreach ($migs as $ver => $fn) {
        if (isset($done[$ver])) continue;

        $db->beginTransaction();
        try {
            $fn($db);
            $st = $db->prepare("INSERT INTO schema_migrations (version) VALUES (:v)");
            $st->execute([':v' => $ver]);
            $db->commit();
            $appliedNow[] = $ver;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    return $appliedNow;
}

/* ---------------- Config auto-population ---------------- */

/**
 * Returns the canonical defaults map for all config keys that should exist in
 * config.php. Each entry: ['default' => mixed, 'comment' => string].
 * Only top-level keys are tracked; nested sub-keys are managed per-key.
 */
function ipam_config_defaults(): array
{
    return [
        'db_path' => [
            'default' => null, // path-dependent, skip auto-append
            'comment' => '',
        ],
        'session_name' => ['default' => null, 'comment' => ''],
        'proxy_trust'  => ['default' => null, 'comment' => ''],
        'bootstrap_admin' => ['default' => null, 'comment' => ''],
        'session_idle_seconds' => ['default' => null, 'comment' => ''],
        'login_max_attempts'   => ['default' => null, 'comment' => ''],
        'login_lockout_seconds'=> ['default' => null, 'comment' => ''],
        'api_max_attempts' => [
            'default' => 20,
            'comment' => 'Max failed API key attempts per IP before lockout.',
        ],
        'api_lockout_seconds' => [
            'default' => 300,
            'comment' => 'Duration (seconds) of API key lockout after too many failed attempts.',
        ],
        'import_csv_max_mb'    => ['default' => null, 'comment' => ''],
        'tmp_cleanup_ttl_seconds' => ['default' => null, 'comment' => ''],
        'audit_log_retention_days' => [
            'default' => 0,
            'comment' => 'Audit log retention (days). Entries older than this are pruned during housekeeping. 0 = keep forever.',
        ],
        'housekeeping' => ['default' => null, 'comment' => ''],
        'utilization_warn'     => ['default' => null, 'comment' => ''],
        'utilization_critical' => ['default' => null, 'comment' => ''],
        'update_check' => [
            'default' => [
                'enabled'           => true,
                'ttl_seconds'       => 86400,
                'notify_prerelease' => false,
            ],
            'comment' => 'Update check: fetches releases from GitHub and shows a banner when a newer version is available.',
        ],
        'backup' => [
            'default' => [
                'enabled'   => false,
                'frequency' => 'daily',
                'retention' => 7,
                'dir'       => '',
            ],
            'comment' => "Automatic database backups. frequency: 'daily' | 'weekly'. retention: keep last N backups.",
        ],
        'oidc' => [
            'default' => [
                'enabled'                  => false,
                'display_name'             => 'SSO',
                'client_id'                => '',
                'client_secret'            => '',
                'discovery_url'            => '',
                'redirect_uri'             => '',
                'scopes'                   => 'openid email profile',
                'auto_link'                => false,
                'auto_provision'           => false,
                'default_role'             => 'readonly',
                'disable_local_login'      => false,
                'hide_emergency_link'      => false,
                'disable_emergency_bypass' => false,
            ],
            'comment' => 'OIDC SSO configuration. See docs/oidc.md for full details.',
        ],
        'password_policy' => [
            'default' => [
                'min_length'            => 12,
                'require_uppercase'     => false,
                'require_lowercase'     => false,
                'require_number'        => false,
                'require_symbol'        => false,
                'max_password_age_days' => 0,
            ],
            'comment' => "Password complexity and rotation policy. min_length: minimum chars. require_*: enforce character classes. max_password_age_days: 0 = never expires.",
        ],
    ];
}

/**
 * Format a PHP value as clean source code with array [] syntax.
 */
function ipam_php_export(mixed $val, int $indent = 1): string
{
    if (is_null($val))    return 'null';
    if (is_bool($val))    return $val ? 'true' : 'false';
    if (is_int($val))     return (string)$val;
    if (is_float($val))   return rtrim(number_format($val, 10, '.', ''), '0') ?: '0.0';
    if (is_string($val))  return "'" . addcslashes($val, "'\\") . "'";

    if (is_array($val)) {
        if (count($val) === 0) return '[]';
        $pad = str_repeat('    ', $indent);
        $outerPad = str_repeat('    ', $indent - 1);
        $isList = array_keys($val) === range(0, count($val) - 1);
        $out = "[\n";
        foreach ($val as $k => $v) {
            $keyStr = $isList ? '' : "'" . addcslashes((string)$k, "'\\") . "' => ";
            $out .= $pad . $keyStr . ipam_php_export($v, $indent + 1) . ",\n";
        }
        $out .= $outerPad . ']';
        return $out;
    }

    return var_export($val, true);
}

/**
 * Check config.php for missing top-level keys and missing sub-keys within
 * existing nested blocks, and append them with their defaults.
 *
 * Returns list of key names added (top-level as 'key', nested as 'key.subkey').
 * Only keys whose default is not null are auto-appended.
 * The 'bootstrap_admin' block is never deep-merged (admin sets it intentionally).
 */
function ipam_config_sync(string $configPath, array $loaded): array
{
    $defaults = ipam_config_defaults();
    $added    = [];

    foreach ($defaults as $key => $meta) {
        if ($meta['default'] === null) continue;

        if (!array_key_exists($key, $loaded)) {
            // --- Top-level key missing: append whole block ---
            $content = @file_get_contents($configPath);
            if ($content === false) break;
            if (!preg_match('/\n\];\s*$/', $content)) break;

            $comment  = (string)$meta['comment'];
            $valuePhp = ipam_php_export($meta['default'], 2);
            $block    = '';
            if ($comment !== '') $block .= "\n    // " . $comment;
            $block .= "\n    '" . addcslashes($key, "'\\") . "' => " . $valuePhp . ",\n";

            $content = preg_replace('/\n\];\s*$/', $block . "\n];", $content);
            if ($content === null) break;
            if (@file_put_contents($configPath, $content) !== false) {
                $added[] = $key;
            }
        } elseif ($key !== 'bootstrap_admin'
               && is_array($meta['default'])
               && is_array($loaded[$key])) {
            // --- Nested block exists: deep-merge missing sub-keys ---
            $missingSubKeys = array_diff_key($meta['default'], $loaded[$key]);
            foreach ($missingSubKeys as $subKey => $subDefault) {
                $content = @file_get_contents($configPath);
                if ($content === false) break 2;

                $escapedKey = preg_quote($key, '/');
                $subValuePhp = ipam_php_export($subDefault, 3);
                $newLine = "\n        '" . addcslashes((string)$subKey, "'\\") . "' => " . $subValuePhp . ",";

                // Match '    'key' => [  ...content...  \n    ],'
                $pattern = '/(\n    \'' . $escapedKey . '\'\s*=>\s*\[)(.*?)(\n    \],)/s';
                if (!preg_match($pattern, $content)) break;

                $content = preg_replace_callback($pattern, static function (array $m) use ($newLine): string {
                    return $m[1] . $m[2] . $newLine . $m[3];
                }, $content);

                if ($content === null) break;
                if (@file_put_contents($configPath, $content) !== false) {
                    $added[] = $key . '.' . $subKey;
                }
            }
        }
    }

    return $added;
}

/* ---------------- Housekeeping ---------------- */

function housekeeping_state_path(): string
{
    return __DIR__ . '/data/housekeeping.json';
}

function housekeeping_should_run(array $config): bool
{
    $hk = $config['housekeeping'] ?? [];
    if (empty($hk['enabled'])) return false;

    $interval = (int)($hk['interval_seconds'] ?? 86400);
    if ($interval < 3600) $interval = 3600;

    $path = housekeeping_state_path();
    if (!is_file($path)) return true;

    $last = @filemtime($path);
    if ($last === false) return true;

    return (time() - $last) >= $interval;
}

function housekeeping_mark_ran(): void
{
    $path = housekeeping_state_path();
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0700, true);

    @file_put_contents($path, json_encode(['last_run' => time()], JSON_PRETTY_PRINT));
    @chmod($path, 0600);
}

function prune_audit_log(PDO $db, int $retentionDays): int
{
    if ($retentionDays <= 0) return 0;
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

    // The audit_log triggers block DELETE, so we bypass them via a shadow table swap.
    // Instead, we use a workaround: recreate the table without the old rows.
    // Actually, the triggers only fire on the audit_log table — use a direct DELETE
    // after temporarily dropping and re-adding them.
    //
    // SQLite doesn't support DROP TRIGGER IF EXISTS inside a transaction on all versions,
    // so we track deletable rows and remove via the internal rowid trick which bypasses
    // row-level triggers. The canonical safe approach: use a staging table.
    $db->beginTransaction();
    try {
        // Collect IDs of rows to keep (newer than cutoff)
        $st = $db->query(
            "SELECT id FROM audit_log WHERE created_at >= " . $db->quote($cutoff)
        );
        $keepIds = array_column($st->fetchAll(), 'id');

        // Rename, recreate, copy kept rows, drop old
        $db->exec("ALTER TABLE audit_log RENAME TO audit_log_old");
        $db->exec("
            CREATE TABLE audit_log (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              created_at TEXT NOT NULL DEFAULT (datetime('now')),
              user_id INTEGER, username TEXT, action TEXT NOT NULL,
              entity_type TEXT NOT NULL, entity_id INTEGER,
              ip TEXT, user_agent TEXT, details TEXT
            )
        ");

        if (!empty($keepIds)) {
            $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
            $db->prepare(
                "INSERT INTO audit_log SELECT * FROM audit_log_old WHERE id IN ($placeholders)"
            )->execute($keepIds);
        }

        $countSt = $db->query("SELECT COUNT(*) FROM audit_log_old");
        $oldCount = (int)$countSt->fetchColumn();
        $newCount = count($keepIds);
        $pruned   = $oldCount - $newCount;

        $db->exec("DROP TABLE audit_log_old");

        // Re-add the append-only triggers
        $db->exec("
            CREATE TRIGGER IF NOT EXISTS audit_log_no_update
            BEFORE UPDATE ON audit_log
            BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END
        ");
        $db->exec("
            CREATE TRIGGER IF NOT EXISTS audit_log_no_delete
            BEFORE DELETE ON audit_log
            BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END
        ");

        $db->commit();
        return $pruned;
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('audit_log prune failed: ' . $e->getMessage());
        return 0;
    }
}

function run_housekeeping_if_due(array $config, ?PDO $db = null): void
{
    if (!housekeeping_should_run($config)) return;

    $lockPath = __DIR__ . '/data/housekeeping.lock';
    $lock = @fopen($lockPath, 'c');
    if (!$lock) return;

    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return;
    }

    try {
        if (!housekeeping_should_run($config)) return;

        $ttl = (int)($config['tmp_cleanup_ttl_seconds'] ?? 86400);
        if ($ttl < 3600) $ttl = 3600;

        cleanup_tmp_import_files($ttl);
        cleanup_tmp_import_plans($ttl);

        if ($db !== null) {
            $retentionDays = (int)($config['audit_log_retention_days'] ?? 0);
            if ($retentionDays > 0) {
                prune_audit_log($db, $retentionDays);
            }
        }

        housekeeping_mark_ran();
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

/* ---------------- Database Backups ---------------- */

function backup_dir(array $config): string
{
    $d = trim((string)($config['backup']['dir'] ?? ''));
    if ($d === '') {
        return __DIR__ . '/data/backups';
    }
    // Make relative paths relative to the app directory
    if (!str_starts_with($d, '/')) {
        $d = __DIR__ . '/' . $d;
    }
    return rtrim($d, '/');
}

function backup_state_path(): string
{
    return __DIR__ . '/data/backup-state.json';
}

function backup_interval_seconds(array $config): int
{
    $freq = strtolower(trim((string)($config['backup']['frequency'] ?? 'daily')));
    return match ($freq) {
        'weekly' => 604800,
        default  => 86400,  // 'daily'
    };
}

function backup_is_due(array $config): bool
{
    $bk = $config['backup'] ?? [];
    if (empty($bk['enabled'])) return false;

    $path = backup_state_path();
    if (!is_file($path)) return true;

    $d = @json_decode((string)file_get_contents($path), true);
    if (!is_array($d) || !isset($d['last_backup'])) return true;

    return (time() - (int)$d['last_backup']) >= backup_interval_seconds($config);
}

/**
 * Run a database backup if one is due. Uses WAL checkpoint + file copy for
 * a consistent snapshot without requiring SQLite3 extension.
 * Returns true if a backup was written, false otherwise.
 */
function run_db_backup_if_due(PDO $db, array $config): bool
{
    if (!backup_is_due($config)) return false;

    $lockPath = __DIR__ . '/data/backup.lock';
    $lock = @fopen($lockPath, 'c');
    if (!$lock) return false;

    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        @fclose($lock);
        return false;
    }

    $wrote = false;
    try {
        if (!backup_is_due($config)) return false;

        $dbPath = (string)($GLOBALS['config']['db_path'] ?? (__DIR__ . '/data/ipam.sqlite'));
        if (!is_file($dbPath)) return false;

        $dir = backup_dir($config);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0700, true)) return false;
        }

        // Flush WAL to the main database file for a consistent copy
        try { $db->exec("PRAGMA wal_checkpoint(FULL)"); } catch (Throwable) {}

        $ts   = date('Y-m-d-His');
        $dest = $dir . '/ipam-' . $ts . '.sqlite';

        if (@copy($dbPath, $dest)) {
            @chmod($dest, 0600);
            $wrote = true;

            // Prune old backups according to retention policy
            $retention = max(1, (int)($config['backup']['retention'] ?? 7));
            $files = glob($dir . '/ipam-*.sqlite');
            if (is_array($files)) {
                rsort($files); // newest first (lexicographic = chronological for our format)
                foreach (array_slice($files, $retention) as $old) {
                    @unlink($old);
                }
            }

            // Record backup timestamp
            $state = ['last_backup' => time(), 'last_file' => basename($dest)];
            @file_put_contents(backup_state_path(), json_encode($state));
            @chmod(backup_state_path(), 0600);
        }
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }

    return $wrote;
}

/**
 * Return info about the current backup state for display in the admin panel.
 * ['last_backup' => timestamp|null, 'last_file' => string|null, 'count' => int, 'dir' => string]
 */
function backup_info(array $config): array
{
    $dir   = backup_dir($config);
    $state = backup_state_path();
    $last  = null;
    $file  = null;

    if (is_file($state)) {
        $d = @json_decode((string)file_get_contents($state), true);
        if (is_array($d)) {
            $last = isset($d['last_backup']) ? (int)$d['last_backup'] : null;
            $file = isset($d['last_file'])   ? (string)$d['last_file'] : null;
        }
    }

    $files = is_dir($dir) ? (glob($dir . '/ipam-*.sqlite') ?: []) : [];

    return [
        'last_backup' => $last,
        'last_file'   => $file,
        'count'       => count($files),
        'dir'         => $dir,
    ];
}

/**
 * Generate a full SQL dump of the SQLite database suitable for import.
 */
function ipam_db_dump(PDO $db): string
{
    $out = "-- Simple PHP IPAM database dump\n";
    $out .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $out .= "PRAGMA foreign_keys=OFF;\n";
    $out .= "BEGIN TRANSACTION;\n\n";

    // Tables: schema + data
    $tables = $db->query(
        "SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
    )->fetchAll();

    foreach ($tables as $t) {
        $name        = (string)$t['name'];
        $quotedName  = '"' . str_replace('"', '""', $name) . '"';
        $out .= "-- Table: {$name}\n";
        $out .= $t['sql'] . ";\n";

        // Dump triggers for this table so they are recreated on import.
        // (Dropping a table also drops its triggers; they must be re-stated.)
        $triggers = $db->query(
            "SELECT sql FROM sqlite_master WHERE type='trigger' AND tbl_name="
            . $db->quote($name) . " AND sql IS NOT NULL ORDER BY name"
        )->fetchAll();
        foreach ($triggers as $trig) {
            $out .= $trig['sql'] . ";\n";
        }

        // Identify BLOB columns so we can always hex-encode raw binary data.
        // mb_check_encoding() is unreliable: many 4-byte IPv4 blobs (e.g. 10.38.83.x)
        // happen to be valid UTF-8 sequences and would be misclassified as text.
        $colInfo  = $db->query("PRAGMA table_info({$quotedName})")->fetchAll();
        $blobCols = [];
        foreach ($colInfo as $ci) {
            if (strtoupper((string)$ci['type']) === 'BLOB') {
                $blobCols[(string)$ci['name']] = true;
            }
        }

        $rows = $db->query("SELECT * FROM {$quotedName}")->fetchAll();
        foreach ($rows as $row) {
            $cols = array_map(
                fn($c) => '"' . str_replace('"', '""', (string)$c) . '"',
                array_keys($row)
            );
            $vals = [];
            foreach ($row as $colName => $v) {
                if ($v === null) {
                    $vals[] = 'NULL';
                } elseif (is_int($v) || is_float($v)) {
                    $vals[] = (string)$v;
                } elseif (isset($blobCols[$colName])) {
                    // Binary blob (ip_bin, network_bin): always use hex literal.
                    $vals[] = "X'" . bin2hex((string)$v) . "'";
                } else {
                    // TEXT column: hex-encode via CAST so the value is stored as TEXT.
                    // This safely handles any content including single quotes, semicolons,
                    // newlines, and NUL bytes without any SQL injection risk.
                    $vals[] = "CAST(X'" . bin2hex((string)$v) . "' AS TEXT)";
                }
            }
            $out .= "INSERT INTO {$quotedName} (" . implode(',', $cols) . ") VALUES ("
                  . implode(',', $vals) . ");\n";
        }
        $out .= "\n";
    }

    // Indices (non-system)
    $indices = $db->query(
        "SELECT sql FROM sqlite_master WHERE type='index' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY name"
    )->fetchAll();
    if ($indices) {
        $out .= "-- Indexes\n";
        foreach ($indices as $idx) {
            $out .= $idx['sql'] . ";\n";
        }
        $out .= "\n";
    }

    $out .= "COMMIT;\n";
    $out .= "PRAGMA foreign_keys=ON;\n";

    return $out;
}

/* ---------------- Pagination ---------------- */

function q_int(string $key, int $default, int $min, int $max): int
{
    $v = $_GET[$key] ?? null;
    if ($v === null || $v === '') return $default;
    if (!is_scalar($v)) return $default;
    if (!preg_match('/^-?\d+$/', (string)$v)) return $default;

    $n = (int)$v;
    if ($n < $min) return $min;
    if ($n > $max) return $max;
    return $n;
}

/**
 * Escape SQL LIKE wildcard characters in a user-supplied search string.
 * Returns the escaped string ready to be wrapped in % delimiters.
 * Use with LIKE :q ESCAPE '\\' in your SQL.
 */
function like_escape(string $q): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
}

function paginate(int $total, int $page, int $pageSize): array
{
    $page = max(1, $page);
    $pageSize = max(1, min(500, $pageSize));
    $pages = (int)max(1, (int)ceil($total / $pageSize));
    if ($page > $pages) $page = $pages;

    return [
        'page' => $page,
        'page_size' => $pageSize,
        'pages' => $pages,
        'offset' => ($page - 1) * $pageSize,
        'limit' => $pageSize,
    ];
}

/* ---------------- CSV export helpers ---------------- */

function safe_export_filename(string $base): string
{
    $base = strtolower($base);
    $base = preg_replace('/[^a-z0-9._-]+/', '-', $base) ?? 'export';
    $base = trim($base, '-.');
    if ($base === '') $base = 'export';
    return $base . '-' . date('Y-m-d-His') . '.csv';
}

function csv_download_headers(string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function csv_output_handle()
{
    static $fh = null;
    if ($fh === null) {
        $fh = fopen('php://output', 'wb');
        if (!$fh) throw new RuntimeException('Cannot open php://output');
    }
    return $fh;
}

function csv_out(array $row): void
{
    $fh = csv_output_handle();
    fputcsv($fh, $row);
}

/* ---------------- IP helpers ---------------- */

function parse_cidr(string $cidr): ?array
{
    $cidr = trim($cidr);
    if (strpos($cidr, '/') === false) return null;
    [$ip, $prefixStr] = explode('/', $cidr, 2);

    $ip = trim($ip);
    $prefixStr = trim($prefixStr);

    $ipBin = @inet_pton($ip);
    if ($ipBin === false) return null;

    $len = strlen($ipBin);
    $version = ($len === 4) ? 4 : (($len === 16) ? 6 : 0);
    if ($version === 0) return null;

    if (!ctype_digit($prefixStr)) return null;
    $prefix = (int)$prefixStr;
    $max = ($version === 4) ? 32 : 128;
    if ($prefix < 0 || $prefix > $max) return null;

    $netBin = apply_prefix_mask($ipBin, $prefix);
    $network = inet_ntop($netBin);

    return [
        'version' => $version,
        'network' => $network,
        'prefix' => $prefix,
        'net_bin' => $netBin,
    ];
}

function apply_prefix_mask(string $ipBin, int $prefix): string
{
    $len = strlen($ipBin);
    $maxBits = ($len === 4) ? 32 : 128;
    $prefix = max(0, min($prefix, $maxBits));

    $fullBytes = intdiv($prefix, 8);
    $remBits = $prefix % 8;

    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $b = ord($ipBin[$i]);
        if ($i < $fullBytes) $out .= chr($b);
        elseif ($i === $fullBytes && $remBits !== 0) {
            $mask = (0xFF << (8 - $remBits)) & 0xFF;
            $out .= chr($b & $mask);
        } else $out .= chr(0);
    }
    return $out;
}

function ip_in_cidr(string $ip, string $network, int $prefix): bool
{
    $ipBin = @inet_pton(trim($ip));
    $netBin = @inet_pton(trim($network));
    if ($ipBin === false || $netBin === false) return false;
    if (strlen($ipBin) !== strlen($netBin)) return false;
    return hash_equals(apply_prefix_mask($ipBin, $prefix), $netBin);
}

function normalize_ip(string $ip): ?array
{
    $bin = @inet_pton(trim($ip));
    if ($bin === false) return null;
    return ['ip' => inet_ntop($bin), 'bin' => $bin, 'version' => (strlen($bin) === 4) ? 4 : 6];
}

function normalize_status(?string $s): string
{
    $s = strtolower(trim((string)$s));
    if ($s === '') return 'used';
    if (in_array($s, ['used','reserved','free'], true)) return $s;
    if (in_array($s, ['inuse','in-use','active'], true)) return 'used';
    if (in_array($s, ['res','reservation'], true)) return 'reserved';
    if (in_array($s, ['avail','available','unused'], true)) return 'free';
    return 'used';
}

/* ---------------- IPv4 helpers ---------------- */

function ipv4_bin_to_int(string $bin): int
{
    $n = unpack('N', $bin)[1];
    return (int)($n & 0xFFFFFFFF);
}

function ipv4_int_to_bin(int $n): string
{
    $n = $n & 0xFFFFFFFF;
    return pack('N', $n);
}

function ipv4_int_to_text(int $n): string
{
    return inet_ntop(ipv4_int_to_bin($n));
}

function ipv4_assignable_count(int $prefix): int
{
    if ($prefix >= 32) return 1;
    if ($prefix === 31) return 2;
    $hostBits = 32 - $prefix;
    $total = ($hostBits === 32) ? 4294967296 : (1 << $hostBits);
    $assignable = $total - 2;
    return ($assignable > 0) ? (int)$assignable : 0;
}

function ipv4_broadcast_int(int $networkInt, int $prefix): int
{
    $hostBits = 32 - $prefix;
    if ($hostBits <= 0) return $networkInt;
    $hostMask = ($hostBits === 32) ? 0xFFFFFFFF : ((1 << $hostBits) - 1);
    return (int)(($networkInt | $hostMask) & 0xFFFFFFFF);
}

/* ---------------- CSV import helpers ---------------- */

function import_max_bytes(array $config): int
{
    $mb = (int)($config['import_csv_max_mb'] ?? 5);
    if ($mb < 5) $mb = 5;
    if ($mb > 50) $mb = 50;
    return $mb * 1024 * 1024;
}

function tmp_dir(): string
{
    return __DIR__ . '/data/tmp';
}

function ensure_tmp_dir(): void
{
    $d = tmp_dir();
    if (!is_dir($d)) mkdir($d, 0700, true);
}

function cleanup_tmp_import_files(int $ttlSeconds): int
{
    ensure_tmp_dir();
    $now = time();
    $deleted = 0;

    foreach (new DirectoryIterator(tmp_dir()) as $f) {
        if ($f->isDot() || !$f->isFile()) continue;
        $name = $f->getFilename();
        if (!preg_match('~^import-[a-f0-9]{16}\.csv$~', $name)) continue;

        $age = $now - $f->getMTime();
        if ($age > $ttlSeconds) {
            @unlink($f->getPathname());
            $deleted++;
        }
    }
    return $deleted;
}

/* -------- Import plan helpers -------- */

function import_plan_dir(): string
{
    return tmp_dir();
}

function save_import_plan(array $plan): string
{
    ensure_tmp_dir();
    $path = import_plan_dir() . '/import-plan-' . bin2hex(random_bytes(8)) . '.json';
    $json = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('Failed to encode import plan');
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('Failed to write import plan');
    }
    @chmod($path, 0600);
    return $path;
}

function load_import_plan(string $path): array
{
    if (!is_file($path)) throw new RuntimeException('Import plan file not found');
    $json = file_get_contents($path);
    if ($json === false) throw new RuntimeException('Failed to read import plan');
    $data = json_decode($json, true);
    if (!is_array($data)) throw new RuntimeException('Invalid import plan');
    return $data;
}

function delete_import_plan(string $path): void
{
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
}

function cleanup_tmp_import_plans(int $ttlSeconds): int
{
    ensure_tmp_dir();
    $now = time();
    $deleted = 0;

    foreach (new DirectoryIterator(tmp_dir()) as $f) {
        if ($f->isDot() || !$f->isFile()) continue;
        $name = $f->getFilename();
        if (!preg_match('~^import-plan-[a-f0-9]{16}\.json$~', $name)) continue;

        $age = $now - $f->getMTime();
        if ($age > $ttlSeconds) {
            @unlink($f->getPathname());
            $deleted++;
        }
    }
    return $deleted;
}

function detect_csv_delimiter(string $sample): string
{
    $candidates = ["," , ";" , "\t" , "|"];
    $best = ",";
    $bestCount = -1;

    foreach ($candidates as $d) {
        $lines = preg_split("/\r\n|\n|\r/", $sample);
        $counts = [];
        foreach (array_slice($lines, 0, 10) as $line) {
            if ($line === '') continue;
            $counts[] = count(str_getcsv($line, $d));
        }
        if (!$counts) continue;
        $avg = array_sum($counts) / count($counts);
        if ($avg > $bestCount) {
            $bestCount = $avg;
            $best = $d;
        }
    }
    return $best;
}

function csv_read_preview(string $path, string $delimiter, int $maxRows = 20): array
{
    $fh = fopen($path, 'rb');
    if (!$fh) throw new RuntimeException("Cannot open upload");
    $rows = [];
    while (!feof($fh) && count($rows) < $maxRows) {
        $row = fgetcsv($fh, 0, $delimiter);
        if ($row === false) break;
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function netmask_to_prefix(string $mask): ?int
{
    $bin = @inet_pton(trim($mask));
    if ($bin === false || strlen($bin) !== 4) return null;

    $n = unpack('N', $bin)[1];
    $prefix = 0;
    $seenZero = false;

    for ($i = 31; $i >= 0; $i--) {
        $bit = ($n >> $i) & 1;
        if ($bit === 1) {
            if ($seenZero) return null;
            $prefix++;
        } else {
            $seenZero = true;
        }
    }
    return $prefix;
}

function find_containing_subnet(PDO $db, array $normIp): ?array
{
    $ver = (int)$normIp['version'];
    $st = $db->prepare("SELECT id, network, prefix, ip_version FROM subnets WHERE ip_version = :v ORDER BY prefix DESC");
    $st->execute([':v' => $ver]);
    foreach ($st->fetchAll() as $s) {
        if (ip_in_cidr($normIp['ip'], (string)$s['network'], (int)$s['prefix'])) return $s;
    }
    return null;
}

function ensure_subnet_exists(PDO $db, string $cidr, string $description = ''): int
{
    $p = parse_cidr($cidr);
    if (!$p) throw new RuntimeException("Invalid CIDR to create: $cidr");

    $normalized = $p['network'] . '/' . $p['prefix'];

    $st = $db->prepare("SELECT id FROM subnets WHERE cidr = :c");
    $st->execute([':c' => $normalized]);
    $row = $st->fetch();
    if ($row) return (int)$row['id'];

    $ins = $db->prepare("INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description)
                         VALUES (:cidr,:ver,:net,:nb,:pre,:d)");
    $ins->execute([
        ':cidr' => $normalized,
        ':ver' => $p['version'],
        ':net' => $p['network'],
        ':nb' => $p['net_bin'],
        ':pre' => $p['prefix'],
        ':d' => $description,
    ]);

    return (int)$db->lastInsertId();
}

function cidr_from_ip_and_prefix(array $normIp, int $prefix): string
{
    $max = ($normIp['version'] === 4) ? 32 : 128;
    if ($prefix < 0 || $prefix > $max) throw new RuntimeException("Bad prefix");
    $netBin = apply_prefix_mask($normIp['bin'], $prefix);
    return inet_ntop($netBin) . '/' . $prefix;
}

/* ---------------- Subnet overlap detection ---------------- */

/**
 * Detect parent/child relationships for a proposed CIDR against existing subnets.
 *
 * In valid CIDR math, two subnets of different prefix lengths either have a strict
 * parent/child containment relationship or are completely disjoint — partial overlap
 * is impossible. Exact duplicates are prevented by the DB UNIQUE constraint on cidr.
 *
 * Returns:
 *   'parents'  — existing subnets that contain the proposed CIDR (new is a child)
 *   'children' — existing subnets that fall inside the proposed CIDR (new is a parent)
 *
 * Both are informational warnings; neither case blocks the operation, as hierarchical
 * nesting is the expected use-case. Pass $excludeId when checking an update so the
 * subnet being edited is not compared against itself.
 *
 * @return array{parents: list<string>, children: list<string>}
 */
function detect_subnet_overlaps(PDO $db, string $cidr, ?int $excludeId = null): array
{
    $p = parse_cidr($cidr);
    if (!$p) return ['parents' => [], 'children' => []];

    $ver    = (int)$p['version'];
    $prefix = (int)$p['prefix'];
    $netBin = (string)$p['net_bin'];

    $sql    = "SELECT id, cidr, prefix, network_bin FROM subnets WHERE ip_version = :v";
    $params = [':v' => $ver];
    if ($excludeId !== null) {
        $sql .= " AND id != :excl";
        $params[':excl'] = $excludeId;
    }
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $parents  = [];
    $children = [];

    foreach ($rows as $row) {
        $rowPrefix = (int)$row['prefix'];
        $rowNetBin = (string)$row['network_bin'];

        if ($rowPrefix < $prefix) {
            // Candidate parent: does the existing broader subnet contain our new one?
            if (hash_equals(apply_prefix_mask($netBin, $rowPrefix), $rowNetBin)) {
                $parents[] = (string)$row['cidr'];
            }
        } elseif ($rowPrefix > $prefix) {
            // Candidate child: does our new broader subnet contain the existing one?
            if (hash_equals(apply_prefix_mask($rowNetBin, $prefix), $netBin)) {
                $children[] = (string)$row['cidr'];
            }
        }
        // Same prefix: exact duplicate — handled by DB UNIQUE constraint on cidr
    }

    return ['parents' => $parents, 'children' => $children];
}

/* ---------------- UI helpers ---------------- */

function page_header(string $title): void
{
    $u = $_SESSION['username'] ?? '';
    $role = $_SESSION['role'] ?? '';

    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    echo "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
    echo "<title>" . e($title) . "</title>";
    echo "<link rel='stylesheet' href='assets/app.css?v=1.5'>";
    echo "<script defer src='assets/app.js?v=1.5'></script>";
    echo "</head><body>";

    echo "<div class='topbar'><div class='nav-wrap'>";
    echo "<div class='nav-links'>";
    if ($u) {
        echo "<a class='nav-pill' href='dashboard.php'>🏠 Dashboard</a>";
        echo "<a class='nav-pill' href='subnets.php'>🌐 Subnets</a>";
        echo "<a class='nav-pill' href='addresses.php'>🧾 Addresses</a>";
        echo "<a class='nav-pill' href='search.php'>🔎 Search</a>";
        echo "<a class='nav-pill' href='audit.php'>📜 Audit</a>";
        // DHCP Pools is a write-access feature — show for admin and netops
        if (in_array($role, ['admin', 'netops'], true)) {
            echo "<a class='nav-pill' href='dhcp_pool.php'>🔒 DHCP</a>";
        }
        if (($role ?? '') === 'admin') {
            echo "<div class='nav-dropdown'>";
            echo "<button type='button' class='nav-pill nav-dropdown-toggle'>⚙ Admin ▾</button>";
            echo "<div class='nav-dropdown-menu'>";
            echo "<a class='nav-dropdown-item' href='sites.php'>📍 Sites</a>";
            echo "<a class='nav-dropdown-item' href='users.php'>👤 Users</a>";
            echo "<a class='nav-dropdown-item' href='api_keys.php'>🔑 API Keys</a>";
            echo "<a class='nav-dropdown-item' href='import_csv.php'>⬆ Import CSV</a>";
            echo "<a class='nav-dropdown-item' href='db_tools.php'>🗄 Database Tools</a>";
            echo "</div></div>";
        }
    } else {
        echo "<a class='nav-pill' href='login.php'>🔐 Login</a>";
    }
    echo "</div>";

    if ($u) {
        echo "<div class='nav-right'>";
        echo "<div class='nav-dropdown'>";
        echo "<button type='button' class='nav-pill nav-dropdown-toggle nav-user-toggle'>";
        echo e((string)$u) . " <span class='badge badge-role-" . e((string)$role) . "'>" . e((string)$role) . "</span> ▾";
        echo "</button>";
        echo "<div class='nav-dropdown-menu nav-dropdown-menu--right'>";
        echo "<button type='button' class='nav-dropdown-item' id='theme-toggle' onclick='ipamCycleTheme()'>🌓 Theme</button>";
        echo "<hr class='nav-dropdown-divider'>";
        echo "<a class='nav-dropdown-item' href='change_password.php'>🔐 Password</a>";
        echo "<a class='nav-dropdown-item' href='logout.php'>↩ Logout</a>";
        echo "</div></div>";
        echo "</div>";
    }

    echo "</div></div>";
    echo "<div class='page'>";

    // Default bootstrap admin password warning (admin only)
    if (($role ?? '') === 'admin') {
        global $config;
        if (($config['bootstrap_admin']['password'] ?? '') === 'ChangeMeNow!12345') {
            echo "<div class='admin-notice admin-notice--danger' role='alert'>"
               . "⚠ <strong>Security warning:</strong> The default bootstrap admin password is still set in <code>config.php</code>. "
               . "<a href='change_password.php'>Change your password</a> and update <code>config.php</code> before this site receives any traffic."
               . "</div>";
        }
    }

    // Config auto-population notice (shown once per session, admin only)
    if (!empty($_SESSION['config_notice']) && ($role ?? '') === 'admin') {
        $notice = e((string)$_SESSION['config_notice']);
        echo "<div class='admin-notice admin-notice--info' role='alert'>"
           . "⚙ Config updated: {$notice} Review and adjust values in config.php."
           . "</div>";
        unset($_SESSION['config_notice']);
    }

    // Update-available dismissible banner (admin only, client-side dismiss via localStorage)
    if (($role ?? '') === 'admin') {
        global $config;
        $update = ipam_update_check($config ?? []);
        if ($update) {
            $uv  = e((string)$update['version']);
            $url = e((string)$update['url']);
            echo "<div class='admin-notice admin-notice--update' id='ipam-update-banner' data-version='{$uv}' role='alert'>"
               . "🚀 Simple PHP IPAM v{$uv} is available. "
               . "<a href='{$url}' target='_blank' rel='noopener'>View release</a>"
               . " &nbsp;<button type='button' class='button-secondary' style='padding:4px 10px;font-size:.85em' "
               . "onclick='ipamDismissUpdate(\"{$uv}\")'>Dismiss</button>"
               . "</div>";
        }
    }
}

function page_footer(): void
{
    global $config;
    require __DIR__ . '/version.php';

    echo "<hr><div class='muted' style='display:flex;align-items:center;gap:10px;flex-wrap:wrap'>";
    echo "<a href='https://github.com/seanmousseau/Simple-PHP-IPAM' target='_blank' rel='noopener' "
       . "style='color:inherit;text-decoration:none'>Simple PHP IPAM</a> v" . e(IPAM_VERSION);

    $update = ipam_update_check($config ?? []);
    if ($update) {
        $uv  = e((string)$update['version']);
        $url = e((string)$update['url']);
        echo " <a href='{$url}' target='_blank' rel='noopener' class='badge badge-update'>"
           . "Update available v{$uv}</a>";
    }

    echo "</div></div></body></html>";
}

/**
 * Normalise a version string to three dot-separated segments so that
 * version_compare('1.2', '1.2.0') and version_compare('1.2.1', '1.2') work
 * as expected regardless of how many segments the installed version has.
 *
 * Examples: '1.2' → '1.2.0',  'v1.2.1' → '1.2.1',  '0.15' → '0.15.0'
 */
function ipam_normalise_version(string $v): string
{
    $v = ltrim($v, 'v');
    $parts = explode('.', $v);
    while (count($parts) < 3) $parts[] = '0';
    return implode('.', $parts);
}

/**
 * Check GitHub for a newer release. Results are cached in data/tmp/ for the
 * configured TTL (default 6 hours). Network failures are silently ignored.
 *
 * Returns ['version' => '1.2.1', 'url' => 'https://...'] if newer, otherwise null.
 */
function ipam_update_check(array $config): ?array
{
    // Memoize within a single request — page_header() and page_footer() both call this
    static $memo = false;
    if ($memo !== false) return $memo;

    $uc = $config['update_check'] ?? [];
    if (isset($uc['enabled']) && !(bool)$uc['enabled']) { $memo = null; return null; }

    $ttl             = max(3600, (int)($uc['ttl_seconds'] ?? 86400));
    $notifyPrerelease = !empty($uc['notify_prerelease']);

    ensure_tmp_dir();
    $cache = tmp_dir() . '/update-check.json';

    if (is_file($cache) && (time() - (int)filemtime($cache)) < $ttl) {
        $d = json_decode((string)file_get_contents($cache), true);
        if (is_array($d) && array_key_exists('checked', $d)) {
            // If the cached update version is <= the running version, we've already
            // upgraded — invalidate so the next check fetches fresh data from GitHub
            require_once __DIR__ . '/version.php';
            if (isset($d['update']['version'])
                && version_compare(ipam_normalise_version((string)$d['update']['version']), ipam_normalise_version(IPAM_VERSION), '<=')) {
                @unlink($cache);
            } else {
                $memo = isset($d['update']) ? (array)$d['update'] : null;
                return $memo;
            }
        }
    }

    require_once __DIR__ . '/version.php';
    $result = null;

    try {
        // Fetch list of releases (up to 10) so we can honour notify_prerelease.
        // /releases/latest skips pre-releases entirely, so we use /releases instead.
        $url = 'https://api.github.com/repos/seanmousseau/Simple-PHP-IPAM/releases?per_page=10';
        $ctx = stream_context_create(['http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'header' => "User-Agent: Simple-PHP-IPAM/" . IPAM_VERSION . "\r\n"
                      . "Accept: application/vnd.github+json\r\n",
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw !== false && $raw !== '') {
            $releases = json_decode($raw, true);
            if (is_array($releases)) {
                foreach ($releases as $rel) {
                    if (!is_array($rel)) continue;
                    if (!empty($rel['draft'])) continue;
                    if (!empty($rel['prerelease']) && !$notifyPrerelease) continue;
                    if (empty($rel['tag_name'])) continue;

                    $latest = ltrim((string)$rel['tag_name'], 'v');
                    if (version_compare(ipam_normalise_version($latest), ipam_normalise_version(IPAM_VERSION), '>')) {
                        $result = [
                            'version'    => $latest,
                            'url'        => (string)($rel['html_url'] ?? ''),
                            'prerelease' => !empty($rel['prerelease']),
                        ];
                    }
                    break; // releases are newest-first; first match wins
                }
            }
        }
    } catch (Throwable) {
        // Non-critical — silently skip on network failure
    }

    @file_put_contents($cache, json_encode(['checked' => time(), 'update' => $result]));
    @chmod($cache, 0600);
    $memo = $result;
    return $result;
}

/**
 * Find the site_id a subnet should inherit from its tightest parent.
 * Returns null if no parent exists or no parent has a site assigned.
 */
function find_parent_site_id(PDO $db, string $cidr, ?int $excludeId = null): ?int
{
    $overlaps = detect_subnet_overlaps($db, $cidr, $excludeId);
    if (empty($overlaps['parents'])) return null;

    $placeholders = implode(',', array_fill(0, count($overlaps['parents']), '?'));
    $st = $db->prepare(
        "SELECT site_id FROM subnets
         WHERE cidr IN ($placeholders) AND site_id IS NOT NULL
         ORDER BY prefix DESC LIMIT 1"
    );
    $st->execute($overlaps['parents']);
    $row = $st->fetch();
    return $row ? (int)$row['site_id'] : null;
}

/* ============================================================
 * OIDC — Authorization Code + PKCE (pure PHP, no dependencies)
 * ============================================================ */

function oidc_enabled(array $config): bool
{
    $o = $config['oidc'] ?? [];
    return !empty($o['enabled'])
        && !empty($o['client_id'])
        && !empty($o['client_secret'])
        && !empty($o['discovery_url'])
        && !empty($o['redirect_uri']);
}

/**
 * Fetch and cache the IdP's OpenID Connect discovery document.
 * Appends /.well-known/openid-configuration if the URL doesn't already
 * contain that path.
 */
function oidc_discovery(array $config): array
{
    $base = rtrim((string)($config['oidc']['discovery_url'] ?? ''), '/');
    if ($base === '') throw new RuntimeException('OIDC discovery_url not set');

    $url = (str_contains($base, '.well-known')) ? $base : $base . '/.well-known/openid-configuration';

    ensure_tmp_dir();
    $cache = tmp_dir() . '/oidc-disc-' . md5($url) . '.json';

    if (is_file($cache) && (time() - (int)filemtime($cache)) < 3600) {
        $d = json_decode((string)file_get_contents($cache), true);
        if (is_array($d) && !empty($d['authorization_endpoint'])) return $d;
    }

    $raw = oidc_http_get($url);
    $d   = json_decode($raw, true);
    if (!is_array($d) || empty($d['authorization_endpoint'])) {
        throw new RuntimeException('Invalid OIDC discovery document from ' . $url);
    }

    file_put_contents($cache, json_encode($d));
    @chmod($cache, 0600);
    return $d;
}

/**
 * Fetch and cache the IdP's JSON Web Key Set.
 * Pass $forceRefresh = true to bypass the cache (used after a verify failure
 * to handle key rotation).
 */
function oidc_jwks(string $jwksUri, bool $forceRefresh = false): array
{
    ensure_tmp_dir();
    $cache = tmp_dir() . '/oidc-jwks-' . md5($jwksUri) . '.json';

    if (!$forceRefresh && is_file($cache) && (time() - (int)filemtime($cache)) < 3600) {
        $d = json_decode((string)file_get_contents($cache), true);
        if (is_array($d) && !empty($d['keys'])) return (array)$d['keys'];
    }

    $raw = oidc_http_get($jwksUri);
    $d   = json_decode($raw, true);
    if (!is_array($d) || !isset($d['keys'])) {
        throw new RuntimeException('Invalid JWKS from ' . $jwksUri);
    }

    file_put_contents($cache, json_encode($d));
    @chmod($cache, 0600);
    return (array)$d['keys'];
}

/** HTTP GET via file_get_contents with a short timeout. */
function oidc_http_get(string $url): string
{
    $ctx = stream_context_create([
        'http' => ['timeout' => 10, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('HTTP GET failed for ' . $url);
    }
    return $raw;
}

/** POST application/x-www-form-urlencoded and return decoded JSON array. */
function oidc_http_post(string $url, array $params): array
{
    $body = http_build_query($params);
    $ctx  = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n"
                             . "Content-Length: " . strlen($body) . "\r\n",
            'content'       => $body,
            'timeout'       => 15,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) throw new RuntimeException('Token endpoint request failed');
    $d = json_decode($raw, true);
    if (!is_array($d)) throw new RuntimeException('Invalid JSON from token endpoint');
    if (!empty($d['error'])) {
        throw new RuntimeException('Token endpoint error: ' . $d['error']
            . (isset($d['error_description']) ? ' — ' . $d['error_description'] : ''));
    }
    return $d;
}

/* ---- PKCE ---- */

function base64url_encode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function base64url_decode(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    $result = base64_decode($s, true);
    if ($result === false) throw new RuntimeException('Invalid base64url string');
    return $result;
}

/**
 * Generate a PKCE verifier and S256 challenge pair.
 * @return array{verifier: string, challenge: string}
 */
function oidc_pkce_pair(): array
{
    $verifier  = base64url_encode(random_bytes(32));
    $challenge = base64url_encode(hash('sha256', $verifier, true));
    return ['verifier' => $verifier, 'challenge' => $challenge];
}

/* ---- JWT / JWK verification ---- */

/**
 * Decode and verify an RS256/RS384/RS512 signed ID token.
 * Returns the verified payload claims array.
 *
 * @param array $jwks    Keys from the IdP's JWKS endpoint
 * @param array $expect  Claims to validate: iss, aud, nonce
 */
function oidc_verify_id_token(string $idToken, array $jwks, array $expect): array
{
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) throw new RuntimeException('Malformed JWT');

    [$hdrB64, $payB64, $sigB64] = $parts;

    $header  = json_decode(base64url_decode($hdrB64), true);
    $payload = json_decode(base64url_decode($payB64), true);
    if (!is_array($header) || !is_array($payload)) {
        throw new RuntimeException('JWT header/payload decoding failed');
    }

    $alg = (string)($header['alg'] ?? '');
    $algMap = ['RS256' => OPENSSL_ALGO_SHA256, 'RS384' => OPENSSL_ALGO_SHA384, 'RS512' => OPENSSL_ALGO_SHA512];
    if (!isset($algMap[$alg])) {
        throw new RuntimeException("Unsupported JWT alg: $alg");
    }

    // Find the matching JWK
    $kid = $header['kid'] ?? null;
    $jwk = null;
    foreach ($jwks as $k) {
        if (!is_array($k) || ($k['kty'] ?? '') !== 'RSA') continue;
        if ($kid !== null && ($k['kid'] ?? '') !== $kid) continue;
        $jwk = $k;
        break;
    }
    if ($jwk === null) {
        throw new RuntimeException('No matching RSA JWK for kid=' . ($kid ?? 'none'));
    }

    $pem    = jwk_rsa_to_pem($jwk);
    $pubKey = openssl_pkey_get_public($pem);
    if ($pubKey === false) throw new RuntimeException('Failed to import JWK public key');

    $sig    = base64url_decode($sigB64);
    $result = openssl_verify($hdrB64 . '.' . $payB64, $sig, $pubKey, $algMap[$alg]);
    if ($result !== 1) throw new RuntimeException('JWT signature invalid');

    // Standard claim validation
    $now = time();
    if (isset($payload['exp']) && (int)$payload['exp'] < $now - 60) {
        throw new RuntimeException('ID token has expired');
    }
    if (isset($payload['iat']) && (int)$payload['iat'] > $now + 60) {
        throw new RuntimeException('ID token iat is in the future');
    }
    if (isset($expect['iss']) && ($payload['iss'] ?? '') !== $expect['iss']) {
        throw new RuntimeException('ID token issuer mismatch');
    }
    if (isset($expect['aud'])) {
        $aud   = $payload['aud'] ?? '';
        $audOk = (is_string($aud) && $aud === $expect['aud'])
              || (is_array($aud)  && in_array($expect['aud'], $aud, true));
        if (!$audOk) throw new RuntimeException('ID token audience mismatch');
    }
    if (isset($expect['nonce']) && ($payload['nonce'] ?? '') !== $expect['nonce']) {
        throw new RuntimeException('ID token nonce mismatch');
    }

    return $payload;
}

/**
 * Convert an RSA JWK (n, e fields) to a PEM-encoded public key.
 * Builds the DER SubjectPublicKeyInfo structure manually so we have
 * no dependency on ext-gmp or any JOSE library.
 */
function jwk_rsa_to_pem(array $jwk): string
{
    $n = base64url_decode((string)($jwk['n'] ?? ''));
    $e = base64url_decode((string)($jwk['e'] ?? ''));
    if ($n === '' || $e === '') throw new RuntimeException('JWK missing n or e');

    // DER integers must not have a leading 1-bit (would be interpreted as negative)
    if (ord($n[0]) & 0x80) $n = "\x00" . $n;
    if (ord($e[0]) & 0x80) $e = "\x00" . $e;

    $intN   = "\x02" . der_len(strlen($n)) . $n;
    $intE   = "\x02" . der_len(strlen($e)) . $e;
    $rsaSeq = "\x30" . der_len(strlen($intN) + strlen($intE)) . $intN . $intE;

    // AlgorithmIdentifier for rsaEncryption (OID 1.2.840.113549.1.1.1) with NULL params
    $oid   = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $algId = "\x30" . der_len(strlen($oid)) . $oid;

    // BIT STRING: 0x00 unused-bits prefix + DER RSAPublicKey
    $bitStr = "\x03" . der_len(strlen($rsaSeq) + 1) . "\x00" . $rsaSeq;

    // SubjectPublicKeyInfo SEQUENCE
    $spki = "\x30" . der_len(strlen($algId) + strlen($bitStr)) . $algId . $bitStr;

    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($spki), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

/** Encode an ASN.1 DER length. */
function der_len(int $len): string
{
    if ($len < 0x80) return chr($len);
    if ($len < 0x100) return "\x81" . chr($len);
    return "\x82" . chr($len >> 8) . chr($len & 0xFF);
}
