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

/* ---------------- Audit ---------------- */

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
        cleanup_tmp_import_plans($ttl);
        housekeeping_mark_ran();
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
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

    echo "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
    echo "<title>" . e($title) . "</title>";
    echo "<link rel='stylesheet' href='assets/app.css?v=0.9.1'>";
    echo "<script defer src='assets/app.js?v=0.9.1'></script>";
    echo "</head><body>";

    echo "<div class='topbar'><div class='nav-wrap'>";
    echo "<div class='nav-links'>";
    if ($u) {
        echo "<span class='nav-user'>Logged in as <b>" . e((string)$u) . "</b> <span class='badge'>" . e((string)$role) . "</span></span>";
        echo "<a class='nav-pill' href='dashboard.php'>🏠 Dashboard</a>";
        echo "<a class='nav-pill' href='subnets.php'>🌐 Subnets</a>";
        echo "<a class='nav-pill' href='addresses.php'>🧾 Addresses</a>";
        echo "<a class='nav-pill' href='search.php'>🔎 Search</a>";
        echo "<a class='nav-pill' href='audit.php'>📜 Audit</a>";
        if (($role ?? '') === 'admin') {
            echo "<a class='nav-pill' href='sites.php'>📍 Sites</a>";
            echo "<a class='nav-pill' href='users.php'>👤 Users</a>";
            echo "<a class='nav-pill' href='import_csv.php'>⬆ Import CSV</a>";
        }
        echo "<a class='nav-pill' href='change_password.php'>🔐 Password</a>";
        echo "<a class='nav-pill' href='logout.php'>↩ Logout</a>";
    } else {
        echo "<a class='nav-pill' href='login.php'>🔐 Login</a>";
    }
    echo "</div>";

    echo "<div class='nav-right'>";
    echo "<button type='button' class='button-secondary' onclick='ipamToggleTheme()'>🌓 Theme</button>";
    echo "<button type='button' class='button-secondary' onclick='ipamClearTheme()'>🖥 System</button>";
    echo "</div>";

    echo "</div></div>";
    echo "<div class='page'>";
}

function page_footer(): void
{
    require __DIR__ . '/version.php';
    echo "<hr><div class='muted'>PHP SQLite IPAM v" . e(IPAM_VERSION) . "</div>";
    echo "</div></body></html>";
}
