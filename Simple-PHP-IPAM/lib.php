<?php
declare(strict_types=1);

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

/* ---- housekeeping ---- */

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

/* ---- IP + CSV helpers ---- */

function parse_cidr(string $cidr): ?array
{
    $cidr = trim($cidr);
    if (strpos($cidr, '/') === false) return null;
    [$ip, $prefixStr] = explode('/', $cidr, 2);

    $ipBin = @inet_pton(trim($ip));
    if ($ipBin === false) return null;

    $len = strlen($ipBin);
    $version = ($len === 4) ? 4 : (($len === 16) ? 6 : 0);
    if ($version === 0) return null;

    if (!ctype_digit(trim($prefixStr))) return null;
    $prefix = (int)$prefixStr;
    $max = ($version === 4) ? 32 : 128;
    if ($prefix < 0 || $prefix > $max) return null;

    $netBin = apply_prefix_mask($ipBin, $prefix);
    return [
        'version' => $version,
        'network' => inet_ntop($netBin),
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

function import_max_bytes(array $config): int
{
    $mb = (int)($config['import_csv_max_mb'] ?? 5);
    if ($mb < 5) $mb = 5;
    if ($mb > 50) $mb = 50;
    return $mb * 1024 * 1024;
}

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
    $st = $db->prepare("SELECT id, network, prefix, ip_version FROM subnets WHERE ip_version = :v ORDER BY prefix ASC");
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
