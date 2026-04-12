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
    $pdo->exec("PRAGMA busy_timeout = 30000;");
    $pdo->exec("PRAGMA foreign_keys = ON;");
    return $pdo;
}

/**
 * Create the audit_log table and its append-only triggers (idempotent).
 * Centralises DDL that was previously duplicated in 4 places.
 */
function ensure_audit_log_table(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS audit_log (
          id          INTEGER PRIMARY KEY AUTOINCREMENT,
          created_at  TEXT NOT NULL DEFAULT (datetime('now')),
          user_id     INTEGER, username TEXT, action TEXT NOT NULL,
          entity_type TEXT NOT NULL, entity_id INTEGER,
          ip TEXT, user_agent TEXT, details TEXT
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_action ON audit_log(action)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at)");
    $db->exec("CREATE TRIGGER IF NOT EXISTS audit_log_no_update
        BEFORE UPDATE ON audit_log
        BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END");
    $db->exec("CREATE TRIGGER IF NOT EXISTS audit_log_no_delete
        BEFORE DELETE ON audit_log
        BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END");
}

function ipam_db_init(PDO $db): void
{
    // Skip bootstrap checks if sentinel exists and DB file hasn't changed since
    $sentinelPath = __DIR__ . '/data/.db_initialized';
    $dbFilePath   = __DIR__ . '/data/ipam.sqlite';
    if (is_file($sentinelPath) && is_file($dbFilePath)) {
        $sentinelTime = (int)filemtime($sentinelPath);
        $dbTime       = (int)filemtime($dbFilePath);
        if ($sentinelTime >= $dbTime) {
            ensure_migrations_table($db);
            apply_migrations($db);
            ensure_audit_log_table($db); // self-heal if table was dropped
            return;
        }
    }

    $st = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
    $st->execute();
    $hasUsers = (bool)$st->fetch();
    $st->closeCursor(); // Release WAL read mark — open cursors block DROP TABLE even on other tables
    unset($st);

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

    // Self-healing: audit_log is created by schema.sql, not a migration, so it can
    // go missing after a botched demo reset. Recreate it (and its triggers) if absent.
    ensure_audit_log_table($db);

    $st = $db->prepare("SELECT COUNT(*) AS c FROM users");
    $st->execute();
    /** @var array<string, mixed>|false $countRow */
    $countRow = $st->fetch();
    $count = is_array($countRow) ? to_int($countRow['c']) : 0;
    if ($count === 0) {
        $config = require __DIR__ . '/config.php';
        $u = $config['bootstrap_admin']['username'];
        $p = $config['bootstrap_admin']['password'];
        $hash = password_hash($p, PASSWORD_DEFAULT);
        $ins = $db->prepare("INSERT INTO users (username, password_hash, role, is_active) VALUES (:u,:h,'admin',1)");
        $ins->execute([':u' => $u, ':h' => $hash]);
    }

    // Write sentinel so subsequent requests skip bootstrap queries
    @touch($sentinelPath);
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Safely coerce a mixed value (e.g. PDO fetch result or superglobal) to int.
 * Needed at PHPStan level 9 where (int) casts on mixed are disallowed.
 */
function to_int(mixed $value): int
{
    if (is_int($value)) return $value;
    if (is_float($value)) return (int)$value;
    if (is_string($value)) return (int)$value;
    if (is_bool($value)) return $value ? 1 : 0;
    return 0;
}

/**
 * Safely coerce a mixed value (e.g. PDO fetch result or superglobal) to string.
 * Needed at PHPStan level 9 where (string) casts on mixed are disallowed.
 * Defined here for api.php/status.php which load lib.php directly without init.php.
 * init.php defines this function earlier so pages that use init.php see it immediately;
 * the function_exists guard prevents a fatal redefinition error.
 */
if (!function_exists('to_str')) {
    function to_str(mixed $value): string
    {
        if (is_string($value)) return $value;
        if (is_int($value) || is_float($value)) return (string)$value;
        if (is_bool($value)) return $value ? '1' : '';
        if ($value === null) return '';
        return '';
    }
}

/* ---------------- CSRF ---------------- */

function csrf_token(): string
{
    $t = $_SESSION['csrf'] ?? null;
    return is_string($t) ? $t : '';
}

function csrf_require(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $sent = $_POST['csrf'] ?? null;
    $real = csrf_token();
    if (!is_string($sent) || !hash_equals($real, $sent)) {
        http_response_code(403);
        header('Location: login.php');
        exit;
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
    /** @var IpamConfig $gConf */
    $gConf = $GLOBALS['config'];
    $idle = $gConf['session_idle_seconds'];
    if (isset($_SESSION['last_active']) && (time() - to_int($_SESSION['last_active'])) > $idle) {
        logout_user();
        header('Location: login.php?timeout=1');
        exit;
    }
    $_SESSION['last_active'] = time();

    // Password expiry check — local accounts only, skip on change_password / logout pages
    $policy  = $gConf['password_policy'];
    $maxAge  = $policy['max_password_age_days'];
    if ($maxAge > 0) {
        $page = basename(to_str($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if (!in_array($page, ['change_password.php', 'logout.php'], true)) {
            try {
                $db = $GLOBALS['db'] ?? null;
                if ($db instanceof PDO) {
                    $st = $db->prepare("SELECT oidc_sub, password_changed_at FROM users WHERE id = :id");
                    $st->execute([':id' => to_int($_SESSION['uid'] ?? 0)]);
                    /** @var array<string, mixed>|false $row */
                    $row = $st->fetch();
                    if ($row && $row['oidc_sub'] === null) {
                        $changedAt = to_str($row['password_changed_at'] ?? '');
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
 *
 * @param array<string, mixed> $policy
 * @return list<string>
 */
function validate_password_complexity(string $password, array $policy): array
{
    $errors = [];
    $min = max(1, to_int($policy['min_length'] ?? 12));
    if (mb_strlen($password) < $min) {
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

/** @return array<string, mixed> */
function current_user(): array
{
    return [
        'id' => to_int($_SESSION['uid'] ?? 0),
        'username' => to_str($_SESSION['username'] ?? ''),
        'role' => to_str($_SESSION['role'] ?? ''),
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

function login_user(int $uid, string $username, string $role, ?PDO $db = null): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    $_SESSION['username'] = $username;
    $_SESSION['role'] = $role;
    $_SESSION['last_active'] = time();
    // Load persisted theme preference so page_header() can prime localStorage
    if ($db !== null) {
        $st = $db->prepare("SELECT theme FROM users WHERE id = :id");
        $st->execute([':id' => $uid]);
        $theme = to_str($st->fetchColumn() ?: 'auto');
        $_SESSION['user_theme'] = in_array($theme, ['light', 'dark', 'auto'], true) ? $theme : 'auto';
    }
}

/* ---------------- Login rate limiting ---------------- */

function login_rate_limited(PDO $db, string $ip, int $maxAttempts, int $windowSeconds): bool
{
    $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);
    $st = $db->prepare("SELECT COUNT(*) AS c FROM login_attempts WHERE ip = :ip AND attempted_at >= :cutoff");
    $st->execute([':ip' => $ip, ':cutoff' => $cutoff]);
    /** @var array<string, mixed>|false $countRow */
    $countRow = $st->fetch();
    return (is_array($countRow) ? to_int($countRow['c']) : 0) >= $maxAttempts;
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
        setcookie((string)session_name(), '', time() - 42000,
            $params["path"], $params["domain"], $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/* ---------------- Audit ---------------- */

function client_ip(): string
{
    /** @var IpamConfig $gConf */
    $gConf = $GLOBALS['config'];
    if (!empty($gConf['proxy_trust']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts     = array_map('trim', explode(',', to_str($_SERVER['HTTP_X_FORWARDED_FOR'])));
        $candidate = $parts[0];
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return $candidate;
        }
    }
    return to_str($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
}

function flash_set(string $message, string $type = 'success'): void
{
    $_SESSION['ipam_flash'] = ['msg' => $message, 'type' => $type];
}

/** @return array{msg: string, type: string}|null */
function flash_get(): ?array
{
    if (empty($_SESSION['ipam_flash'])) return null;
    $flash = $_SESSION['ipam_flash'];
    unset($_SESSION['ipam_flash']);
    if (!is_array($flash)) return null;
    $msg  = is_string($flash['msg']  ?? null) ? $flash['msg']  : '';
    $type = is_string($flash['type'] ?? null) ? $flash['type'] : 'info';
    return ['msg' => $msg, 'type' => $type];
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
        ':ua'  => to_str($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ':dt'  => $details,
    ]);
}

function audit_export(PDO $db, string $what, string $details = ''): void
{
    audit($db, "export.$what", 'system', null, $details);
}

/* ---------------- History ---------------- */

/**
 * @param array<string, mixed>|null $before
 * @param array<string, mixed>|null $after
 */
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
        ':ua'  => to_str($_SERVER['HTTP_USER_AGENT'] ?? ''),
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

/** @return list<string> */
function applied_migrations(PDO $db): array
{
    $st = $db->prepare("SELECT version FROM schema_migrations");
    $st->execute();
    /** @var list<array<string, mixed>> $rows */
    $rows = $st->fetchAll();
    return array_map(fn(array $r): string => to_str($r['version']), $rows);
}

/** @return list<string> */
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

        // SQLite DDL (DROP TABLE, ALTER TABLE) needs a full exclusive lock —
        // even readers block it. busy_timeout only retries SQLITE_BUSY (5),
        // not SQLITE_LOCKED (6), so we retry at the PHP level: ROLLBACK the
        // transaction, sleep 1 s, and try again. SQLite DDL is fully
        // transactional so ROLLBACK cleanly undoes any partial work.
        $lastErr  = null;
        $applied  = false;
        for ($attempt = 0; $attempt < 60 && !$applied; $attempt++) {
            if ($attempt > 0) {
                sleep(1);
            }

            // PRAGMA foreign_keys cannot be changed inside a transaction — it must
            // be set here, outside BEGIN. When it is ON, SQLite executes an implicit
            // DELETE on every row before DROP TABLE, which triggers ON DELETE CASCADE
            // on all child tables (addresses, subnet_tags, etc.), wiping all data.
            // Disabling it for the duration of each migration prevents that cascade.
            // FK enforcement is restored unconditionally after the transaction ends.
            $db->exec("PRAGMA foreign_keys = OFF");

            try {
                $db->exec("BEGIN EXCLUSIVE");
            } catch (Throwable $e) {
                $db->exec("PRAGMA foreign_keys = ON");
                $lastErr = $e;
                if (stripos($e->getMessage(), 'locked') !== false || stripos($e->getMessage(), 'busy') !== false) {
                    continue;
                }
                throw $e;
            }

            try {
                $fn($db);
                $st = $db->prepare("INSERT INTO schema_migrations (version) VALUES (:v)");
                $st->execute([':v' => $ver]);
                $db->exec("COMMIT");
                $applied = true;
                $appliedNow[] = $ver;
                $lastErr = null;
            } catch (Throwable $e) {
                try { $db->exec("ROLLBACK"); } catch (Throwable) {}
                $db->exec("PRAGMA foreign_keys = ON");
                $lastErr = $e;
                if (stripos($e->getMessage(), 'locked') !== false || stripos($e->getMessage(), 'busy') !== false) {
                    continue;
                }
                throw $e;
            }
            $db->exec("PRAGMA foreign_keys = ON");
        }

        if ($lastErr !== null) {
            throw $lastErr;
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
/** @return array<string, array{default: mixed, comment: string}> */
function ipam_config_defaults(): array
{
    return [
        'db_path' => [
            'default' => null, // path-dependent, skip auto-append
            'comment' => '',
        ],
        'session_name' => ['default' => null, 'comment' => ''],
        'base_url'     => [
            'default' => null,
            'comment' => "Canonical HTTPS base URL (e.g. 'https://ipam.example.com'). "
                       . "Used for the HTTP→HTTPS redirect. If null, falls back to HTTP_HOST.",
        ],
        'proxy_trust'  => ['default' => null, 'comment' => ''],
        'app_name' => [
            'default' => 'Simple PHP IPAM',
            'comment' => "Application display name shown in the browser tab, nav bar, and login page. Default: 'Simple PHP IPAM'.",
        ],
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
        'api_bulk_limit' => [
            'default' => 500,
            'comment' => 'Maximum number of records per bulk API write request (POST ?resource=addresses&bulk=1).',
        ],
        'recaptcha_enterprise' => [
            'default' => [
                'enabled'          => false,
                'project_id'       => '',
                'api_key'          => '',
                'expected_action'  => 'login',
                'score_threshold'  => 0.5,
            ],
            'comment' => "reCAPTCHA Enterprise (v3). Set login_protection.method = 'recaptcha' and enable this block to use the Enterprise API for backend verification. project_id: GCP project ID. api_key: server-side API key. expected_action: action name from widget. score_threshold: 0.0–1.0 (default 0.5).",
        ],
        'import_csv_max_mb'    => ['default' => null, 'comment' => ''],
        'tmp_cleanup_ttl_seconds' => ['default' => null, 'comment' => ''],
        'audit_log_retention_days' => [
            'default' => 0,
            'comment' => 'Audit log retention (days). Entries older than this are pruned during housekeeping. 0 = keep forever.',
        ],
        'address_history_retention_days' => [
            'default' => 0,
            'comment' => 'Address history retention (days). Entries older than this are pruned during housekeeping. 0 = keep forever.',
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
        'login_protection' => [
            'default' => [
                'method'      => null,
                'site_key'    => '',
                'secret_key'  => '',
                'min_seconds' => 3,
                'version'     => 2,
            ],
            'comment' => "Login form bot protection. method: null | 'honeypot' | 'time_check' | 'turnstile' | 'hcaptcha' | 'recaptcha' | 'friendly_captcha'. site_key/secret_key required for widget methods.",
        ],
        'demo_mode' => [
            'default' => [
                'enabled'    => false,
                'gate'       => null,
                'site_key'   => '',
                'secret_key' => '',
            ],
            'comment' => "Demo mode: only demo/demo can log in; data resets nightly. gate: optional pre-login bot challenge (null | 'honeypot' | 'turnstile' | 'hcaptcha' | 'recaptcha' | 'friendly_captcha').",
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
/**
 * @param array<string, mixed> $loaded
 * @return list<string>
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

            $comment  = to_str($meta['comment']);
            $valuePhp = ipam_php_export($meta['default'], 2);
            $block    = '';
            if ($comment !== '') $block .= "\n    // " . $comment;
            $block .= "\n    '" . addcslashes($key, "'\\") . "' => " . $valuePhp . ",\n";

            $content = preg_replace('/\n\];\s*$/', $block . "\n];", $content);
            if ($content === null) break;
            if (@file_put_contents($configPath, $content) !== false) {
                $added[] = $key;
            } else {
                $_SESSION['config_unwritable'] = true; // #119: surface in page_header()
                break;
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
                } else {
                    $_SESSION['config_unwritable'] = true; // #119: surface in page_header()
                    break 2;
                }
            }
        }
    }

    return $added;
}

/**
 * Validate loaded config values and return a list of human-readable warning strings.
 * Logs each warning to the PHP error log as well.
 *
 * @param IpamConfig $config
 * @return list<string>
 */
function ipam_validate_config(array $config): array
{
    $warnings = [];

    $sessionIdle = to_int($config['session_idle_seconds']);
    if ($sessionIdle < 60) {
        $warnings[] = "session_idle_seconds is {$sessionIdle}; minimum is 60.";
    }

    $loginMax = to_int($config['login_max_attempts']);
    if ($loginMax < 1) {
        $warnings[] = "login_max_attempts is {$loginMax}; minimum is 1.";
    }

    $lockout = to_int($config['login_lockout_seconds']);
    if ($lockout < 1) {
        $warnings[] = "login_lockout_seconds is {$lockout}; minimum is 1.";
    }

    $auditRetention = to_int($config['audit_log_retention_days']);
    if ($auditRetention < 0) {
        $warnings[] = "audit_log_retention_days is {$auditRetention}; must be 0 or greater.";
    }

    $backup = $config['backup'];
    if (!empty($backup['enabled'])) {
        $retention = to_int($backup['retention']);
        if ($retention < 1) {
            $warnings[] = "backup.retention is {$retention}; minimum is 1.";
        }
    }

    foreach ($warnings as $w) {
        error_log("Simple PHP IPAM config warning: {$w}");
    }

    return $warnings;
}

/* ---------------- Housekeeping ---------------- */

function housekeeping_state_path(): string
{
    return __DIR__ . '/data/housekeeping.json';
}

/**
 * @phpstan-impure
 * @param IpamConfig $config
 */
function housekeeping_should_run(array $config): bool
{
    $hk = $config['housekeeping'];
    if (empty($hk['enabled'])) return false;

    $interval = to_int($hk['interval_seconds']);
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
    $cutoff = date('Y-m-d H:i:s', (int)strtotime("-{$retentionDays} days"));

    // The audit_log triggers block DELETE. Drop triggers, DELETE directly, recreate.
    // This avoids ALTER TABLE RENAME (which can implicitly commit in SQLite)
    // and SELECT * column-ordering risks.
    try {
        $oldCount = to_int(($db->query("SELECT COUNT(*) FROM audit_log")
            ?: throw new \RuntimeException('Query failed'))->fetchColumn());

        $db->exec("DROP TRIGGER IF EXISTS audit_log_no_update");
        $db->exec("DROP TRIGGER IF EXISTS audit_log_no_delete");

        $st = $db->prepare("DELETE FROM audit_log WHERE created_at < :cutoff");
        $st->execute([':cutoff' => $cutoff]);
        $pruned = $st->rowCount();

        // Recreate append-only triggers
        $db->exec("CREATE TRIGGER IF NOT EXISTS audit_log_no_update
            BEFORE UPDATE ON audit_log
            BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END");
        $db->exec("CREATE TRIGGER IF NOT EXISTS audit_log_no_delete
            BEFORE DELETE ON audit_log
            BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END");

        return $pruned;
    } catch (Throwable $e) {
        // Attempt to restore triggers even on failure
        try {
            $db->exec("CREATE TRIGGER IF NOT EXISTS audit_log_no_update
                BEFORE UPDATE ON audit_log
                BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END");
            $db->exec("CREATE TRIGGER IF NOT EXISTS audit_log_no_delete
                BEFORE DELETE ON audit_log
                BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END");
        } catch (Throwable) {}
        error_log('audit_log prune failed: ' . $e->getMessage());
        return 0;
    }
}

function prune_address_history(PDO $db, int $retentionDays): int
{
    if ($retentionDays <= 0) return 0;
    $cutoff = date('Y-m-d H:i:s', (int)strtotime("-{$retentionDays} days"));
    $st = $db->prepare("DELETE FROM address_history WHERE created_at < :cutoff");
    $st->execute([':cutoff' => $cutoff]);
    return $st->rowCount();
}

/**
 * Send email utilization alerts for subnets that have crossed warn/crit thresholds.
 * Deduplicates sends using the alert_state table (max one alert per subnet+level per 24 h).
 * Auto-clears alert_state rows when utilization drops back below the threshold.
 *
 * @param IpamConfig $config
 */
function check_utilization_alerts(PDO $db, array $config): void
{
    $alertEmail = trim(to_str($config['alert_email'] ?? ''));
    if ($alertEmail === '') return;

    $warnPct = to_int($config['alert_util_warn_pct'] ?? 80);
    $critPct = to_int($config['alert_util_crit_pct'] ?? 95);
    $appName = to_str($config['app_name']);

    // Compute direct address counts per subnet (used+reserved)
    $rows = ($db->query("
        SELECT s.id, s.cidr, s.prefix,
               SUM(CASE WHEN a.status IN ('used','reserved') THEN 1 ELSE 0 END) AS assigned
        FROM subnets s
        LEFT JOIN addresses a ON a.subnet_id = s.id
        WHERE s.ip_version = 4
        GROUP BY s.id
    ") ?: throw new \RuntimeException('Query failed'))->fetchAll();

    // Load existing alert state
    $stateRows = ($db->query("SELECT subnet_id, level, last_alerted_at FROM alert_state")
        ?: throw new \RuntimeException('Query failed'))->fetchAll();
    $alertState = [];
    foreach ($stateRows as $sr) {
        $alertState[to_int($sr['subnet_id'])][to_str($sr['level'])] = to_str($sr['last_alerted_at']);
    }

    $cooldownSeconds = 86400; // 24 hours

    foreach ($rows as $row) {
        $sid    = to_int($row['id']);
        $cidr   = to_str($row['cidr']);
        $prefix = to_int($row['prefix']);
        $assigned = to_int($row['assigned']);

        $assignable = ipv4_assignable_count($prefix);
        if ($assignable <= 0) continue;

        $pct = (int)round($assigned / $assignable * 100);

        // Determine active levels
        $levels = [];
        if ($pct >= $critPct) $levels[] = 'crit';
        elseif ($pct >= $warnPct) $levels[] = 'warn';

        // Auto-clear stale alert_state rows when below thresholds
        foreach (['warn', 'crit'] as $lvl) {
            if (isset($alertState[$sid][$lvl]) && !in_array($lvl, $levels, true)) {
                $db->prepare("DELETE FROM alert_state WHERE subnet_id = :sid AND level = :lvl")
                   ->execute([':sid' => $sid, ':lvl' => $lvl]);
                unset($alertState[$sid][$lvl]);
            }
        }

        foreach ($levels as $lvl) {
            $lastAlerted = $alertState[$sid][$lvl] ?? null;
            if ($lastAlerted !== null) {
                $lastTs = strtotime($lastAlerted);
                if ($lastTs !== false && (time() - $lastTs) < $cooldownSeconds) continue;
            }

            $levelLabel = $lvl === 'crit' ? 'CRITICAL' : 'WARNING';
            $subject    = "[{$appName}] {$levelLabel}: subnet {$cidr} at {$pct}% utilization";
            $body       = "{$levelLabel}: Subnet {$cidr} has reached {$pct}% utilization.\n"
                        . "Assigned: {$assigned} / Assignable: {$assignable}\n\n"
                        . "-- {$appName}";

            // Sanitize recipient and subject to prevent header injection
            $safeEmail   = preg_replace('/[\r\n]/', '', $alertEmail) ?? '';
            $safeSubject = preg_replace('/[\r\n]/', '', $subject) ?? '';

            @mail($safeEmail, $safeSubject, $body);

            $now = date('Y-m-d H:i:s');
            $db->prepare(
                "INSERT INTO alert_state (subnet_id, level, last_alerted_at)
                 VALUES (:sid, :lvl, :now)
                 ON CONFLICT(subnet_id, level) DO UPDATE SET last_alerted_at = excluded.last_alerted_at"
            )->execute([':sid' => $sid, ':lvl' => $lvl, ':now' => $now]);
            $alertState[$sid][$lvl] = $now;
        }
    }
}

/**
 * Run utilization alert check if the alert interval has elapsed.
 * Uses a dedicated state file so it can fire more frequently than main housekeeping.
 *
 * @param IpamConfig $config
 */
function alerts_check_if_due(array $config, PDO $db): void
{
    $alertEmail = trim(to_str($config['alert_email'] ?? ''));
    if ($alertEmail === '') return;

    $interval = to_int($config['alert_interval_seconds'] ?? 3600);
    if ($interval < 60) $interval = 60;

    $statePath = __DIR__ . '/data/alerts_last_run.txt';
    if (is_file($statePath)) {
        $last = (int)@file_get_contents($statePath);
        if ((time() - $last) < $interval) return;
    }

    check_utilization_alerts($db, $config);

    @file_put_contents($statePath, (string)time());
    @chmod($statePath, 0600);
}

/** @param IpamConfig $config */
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

        $ttl = to_int($config['tmp_cleanup_ttl_seconds']);
        if ($ttl < 3600) $ttl = 3600;

        cleanup_tmp_import_files($ttl);
        cleanup_tmp_import_plans($ttl);

        if ($db !== null) {
            $retentionDays = to_int($config['audit_log_retention_days']);
            if ($retentionDays > 0) {
                prune_audit_log($db, $retentionDays);
            }
            $histRetention = to_int($config['address_history_retention_days']);
            if ($histRetention > 0) {
                prune_address_history($db, $histRetention);
            }
        }

        housekeeping_mark_ran();
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

/**
 * Demo nightly reset — runs independently of the housekeeping schedule.
 * Called directly from init.php, wrapped in try-catch so a seed failure
 * never crashes the page load (which would leave $_SESSION without a
 * CSRF token and cause "Bad CSRF token" on all subsequent requests).
 */
function run_demo_reset_if_due(PDO $db): void
{
    if (!demo_mode_enabled() || !demo_require_reset()) return;

    $marker = __DIR__ . '/data/demo_last_reset.txt';
    try {
        demo_reset_db($db);
        file_put_contents($marker, (string)time());
    } catch (Throwable $e) {
        // Log but do not re-throw — page must continue so CSRF is initialised
        error_log('Simple PHP IPAM demo reset failed: ' . $e->getMessage());
    }
}

/* ---------------- Database Backups ---------------- */

/** @param IpamConfig $config */
function backup_dir(array $config): string
{
    $d = trim($config['backup']['dir']);
    if ($d === '') {
        return __DIR__ . '/data/backups';
    }
    // Make relative paths relative to the app directory
    if (!str_starts_with($d, '/')) {
        $d = __DIR__ . '/' . $d;
    }
    // Canonicalize: resolve .. segments without requiring the directory to exist (#113)
    $parts = [];
    foreach (explode('/', $d) as $segment) {
        if ($segment === '..') { array_pop($parts); }
        elseif ($segment !== '' && $segment !== '.') { $parts[] = $segment; }
    }
    return '/' . implode('/', $parts);
}

function backup_state_path(): string
{
    return __DIR__ . '/data/backup-state.json';
}

/** @param IpamConfig $config */
function backup_interval_seconds(array $config): int
{
    $freq = strtolower(trim($config['backup']['frequency']));
    return match ($freq) {
        'weekly' => 604800,
        default  => 86400,  // 'daily'
    };
}

/**
 * @phpstan-impure
 * @param IpamConfig $config
 */
function backup_is_due(array $config): bool
{
    $bk = $config['backup'];
    if (empty($bk['enabled'])) return false;

    $path = backup_state_path();
    if (!is_file($path)) return true;

    $d = @json_decode((string)file_get_contents($path), true);
    if (!is_array($d) || !isset($d['last_backup'])) return true;

    return (time() - to_int($d['last_backup'])) >= backup_interval_seconds($config);
}

/**
 * Run a database backup if one is due. Uses WAL checkpoint + file copy for
 * a consistent snapshot without requiring SQLite3 extension.
 * Returns true if a backup was written, false otherwise.
 */
/** @param IpamConfig $config */
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

        /** @var IpamConfig $gConf */
        $gConf = $GLOBALS['config'];
        $dbPath = $gConf['db_path'] !== '' ? $gConf['db_path'] : (__DIR__ . '/data/ipam.sqlite');
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
            $retention = max(1, $config['backup']['retention']);
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
 *
 * @param IpamConfig $config
 * @return array{last_backup: int|null, last_file: string|null, count: int, dir: string}
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
            $last = isset($d['last_backup']) ? to_int($d['last_backup']) : null;
            $file = isset($d['last_file'])   ? to_str($d['last_file']) : null;
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
 * Stream a full SQL dump of the SQLite database to a callable.
 * Each call to $write receives a chunk of SQL text.
 */
function ipam_db_dump_stream(PDO $db, callable $write): void
{
    $write("-- Simple PHP IPAM database dump\n");
    $write("-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
    $write("PRAGMA foreign_keys=OFF;\n");
    $write("BEGIN TRANSACTION;\n\n");

    // Tables: schema + data
    $tables = ($db->query(
        "SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
    ) ?: throw new \RuntimeException('Query failed'))->fetchAll();

    foreach ($tables as $t) {
        $name        = to_str($t['name']);
        $quotedName  = '"' . str_replace('"', '""', $name) . '"';
        $write("-- Table: {$name}\n");
        $write(to_str($t['sql']) . ";\n");

        // Dump triggers for this table so they are recreated on import.
        $triggers = ($db->query(
            "SELECT sql FROM sqlite_master WHERE type='trigger' AND tbl_name="
            . $db->quote($name) . " AND sql IS NOT NULL ORDER BY name"
        ) ?: throw new \RuntimeException('Query failed'))->fetchAll();
        foreach ($triggers as $trig) {
            $write(to_str($trig['sql']) . ";\n");
        }

        // Identify BLOB columns so we can always hex-encode raw binary data.
        $colInfo  = ($db->query("PRAGMA table_info({$quotedName})")
            ?: throw new \RuntimeException('Query failed'))->fetchAll();
        $blobCols = [];
        foreach ($colInfo as $ci) {
            if (strtoupper(to_str($ci['type'])) === 'BLOB') {
                $blobCols[to_str($ci['name'])] = true;
            }
        }

        $rows = ($db->query("SELECT * FROM {$quotedName}")
            ?: throw new \RuntimeException('Query failed'))->fetchAll();
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
                    $vals[] = "X'" . bin2hex(to_str($v)) . "'";
                } else {
                    $vals[] = "CAST(X'" . bin2hex(to_str($v)) . "' AS TEXT)";
                }
            }
            $write("INSERT INTO {$quotedName} (" . implode(',', $cols) . ") VALUES ("
                  . implode(',', $vals) . ");\n");
        }
        $write("\n");
    }

    // Indices (non-system)
    $indices = ($db->query(
        "SELECT sql FROM sqlite_master WHERE type='index' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY name"
    ) ?: throw new \RuntimeException('Query failed'))->fetchAll();
    if ($indices) {
        $write("-- Indexes\n");
        foreach ($indices as $idx) {
            $write(to_str($idx['sql']) . ";\n");
        }
        $write("\n");
    }

    $write("COMMIT;\n");
    $write("PRAGMA foreign_keys=ON;\n");
}

/**
 * Generate a full SQL dump of the SQLite database suitable for import.
 * Backwards-compatible wrapper around ipam_db_dump_stream().
 */
function ipam_db_dump(PDO $db): string
{
    $out = '';
    ipam_db_dump_stream($db, function(string $chunk) use (&$out) {
        $out .= $chunk;
    });
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

/**
 * Parse and validate ?sort=col&dir=asc|desc from $_GET.
 *
 * @param array<string,string> $allowed  Map of GET param value → SQL column expression (whitelisted)
 * @param string $defaultCol  Key in $allowed to use when no valid sort param is provided
 * @param string $defaultDir  'asc' or 'desc'
 * @return array{col: string, dir: string, sql: string}
 */
function parse_sort(array $allowed, string $defaultCol, string $defaultDir = 'asc'): array
{
    $col = to_str($_GET['sort'] ?? $defaultCol);
    $dir = strtolower(to_str($_GET['dir'] ?? $defaultDir));
    if (!isset($allowed[$col])) $col = $defaultCol;
    if (!in_array($dir, ['asc', 'desc'], true)) $dir = $defaultDir;
    return ['col' => $col, 'dir' => $dir, 'sql' => $allowed[$col] . ' ' . strtoupper($dir)];
}

/**
 * Render a sortable <th> element linking to the current page with sort params applied.
 * $baseQs should be the query string prefix for the page (e.g. '?subnet_id=3&page_size=50').
 */
function sort_th(string $col, string $label, string $currentCol, string $currentDir, string $baseQs, string $dataCol = ''): string
{
    $isActive  = $col === $currentCol;
    $nextDir   = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
    $arrow     = $isActive ? ($currentDir === 'asc' ? ' ▲' : ' ▼') : '';
    $sep       = str_contains($baseQs, '?') ? '&' : '?';
    $qs        = $baseQs . $sep . 'sort=' . urlencode($col) . '&dir=' . $nextDir;
    $cls       = $isActive ? ' class="sort-active"' : '';
    $dataAttr  = $dataCol !== '' ? ' data-col="' . e($dataCol) . '"' : '';
    return "<th{$cls}{$dataAttr}><a href='" . e($qs) . "'>" . e($label) . $arrow . '</a></th>';
}

/* ---------------- Login protection (#124) ---------------- */

/**
 * Verify a reCAPTCHA token against the Enterprise Assessment API.
 * Returns null on pass, error string on fail, null on network error (fail-open).
 *
 * @param array{enabled: bool, project_id: string, api_key: string, expected_action: string, score_threshold: float} $cfg
 */
function recaptcha_enterprise_verify(string $token, string $siteKey, array $cfg): ?string
{
    $projectId      = to_str($cfg['project_id']);
    $apiKey         = to_str($cfg['api_key']);
    $expectedAction = to_str($cfg['expected_action']);
    $threshold      = (float)$cfg['score_threshold'];

    if ($projectId === '' || $apiKey === '') {
        error_log('reCAPTCHA Enterprise: project_id and api_key must be configured.');
        return null; // fail open — misconfiguration should not block users
    }

    $url     = 'https://recaptchaenterprise.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/assessments?key=' . rawurlencode($apiKey);
    $payload = (string)json_encode(['event' => ['token' => $token, 'expectedAction' => $expectedAction, 'siteKey' => $siteKey]]);
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
            'content'       => $payload,
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    try {
        $raw = @file_get_contents($url, false, $ctx, 0, 1048576);
        if ($raw === false) {
            error_log('reCAPTCHA Enterprise: HTTP request failed.');
            return null;
        }
        $resp = json_decode($raw, true);
        if (!is_array($resp)) {
            error_log('reCAPTCHA Enterprise: Invalid JSON response.');
            return null;
        }
        /** @var array<string, mixed> $resp */
        $tokenProps  = is_array($resp['tokenProperties'] ?? null) ? $resp['tokenProperties'] : [];
        $riskAnalysis = is_array($resp['riskAnalysis'] ?? null)   ? $resp['riskAnalysis']   : [];

        if (!empty($tokenProps['invalid'])) return 'Security check failed. Please try again.';
        if ($expectedAction !== '' && isset($tokenProps['action']) && to_str($tokenProps['action']) !== $expectedAction) {
            return 'Security check failed. Please try again.';
        }

        $score = (isset($riskAnalysis['score']) && is_numeric($riskAnalysis['score'])) ? (float)$riskAnalysis['score'] : 0.0;
        return $score >= $threshold ? null : 'Security check failed. Please try again.';
    } catch (Throwable $e) {
        error_log('reCAPTCHA Enterprise verify error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Verify the login form protection token/field for the current POST request.
 *
 * Returns null on pass, '' for a silent honeypot rejection (no error shown),
 * or a non-empty error string that should be shown to the user.
 * Fails open on network errors so a broken CAPTCHA provider never blocks login.
 *
 * @param LoginProtectionConfig $config
 * @param array<string, mixed> $post
 */
function login_protection_verify(array $config, array $post): ?string
{
    $cfg    = $config['login_protection'];
    $method = to_str($cfg['method'] ?? '');
    if ($method === '' || $method === 'null') return null;

    if ($method === 'honeypot') {
        return ($post['website'] ?? '') !== '' ? '' : null;
    }

    if ($method === 'time_check') {
        $min = max(1, $cfg['min_seconds']);
        $ts  = to_int($_SESSION['login_form_at'] ?? 0);
        unset($_SESSION['login_form_at']);
        if ($ts === 0 || (time() - $ts) < $min) {
            return 'Form submission was too fast. Please wait a moment and try again.';
        }
        return null;
    }

    $secretKey = $cfg['secret_key'];

    if ($method === 'turnstile') {
        $token = to_str($post['cf-turnstile-response'] ?? '');
        if ($token === '') return 'Please complete the security check.';
        try {
            $resp = oidc_http_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret'   => $secretKey,
                'response' => $token,
                'remoteip' => client_ip(),
            ]);
        } catch (Throwable $e) {
            error_log('Turnstile verify error: ' . $e->getMessage());
            return null; // fail open
        }
        return !empty($resp['success']) ? null : 'Security check failed. Please try again.';
    }

    if ($method === 'hcaptcha') {
        $token = to_str($post['h-captcha-response'] ?? '');
        if ($token === '') return 'Please complete the security check.';
        try {
            $resp = oidc_http_post('https://hcaptcha.com/siteverify', [
                'secret'   => $secretKey,
                'response' => $token,
                'remoteip' => client_ip(),
            ]);
        } catch (Throwable $e) {
            error_log('hCaptcha verify error: ' . $e->getMessage());
            return null; // fail open
        }
        return !empty($resp['success']) ? null : 'Security check failed. Please try again.';
    }

    if ($method === 'recaptcha') {
        $token = to_str($post['g-recaptcha-response'] ?? '');
        if ($token === '') return 'Please complete the security check.';

        // Use Enterprise API if configured; fall back to standard reCAPTCHA API
        /** @var IpamConfig $gConfig */
        $gConfig    = $GLOBALS['config'] ?? [];
        $enterprise = $gConfig['recaptcha_enterprise'];
        if (!empty($enterprise['enabled'])) {
            return recaptcha_enterprise_verify($token, $cfg['site_key'], $enterprise);
        }

        try {
            $resp = oidc_http_post('https://www.google.com/recaptcha/api/siteverify', [
                'secret'   => $secretKey,
                'response' => $token,
                'remoteip' => client_ip(),
            ]);
        } catch (Throwable $e) {
            error_log('reCAPTCHA verify error: ' . $e->getMessage());
            return null; // fail open
        }
        return !empty($resp['success']) ? null : 'Security check failed. Please try again.';
    }

    if ($method === 'friendly_captcha') {
        $token = to_str($post['frc-captcha-solution'] ?? '');
        if ($token === '' || $token === '.UNSTARTED') return 'Please complete the security check.';
        if ($token === '.FETCHING') return null; // in-progress, fail open
        try {
            $resp = oidc_http_post('https://api.friendlycaptcha.com/api/v1/siteverify', [
                'secret'  => $secretKey,
                'solution'=> $token,
                'sitekey' => $cfg['site_key'],
            ]);
        } catch (Throwable $e) {
            error_log('FriendlyCaptcha verify error: ' . $e->getMessage());
            return null; // fail open
        }
        return !empty($resp['success']) ? null : 'Security check failed. Please try again.';
    }

    return null; // unknown method — pass through
}

/**
 * Return the HTML widget snippet to embed in the login/gate form.
 * For time_check, also sets the session timestamp on GET requests.
 */
/** @param LoginProtectionConfig $config */
function login_protection_widget_html(array $config): string
{
    $cfg     = $config['login_protection'];
    $method  = to_str($cfg['method'] ?? '');
    $siteKey = e($cfg['site_key']);

    switch ($method) {
        case 'honeypot':
            return "<input type='text' name='website' autocomplete='off' tabindex='-1' aria-hidden='true' class='hidden'>";
        case 'time_check':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $_SESSION['login_form_at'] = time();
            }
            return ''; // no visible widget
        case 'turnstile':
            return "<script src='https://challenges.cloudflare.com/turnstile/v0/api.js' async defer></script>"
                 . "<div class='cf-turnstile' data-sitekey='{$siteKey}'></div>";
        case 'hcaptcha':
            return "<script src='https://js.hcaptcha.com/1/api.js' async defer></script>"
                 . "<div class='h-captcha' data-sitekey='{$siteKey}'></div>";
        case 'recaptcha':
            $ver        = $cfg['version'];
            /** @var IpamConfig $gCfg */
            $gCfg  = $GLOBALS['config'] ?? [];
            $isEnt = !empty($gCfg['recaptcha_enterprise']['enabled']);
            if ($ver === 3) {
                $scriptSrc    = $isEnt
                    ? "https://www.google.com/recaptcha/enterprise.js?render={$siteKey}"
                    : "https://www.google.com/recaptcha/api.js?render={$siteKey}";
                $entAttr      = $isEnt ? " data-recaptcha-enterprise='1'" : '';
                $action       = e(to_str($gCfg['recaptcha_enterprise']['expected_action']));
                $actionAttr   = " data-recaptcha-action='{$action}'";
                return "<script src='{$scriptSrc}' async defer></script>"
                     . "<input type='hidden' name='g-recaptcha-response' id='g-recaptcha-response' data-recaptcha-v3-key='{$siteKey}'{$entAttr}{$actionAttr}>";
            }
            return "<script src='https://www.google.com/recaptcha/api.js' async defer></script>"
                 . "<div class='g-recaptcha' data-sitekey='{$siteKey}'></div>";
        case 'friendly_captcha':
            return "<script src='https://cdn.jsdelivr.net/npm/friendly-challenge@latest/widget.module.min.js' async defer></script>"
                 . "<div class='frc-captcha' data-sitekey='{$siteKey}'></div>";
        default:
            return '';
    }
}

/**
 * Return extra CSP directives needed for the active login protection method.
 * Returns ['script_src' => '...', 'frame_src' => '...'] — either may be empty.
 * Turnstile, hCaptcha, and reCAPTCHA render inside an iframe so frame_src must
 * be explicitly allowed; Friendly Captcha uses Web Components (no iframe needed).
 */
/**
 * @param LoginProtectionConfig $config
 * @return array{script_src: string, frame_src: string}
 */
function login_protection_extra_csp(array $config): array
{
    $method = to_str($config['login_protection']['method'] ?? '');
    return match ($method) {
        'turnstile'        => [
            'script_src' => 'https://challenges.cloudflare.com',
            'frame_src'  => 'https://challenges.cloudflare.com',
        ],
        'hcaptcha'         => [
            'script_src' => 'https://hcaptcha.com https://assets.hcaptcha.com',
            'frame_src'  => 'https://newassets.hcaptcha.com',
        ],
        'recaptcha'        => [
            'script_src' => 'https://www.google.com https://www.gstatic.com',
            'frame_src'  => 'https://www.google.com',
        ],
        'friendly_captcha' => [
            'script_src' => 'https://cdn.jsdelivr.net',
            'frame_src'  => '',
        ],
        default            => ['script_src' => '', 'frame_src' => ''],
    };
}

/* ---------------- Auto-reserve IPs ---------------- */

/**
 * Auto-reserve the network address, broadcast address (IPv4), and optionally
 * a gateway IP immediately after a subnet is created.
 *
 * IPv4: reserves network (x.x.x.0) and broadcast (x.x.x.255) as status=reserved.
 * IPv6: reserves network address only (broadcast concept does not apply).
 * Gateway (if provided): reserved as status=reserved with hostname 'gateway'.
 *
 * Skips any IP that is already assigned (INSERT OR IGNORE).
 */
function auto_reserve_subnet_ips(PDO $db, int $subnetId, string $cidr, ?string $gateway): void
{
    $p = parse_cidr($cidr);
    if (!$p) return;

    $ins = $db->prepare(
        "INSERT OR IGNORE INTO addresses (subnet_id, ip, ip_bin, hostname, status, owner, note, grp, mac)
         VALUES (:sid, :ip, :ipbin, :host, 'reserved', '', 'Auto-reserved', '', '')"
    );

    // Network address
    $netIp  = $p['network'];
    $netBin = $p['net_bin'];
    $ins->execute([':sid' => $subnetId, ':ip' => $netIp, ':ipbin' => $netBin, ':host' => 'network']);
    if ($db->lastInsertId()) {
        audit($db, 'address.create', 'address', (int)$db->lastInsertId(), "auto-reserve network $netIp in subnet $subnetId");
    }

    if ($p['version'] === 4) {
        // Broadcast address for IPv4
        $hostBits = 32 - $p['prefix'];
        if ($hostBits > 0) {
            $unpacked  = unpack('N', $netBin) ?: [1 => 0];
            $n         = (int)$unpacked[1];
            $hostMask  = $hostBits === 32 ? 0xFFFFFFFF : ((1 << $hostBits) - 1);
            $bcastInt  = ($n | $hostMask) & 0xFFFFFFFF;
            $bcastBin  = pack('N', $bcastInt);
            $bcastIp   = inet_ntop($bcastBin) ?: '';
            if ($bcastIp !== '' && $bcastIp !== $netIp) {
                $ins->execute([':sid' => $subnetId, ':ip' => $bcastIp, ':ipbin' => $bcastBin, ':host' => 'broadcast']);
                if ($db->lastInsertId()) {
                    audit($db, 'address.create', 'address', (int)$db->lastInsertId(), "auto-reserve broadcast $bcastIp in subnet $subnetId");
                }
            }
        }
    }

    // Gateway (optional, any version)
    if ($gateway !== null && $gateway !== '') {
        $gwNorm = normalize_ip($gateway);
        if ($gwNorm && ip_in_cidr($gwNorm['ip'], $p['network'], $p['prefix'])) {
            $ins->execute([':sid' => $subnetId, ':ip' => $gwNorm['ip'], ':ipbin' => $gwNorm['bin'], ':host' => 'gateway']);
            if ($db->lastInsertId()) {
                audit($db, 'address.create', 'address', (int)$db->lastInsertId(), "auto-reserve gateway {$gwNorm['ip']} in subnet $subnetId");
            }
        }
    }
}

/* ---------------- Tags ---------------- */

/**
 * Return tags for a given entity (subnet or address).
 * @return array<array{id: int, name: string, colour: string}>
 */
function get_tags_for_entity(PDO $db, string $type, int $id): array
{
    if ($type === 'subnet') {
        $sql = "SELECT t.id, t.name, t.colour FROM tags t JOIN subnet_tags st ON st.tag_id = t.id WHERE st.subnet_id = :id ORDER BY t.name";
    } elseif ($type === 'address') {
        $sql = "SELECT t.id, t.name, t.colour FROM tags t JOIN address_tags at ON at.tag_id = t.id WHERE at.address_id = :id ORDER BY t.name";
    } else {
        return [];
    }
    $st = $db->prepare($sql);
    $st->execute([':id' => $id]);
    return array_map(fn($r) => ['id' => to_int($r['id']), 'name' => to_str($r['name']), 'colour' => to_str($r['colour'])], $st->fetchAll());
}

/**
 * Replace all tags for a given entity with the supplied tag IDs.
 * @param list<int> $tagIds
 */
function save_tags_for_entity(PDO $db, string $type, int $id, array $tagIds): void
{
    if ($type === 'subnet') {
        $db->prepare("DELETE FROM subnet_tags WHERE subnet_id = :id")->execute([':id' => $id]);
        $ins = $db->prepare("INSERT OR IGNORE INTO subnet_tags (subnet_id, tag_id) VALUES (:eid, :tid)");
    } elseif ($type === 'address') {
        $db->prepare("DELETE FROM address_tags WHERE address_id = :id")->execute([':id' => $id]);
        $ins = $db->prepare("INSERT OR IGNORE INTO address_tags (address_id, tag_id) VALUES (:eid, :tid)");
    } else {
        return;
    }
    foreach ($tagIds as $tid) {
        $ins->execute([':eid' => $id, ':tid' => $tid]);
    }
}

/** Render coloured tag badges for a list of tags (HTML-safe). */
function render_tag_badges(PDO $db, string $type, int $id): string
{
    $tags = get_tags_for_entity($db, $type, $id);
    if (!$tags) return '';
    $out = '';
    foreach ($tags as $tag) {
        $bg   = e($tag['colour']);
        $name = e($tag['name']);
        $out .= "<span class='tag-badge' style='background:$bg'>$name</span>";
    }
    return $out;
}

/* ---------------- Demo mode ---------------- */

function demo_mode_enabled(): bool
{
    /** @var IpamConfig $gConf */
    $gConf = $GLOBALS['config'];
    return !empty($gConf['demo_mode']['enabled']);
}

function demo_require_reset(): bool
{
    $marker = __DIR__ . '/data/demo_last_reset.txt';
    if (!is_file($marker)) return true;
    $lastReset = (int)file_get_contents($marker);
    $midnight  = mktime(0, 0, 0);
    return $lastReset < $midnight;
}

/** @return array<string, int> */
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
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel compatibility
}

/** @return resource */
function csv_output_handle()
{
    static $fh = null;
    if ($fh === null) {
        $fh = fopen('php://output', 'wb');
        if (!$fh) throw new RuntimeException('Cannot open php://output');
    }
    return $fh;
}

/** @param list<string> $row */
function csv_out(array $row): void
{
    $fh = csv_output_handle();
    fputcsv($fh, $row, ',', '"', '');
}

/* ---------------- Security warning banner ---------------- */

/**
 * Render a dismissible per-session security warning banner.
 *
 * Call this immediately after page_header() on sensitive admin pages.
 * The banner is hidden for the rest of the session once the user dismisses it.
 *
 * @param string $context  Short identifier for this banner, e.g. 'db_tools', 'import_csv'
 * @param string $message  Warning text to display (HTML-escaped before output)
 */
function render_security_banner(string $context, string $message): void
{
    // Handle dismiss: clicking the link adds ?dismiss_warning=<context> to the URL.
    // We process it here (before any HTML output from this function) and store in session.
    if (isset($_GET['dismiss_warning']) && $_GET['dismiss_warning'] === $context) {
        $dw = is_array($_SESSION['dismissed_warnings'] ?? null) ? $_SESSION['dismissed_warnings'] : [];
        $dw[$context] = true;
        $_SESSION['dismissed_warnings'] = $dw;
    }

    $dw = is_array($_SESSION['dismissed_warnings'] ?? null) ? $_SESSION['dismissed_warnings'] : [];
    if (!empty($dw[$context])) {
        return;
    }

    // Build dismiss URL: current URL with dismiss_warning param added, page reset removed
    $params = array_merge($_GET, ['dismiss_warning' => $context]);
    $dismissUrl = '?' . http_build_query($params);

    echo '<div class="security-banner">'
       . '<span>⚠ <strong>Security notice:</strong> ' . e($message) . '</span>'
       . '<a class="dismiss-link" href="' . e($dismissUrl) . '">Dismiss</a>'
       . '</div>';
}

/* ---------------- Demo mode seed/reset ---------------- */

function demo_reset_db(PDO $db): void
{
    // audit_log has append-only triggers that block DELETE, so bypass via rename+drop,
    // then immediately recreate the table and triggers.
    $db->exec("ALTER TABLE audit_log RENAME TO audit_log_old");
    $db->exec("DROP TABLE audit_log_old");
    ensure_audit_log_table($db);

    // Clear in FK-safe order; CASCADE removes subnet_tags, address_tags, alert_state.
    $tables = ['address_history', 'login_attempts', 'api_keys',
               'addresses', 'subnets', 'vlans', 'vrfs', 'contacts', 'tags',
               'sites', 'users', 'schema_migrations'];
    foreach ($tables as $t) {
        $db->exec("DELETE FROM $t");
        $db->exec("DELETE FROM sqlite_sequence WHERE name='$t'");
    }
    $db->exec("DELETE FROM sqlite_sequence WHERE name='audit_log'");
    apply_migrations($db);
    demo_seed_data($db);
}

function demo_seed_data(PDO $db): void
{
    // --- Sites (id=5,6 are region parents inserted first for self-referential FK) ---
    $si = $db->prepare("INSERT INTO sites (id, name, description, parent_id) VALUES (?,?,?,?)");
    foreach ([
        [5, 'EMEA Region',     'Europe, Middle East & Africa', null],
        [6, 'Americas Region', 'North & South America',        null],
        [1, 'London HQ',       'Primary headquarters',         5],
        [2, 'New York DC',     'East coast data centre',       6],
        [3, 'Sydney Office',   'APAC regional office',         null],
        [4, 'AWS eu-west-1',   'Cloud infrastructure',         5],
        ] as $s) $si->execute($s);

    // --- VRFs ---
    $vr = $db->prepare("INSERT INTO vrfs (id, name, description, rd) VALUES (?,?,?,?)");
    foreach ([
        [1, 'DEFAULT',  'Default global routing table', '65000:0'],
        [2, 'MGMT-VRF', 'Management plane VRF',         '65000:100'],
        ] as $v) $vr->execute($v);

    // --- VLANs ---
    $vl = $db->prepare("INSERT INTO vlans (id, vlan_id, name, description, site_id) VALUES (?,?,?,?,?)");
    foreach ([
        [1, 10,  'Management',    'Out-of-band management VLAN',    1],
        [2, 20,  'Servers',       'Server infrastructure VLAN',      1],
        [3, 30,  'DMZ',           'Demilitarised zone',              1],
        [4, 100, 'Cloud-Connect', 'AWS Direct Connect peering VLAN', 4],
        ] as $v) $vl->execute($v);

    // --- Contacts ---
    $ct = $db->prepare("INSERT INTO contacts (id, name, email, phone, org, note) VALUES (?,?,?,?,?,?)");
    foreach ([
        [1, 'Alice Smith', 'alice@example.com', '+44 20 7946 0001', 'NetOps',   'Primary network contact'],
        [2, 'Bob Jones',   'bob@example.com',   '+44 20 7946 0002', 'DBA',      'Database team lead'],
        [3, 'Carol Wu',    'carol@example.com', '+1 212 555 0103',  'Security', 'Security operations engineer'],
        ] as $c) $ct->execute($c);

    // --- Tags ---
    $tg = $db->prepare("INSERT INTO tags (id, name, colour) VALUES (?,?,?)");
    foreach ([
        [1, 'Production',  '#28a745'],
        [2, 'Development', '#17a2b8'],
        [3, 'Critical',    '#dc3545'],
        [4, 'Monitored',   '#6c757d'],
        ] as $t) $tg->execute($t);

    // --- Subnets (IPv4) ---
    // [id, cidr, site_id, vlan_fk, vrf_id, description]
    $sn = $db->prepare(
        "INSERT INTO subnets (id, cidr, ip_version, network, network_bin, prefix, description, site_id, vlan_fk, vrf_id)
         VALUES (?,?,4,?,?,?,?,?,?,?)"
    );
    foreach ([
        [1,  '10.0.0.0/8',    null, null, null, 'RFC-1918 supernet (informational)'],
        [2,  '10.10.0.0/16',  1,    null, null, 'London HQ corporate'],
        [3,  '10.10.1.0/24',  1,    1,    2,    'London management'],
        [4,  '10.10.2.0/24',  1,    2,    null, 'London servers'],
        [5,  '10.10.3.0/27',  1,    3,    null, 'London DMZ'],
        [6,  '10.20.0.0/16',  2,    null, null, 'New York DC corporate'],
        [7,  '10.20.1.0/24',  2,    null, null, 'New York servers'],
        [8,  '10.20.2.0/24',  2,    null, null, 'New York management'],
        [9,  '172.16.0.0/16', 3,    null, null, 'Sydney corporate'],
        [10, '172.16.1.0/24', 3,    null, null, 'Sydney servers'],
        ] as [$id, $cidr, $siteId, $vlanFk, $vrfId, $desc]) {
        [$net, $pfx] = explode('/', $cidr);
        $rawBin  = inet_pton($net) ?: throw new \RuntimeException("Invalid IP: $net");
        $netNorm = inet_ntop(apply_prefix_mask($rawBin, (int)$pfx)) ?: throw new \RuntimeException("inet_ntop failed");
        $netBin  = inet_pton($netNorm) ?: throw new \RuntimeException("inet_pton failed on $netNorm");
        $sn->execute([$id, $cidr, $netNorm, $netBin, (int)$pfx, $desc, $siteId, $vlanFk, $vrfId]);
    }

    // --- Subnets (IPv6) ---
    $sn6 = $db->prepare(
        "INSERT INTO subnets (id, cidr, ip_version, network, network_bin, prefix, description, site_id, vlan_fk, vrf_id)
         VALUES (?,?,6,?,?,?,?,?,?,?)"
    );
    foreach ([
        [11, '2001:db8::/32',   1, null, null, 'London HQ IPv6 allocation'],
        [12, '2001:db8:1::/48', 1, null, null, 'London servers IPv6'],
        [13, '2001:db8:2::/64', 2, null, null, 'New York IPv6 segment'],
        ] as [$id, $cidr, $siteId, $vlanFk, $vrfId, $desc]) {
        [$net, $pfx] = explode('/', $cidr);
        $rawBin6  = inet_pton($net) ?: throw new \RuntimeException("Invalid IP: $net");
        $netNorm6 = inet_ntop(apply_prefix_mask($rawBin6, (int)$pfx)) ?: throw new \RuntimeException("inet_ntop failed");
        $netBin6  = inet_pton($netNorm6) ?: throw new \RuntimeException("inet_pton failed on $netNorm6");
        $sn6->execute([$id, $cidr, $netNorm6, $netBin6, (int)$pfx, $desc, $siteId, $vlanFk, $vrfId]);
    }

    // --- Subnet tags ---
    $st = $db->prepare("INSERT INTO subnet_tags (subnet_id, tag_id) VALUES (?,?)");
    foreach ([
        [4, 1], [4, 4],   // London servers: Production, Monitored
        [5, 1], [5, 3],   // London DMZ: Production, Critical
        [12, 2],          // London servers IPv6: Development
        ] as $t) $st->execute($t);

    // --- Users ---
    // demo: admin, password 'demo' | readonly-user / netops-user: locked accounts for display
    $us = $db->prepare(
        "INSERT INTO users (username, password_hash, role, is_active, name, email) VALUES (?,?,?,?,?,?)"
    );
    foreach ([
        ['demo',          password_hash('demo', PASSWORD_DEFAULT), 'admin',    1, 'Demo Admin',  'demo@example.com'],
        ['readonly-user', '!disabled',                              'readonly', 1, 'Read Only',   'readonly@example.com'],
        ['netops-user',   '!disabled',                              'netops',   1, 'NetOps User', 'netops@example.com'],
        ] as $u) $us->execute($u);

    // --- Addresses ---
    // [subnet_id, ip, hostname, owner, status, note, mac, expires_at, owner_contact_id]
    // Address IDs assigned sequentially: subnet 3 = 1–9, subnet 4 = 10–27,
    // subnet 5 = 28–37, subnet 7 = 38–45, subnet 8 = 46–49, subnet 10 = 50–54.
    $ai = $db->prepare(
        "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, status, note, mac, expires_at, owner_contact_id)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    foreach ([
        // 10.10.1.0/24 — London management (id=1..9)
        [3, '10.10.1.1',   'gw-lon-mgmt',    'NetOps',   'used',     'Default gateway',        'aa:bb:cc:00:01:01', null,         1],
        [3, '10.10.1.2',   'sw-lon-core-01', 'NetOps',   'used',     'Core switch',            'aa:bb:cc:00:01:02', null,         1],
        [3, '10.10.1.3',   'sw-lon-core-02', 'NetOps',   'used',     'Core switch redundant',  'aa:bb:cc:00:01:03', null,         1],
        [3, '10.10.1.10',  'mon-lon-01',     'NetOps',   'used',     'Monitoring server',      '',                  null,         null],
        [3, '10.10.1.20',  'ntp-lon-01',     'NetOps',   'used',     'NTP server',             '',                  null,         null],
        [3, '10.10.1.30',  'dns-lon-01',     'NetOps',   'used',     'Primary DNS',            '',                  null,         null],
        [3, '10.10.1.31',  'dns-lon-02',     'NetOps',   'used',     'Secondary DNS',          '',                  null,         null],
        [3, '10.10.1.50',  '',               '',         'reserved', 'Reserved for IPMI',      '',                  null,         null],
        [3, '10.10.1.51',  '',               '',         'reserved', 'Reserved for IPMI',      '',                  null,         null],
        // 10.10.2.0/24 — London servers (id=10..27)
        [4, '10.10.2.1',   'gw-lon-srv',     'NetOps',   'used',     'Server gateway',         'aa:bb:cc:00:02:01', null,         1],
        [4, '10.10.2.10',  'web-lon-01',     'WebTeam',  'used',     'Web frontend 1',         'de:ad:be:ef:00:01', '2027-06-30', null],
        [4, '10.10.2.11',  'web-lon-02',     'WebTeam',  'used',     'Web frontend 2',         'de:ad:be:ef:00:02', '2027-06-30', null],
        [4, '10.10.2.12',  'web-lon-03',     'WebTeam',  'used',     'Web frontend 3',         'de:ad:be:ef:00:03', '2027-06-30', null],
        [4, '10.10.2.20',  'app-lon-01',     'AppTeam',  'used',     'Application server 1',   '',                  null,         null],
        [4, '10.10.2.21',  'app-lon-02',     'AppTeam',  'used',     'Application server 2',   '',                  null,         null],
        [4, '10.10.2.22',  'app-lon-03',     'AppTeam',  'used',     'Application server 3',   '',                  null,         null],
        [4, '10.10.2.30',  'db-lon-01',      'DBA',      'used',     'Primary database',       'fa:ce:b0:00:00:01', null,         2],
        [4, '10.10.2.31',  'db-lon-02',      'DBA',      'used',     'Replica database',       'fa:ce:b0:00:00:02', null,         2],
        [4, '10.10.2.32',  'db-lon-03',      'DBA',      'used',     'Backup database',        'fa:ce:b0:00:00:03', null,         2],
        [4, '10.10.2.40',  'cache-lon-01',   'AppTeam',  'used',     'Redis cache 1',          '',                  null,         null],
        [4, '10.10.2.41',  'cache-lon-02',   'AppTeam',  'used',     'Redis cache 2',          '',                  null,         null],
        [4, '10.10.2.50',  'storage-lon-01', 'Infra',    'used',     'NFS storage',            '',                  null,         null],
        [4, '10.10.2.51',  'storage-lon-02', 'Infra',    'used',     'NFS storage replica',    '',                  null,         null],
        [4, '10.10.2.100', 'backup-lon-01',  'Infra',    'used',     'Backup server',          '',                  null,         null],
        [4, '10.10.2.200', '',               '',         'free',     '',                        '',                  null,         null],
        [4, '10.10.2.201', '',               '',         'free',     '',                        '',                  null,         null],
        [4, '10.10.2.202', '',               '',         'free',     '',                        '',                  null,         null],
        // 10.10.3.0/27 — London DMZ (id=28..37)
        [5, '10.10.3.1',   'fw-lon-dmz',     'Security', 'used',     'DMZ firewall inside',    '00:50:56:a1:b2:c3', null,         3],
        [5, '10.10.3.2',   'proxy-lon-01',   'Security', 'used',     'Squid proxy',            '00:50:56:a1:b2:c4', null,         3],
        [5, '10.10.3.3',   'proxy-lon-02',   'Security', 'used',     'Squid proxy standby',    '00:50:56:a1:b2:c5', null,         3],
        [5, '10.10.3.4',   'waf-lon-01',     'Security', 'used',     'WAF node 1',             '',                  '2026-12-31', null],
        [5, '10.10.3.5',   'waf-lon-02',     'Security', 'used',     'WAF node 2',             '',                  '2026-12-31', null],
        [5, '10.10.3.6',   'mailgw-lon-01',  'Infra',    'used',     'Mail gateway',           '',                  null,         null],
        [5, '10.10.3.10',  'vpn-lon-01',     'NetOps',   'used',     'VPN concentrator',       '',                  null,         1],
        [5, '10.10.3.20',  '',               '',         'reserved', 'Future load balancer',   '',                  null,         null],
        [5, '10.10.3.21',  '',               '',         'reserved', 'Future load balancer',   '',                  null,         null],
        [5, '10.10.3.30',  '',               '',         'free',     '',                        '',                  null,         null],
        // 10.20.1.0/24 — New York servers (id=38..45)
        [7, '10.20.1.1',   'gw-nyc-srv',     'NetOps',   'used',     'NY server gateway',      '',                  null,         null],
        [7, '10.20.1.10',  'web-nyc-01',     'WebTeam',  'used',     'Web server NY 1',        '',                  '2027-03-31', null],
        [7, '10.20.1.11',  'web-nyc-02',     'WebTeam',  'used',     'Web server NY 2',        '',                  '2027-03-31', null],
        [7, '10.20.1.20',  'app-nyc-01',     'AppTeam',  'used',     'App server NY 1',        '',                  null,         null],
        [7, '10.20.1.21',  'app-nyc-02',     'AppTeam',  'used',     'App server NY 2',        '',                  null,         null],
        [7, '10.20.1.30',  'db-nyc-01',      'DBA',      'used',     'NY database',            '',                  null,         2],
        [7, '10.20.1.40',  'backup-nyc-01',  'Infra',    'used',     'NY backup server',       '',                  null,         null],
        [7, '10.20.1.200', '',               '',         'free',     '',                        '',                  null,         null],
        // 10.20.2.0/24 — New York management (id=46..49)
        [8, '10.20.2.1',   'gw-nyc-mgmt',   'NetOps',   'used',     'NY mgmt gateway',        '',                  null,         1],
        [8, '10.20.2.10',  'mon-nyc-01',    'NetOps',   'used',     'NY monitoring',           '',                  null,         null],
        [8, '10.20.2.20',  'ntp-nyc-01',    'NetOps',   'used',     'NY NTP',                  '',                  null,         null],
        [8, '10.20.2.30',  'dns-nyc-01',    'NetOps',   'used',     'NY DNS primary',          '',                  null,         null],
        // 172.16.1.0/24 — Sydney servers (id=50..54)
        [10, '172.16.1.1',  'gw-syd-srv',   'NetOps',   'used',     'Sydney server gateway',  '',                  null,         null],
        [10, '172.16.1.10', 'web-syd-01',   'WebTeam',  'used',     'Sydney web server',      '',                  null,         null],
        [10, '172.16.1.20', 'app-syd-01',   'AppTeam',  'used',     'Sydney app server',      '',                  null,         null],
        [10, '172.16.1.30', 'db-syd-01',    'DBA',      'used',     'Sydney database',        '',                  null,         null],
        [10, '172.16.1.100','',             '',          'free',     '',                        '',                  null,         null],
        ] as [$sid, $ip, $hn, $ow, $st, $nt, $mac, $exp, $cid]) {
        $bin = inet_pton($ip);
        $ai->execute([$sid, $ip, $bin, $hn, $ow, $st, $nt, $mac, $exp, $cid]);
    }

    // --- Address tags ---
    // db-lon-01 id=17: Critical(3), Monitored(4) | db-lon-02 id=18: Monitored(4) | fw-lon-dmz id=28: Critical(3)
    $at = $db->prepare("INSERT INTO address_tags (address_id, tag_id) VALUES (?,?)");
    foreach ([[17, 3], [17, 4], [18, 4], [28, 3]] as $t) $at->execute($t);

    // --- API Keys ---
    $ak = $db->prepare(
        "INSERT INTO api_keys (name, key_hash, is_active, created_by) VALUES (?,?,?,?)"
    );
    $ak->execute(['Monitoring (active)',   hash('sha256', 'demo-api-key-monitoring-1234567890abcdef'), 1, 'demo']);
    $ak->execute(['Old script (inactive)', hash('sha256', 'demo-api-key-old-script-0987654321fedcba'), 0, 'demo']);

    // --- Audit log (backdated) ---
    $al = $db->prepare(
        "INSERT INTO audit_log (action, entity_type, entity_id, username, ip, details, created_at)
         VALUES (?,?,?,?,?,?,datetime('now',?))"
    );
    foreach ([
        ['auth.login',        'user',    1,    'demo',          '192.168.1.100', 'login ok',                               '-30 days'],
        ['subnet.create',     'subnet',  3,    'demo',          '192.168.1.100', 'cidr=10.10.1.0/24',                      '-29 days'],
        ['subnet.create',     'subnet',  4,    'demo',          '192.168.1.100', 'cidr=10.10.2.0/24',                      '-29 days'],
        ['address.create',    'address', 1,    'demo',          '192.168.1.100', 'ip=10.10.1.1 subnet_id=3',               '-28 days'],
        ['address.create',    'address', 2,    'demo',          '192.168.1.100', 'ip=10.10.1.2 subnet_id=3',               '-28 days'],
        ['user.create',       'user',    2,    'demo',          '192.168.1.100', 'username=readonly-user role=readonly',   '-27 days'],
        ['user.create',       'user',    3,    'demo',          '192.168.1.100', 'username=netops-user role=netops',        '-27 days'],
        ['auth.login',        'user',    2,    'readonly-user', '10.10.1.55',    'login ok',                               '-26 days'],
        ['address.update',    'address', 11,   'demo',          '192.168.1.100', 'hostname=web-lon-01',                    '-25 days'],
        ['address.update',    'address', 12,   'demo',          '192.168.1.100', 'hostname=web-lon-02',                    '-25 days'],
        ['subnet.create',     'subnet',  5,    'demo',          '192.168.1.100', 'cidr=10.10.3.0/27',                      '-24 days'],
        ['address.create',    'address', 28,   'demo',          '192.168.1.100', 'ip=10.10.3.1 subnet_id=5',               '-23 days'],
        ['apikey.create',     'api_key', 1,    'demo',          '192.168.1.100', 'name=Monitoring (active)',                '-22 days'],
        ['apikey.create',     'api_key', 2,    'demo',          '192.168.1.100', 'name=Old script (inactive)',              '-22 days'],
        ['apikey.deactivate', 'api_key', 2,    'demo',          '192.168.1.100', '',                                       '-21 days'],
        ['site.create',       'site',    2,    'demo',          '192.168.1.100', 'name=New York DC',                       '-20 days'],
        ['site.create',       'site',    3,    'demo',          '192.168.1.100', 'name=Sydney Office',                     '-20 days'],
        ['auth.login',        'user',    3,    'netops-user',   '10.20.1.55',    'login ok',                               '-19 days'],
        ['address.create',    'address', 38,   'demo',          '192.168.1.100', 'ip=10.20.1.1 subnet_id=7',               '-18 days'],
        ['address.create',    'address', 50,   'demo',          '192.168.1.100', 'ip=172.16.1.1 subnet_id=10',             '-17 days'],
        ['vlan.create',       'vlan',    1,    'demo',          '192.168.1.100', 'vlan_id=10 name=Management',             '-16 days'],
        ['vrf.create',        'vrf',     2,    'demo',          '192.168.1.100', 'name=MGMT-VRF rd=65000:100',             '-16 days'],
        ['contact.create',    'contact', 1,    'demo',          '192.168.1.100', 'name=Alice Smith',                       '-15 days'],
        ['contact.create',    'contact', 2,    'demo',          '192.168.1.100', 'name=Bob Jones',                         '-15 days'],
        ['auth.login_failed', 'user',    null, '',              '203.0.113.50',  'username=hacker',                        '-15 days'],
        ['auth.login_failed', 'user',    null, '',              '203.0.113.50',  'username=hacker',                        '-15 days'],
        ['auth.login_blocked','user',    null, '',              '203.0.113.50',  'ip=203.0.113.50',                        '-15 days'],
        ['tag.create',        'tag',     1,    'demo',          '192.168.1.100', 'name=Production colour=#28a745',         '-14 days'],
        ['address.update',    'address', 35,   'demo',          '192.168.1.100', 'status=reserved',                        '-10 days'],
        ['export.csv',        'address', null, 'demo',          '192.168.1.100', 'subnet_id=4',                            '-8 days'],
        ['address.create',    'address', 51,   'demo',          '192.168.1.100', 'ip=172.16.1.10 subnet_id=10',            '-5 days'],
        ['auth.login',        'user',    1,    'demo',          '192.168.1.100', 'login ok',                               '-3 days'],
        ['address.bulk_update','address',null, 'demo',          '192.168.1.100', 'subnet_id=4 selected=3 affected=3',      '-2 days'],
        ['auth.login',        'user',    1,    'demo',          '192.168.1.100', 'login ok',                               '-1 day'],
        ['auth.login',        'user',    1,    'demo',          '192.168.1.100', 'login ok',                               '-0 seconds'],
        ] as $e) $al->execute($e);

    // --- Address history ---
    $hist = $db->prepare(
        "INSERT INTO address_history (address_id, subnet_id, ip, action, username, client_ip, before_json, after_json, created_at)
         VALUES (?,?,?,?,?,?,?,?,datetime('now',?))"
    );
    foreach ([
        // id=1 → 10.10.1.1 gw-lon-mgmt
        [1,  3, '10.10.1.1',  'create', 'demo', '192.168.1.100', null,
         '{"hostname":"gw-lon-mgmt","owner":"NetOps","status":"used","note":"Default gateway","mac":"aa:bb:cc:00:01:01"}',
         '-28 days'],
        // id=11 → 10.10.2.10 web-lon-01
        [11, 4, '10.10.2.10', 'create', 'demo', '192.168.1.100', null,
         '{"hostname":"","owner":"WebTeam","status":"used","note":"","mac":""}',
         '-28 days'],
        [11, 4, '10.10.2.10', 'update', 'demo', '192.168.1.100',
         '{"hostname":"","owner":"WebTeam","status":"used","note":"","mac":""}',
         '{"hostname":"web-lon-01","owner":"WebTeam","status":"used","note":"Web frontend 1","mac":"de:ad:be:ef:00:01","expires_at":"2027-06-30"}',
         '-25 days'],
        // id=13 → 10.10.2.12 web-lon-03
        [13, 4, '10.10.2.12', 'create', 'demo', '192.168.1.100', null,
         '{"hostname":"web-lon-03","owner":"WebTeam","status":"used","note":"Web frontend 3","mac":"de:ad:be:ef:00:03"}',
         '-28 days'],
        // id=14 → 10.10.2.20 app-lon-01
        [14, 4, '10.10.2.20', 'create', 'demo', '192.168.1.100', null,
         '{"hostname":"app-lon-01","owner":"AppTeam","status":"used","note":"Application server 1","mac":""}',
         '-28 days'],
        // id=28 → 10.10.3.1 fw-lon-dmz
        [28, 5, '10.10.3.1',  'create', 'demo', '192.168.1.100', null,
         '{"hostname":"fw-lon-dmz","owner":"Security","status":"used","note":"DMZ firewall inside","mac":"00:50:56:a1:b2:c3"}',
         '-23 days'],
        // id=35 → 10.10.3.20 (future load balancer)
        [35, 5, '10.10.3.20', 'create', 'demo', '192.168.1.100', null,
         '{"hostname":"","owner":"","status":"free","note":"","mac":""}',
         '-23 days'],
        [35, 5, '10.10.3.20', 'update', 'demo', '192.168.1.100',
         '{"hostname":"","owner":"","status":"free","note":"","mac":""}',
         '{"hostname":"","owner":"","status":"reserved","note":"Future load balancer","mac":""}',
         '-10 days'],
        ] as $h) $hist->execute($h);
}

/* ---------------- IP helpers ---------------- */

/** @return array{version: int, network: string, prefix: int, net_bin: string}|null */
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
    if ($network === false) return null;

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

/** @return array{ip: string, bin: string, version: int}|null */
function normalize_ip(string $ip): ?array
{
    $bin = @inet_pton(trim($ip));
    if ($bin === false) return null;
    $normalized = inet_ntop($bin);
    if ($normalized === false) return null;
    return ['ip' => $normalized, 'bin' => $bin, 'version' => (strlen($bin) === 4) ? 4 : 6];
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
    $unpacked = unpack('N', $bin);
    $n = $unpacked !== false ? $unpacked[1] : 0;
    return to_int($n & 0xFFFFFFFF);
}

function ipv4_int_to_bin(int $n): string
{
    $n = $n & 0xFFFFFFFF;
    return pack('N', $n);
}

function ipv4_int_to_text(int $n): string
{
    return inet_ntop(ipv4_int_to_bin($n)) ?: '';
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

/**
 * Find the first unassigned IPv4 host address in a subnet.
 * Returns the IP as text, or null if none available.
 */
function find_next_available_ipv4(PDO $db, int $subnetId, string $network, int $prefix): ?string
{
    if ($prefix > 32 || $prefix < 8) return null;
    $networkBin = inet_pton($network);
    if ($networkBin === false) return null;
    $netInt = ipv4_bin_to_int($networkBin);
    $first  = ($prefix >= 31) ? $netInt : $netInt + 1;
    $last   = ($prefix >= 31) ? $netInt + ((1 << (32 - $prefix)) - 1)
                              : $netInt + ((1 << (32 - $prefix)) - 1) - 1;

    // Fetch all used IPs in this subnet as a set
    $st = $db->prepare("SELECT ip FROM addresses WHERE subnet_id = :sid");
    $st->execute([':sid' => $subnetId]);
    $used = [];
    foreach ($st->fetchAll() as $r) $used[to_str($r['ip'])] = true;

    for ($i = $first; $i <= $last; $i++) {
        $ip = ipv4_int_to_text($i);
        if (!isset($used[$ip])) return $ip;
    }
    return null;
}

function ipv4_broadcast_int(int $networkInt, int $prefix): int
{
    $hostBits = 32 - $prefix;
    if ($hostBits <= 0) return $networkInt;
    $hostMask = ($hostBits === 32) ? 0xFFFFFFFF : ((1 << $hostBits) - 1);
    return to_int(($networkInt | $hostMask) & 0xFFFFFFFF);
}

/* ---------------- IPv6 enumeration helpers ---------------- */

/**
 * Increment a 16-byte IPv6 binary address by 1.
 * Returns all-zeros if the address overflows (all-0xFF).
 */
function ipv6_bin_increment(string $bin): string
{
    /** @var array<int, int> $bytes */
    $bytes = array_values(unpack('C16', $bin) ?: []);
    for ($i = 15; $i >= 0; $i--) {
        if ($bytes[$i] < 255) {
            $bytes[$i]++;
            return pack('C16', ...$bytes);
        }
        $bytes[$i] = 0;
    }
    return pack('C16', ...array_fill(0, 16, 0));
}

/**
 * Return the first $n unassigned IPv6 host addresses in a subnet.
 * Scans at most ($n + count(assigned) + 1) candidates from the first host address.
 *
 * @return list<string>
 */
function ipv6_enumerate_first_n(PDO $db, int $subnetId, string $networkBin, int $prefix, int $n): array
{
    if ($prefix >= 128) {
        $ip = inet_ntop($networkBin) ?: '';
        $st = $db->prepare("SELECT id FROM addresses WHERE subnet_id = :sid AND ip = :ip LIMIT 1");
        $st->execute([':sid' => $subnetId, ':ip' => $ip]);
        return ($ip !== '' && $st->fetch() === false) ? [$ip] : [];
    }

    $st = $db->prepare("SELECT ip FROM addresses WHERE subnet_id = :sid");
    $st->execute([':sid' => $subnetId]);
    $assigned = [];
    foreach ($st->fetchAll() as $r) $assigned[to_str($r['ip'])] = true;

    $current = ipv6_bin_increment($networkBin);
    $scanLimit = $n + count($assigned) + 1;
    $result = [];

    for ($i = 0; $i < $scanLimit && count($result) < $n; $i++) {
        $ip = inet_ntop($current) ?: '';
        if ($ip !== '' && !isset($assigned[$ip])) {
            $result[] = $ip;
        }
        $current = ipv6_bin_increment($current);
    }

    return $result;
}

/* ---------------- CSV import helpers ---------------- */

/** @param IpamConfig $config */
function import_max_bytes(array $config): int
{
    $mb = to_int($config['import_csv_max_mb']);
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

/** @param array<string, mixed> $plan */
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

/** @return array<string, mixed> */
function load_import_plan(string $path): array
{
    if (!is_file($path)) throw new RuntimeException('Import plan file not found');
    $json = file_get_contents($path);
    if ($json === false) throw new RuntimeException('Failed to read import plan');
    $data = json_decode($json, true);
    if (!is_array($data)) throw new RuntimeException('Invalid import plan');
    /** @var array<string, mixed> $data */
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
        $lines = preg_split("/\r\n|\n|\r/", $sample) ?: [];
        $counts = [];
        foreach (array_slice($lines, 0, 10) as $line) {
            if ($line === '') continue;
            $counts[] = count(str_getcsv($line, $d, '"', ''));
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

/** @return list<list<string>> */
function csv_read_preview(string $path, string $delimiter, int $maxRows = 20): array
{
    $fh = fopen($path, 'rb');
    if (!$fh) throw new RuntimeException("Cannot open upload");
    $rows = [];
    while (!feof($fh) && count($rows) < $maxRows) {
        $row = fgetcsv($fh, 0, $delimiter, '"', '');
        if ($row === false) break;
        $row = array_map('strval', $row);
        if (count($row) === 1 && trim($row[0]) === '') continue;
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function netmask_to_prefix(string $mask): ?int
{
    $bin = @inet_pton(trim($mask));
    if ($bin === false || strlen($bin) !== 4) return null;

    $unpacked = unpack('N', $bin);
    $n = $unpacked !== false ? $unpacked[1] : 0;
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

/**
 * @param array{ip: string, bin: string, version: int} $normIp
 * @return array<string, mixed>|null
 */
function find_containing_subnet(PDO $db, array $normIp): ?array
{
    static $cache = [];
    $ver = to_int($normIp['version']);
    if (!isset($cache[$ver])) {
        $st = $db->prepare("SELECT id, network, prefix, ip_version FROM subnets WHERE ip_version = :v ORDER BY prefix DESC");
        $st->execute([':v' => $ver]);
        $cache[$ver] = $st->fetchAll();
    }
    foreach ($cache[$ver] as $s) {
        if (ip_in_cidr($normIp['ip'], to_str($s['network']), to_int($s['prefix']))) return $s;
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
    /** @var array<string, mixed>|false $row */
    $row = $st->fetch();
    if ($row) return to_int($row['id']);

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

/** @param array{ip: string, bin: string, version: int} $normIp */
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
function detect_subnet_overlaps(PDO $db, string $cidr, ?int $excludeId = null, ?int $vrfId = null): array
{
    $p = parse_cidr($cidr);
    if (!$p) return ['parents' => [], 'children' => []];

    $ver    = to_int($p['version']);
    $prefix = to_int($p['prefix']);
    $netBin = to_str($p['net_bin']);

    // Scope overlap detection to the same VRF (NULL = global routing table).
    // SQLite's IS operator handles NULL equality correctly.
    $sql    = "SELECT id, cidr, prefix, network_bin FROM subnets WHERE ip_version = :v AND vrf_id IS :vrf";
    $params = [':v' => $ver, ':vrf' => $vrfId];
    if ($excludeId !== null) {
        $sql .= " AND id != :excl";
        $params[':excl'] = $excludeId;
    }
    $st = $db->prepare($sql);
    $st->execute($params);
    /** @var list<array<string, mixed>> $rows */
    $rows = $st->fetchAll();

    $parents  = [];
    $children = [];

    foreach ($rows as $row) {
        $rowPrefix = to_int($row['prefix']);
        $rowNetBin = to_str($row['network_bin']);

        if ($rowPrefix < $prefix) {
            // Candidate parent: does the existing broader subnet contain our new one?
            if (hash_equals(apply_prefix_mask($netBin, $rowPrefix), $rowNetBin)) {
                $parents[] = to_str($row['cidr']);
            }
        } elseif ($rowPrefix > $prefix) {
            // Candidate child: does our new broader subnet contain the existing one?
            if (hash_equals(apply_prefix_mask($rowNetBin, $prefix), $netBin)) {
                $children[] = to_str($row['cidr']);
            }
        }
        // Same prefix: exact duplicate — handled by DB UNIQUE constraint on cidr
    }

    return ['parents' => $parents, 'children' => $children];
}

/** @param array{parents: list<string>, children: list<string>} $overlaps */
function subnet_overlap_warning_text(array $overlaps): string
{
    $parts = [];
    if (!empty($overlaps['parents'])) {
        $list = implode(', ', $overlaps['parents']);
        $parts[] = 'nested inside: ' . $list;
    }
    if (!empty($overlaps['children'])) {
        $list = implode(', ', $overlaps['children']);
        $parts[] = 'parent of: ' . $list;
    }
    return 'Hierarchy notice — this subnet is ' . implode('; and ', $parts) . '. Verify this nesting is intentional.';
}

/* ---------------- UI helpers ---------------- */

/** @param array<string, string> $opts */
function page_header(string $title, array $opts = []): void
{
    global $config;
    $u = to_str($_SESSION['username'] ?? '');
    $role = to_str($_SESSION['role'] ?? '');
    $appName = trim($config['app_name']) ?: 'Simple PHP IPAM';

    $extraScriptSrc = isset($opts['extra_script_src']) && $opts['extra_script_src'] !== '' ? ' ' . $opts['extra_script_src'] : '';
    $frameSrc       = isset($opts['extra_frame_src'])  && $opts['extra_frame_src']  !== '' ? " frame-src 'self' " . $opts['extra_frame_src'] . ';' : '';
    header("Content-Security-Policy: default-src 'self'; script-src 'self'{$extraScriptSrc}; style-src 'self'; img-src 'self' data:;{$frameSrc} frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    echo "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
    echo "<title>" . e($appName) . " \u{2014} " . e($title) . "</title>";
    echo "<link rel='icon' type='image/webp' sizes='32x32' href='assets/favicon-32.webp'>";
    echo "<link rel='icon' type='image/png' sizes='32x32' href='assets/favicon-32.png'>";
    echo "<link rel='apple-touch-icon' type='image/webp' sizes='180x180' href='assets/apple-touch-icon.webp'>";
    echo "<link rel='apple-touch-icon' sizes='180x180' href='assets/apple-touch-icon.png'>";
    echo "<link rel='stylesheet' href='assets/app.css?v=2.2.1'>";
    // Expose server-side theme via meta tag so app.js can seed localStorage (CSP-safe)
    $userTheme = to_str($_SESSION['user_theme'] ?? 'auto');
    echo "<meta name='ipam-server-theme' content='" . e($userTheme) . "'>";
    echo "<script defer src='assets/app.js?v=2.2.1'></script>";
    echo "</head><body>";

    echo "<div class='topbar'><div class='nav-wrap'>";
    echo "<a href='dashboard.php' class='nav-brand'>"
       . "<picture><source srcset='assets/logo.webp' type='image/webp'><img src='assets/logo.png' alt='' class='nav-logo' aria-hidden='true' width='161' height='48'></picture>"
       . "</a>";
    echo "<button class='nav-toggle' id='nav-toggle' aria-label='Open menu' aria-expanded='false' aria-controls='nav-drawer'>&#9776;</button>";
    echo "<div class='nav-links'>";
    if ($u) {
        echo "<a class='nav-pill' href='dashboard.php'>🏠 Dashboard</a>";
        echo "<a class='nav-pill' href='subnets.php'>🌐 Subnets</a>";
        echo "<a class='nav-pill' href='addresses.php'>🧾 Addresses</a>";
        echo "<a class='nav-pill nav-search-link' href='search.php'>🔎 Search <kbd class='nav-kbd'>⌘K</kbd></a>";
        echo "<a class='nav-pill' href='audit.php'>📜 Audit</a>";
        if ($role === 'admin') {
            echo "<div class='nav-dropdown'>";
            echo "<button type='button' class='nav-pill nav-dropdown-toggle'>⚙ Admin ▾</button>";
            echo "<div class='nav-dropdown-menu'>";
            echo "<a class='nav-dropdown-item' href='dhcp_pool.php'>🔒 DHCP Pools</a>";
            echo "<hr class='nav-dropdown-divider'>";
            echo "<a class='nav-dropdown-item' href='sites.php'>📍 Sites</a>";
            echo "<a class='nav-dropdown-item' href='vrfs.php'>🌐 VRFs</a>";
            echo "<a class='nav-dropdown-item' href='vlans.php'>🏷 VLANs</a>";
            echo "<a class='nav-dropdown-item' href='tags.php'>🔖 Tags</a>";
            echo "<a class='nav-dropdown-item' href='contacts.php'>📇 Contacts</a>";
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
        echo e($u) . " <span class='badge badge-role-" . e($role) . "'>" . e($role) . "</span> ▾";
        echo "</button>";
        echo "<div class='nav-dropdown-menu nav-dropdown-menu--right'>";
        echo "<button type='button' class='nav-dropdown-item' id='theme-toggle'>🌓 Theme</button>";
        echo "<hr class='nav-dropdown-divider'>";
        echo "<a class='nav-dropdown-item' href='change_password.php'>🔐 Password</a>";
        echo "<a class='nav-dropdown-item' href='logout.php'>↩ Logout</a>";
        echo "</div></div>";
        echo "</div>";
    }

    echo "</div></div>";

    // Mobile nav drawer (hidden on desktop, slides in on mobile)
    echo "<div id='nav-drawer' aria-hidden='true'>";
    echo "<button class='drawer-close' aria-label='Close menu'>&#10005;</button>";
    if ($u) {
        echo "<span class='nav-drawer-section'>Navigation</span>";
        echo "<a href='dashboard.php'>&#127968; Dashboard</a>";
        echo "<a href='subnets.php'>&#127760; Subnets</a>";
        echo "<a href='addresses.php'>&#129438; Addresses</a>";
        echo "<a href='search.php'>&#128270; Search</a>";
        echo "<a href='audit.php'>&#128220; Audit</a>";
        if ($role === 'admin') {
            echo "<hr>";
            echo "<span class='nav-drawer-section'>Admin</span>";
            echo "<a href='dhcp_pool.php'>&#128274; DHCP Pools</a>";
            echo "<a href='sites.php'>&#128205; Sites</a>";
            echo "<a href='vrfs.php'>&#127760; VRFs</a>";
            echo "<a href='vlans.php'>&#127991; VLANs</a>";
            echo "<a href='tags.php'>&#128278; Tags</a>";
            echo "<a href='contacts.php'>&#128215; Contacts</a>";
            echo "<a href='users.php'>&#128100; Users</a>";
            echo "<a href='api_keys.php'>&#128273; API Keys</a>";
            echo "<a href='import_csv.php'>&#8679; Import CSV</a>";
            echo "<a href='db_tools.php'>&#128444; Database Tools</a>";
        }
        echo "<hr>";
        echo "<span class='nav-drawer-section'>Account</span>";
        echo "<a href='change_password.php'>&#128272; Password</a>";
        echo "<a href='logout.php'>&#8617; Logout</a>";
    } else {
        echo "<a href='login.php'>&#128272; Login</a>";
    }
    echo "</div>";
    echo "<div class='nav-drawer-overlay'></div>";

    // ⌘K / Ctrl+K search overlay (#253)
    echo "<div id='search-overlay' role='dialog' aria-modal='true' aria-label='Quick search'>";
    echo "<div class='so-box'>";
    echo "<input id='search-overlay-input' type='search' placeholder='Search IPs, hostnames, owners…' autocomplete='off' spellcheck='false'>";
    echo "<button id='search-overlay-close' class='so-close' aria-label='Close search'>&times;</button>";
    echo "<ul id='search-overlay-list'></ul>";
    echo "<div class='so-hint'>&#x23CE; to navigate &nbsp;&middot;&nbsp; &#x2191;&#x2193; to move &nbsp;&middot;&nbsp; <kbd>Esc</kbd> to close</div>";
    echo "</div>";
    echo "</div>";

    echo "<div class='page'>";

    // Demo mode banner (non-dismissible)
    if (demo_mode_enabled()) {
        echo "<div class='admin-notice admin-notice--info text-center' role='alert'>"
           . "🧪 <strong>Demo mode</strong> — Explore freely. Destructive actions are disabled. Data resets nightly at midnight."
           . "</div>";
    }

    // Default bootstrap admin password warning (admin only)
    if ($role === 'admin') {
        if (($config['bootstrap_admin']['password'] ?? '') === 'ChangeMeNow!12345') {
            echo "<div class='admin-notice admin-notice--danger' role='alert'>"
               . "⚠ <strong>Security warning:</strong> The default bootstrap admin password is still set in <code>config.php</code>. "
               . "<a href='change_password.php'>Change your password</a> and update <code>config.php</code> before this site receives any traffic."
               . "</div>";
        }
    }

    // Config auto-population notice (shown once per session, admin only)
    if (!empty($_SESSION['config_notice']) && $role === 'admin') {
        $notice = e(to_str($_SESSION['config_notice']));
        echo "<div class='admin-notice admin-notice--info' role='alert'>"
           . "⚙ Config updated: {$notice} Review and adjust values in config.php."
           . "</div>";
        unset($_SESSION['config_notice']);
    }

    // Config write failure notice — shown when config.php is not writable (#119)
    if (!empty($_SESSION['config_unwritable']) && $role === 'admin') {
        echo "<div class='admin-notice admin-notice--danger' role='alert'>"
           . "&#9888; config.php is not writable — new configuration keys could not be saved. "
           . "Check file permissions."
           . "</div>";
        unset($_SESSION['config_unwritable']);
    }

    // Config validation warnings — shown to admins when config.php has invalid values (#236)
    if ($role === 'admin' && !empty($GLOBALS['config_warnings']) && is_array($GLOBALS['config_warnings'])) {
        foreach ($GLOBALS['config_warnings'] as $cfgWarn) {
            echo "<div class='admin-notice admin-notice--danger' role='alert'>"
               . "&#9888; <strong>Config warning:</strong> " . e(to_str($cfgWarn))
               . " Review <code>config.php</code>."
               . "</div>";
        }
    }

    // General flash messages (success, warning, danger)
    $flash = flash_get();
    if ($flash) {
        $flashClass = match ($flash['type']) {
            'success' => 'success',
            'warning' => 'warning',
            'error', 'danger' => 'danger',
            default => 'success',
        };
        echo "<p class='{$flashClass}'>" . e($flash['msg']) . "</p>";
    }

    // Update-available dismissible banner (admin only, client-side dismiss via localStorage)
    if ($role === 'admin') {
        $update = ipam_update_check($config ?? []);
        if ($update) {
            $uv  = e(to_str($update['version']));
            $url = e(to_str($update['url']));
            echo "<div class='admin-notice admin-notice--update' id='ipam-update-banner' data-version='{$uv}' role='alert'>"
               . "🚀 Simple PHP IPAM v{$uv} is available. "
               . "<a href='{$url}' target='_blank' rel='noopener'>View release</a>"
               . " &nbsp;<button type='button' class='button-secondary btn-sm' "
               . "data-dismiss-update='{$uv}'>Dismiss</button>"
               . "</div>";
        }
    }
}

function page_footer(): void
{
    global $config;
    require_once __DIR__ . '/version.php';

    echo "<hr><div class='muted footer-meta'>";
    echo "<a href='https://github.com/seanmousseau/Simple-PHP-IPAM' target='_blank' rel='noopener' class='link-plain'>"
       . "<picture><source srcset='assets/logo.webp' type='image/webp'><img src='assets/logo.png' alt='Simple PHP IPAM' width='81' height='24' style='vertical-align:middle;opacity:.7;'></picture>"
       . "</a> v" . e(IPAM_VERSION);

    $update = ipam_update_check($config ?? []);
    if ($update) {
        $uv  = e(to_str($update['version']));
        $url = e(to_str($update['url']));
        echo " <a href='{$url}' target='_blank' rel='noopener' class='badge badge-update'>"
           . "Update available v{$uv}</a>";
    }

    // Slide-in form drawer container (populated by JS openFormDrawer())
    echo "<div id='form-drawer' role='dialog' aria-modal='true' aria-labelledby='drawer-title-text'>";
    echo "<div class='drawer-header'>";
    echo "<span class='drawer-title' id='drawer-title-text'></span>";
    echo "<button class='drawer-close-btn' aria-label='Close'>&times;</button>";
    echo "</div>";
    echo "<div id='form-drawer-body'></div>";
    echo "</div>";
    echo "<div class='form-drawer-overlay'></div>";

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
/**
 * @param IpamConfig $config
 * @return array{version: string, url: string}|null
 */
function ipam_update_check(array $config): ?array
{
    // Memoize within a single request — page_header() and page_footer() both call this
    static $memo = false;
    if ($memo !== false) return $memo;

    $uc = $config['update_check'];
    if (!(bool)$uc['enabled']) { $memo = null; return null; }

    $ttl             = max(3600, to_int($uc['ttl_seconds']));
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
                && version_compare(ipam_normalise_version(to_str($d['update']['version'])), ipam_normalise_version(IPAM_VERSION), '<=')) {
                @unlink($cache);
            } else {
                $u = isset($d['update']) && is_array($d['update']) ? $d['update'] : null;
                $memo = ($u !== null && isset($u['version'], $u['url']))
                    ? ['version' => to_str($u['version']), 'url' => to_str($u['url'])]
                    : null;
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

                    $latest = ltrim(to_str($rel['tag_name']), 'v');
                    if (version_compare(ipam_normalise_version($latest), ipam_normalise_version(IPAM_VERSION), '>')) {
                        $result = [
                            'version'    => $latest,
                            'url'        => to_str($rel['html_url'] ?? ''),
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
function find_parent_site_id(PDO $db, string $cidr, ?int $excludeId = null, ?int $vrfId = null): ?int
{
    $overlaps = detect_subnet_overlaps($db, $cidr, $excludeId, $vrfId);
    if (empty($overlaps['parents'])) return null;

    $placeholders = implode(',', array_fill(0, count($overlaps['parents']), '?'));
    $st = $db->prepare(
        "SELECT site_id FROM subnets
         WHERE cidr IN ($placeholders) AND site_id IS NOT NULL AND vrf_id IS ?
         ORDER BY prefix DESC LIMIT 1"
    );
    $st->execute(array_merge($overlaps['parents'], [$vrfId]));
    /** @var array<string, mixed>|false $row */
    $row = $st->fetch();
    return $row ? to_int($row['site_id']) : null;
}

/* ============================================================
 * OIDC — Authorization Code + PKCE (pure PHP, no dependencies)
 * ============================================================ */

/** @param IpamConfig $config */
function oidc_enabled(array $config): bool
{
    $o = $config['oidc'];
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
/**
 * @param IpamConfig $config
 * @return array<string, mixed>
 */
function oidc_discovery(array $config): array
{
    $base = rtrim($config['oidc']['discovery_url'], '/');
    if ($base === '') throw new RuntimeException('OIDC discovery_url not set');

    $url = (str_contains($base, '.well-known')) ? $base : $base . '/.well-known/openid-configuration';

    ensure_tmp_dir();
    $cache = tmp_dir() . '/oidc-disc-' . md5($url) . '.json';

    if (is_file($cache) && (time() - (int)filemtime($cache)) < 3600) {
        $d = json_decode((string)file_get_contents($cache), true);
        if (is_array($d) && !empty($d['authorization_endpoint'])) {
            /** @var array<string, mixed> $d */
            return $d;
        }
    }

    $raw = oidc_http_get($url);
    $d   = json_decode($raw, true);
    if (!is_array($d) || empty($d['authorization_endpoint'])) {
        throw new RuntimeException('Invalid OIDC discovery document from ' . $url);
    }
    /** @var array<string, mixed> $d */

    file_put_contents($cache, json_encode($d));
    @chmod($cache, 0600);
    return $d;
}

/**
 * Fetch and cache the IdP's JSON Web Key Set.
 * Pass $forceRefresh = true to bypass the cache (used after a verify failure
 * to handle key rotation).
 */
/** @return list<mixed> */
function oidc_jwks(string $jwksUri, bool $forceRefresh = false): array
{
    ensure_tmp_dir();
    $cache = tmp_dir() . '/oidc-jwks-' . md5($jwksUri) . '.json';

    if (!$forceRefresh && is_file($cache) && (time() - (int)filemtime($cache)) < 3600) {
        $d = json_decode((string)file_get_contents($cache), true);
        if (is_array($d) && !empty($d['keys'])) return array_values((array)$d['keys']);
    }

    $raw = oidc_http_get($jwksUri);
    $d   = json_decode($raw, true);
    if (!is_array($d) || !isset($d['keys'])) {
        throw new RuntimeException('Invalid JWKS from ' . $jwksUri);
    }

    file_put_contents($cache, json_encode($d));
    @chmod($cache, 0600);
    return array_values((array)$d['keys']);
}

/** HTTP GET via file_get_contents with a short timeout. */
function oidc_http_get(string $url): string
{
    $ctx = stream_context_create([
        'http' => ['timeout' => 10, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx, 0, 1048576);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('HTTP GET failed for ' . $url);
    }
    return $raw;
}

/**
 * POST application/x-www-form-urlencoded and return decoded JSON array.
 *
 * @param array<string, string> $params
 * @return array<string, mixed>
 */
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
    $raw = @file_get_contents($url, false, $ctx, 0, 1048576);
    if ($raw === false) throw new RuntimeException('Token endpoint request failed');
    $d = json_decode($raw, true);
    if (!is_array($d)) throw new RuntimeException('Invalid JSON from token endpoint');
    /** @var array<string, mixed> $d */
    if (!empty($d['error'])) {
        throw new RuntimeException('Token endpoint error: ' . to_str($d['error'])
            . (isset($d['error_description']) ? ' — ' . to_str($d['error_description']) : ''));
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
 * @param list<mixed> $jwks                  Keys from the IdP's JWKS endpoint
 * @param array<string, string> $expect       Claims to validate: iss, aud, nonce
 * @return array<string, mixed>
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

    $alg = to_str($header['alg'] ?? '');
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
        throw new RuntimeException('No matching RSA JWK for kid=' . to_str($kid ?? 'none'));
    }

    $pem    = jwk_rsa_to_pem($jwk);
    $pubKey = openssl_pkey_get_public($pem);
    if ($pubKey === false) throw new RuntimeException('Failed to import JWK public key');

    $sig    = base64url_decode($sigB64);
    $result = openssl_verify($hdrB64 . '.' . $payB64, $sig, $pubKey, $algMap[$alg]);
    if ($result !== 1) throw new RuntimeException('JWT signature invalid');

    // Standard claim validation
    $now = time();
    if (isset($payload['exp']) && to_int($payload['exp']) < $now - 60) {
        throw new RuntimeException('ID token has expired');
    }
    if (isset($payload['iat']) && to_int($payload['iat']) > $now + 60) {
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
/** @param array<string, mixed> $jwk */
function jwk_rsa_to_pem(array $jwk): string
{
    $n = base64url_decode(to_str($jwk['n'] ?? ''));
    $e = base64url_decode(to_str($jwk['e'] ?? ''));
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
