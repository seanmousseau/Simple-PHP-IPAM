<?php
declare(strict_types=1);

/*
 * PATCH: v0.6 bulk update nav link (Option B)
 * - Adds "Bulk Update" link in navigation for non-readonly users.
 *
 * IMPORTANT:
 * This file is provided as a FULL lib.php for the current bundle line.
 * If you have local modifications, merge only the page_header() change:
 *
 *   if (($role ?? '') !== 'readonly') echo "<a href='bulk_update.php'>Bulk Update</a>";
 *
 * placed after the Addresses link.
 */

function ipam_db(string $path): PDO
{
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0700, true);

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

/* ---- migrations ---- */

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

/* ---- UI ---- */

function page_header(string $title): void
{
    $u = $_SESSION['username'] ?? '';
    $role = $_SESSION['role'] ?? '';

    echo "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
    echo "<title>" . e($title) . "</title>";
    echo "<style>
      body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:24px;max-width:1100px}
      nav a{margin-right:12px}
      table{border-collapse:collapse;width:100%}
      th,td{border:1px solid #ccc;padding:8px;text-align:left;vertical-align:top}
      input,select{padding:6px}
      .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
      .muted{color:#666}
      .danger{color:#b00020}
      .badge{display:inline-block;padding:2px 8px;border:1px solid #999;border-radius:999px;font-size:12px}
      code{background:#f5f5f5;padding:1px 4px;border-radius:4px}
      summary{cursor:pointer}
    </style>";
    echo "</head><body>";

    echo "<nav>";
    if ($u) {
        echo "Logged in as <b>" . e((string)$u) . "</b> <span class='badge'>" . e((string)$role) . "</span> &nbsp;|&nbsp; ";
        echo "<a href='dashboard.php'>Dashboard</a>";
        echo "<a href='subnets.php'>Subnets</a>";
        echo "<a href='addresses.php'>Addresses</a>";

        // Option B: show Bulk Update only for non-readonly users
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
    } else {
        echo "<a href='login.php'>Login</a>";
    }
    echo "</nav><hr>";
}

function page_footer(): void
{
    require __DIR__ . '/version.php';
    echo "<hr><div class='muted'>PHP SQLite IPAM v" . e(IPAM_VERSION) . "</div></body></html>";
}
