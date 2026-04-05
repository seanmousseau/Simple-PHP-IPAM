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
 *   GET api.php?resource=subnets            — list subnets (paginated)
 *     optional: &page=N  &limit=N (max 1000, default 200)
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
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

$db = ipam_db((string)$config['db_path']);
ipam_db_init($db);

// ---- API key authentication ----

$apiMaxAttempts   = (int)($config['api_max_attempts']   ?? 20);
$apiLockoutSeconds = (int)($config['api_lockout_seconds'] ?? 300);
if (!empty($config['proxy_trust']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $xffParts  = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
    $xffFirst  = $xffParts[0] ?? '';
    $clientIp  = (filter_var($xffFirst, FILTER_VALIDATE_IP) !== false)
                 ? $xffFirst
                 : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
} else {
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
}

// Rate-limit by IP before even reading the key
if (login_rate_limited($db, $clientIp, $apiMaxAttempts, $apiLockoutSeconds)) {
    http_response_code(429);
    header('Retry-After: ' . $apiLockoutSeconds);
    header('X-RateLimit-Limit: ' . $apiMaxAttempts);
    echo json_encode(['error' => 'Too many failed API key attempts. Try again later.']);
    exit;
}

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
    record_login_failure($db, $clientIp);
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or inactive API key.']);
    exit;
}

// Successful auth — clear any accumulated failures for this IP
clear_login_failures($db, $clientIp);

$db->prepare("UPDATE api_keys SET last_used_at = datetime('now') WHERE id = :id")
   ->execute([':id' => (int)$apiKey['id']]);

// ---- Route ----

$resource = strtolower(trim((string)($_GET['resource'] ?? '')));
$method   = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Parse JSON body for write requests
$body = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $raw = (string)file_get_contents('php://input');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            api_error(400, 'Invalid JSON body.');
        }
        $body = $decoded;
    }
}

match ($resource) {
    'subnets' => match ($method) {
        'GET'    => api_subnets($db),
        'POST'   => api_subnets_create($db, $apiKey, $body),
        'PUT'    => api_subnets_update($db, $apiKey, (int)($_GET['id'] ?? 0), $body),
        'DELETE' => api_subnets_delete($db, $apiKey, (int)($_GET['id'] ?? 0)),
        default  => api_error(405, 'Method not allowed.'),
    },
    'addresses' => match ($method) {
        'GET'    => api_addresses($db),
        'POST'   => api_addresses_create($db, $apiKey, $body),
        'PUT'    => api_addresses_update($db, $apiKey, (int)($_GET['id'] ?? 0), $body),
        'DELETE' => api_addresses_delete($db, $apiKey, (int)($_GET['id'] ?? 0)),
        default  => api_error(405, 'Method not allowed.'),
    },
    'sites'   => $method === 'GET' ? api_sites($db)   : api_error(405, 'Method not allowed.'),
    'history' => $method === 'GET' ? api_history($db) : api_error(405, 'Method not allowed.'),
    default   => api_error(404, 'Unknown resource. Valid resources: subnets, addresses, sites, history'),
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
                       s.description, s.vlan_id, s.created_at, si.name AS site
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

    $page   = max(1, (int)($_GET['page']  ?? 1));
    $limit  = max(1, min(1000, (int)($_GET['limit'] ?? 200)));
    $offset = ($page - 1) * $limit;

    $cntSt = $db->query("SELECT COUNT(*) AS c FROM subnets");
    $total  = (int)$cntSt->fetch()['c'];

    $st = $db->prepare($baseSql . " ORDER BY s.ip_version, s.network_bin LIMIT :lim OFFSET :off");
    $st->bindValue(':lim', $limit,  PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    api_json([
        'total'   => $total,
        'page'    => $page,
        'limit'   => $limit,
        'subnets' => array_map('fmt_subnet', $st->fetchAll()),
    ]);
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
        'vlan_id'     => $r['vlan_id'] !== null ? (int)$r['vlan_id'] : null,
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
                   a.status, a.note, a.grp, a.created_at
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
            'group'       => (string)$r['grp'],
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

    // Fetch current address if it still exists (deleted addresses may still have history)
    $addrSt = $db->prepare("SELECT ip FROM addresses WHERE id = :id");
    $addrSt->execute([':id' => $addressId]);
    $addr = $addrSt->fetch();

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
        'ip'         => $addr ? (string)$addr['ip'] : null,
        'total'      => $total,
        'page'       => $page,
        'limit'      => $limit,
        'history'    => $rows,
    ]);
}

