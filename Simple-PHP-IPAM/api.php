<?php
declare(strict_types=1);

/**
 * Simple-PHP-IPAM — Read-only REST API
 *
 * Authentication: pass your API key via the Authorization header
 *   Authorization: Bearer <key>
 * or as a query parameter (less secure, avoid in logs):
 *   ?api_key=<key>
 *
 * Resources:
 *   GET api.php?resource=subnets            — list all subnets
 *   GET api.php?resource=subnets&id=N       — single subnet
 *   GET api.php?resource=addresses          — list addresses (paginated)
 *     optional: &subnet_id=N  &status=used|reserved|free
 *     optional: &page=N  &limit=N (max 500, default 100)
 *   GET api.php?resource=sites              — list all sites
 *   GET api.php?resource=history&address_id=N — address change history (paginated)
 *     optional: &page=N  &limit=N (max 200, default 50)
 */

// No session; stateless API request
$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$db = ipam_db((string)$config['db_path']);
ipam_db_init($db);

// ---- API key authentication ----

$rawKey = '';
$authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
    $rawKey = $m[1];
} elseif (!empty($_GET['api_key'])) {
    $rawKey = (string)$_GET['api_key'];
}

if ($rawKey === '') {
    http_response_code(401);
    echo json_encode(['error' => 'API key required. Pass via Authorization: Bearer <key> header.']);
    exit;
}

$keyHash = hash('sha256', $rawKey);
$st = $db->prepare("SELECT id, name FROM api_keys WHERE key_hash = :h AND is_active = 1");
$st->execute([':h' => $keyHash]);
$apiKey = $st->fetch();

if (!$apiKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or inactive API key.']);
    exit;
}

$db->prepare("UPDATE api_keys SET last_used_at = datetime('now') WHERE id = :id")
   ->execute([':id' => (int)$apiKey['id']]);

// ---- Route ----

$resource = strtolower(trim((string)($_GET['resource'] ?? '')));

match ($resource) {
    'subnets'   => api_subnets($db),
    'addresses' => api_addresses($db),
    'sites'     => api_sites($db),
    'history'   => api_history($db),
    default     => api_error(404, 'Unknown resource. Valid resources: subnets, addresses, sites, history'),
};

// ---- Helpers ----

function api_json(mixed $data): never
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(int $code, string $message): never
{
    http_response_code($code);
    api_json(['error' => $message]);
}

// ---- Resource handlers ----

function api_subnets(PDO $db): never
{
    $baseSql = "SELECT s.id, s.cidr, s.ip_version, s.network, s.prefix,
                       s.description, s.created_at, si.name AS site
                FROM subnets s
                LEFT JOIN sites si ON si.id = s.site_id";

    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $st = $db->prepare($baseSql . " WHERE s.id = :id");
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        if (!$row) api_error(404, 'Subnet not found.');
        api_json(fmt_subnet($row));
    }

    $st = $db->query($baseSql . " ORDER BY s.ip_version, s.network_bin");
    api_json(['subnets' => array_map('fmt_subnet', $st->fetchAll())]);
}

function fmt_subnet(array $r): array
{
    return [
        'id'          => (int)$r['id'],
        'cidr'        => $r['cidr'],
        'ip_version'  => (int)$r['ip_version'],
        'network'     => $r['network'],
        'prefix'      => (int)$r['prefix'],
        'description' => (string)$r['description'],
        'site'        => $r['site'],
        'created_at'  => $r['created_at'],
    ];
}

function api_addresses(PDO $db): never
{
    $where  = [];
    $params = [];

    if (isset($_GET['subnet_id'])) {
        $where[] = 'a.subnet_id = :sid';
        $params[':sid'] = (int)$_GET['subnet_id'];
    }
    if (isset($_GET['status'])) {
        $s = strtolower(trim((string)$_GET['status']));
        if (in_array($s, ['used', 'reserved', 'free'], true)) {
            $where[]         = 'a.status = :st';
            $params[':st']   = $s;
        }
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $page   = max(1, (int)($_GET['page']  ?? 1));
    $limit  = max(1, min(500, (int)($_GET['limit'] ?? 100)));
    $offset = ($page - 1) * $limit;

    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM addresses a $whereSql");
    $cntSt->execute($params);
    $total = (int)$cntSt->fetch()['c'];

    $sql = "SELECT a.id, a.subnet_id, a.ip, a.hostname, a.owner,
                   a.status, a.note, a.created_at
            FROM addresses a $whereSql
            ORDER BY a.ip_bin
            LIMIT :lim OFFSET :off";

    $st = $db->prepare($sql);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->execute();

    $rows = array_map(function(array $r): array {
        return [
            'id'          => (int)$r['id'],
            'subnet_id'   => (int)$r['subnet_id'],
            'ip'          => $r['ip'],
            'hostname'    => (string)$r['hostname'],
            'owner'       => (string)$r['owner'],
            'status'      => $r['status'],
            'note'        => (string)$r['note'],
            'created_at'  => $r['created_at'],
        ];
    }, $st->fetchAll());

    api_json([
        'total'     => $total,
        'page'      => $page,
        'limit'     => $limit,
        'addresses' => $rows,
    ]);
}

function api_sites(PDO $db): never
{
    $st = $db->query("SELECT id, name, description, created_at FROM sites ORDER BY name");
    $rows = array_map(function(array $r): array {
        return [
            'id'          => (int)$r['id'],
            'name'        => $r['name'],
            'description' => (string)$r['description'],
            'created_at'  => $r['created_at'],
        ];
    }, $st->fetchAll());
    api_json(['sites' => $rows]);
}

function api_history(PDO $db): never
{
    if (!isset($_GET['address_id'])) {
        api_error(400, 'address_id is required.');
    }
    $addressId = (int)$_GET['address_id'];

    // Verify the address exists
    $st = $db->prepare("SELECT id, ip FROM addresses WHERE id = :id");
    $st->execute([':id' => $addressId]);
    $addr = $st->fetch();
    if (!$addr) {
        api_error(404, 'Address not found.');
    }

    $page   = max(1, (int)($_GET['page']  ?? 1));
    $limit  = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM address_history WHERE address_id = :id");
    $cntSt->execute([':id' => $addressId]);
    $total = (int)$cntSt->fetch()['c'];

    $st = $db->prepare(
        "SELECT id, action, before_json, after_json, username, created_at
         FROM address_history
         WHERE address_id = :id
         ORDER BY id DESC
         LIMIT :lim OFFSET :off"
    );
    $st->bindValue(':id',  $addressId, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit,     PDO::PARAM_INT);
    $st->bindValue(':off', $offset,    PDO::PARAM_INT);
    $st->execute();

    $rows = array_map(function(array $r): array {
        $before = $r['before_json'] !== null ? json_decode((string)$r['before_json'], true) : null;
        $after  = $r['after_json']  !== null ? json_decode((string)$r['after_json'],  true) : null;
        return [
            'id'         => (int)$r['id'],
            'action'     => $r['action'],
            'before'     => $before,
            'after'      => $after,
            'username'   => (string)$r['username'],
            'created_at' => $r['created_at'],
        ];
    }, $st->fetchAll());

    api_json([
        'address_id' => $addressId,
        'ip'         => $addr['ip'],
        'total'      => $total,
        'page'       => $page,
        'limit'      => $limit,
        'history'    => $rows,
    ]);
}
