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
    // New DB?
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

    // Existing DB: don't exec schema.sql
    ensure_migrations_table($db);
    apply_migrations($db);

    // Ensure at least one user exists
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

/* ---- CSRF / auth helpers ---- */

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
    ensure_migrations_table($db);
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

/* ---- IP helpers ---- */

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

/* ---- Subnet hierarchy + utilization ---- */

function subnet_contains_bin(string $parentNetBin, int $parentPrefix, string $childNetBin): bool
{
    return hash_equals(apply_prefix_mask($childNetBin, $parentPrefix), $parentNetBin);
}

function build_subnet_tree(array $rows): array
{
    $byId = [];
    foreach ($rows as $r) $byId[(int)$r['id']] = $r;

    $ids = array_keys($byId);
    $parentOf = [];
    $children = [];
    $roots = [];

    foreach ($ids as $childId) {
        $child = $byId[$childId];
        $bestParent = null;
        $bestPrefix = -1;

        foreach ($ids as $parentId) {
            if ($parentId === $childId) continue;
            $parent = $byId[$parentId];

            if ((int)$parent['ip_version'] !== (int)$child['ip_version']) continue;
            $pp = (int)$parent['prefix'];
            $cp = (int)$child['prefix'];
            if ($pp >= $cp) continue;

            if (subnet_contains_bin($parent['network_bin'], $pp, $child['network_bin']) && $pp > $bestPrefix) {
                $bestPrefix = $pp;
                $bestParent = $parentId;
            }
        }
        $parentOf[$childId] = $bestParent;
    }

    $cmpFn = function(int $a, int $b) use ($byId): int {
        $ra = $byId[$a]; $rb = $byId[$b];
        $va = (int)$ra['ip_version']; $vb = (int)$rb['ip_version'];
        if ($va !== $vb) return $va <=> $vb;
        $c = strcmp($ra['network_bin'], $rb['network_bin']);
        if ($c !== 0) return $c;
        return (int)$ra['prefix'] <=> (int)$rb['prefix'];
    };

    foreach ($ids as $id) {
        $p = $parentOf[$id];
        if ($p === null) $roots[] = $id;
        else $children[$p][] = $id;
    }

    usort($roots, $cmpFn);
    foreach ($children as $pid => $list) {
        usort($list, $cmpFn);
        $children[$pid] = $list;
    }

    return ['roots' => $roots, 'children' => $children, 'byId' => $byId, 'parentOf' => $parentOf];
}

function subnet_direct_counts(PDO $db): array
{
    $st = $db->prepare("SELECT subnet_id, status, COUNT(*) AS c FROM addresses GROUP BY subnet_id, status");
    $st->execute();

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $sid = (int)$r['subnet_id'];
        $status = (string)$r['status'];
        $c = (int)$r['c'];
        $out[$sid] ??= ['used'=>0,'reserved'=>0,'free'=>0,'total'=>0];
        if (isset($out[$sid][$status])) $out[$sid][$status] += $c;
        $out[$sid]['total'] += $c;
    }
    return $out;
}

function subnet_aggregated_counts(array $tree, array $directCounts): array
{
    $children = $tree['children'];
    $agg = [];

    $sumNode = function(int $id) use (&$sumNode, &$agg, $children, $directCounts): array {
        if (isset($agg[$id])) return $agg[$id];

        $base = $directCounts[$id] ?? ['used'=>0,'reserved'=>0,'free'=>0,'total'=>0];
        $sum = $base;

        foreach (($children[$id] ?? []) as $cid) {
            $c = $sumNode((int)$cid);
            $sum['used']     += $c['used'];
            $sum['reserved'] += $c['reserved'];
            $sum['free']     += $c['free'];
            $sum['total']    += $c['total'];
        }
        return $agg[$id] = $sum;
    };

    foreach ($tree['byId'] as $id => $_row) $sumNode((int)$id);
    return $agg;
}

function fmt_counts(array $c): string
{
    return "total {$c['total']} (used {$c['used']}, res {$c['reserved']}, free {$c['free']})";
}

/* ---- IPv4 unassigned (assignable only) ---- */

function ipv4_assignable_count(int $prefix): int
{
    if ($prefix >= 32) return 1;
    if ($prefix === 31) return 2;

    $hostBits = 32 - $prefix;
    $total = ($hostBits === 32) ? 4294967296 : (1 << $hostBits);
    $assignable = $total - 2;
    return ($assignable > 0) ? (int)$assignable : 0;
}

function ipv4_broadcast_bin(string $netBin, int $prefix): string
{
    $hostBits = 32 - $prefix;
    if ($hostBits <= 0) return $netBin;

    $n = unpack('N', $netBin)[1];
    $hostMask = ($hostBits === 32) ? 0xFFFFFFFF : ((1 << $hostBits) - 1);
    $b = ($n | $hostMask) & 0xFFFFFFFF;

    return pack('N', $b);
}

/**
 * Returns IPv4-only unassigned summary:
 * [subnet_id => ['assignable_total'=>int,'assigned_assignable'=>int,'unassigned_assignable'=>int]]
 */
function ipv4_unassigned_summary(PDO $db): array
{
    $st = $db->prepare("SELECT id, prefix, network_bin FROM subnets WHERE ip_version = 4");
    $st->execute();
    $subs = $st->fetchAll();
    if (!$subs) return [];

    $st = $db->prepare("
        SELECT a.subnet_id, a.ip_bin
        FROM addresses a
        JOIN subnets s ON s.id = a.subnet_id
        WHERE s.ip_version = 4
    ");
    $st->execute();
    $addrRows = $st->fetchAll();

    $ipsBySubnet = [];
    foreach ($addrRows as $r) {
        $sid = (int)$r['subnet_id'];
        $ipsBySubnet[$sid] ??= [];
        $ipsBySubnet[$sid][] = $r['ip_bin'];
    }

    $out = [];
    foreach ($subs as $s) {
        $sid = (int)$s['id'];
        $prefix = (int)$s['prefix'];
        $netBin = $s['network_bin'];

        $assignableTotal = ipv4_assignable_count($prefix);
        $ips = $ipsBySubnet[$sid] ?? [];

        if ($prefix <= 30) {
            $bcast = ipv4_broadcast_bin($netBin, $prefix);
            $assignedAssignable = 0;
            foreach ($ips as $ipb) {
                if (hash_equals($ipb, $netBin) || hash_equals($ipb, $bcast)) continue;
                $assignedAssignable++;
            }
        } else {
            $assignedAssignable = count($ips);
        }

        $unassigned = $assignableTotal - $assignedAssignable;
        if ($unassigned < 0) $unassigned = 0;

        $out[$sid] = [
            'assignable_total' => (int)$assignableTotal,
            'assigned_assignable' => (int)$assignedAssignable,
            'unassigned_assignable' => (int)$unassigned,
        ];
    }

    return $out;
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
        if (($role ?? '') === 'admin') echo "<a href='users.php'>Users</a>";
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