// ---- Write: Addresses ----

function api_addresses_create(PDO $db, array $apiKey, array $body): never
{
    $subnetId = isset($body['subnet_id']) ? (int)$body['subnet_id'] : 0;
    $ip       = trim((string)($body['ip'] ?? ''));
    $hostname = trim((string)($body['hostname'] ?? ''));
    $owner    = trim((string)($body['owner']    ?? ''));
    $note     = trim((string)($body['note']     ?? ''));
    $grp      = trim((string)($body['group']    ?? ''));
    $status   = strtolower(trim((string)($body['status'] ?? 'used')));

    if ($subnetId <= 0)  api_error(400, 'subnet_id is required.');
    if ($ip === '')      api_error(400, 'ip is required.');
    if (!in_array($status, ['used', 'reserved', 'free'], true)) {
        api_error(400, 'status must be used, reserved, or free.');
    }

    $subnetSt = $db->prepare("SELECT id, cidr, ip_version FROM subnets WHERE id = :id");
    $subnetSt->execute([':id' => $subnetId]);
    $subnet = $subnetSt->fetch();
    if (!$subnet) api_error(404, 'Subnet not found.');

    $n = normalize_ip($ip);
    if (!$n) api_error(400, 'Invalid IP address.');
    if ((int)$n['version'] !== (int)$subnet['ip_version']) {
        api_error(400, 'IP version does not match subnet.');
    }

    $p = parse_cidr((string)$subnet['cidr']);
    if (!ip_in_cidr($n['ip'], (string)$p['network'], (int)$p['prefix'])) {
        api_error(400, 'IP is not within the specified subnet (' . $subnet['cidr'] . ').');
    }

    // Check for duplicate
    $dupSt = $db->prepare("SELECT id FROM addresses WHERE subnet_id = :sid AND ip_bin = :b");
    $dupSt->execute([':sid' => $subnetId, ':b' => $n['bin']]);
    if ($dupSt->fetch()) api_error(409, 'An address record for this IP already exists in the subnet.');

    $db->prepare(
        "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, grp, status)
         VALUES (:sid, :ip, :b, :hn, :ow, :nt, :grp, :st)"
    )->execute([
        ':sid' => $subnetId, ':ip' => $n['ip'], ':b' => $n['bin'],
        ':hn'  => $hostname,  ':ow' => $owner,   ':nt' => $note,
        ':grp' => $grp,       ':st' => $status,
    ]);
    $newId = (int)$db->lastInsertId();

    api_audit($db, $apiKey, 'address.create', 'address', $newId,
        "ip={$n['ip']} subnet_id={$subnetId} status={$status}");

    api_history_log_address($db, $apiKey, 'create', $subnetId, $n['ip'], $newId, null, [
        'hostname' => $hostname, 'owner' => $owner, 'note' => $note,
        'grp' => $grp, 'status' => $status,
    ]);

    http_response_code(201);
    api_json(['id' => $newId]);
}

function api_addresses_update(PDO $db, array $apiKey, int $id, array $body): never
{
    if ($id <= 0) api_error(400, 'id is required as a query parameter.');

    $st = $db->prepare("SELECT id, ip, subnet_id, hostname, owner, note, grp, status FROM addresses WHERE id = :id");
    $st->execute([':id' => $id]);
    $addr = $st->fetch();
    if (!$addr) api_error(404, 'Address not found.');

    $hostname = array_key_exists('hostname', $body) ? trim((string)$body['hostname']) : (string)$addr['hostname'];
    $owner    = array_key_exists('owner',    $body) ? trim((string)$body['owner'])    : (string)$addr['owner'];
    $note     = array_key_exists('note',     $body) ? trim((string)$body['note'])     : (string)$addr['note'];
    $grp      = array_key_exists('group',    $body) ? trim((string)$body['group'])    : (string)$addr['grp'];
    $status   = array_key_exists('status',   $body) ? strtolower(trim((string)$body['status'])) : (string)$addr['status'];

    if (!in_array($status, ['used', 'reserved', 'free'], true)) {
        api_error(400, 'status must be used, reserved, or free.');
    }

    $db->prepare(
        "UPDATE addresses SET hostname = :hn, owner = :ow, note = :nt, grp = :grp, status = :st WHERE id = :id"
    )->execute([':hn' => $hostname, ':ow' => $owner, ':nt' => $note, ':grp' => $grp, ':st' => $status, ':id' => $id]);

    api_audit($db, $apiKey, 'address.update', 'address', $id,
        "ip={$addr['ip']} status={$status}");

    api_history_log_address($db, $apiKey, 'update', (int)$addr['subnet_id'], (string)$addr['ip'], $id,
        ['hostname' => (string)$addr['hostname'], 'owner' => (string)$addr['owner'],
         'note' => (string)$addr['note'], 'grp' => (string)$addr['grp'], 'status' => (string)$addr['status']],
        ['hostname' => $hostname, 'owner' => $owner, 'note' => $note,
         'grp' => $grp, 'status' => $status]
    );

    api_json(['id' => $id]);
}

