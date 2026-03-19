<?php
declare(strict_types=1);

/**
 * Full lib.php (v0.8 patch) - includes CSS/JS asset loading + theme toggle buttons in nav.
 * NOTE: This file is long; if you have local modifications, merge only page_header/page_footer changes.
 */

/* ===================== DB ===================== */

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

        ensure_migrations_table($db);
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

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ===================== CSRF ===================== */

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

/* ===================== Auth / RBAC ===================== */

function is_logged_in(): bool { return !empty($_SESSION['uid']); }

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
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

/* ===================== Audit ===================== */

function client_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? ''); }

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

/* ===================== History ===================== */

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

/* ===================== Migrations ===================== */

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

/* ===================== Housekeeping ===================== */

function housekeeping_state_path(): string { return __DIR__ . '/data/housekeeping.json'; }

function housekeeping_should_run(array $config): bool
{
    $hk = $config['housekeeping'] ?? [];
    if (empty($hk['enabled'])) return false;

    $interval = (int)($hk['interval_seconds'] ?? 86400);
    if ($interval < 3600) $interval = 3600;

    $path = housekeeping_state_path();
    if (!is_file($path)) return true;

    $json = file_get_contents($path);
    if ($json === false) return true;

    $data = json_decode($json, true);
    $last = (int)($data['last_run'] ?? 0);

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

function run_housekeeping_if_due(array $config): void
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
        housekeeping_mark_ran();
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

/* ===================== CSV temp helpers ===================== */

function tmp_dir(): string { return __DIR__ . '/data/tmp'; }

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

/* ===================== UI ===================== */

function page_header(string $title): void
{
    $u = $_SESSION['username'] ?? '';
    $role = $_SESSION['role'] ?? '';

    echo "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
    echo "<title>" . e($title) . "</title>";
    echo "<link rel='stylesheet' href='assets/app.css'>";
    echo "<script defer src='assets/app.js'></script>";
    echo "</head><body>";

    echo "<nav>";
    if ($u) {
        echo "Logged in as <b>" . e((string)$u) . "</b> <span class='badge'>" . e((string)$role) . "</span> &nbsp;|&nbsp; ";
        echo "<a href='dashboard.php'>Dashboard</a>";
        echo "<a href='subnets.php'>Subnets</a>";
        echo "<a href='addresses.php'>Addresses</a>";
        echo "<a href='search.php'>Search</a>";
        echo "<a href='unassigned.php'>Unassigned</a>";

        if (($role ?? '') !== 'readonly') {
            echo "<a href='bulk_update.php'>Bulk Update</a>";
        }

        echo "<a href='audit.php'>Audit</a>";
        if (($role ?? '') === 'admin') {
            echo "<a href='users.php'>Users</a>";
            echo "<a href='import_csv.php'>Import CSV</a>";
        }
        echo "<a href='change_password.php'>Change Password</a>";
        echo "<a href='logout.php'>Logout</a>";

        echo "<span class='nav-right'>";
        echo "<button type='button' onclick='ipamToggleTheme()'>Toggle theme</button>";
        echo "<button type='button' onclick='ipamClearTheme()'>System</button>";
        echo "</span>";
    } else {
        echo "<a href='login.php'>Login</a>";
        echo "<span class='nav-right'>";
        echo "<button type='button' onclick='ipamToggleTheme()'>Toggle theme</button>";
        echo "<button type='button' onclick='ipamClearTheme()'>System</button>";
        echo "</span>";
    }
    echo "</nav><hr>";
}

function page_footer(): void
{
    require __DIR__ . '/version.php';
    echo "<hr><div class='muted'>PHP SQLite IPAM v" . e(IPAM_VERSION) . "</div></body></html>";
}