function api_addresses_delete(PDO $db, array $apiKey, int $id): never
{
    if ($id <= 0) api_error(400, 'id is required as a query parameter.');

    $st = $db->prepare("SELECT id, ip, subnet_id, hostname, owner, note, grp, status FROM addresses WHERE id = :id");
    $st->execute([':id' => $id]);
    $addr = $st->fetch();
    if (!$addr) api_error(404, 'Address not found.');

    $db->prepare("DELETE FROM addresses WHERE id = :id")->execute([':id' => $id]);

    api_audit($db, $apiKey, 'address.delete', 'address', $id, "ip={$addr['ip']}");

    api_history_log_address($db, $apiKey, 'delete', (int)$addr['subnet_id'], (string)$addr['ip'], $id,
        ['hostname' => (string)$addr['hostname'], 'owner' => (string)$addr['owner'],
         'note' => (string)$addr['note'], 'grp' => (string)$addr['grp'], 'status' => (string)$addr['status']],
        null
    );

    http_response_code(204);
    exit;
}

// ---- Write: Subnets ----

function api_subnets_create(PDO $db, array $apiKey, array $body): never
{
    $cidr        = trim((string)($body['cidr']        ?? ''));
    $description = trim((string)($body['description'] ?? ''));
    $siteId      = isset($body['site_id']) && $body['site_id'] !== null ? (int)$body['site_id'] : null;
    $vlanId      = isset($body['vlan_id']) && $body['vlan_id'] !== null ? (int)$body['vlan_id'] : null;

    if ($cidr === '') api_error(400, 'cidr is required.');

    $p = parse_cidr($cidr);
    if (!$p) api_error(400, 'Invalid CIDR notation.');

    if ($vlanId !== null && ($vlanId < 1 || $vlanId > 4094)) {
        api_error(400, 'vlan_id must be between 1 and 4094.');
    }

    if ($siteId !== null) {
        $siteSt = $db->prepare("SELECT id FROM sites WHERE id = :id");
        $siteSt->execute([':id' => $siteId]);
        if (!$siteSt->fetch()) api_error(404, 'Site not found.');
    }

    // Check duplicate CIDR
    $normalized = $p['network'] . '/' . $p['prefix'];
    $dupSt = $db->prepare("SELECT id FROM subnets WHERE cidr = :cidr");
    $dupSt->execute([':cidr' => $normalized]);
    if ($dupSt->fetch()) api_error(409, 'A subnet with this CIDR already exists.');

    // Inherit site from tightest parent if one exists
    $inheritedSiteId = find_parent_site_id($db, $normalized);
    if ($inheritedSiteId !== null) $siteId = $inheritedSiteId;

    $db->prepare(
        "INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, site_id, vlan_id)
         VALUES (:cidr, :ver, :net, :nbin, :pfx, :desc, :sid, :vlan)"
    )->execute([
        ':cidr' => $p['network'] . '/' . $p['prefix'],
        ':ver'  => $p['version'],
        ':net'  => $p['network'],
        ':nbin' => $p['net_bin'],
        ':pfx'  => $p['prefix'],
        ':desc' => $description,
        ':sid'  => $siteId,
        ':vlan' => $vlanId,
    ]);
    $newId = (int)$db->lastInsertId();

    api_audit($db, $apiKey, 'subnet.create', 'subnet', $newId,
        "cidr={$p['network']}/{$p['prefix']}");

    // Non-blocking overlap warnings
    $overlaps = detect_subnet_overlaps($db, $normalized);
    $warnings = [];
    if (!empty($overlaps['parents']) || !empty($overlaps['children'])) {
        $warnings[] = subnet_overlap_warning_text($overlaps);
    }

    http_response_code(201);
    $resp = ['id' => $newId];
    if ($warnings) $resp['warnings'] = $warnings;
    api_json($resp);
}

function api_subnets_update(PDO $db, array $apiKey, int $id, array $body): never
{
    if ($id <= 0) api_error(400, 'id is required as a query parameter.');

    $st = $db->prepare("SELECT id, cidr, description, site_id, vlan_id FROM subnets WHERE id = :id");
    $st->execute([':id' => $id]);
    $subnet = $st->fetch();
    if (!$subnet) api_error(404, 'Subnet not found.');

    $description = array_key_exists('description', $body) ? trim((string)$body['description']) : (string)$subnet['description'];
    $siteId = array_key_exists('site_id', $body)
        ? ($body['site_id'] !== null ? (int)$body['site_id'] : null)
        : ($subnet['site_id'] !== null ? (int)$subnet['site_id'] : null);
    $vlanId = array_key_exists('vlan_id', $body)
        ? ($body['vlan_id'] !== null ? (int)$body['vlan_id'] : null)
        : ($subnet['vlan_id'] !== null ? (int)$subnet['vlan_id'] : null);

    if ($vlanId !== null && ($vlanId < 1 || $vlanId > 4094)) {
        api_error(400, 'vlan_id must be between 1 and 4094.');
    }

    if ($siteId !== null) {
        $siteSt = $db->prepare("SELECT id FROM sites WHERE id = :id");
        $siteSt->execute([':id' => $siteId]);
        if (!$siteSt->fetch()) api_error(404, 'Site not found.');
    }

    $db->prepare(
        "UPDATE subnets SET description = :desc, site_id = :sid, vlan_id = :vlan WHERE id = :id"
    )->execute([':desc' => $description, ':sid' => $siteId, ':vlan' => $vlanId, ':id' => $id]);

    api_audit($db, $apiKey, 'subnet.update', 'subnet', $id, "cidr={$subnet['cidr']}");

    api_json(['id' => $id]);
}

function api_subnets_delete(PDO $db, array $apiKey, int $id): never
{
    if ($id <= 0) api_error(400, 'id is required as a query parameter.');

    $st = $db->prepare("SELECT id, cidr FROM subnets WHERE id = :id");
    $st->execute([':id' => $id]);
    $subnet = $st->fetch();
    if (!$subnet) api_error(404, 'Subnet not found.');

    // Block deletion if subnet has addresses
    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM addresses WHERE subnet_id = :id");
    $cntSt->execute([':id' => $id]);
    $count = (int)$cntSt->fetch()['c'];
    if ($count > 0) {
        api_error(409, "Cannot delete subnet with {$count} address record(s). Delete addresses first.");
    }

    $db->prepare("DELETE FROM subnets WHERE id = :id")->execute([':id' => $id]);

    api_audit($db, $apiKey, 'subnet.delete', 'subnet', $id, "cidr={$subnet['cidr']}");

    http_response_code(204);
    exit;
}

// ---- Audit helper for API writes ----

function api_history_log_address(PDO $db, array $apiKey, string $action, int $subnetId, string $ip, ?int $addressId, ?array $before, ?array $after): void
{
    $st = $db->prepare("
        INSERT INTO address_history
          (address_id, subnet_id, ip, action, user_id, username, client_ip, user_agent, before_json, after_json)
        VALUES
          (:aid, :sid, :ip, :ac, NULL, :un, :cip, :ua, :bj, :aj)
    ");
    $st->execute([
        ':aid' => $addressId,
        ':sid' => $subnetId,
        ':ip'  => $ip,
        ':ac'  => $action,
        ':un'  => 'api:' . $apiKey['name'],
        ':cip' => client_ip(),
        ':ua'  => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ':bj'  => $before ? json_encode($before, JSON_UNESCAPED_SLASHES) : null,
        ':aj'  => $after ? json_encode($after, JSON_UNESCAPED_SLASHES) : null,
    ]);
}

function api_audit(PDO $db, array $apiKey, string $action, string $entityType, int $entityId, string $details): void
{
    $username = 'api:' . $apiKey['name'];
    $db->prepare(
        "INSERT INTO audit_log (action, entity_type, entity_id, user_id, username, ip, details)
         VALUES (:act, :et, :eid, NULL, :un, :ip, :det)"
    )->execute([
        ':act' => $action,
        ':et'  => $entityType,
        ':eid' => $entityId,
        ':un'  => $username,
        ':ip'  => client_ip(),
        ':det' => $details,
    ]);
}
