<?php
declare(strict_types=1);

/**
 * Simple-PHP-IPAM — REST API
 *
 * Authentication: pass your API key via the Authorization header
 *   Authorization: Bearer <key>
 *
 * Resources (read):
 *   GET  ?resource=subnets             — list subnets (paginated, filterable by ip_version, vlan_id)
 *   GET  ?resource=addresses           — list addresses (paginated, filterable)
 *   GET  ?resource=sites               — list all sites
 *   GET  ?resource=history&address_id= — address change history (paginated)
 *   GET  ?resource=search&q=           — search addresses by IP/hostname/owner/note
 *   GET  ?resource=audit               — audit log (paginated, filterable by action/date)
 *
 * Resources (write):
 *   POST/PUT/DELETE ?resource=subnets  — create/update/delete subnets
 *   POST/PUT/DELETE ?resource=addresses — create/update/delete addresses
 *   POST/PUT/DELETE ?resource=sites    — create/update/delete sites
 */

// No session; stateless API request
$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

$db = ipam_db(to_str($config['db_path']));
ipam_db_init($db);

// ---- API key authentication ----

$apiMaxAttempts   = to_int($config['api_max_attempts']   ?? 20);
$apiLockoutSeconds = to_int($config['api_lockout_seconds'] ?? 300);
if (!empty($config['proxy_trust']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $xffParts  = array_map('trim', explode(',', to_str($_SERVER['HTTP_X_FORWARDED_FOR'])));
    $xffFirst  = $xffParts[0];
    $clientIp  = (filter_var($xffFirst, FILTER_VALIDATE_IP) !== false)
                 ? $xffFirst
                 : to_str($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
} else {
    $clientIp = to_str($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
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
$authHeader = to_str($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
    $rawKey = $m[1];
} elseif (!empty($_GET['api_key'])) {
    $rawKey = to_str($_GET['api_key']);
    header('Deprecation: true');
    header('X-Deprecation-Reason: API key via query parameter is deprecated. Use Authorization: Bearer header.');
}

if ($rawKey === '') {
    http_response_code(401);
    echo json_encode(['error' => 'API key required. Pass via Authorization: Bearer <key> header.']);
    exit;
}

$keyHash = hash('sha256', $rawKey);
$st = $db->prepare("SELECT id, name, is_readonly FROM api_keys WHERE key_hash = :h AND is_active = 1");
$st->execute([':h' => $keyHash]);
/** @var array<string, mixed>|false $apiKey */
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
   ->execute([':id' => to_int($apiKey['id'])]);

// ---- Route ----

$resource  = strtolower(trim(to_str($_GET['resource'] ?? '')));
$method    = strtoupper(to_str($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$isBulk    = !empty($_GET['bulk']);
$bulkLimit = isset($config['api_bulk_limit']) ? max(1, min(500, to_int($config['api_bulk_limit']))) : 500;

if (to_int($apiKey['is_readonly'] ?? 0) === 1 && in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    api_error(403, 'This API key is read-only.');
}

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
        'POST'   => $isBulk ? api_subnets_bulk_create($db, $apiKey, $body, $bulkLimit) : api_subnets_create($db, $apiKey, $body),
        'PUT'    => api_subnets_update($db, $apiKey, to_int($_GET['id'] ?? 0), $body),
        'DELETE' => api_subnets_delete($db, $apiKey, to_int($_GET['id'] ?? 0)),
        default  => api_error(405, 'Method not allowed.'),
    },
    'addresses' => match ($method) {
        'GET'    => api_addresses($db),
        'POST'   => $isBulk ? api_addresses_bulk_create($db, $apiKey, $body, $bulkLimit) : api_addresses_create($db, $apiKey, $body),
        'PUT'    => api_addresses_update($db, $apiKey, to_int($_GET['id'] ?? 0), $body),
        'DELETE' => api_addresses_delete($db, $apiKey, to_int($_GET['id'] ?? 0)),
        default  => api_error(405, 'Method not allowed.'),
    },
    'sites' => match ($method) {
        'GET'    => api_sites($db),
        'POST'   => api_sites_create($db, $apiKey, $body),
        'PUT'    => api_sites_update($db, $apiKey, to_int($_GET['id'] ?? 0), $body),
        'DELETE' => api_sites_delete($db, $apiKey, to_int($_GET['id'] ?? 0)),
        default  => api_error(405, 'Method not allowed.'),
    },
    'history'    => $method === 'GET' ? api_history($db)      : api_error(405, 'Method not allowed.'),
    'vlans'      => $method === 'GET' ? api_vlans($db)         : api_error(405, 'Method not allowed.'),
    'search'     => $method === 'GET' ? api_search($db)       : api_error(405, 'Method not allowed.'),
    'audit'      => $method === 'GET' ? api_audit_log($db)    : api_error(405, 'Method not allowed.'),
    'unassigned' => $method === 'GET' ? api_unassigned($db)   : api_error(405, 'Method not allowed.'),
    default      => api_error(404, 'Unknown resource. Valid: subnets, addresses, sites, vlans, history, search, audit, unassigned'),
};

// ---- Helpers ----

/**
 * Batch-fetch tags for a list of entity IDs (address or subnet).
 * Returns a map of entity_id → list of tag arrays [{id, name, colour}].
 *
 * @param array<int> $ids
 * @return array<int, list<array{id: int, name: string, colour: string}>>
 */
function api_fetch_tags_for_ids(PDO $db, string $type, array $ids): array
{
    if (!$ids) return [];

    if ($type === 'address') {
        $join  = 'JOIN address_tags j ON j.tag_id = t.id';
        $col   = 'j.address_id AS entity_id';
        $where = 'j.address_id';
    } else {
        $join  = 'JOIN subnet_tags j ON j.tag_id = t.id';
        $col   = 'j.subnet_id AS entity_id';
        $where = 'j.subnet_id';
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT $col, t.id, t.name, t.colour FROM tags t $join WHERE $where IN ($placeholders) ORDER BY t.name");
    $st->execute($ids);

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $eid = to_int($r['entity_id']);
        $out[$eid][] = ['id' => to_int($r['id']), 'name' => to_str($r['name']), 'colour' => to_str($r['colour'])];
    }
    return $out;
}

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
    $withCounts = isset($_GET['counts']) && $_GET['counts'] !== '0';

    $countsSelect = $withCounts
        ? ", COALESCE(ac.used_count, 0) AS used_count,
              COALESCE(ac.reserved_count, 0) AS reserved_count,
              COALESCE(ac.free_count, 0) AS free_count"
        : '';

    $countsJoin = $withCounts
        ? "LEFT JOIN (
               SELECT subnet_id,
                      SUM(status = 'used')     AS used_count,
                      SUM(status = 'reserved') AS reserved_count,
                      SUM(status = 'free')     AS free_count
               FROM addresses
               GROUP BY subnet_id
           ) ac ON ac.subnet_id = s.id"
        : '';

    $baseSql = "SELECT s.id, s.cidr, s.ip_version, s.network, s.prefix,
                       s.description, s.vlan_id, s.vlan_fk, s.site_id, s.created_at,
                       si.name AS site, v.vlan_id AS vlan_number, v.name AS vlan_name$countsSelect
                FROM subnets s
                LEFT JOIN sites si ON si.id = s.site_id
                LEFT JOIN vlans v ON v.id = s.vlan_fk
                $countsJoin";

    if (isset($_GET['id'])) {
        $id = to_int($_GET['id']);
        $st = $db->prepare($baseSql . " WHERE s.id = :id");
        $st->execute([':id' => $id]);
        /** @var array<string, mixed>|false $row */
        $row = $st->fetch();
        if (!$row) api_error(404, 'Subnet not found.');
        $subnetTags = api_fetch_tags_for_ids($db, 'subnet', [$id]);
        api_json(fmt_subnet($row, $withCounts, $subnetTags[$id] ?? []));
    }

    $page   = max(1, to_int($_GET['page']  ?? 1));
    $limit  = max(1, min(1000, to_int($_GET['limit'] ?? 200)));
    $offset = ($page - 1) * $limit;

    $where  = [];
    $params = [];

    if (isset($_GET['ip_version'])) {
        $v = to_int($_GET['ip_version']);
        if (!in_array($v, [4, 6], true)) api_error(400, 'ip_version must be 4 or 6.');
        $where[]          = 's.ip_version = :ipver';
        $params[':ipver'] = $v;
    }

    if (isset($_GET['vlan_id'])) {
        $v = to_int($_GET['vlan_id']);
        if ($v < 1 || $v > 4094) api_error(400, 'vlan_id must be between 1 and 4094.');
        // Filter by VLAN numeric ID via the FK join, falling back to legacy integer field
        $where[]         = '(v.vlan_id = :vlan OR (s.vlan_fk IS NULL AND s.vlan_id = :vlan2))';
        $params[':vlan']  = $v;
        $params[':vlan2'] = $v;
    }

    if (isset($_GET['site_id'])) {
        $v = to_int($_GET['site_id']);
        $where[]          = 's.site_id = :site_id';
        $params[':site_id'] = $v;
    }

    $tagJoin = '';
    if (isset($_GET['tag'])) {
        $tagName = trim(to_str($_GET['tag']));
        if ($tagName !== '') {
            $tagJoin = 'JOIN subnet_tags stf ON stf.subnet_id = s.id JOIN tags tf ON tf.id = stf.tag_id';
            $where[]           = 'tf.name = :tag_name';
            $params[':tag_name'] = $tagName;
        }
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM subnets s $tagJoin $whereSql");
    $cntSt->execute($params);
    /** @var array<string, mixed>|false $cntRow */

    $cntRow = $cntSt->fetch();

    $total = is_array($cntRow) ? to_int($cntRow['c']) : 0;

    $st = $db->prepare($baseSql . " $tagJoin $whereSql ORDER BY s.ip_version, s.network_bin LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v, PDO::PARAM_INT);
    }
    // tag_name is a string param — rebind as string
    if (isset($params[':tag_name'])) {
        $st->bindValue(':tag_name', $params[':tag_name'], PDO::PARAM_STR);
    }
    $st->bindValue(':lim', $limit,  PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    $rawSubnets = $st->fetchAll();

    // Batch-load tags for all returned subnets
    $subnetIds    = array_map(fn($r) => to_int($r['id']), $rawSubnets);
    $tagsBySubnet = api_fetch_tags_for_ids($db, 'subnet', $subnetIds);

    api_json([
        'total'   => $total,
        'page'    => $page,
        'limit'   => $limit,
        'subnets' => array_map(fn($r) => fmt_subnet($r, $withCounts, $tagsBySubnet[to_int($r['id'])] ?? []), $rawSubnets),
    ]);
}

/**
 * @param array<string, mixed> $r
 * @param list<array{id: int, name: string, colour: string}> $tags
 * @return array<string, mixed>
 */
function fmt_subnet(array $r, bool $withCounts = false, array $tags = []): array
{
    $vlanFk = $r['vlan_fk'] !== null ? to_int($r['vlan_fk']) : null;
    $out = [
        'id'          => to_int($r['id']),
        'cidr'        => $r['cidr'],
        'ip_version'  => to_int($r['ip_version']),
        'network'     => $r['network'],
        'prefix'      => to_int($r['prefix']),
        'description' => to_str($r['description']),
        'vlan_id'     => $r['vlan_id'] !== null ? to_int($r['vlan_id']) : null,
        'vlan_fk'     => $vlanFk,
        'vlan_name'   => $vlanFk !== null ? to_str($r['vlan_name'] ?? '') : null,
        'site_id'     => $r['site_id'] !== null ? to_int($r['site_id']) : null,
        'site'        => $r['site'],
        'tags'        => $tags,
        'created_at'  => $r['created_at'],
    ];
    if ($withCounts) {
        $used     = to_int($r['used_count']);
        $reserved = to_int($r['reserved_count']);
        $free     = to_int($r['free_count']);
        $prefix   = to_int($r['prefix']);
        $ipVer    = to_int($r['ip_version']);

        if ($ipVer === 4) {
            $rawHosts = to_int(2 ** (32 - $prefix));
            $capacity = $prefix >= 31 ? $rawHosts : max(1, $rawHosts - 2);
            $utilPct  = round(($used + $reserved) / $capacity * 100, 2);
        } else {
            $utilPct = null; // IPv6 — subnet too large for meaningful percentage
        }

        $out['address_counts'] = [
            'used'            => $used,
            'reserved'        => $reserved,
            'free'            => $free,
            'total'           => $used + $reserved + $free,
            'utilization_pct' => $utilPct,
        ];
    }
    return $out;
}

function api_addresses(PDO $db): never
{
    $where  = [];
    $params = [];
    $joinSite = false;
    $joinTag  = false;

    if (isset($_GET['subnet_id'])) {
        $where[] = 'a.subnet_id = :sid';
        $params[':sid'] = to_int($_GET['subnet_id']);
    }
    if (isset($_GET['status'])) {
        $s = strtolower(trim(to_str($_GET['status'])));
        if (in_array($s, ['used', 'reserved', 'free'], true)) {
            $where[]         = 'a.status = :st';
            $params[':st']   = $s;
        }
    }
    if (isset($_GET['site_id'])) {
        $joinSite = true;
        $where[]            = 's.site_id = :site_id';
        $params[':site_id'] = to_int($_GET['site_id']);
    }
    if (isset($_GET['tag'])) {
        $tagName = trim(to_str($_GET['tag']));
        if ($tagName !== '') {
            $joinTag = true;
            $where[]          = 'tf.name = :tag_name';
            $params[':tag_name'] = $tagName;
        }
    }

    $joins   = ($joinSite ? ' JOIN subnets s ON s.id = a.subnet_id' : '');
    $joins  .= ($joinTag  ? ' JOIN address_tags atf ON atf.address_id = a.id JOIN tags tf ON tf.id = atf.tag_id' : '');
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $page   = max(1, to_int($_GET['page']  ?? 1));
    $limit  = max(1, min(500, to_int($_GET['limit'] ?? 100)));
    $offset = ($page - 1) * $limit;

    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM addresses a$joins $whereSql");
    $cntSt->execute($params);
    /** @var array<string, mixed>|false $cntRow */

    $cntRow = $cntSt->fetch();

    $total = is_array($cntRow) ? to_int($cntRow['c']) : 0;

    $sql = "SELECT a.id, a.subnet_id, a.ip, a.hostname, a.owner,
                   a.status, a.note, a.grp, a.mac, a.expires_at, a.created_at, a.updated_at
            FROM addresses a$joins $whereSql
            ORDER BY a.ip_bin
            LIMIT :lim OFFSET :off";

    $st = $db->prepare($sql);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->execute();
    $rawRows = $st->fetchAll();

    // Batch-load tags for all returned addresses
    $addrIds = array_map(fn($r) => to_int($r['id']), $rawRows);
    $tagsByAddr = api_fetch_tags_for_ids($db, 'address', $addrIds);

    $rows = array_map(function(array $r) use ($tagsByAddr): array {
        $id = to_int($r['id']);
        return [
            'id'          => $id,
            'subnet_id'   => to_int($r['subnet_id']),
            'ip'          => $r['ip'],
            'hostname'    => to_str($r['hostname']),
            'owner'       => to_str($r['owner']),
            'status'      => $r['status'],
            'note'        => to_str($r['note']),
            'group'       => to_str($r['grp']),
            'mac'         => to_str($r['mac']),
            'expires_at'  => isset($r['expires_at']) ? to_str($r['expires_at']) : null,
            'tags'        => $tagsByAddr[$id] ?? [],
            'created_at'  => $r['created_at'],
            'updated_at'  => $r['updated_at'],
        ];
    }, $rawRows);

    api_json([
        'total'     => $total,
        'page'      => $page,
        'limit'     => $limit,
        'addresses' => $rows,
    ]);
}

function api_sites(PDO $db): never
{
    if (isset($_GET['id'])) {
        $id = to_int($_GET['id']);
        $st = $db->prepare("SELECT s.id, s.name, s.description, s.parent_id, s.created_at, p.name AS parent_name FROM sites s LEFT JOIN sites p ON p.id = s.parent_id WHERE s.id = :id");
        $st->execute([':id' => $id]);
        /** @var array<string, mixed>|false $row */
        $row = $st->fetch();
        if (!$row) api_error(404, 'Site not found.');
        api_json(fmt_site($row));
    }

    $where  = [];
    $params = [];

    if (isset($_GET['parent_id'])) {
        $pid = to_int($_GET['parent_id']);
        $where[]            = 's.parent_id = :parent_id';
        $params[':parent_id'] = $pid;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $st = $db->prepare("SELECT s.id, s.name, s.description, s.parent_id, s.created_at, p.name AS parent_name FROM sites s LEFT JOIN sites p ON p.id = s.parent_id $whereSql ORDER BY s.name");
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v, PDO::PARAM_INT);
    }
    $st->execute();
    api_json(['sites' => array_map('fmt_site', $st->fetchAll())]);
}

/**
 * @param array<string, mixed> $r
 * @return array<string, mixed>
 */
function fmt_site(array $r): array
{
    return [
        'id'          => to_int($r['id']),
        'name'        => to_str($r['name']),
        'description' => to_str($r['description']),
        'parent_id'   => $r['parent_id'] !== null ? to_int($r['parent_id']) : null,
        'parent_name' => $r['parent_name'] !== null ? to_str($r['parent_name']) : null,
        'created_at'  => $r['created_at'],
    ];
}

function api_vlans(PDO $db): never
{
    if (isset($_GET['id'])) {
        $id = to_int($_GET['id']);
        $st = $db->prepare("SELECT v.id, v.vlan_id, v.name, v.description, v.site_id, v.created_at, v.updated_at, s.name AS site_name FROM vlans v LEFT JOIN sites s ON s.id = v.site_id WHERE v.id = :id");
        $st->execute([':id' => $id]);
        /** @var array<string, mixed>|false $row */
        $row = $st->fetch();
        if (!$row) api_error(404, 'VLAN not found.');
        api_json(fmt_vlan($row));
    }

    $where  = [];
    $params = [];

    if (isset($_GET['site_id'])) {
        $where[]           = 'v.site_id = :site_id';
        $params[':site_id'] = to_int($_GET['site_id']);
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $st = $db->prepare("SELECT v.id, v.vlan_id, v.name, v.description, v.site_id, v.created_at, v.updated_at, s.name AS site_name FROM vlans v LEFT JOIN sites s ON s.id = v.site_id $whereSql ORDER BY v.vlan_id");
    foreach ($params as $k => $v2) {
        $st->bindValue($k, $v2, PDO::PARAM_INT);
    }
    $st->execute();
    api_json(['vlans' => array_map('fmt_vlan', $st->fetchAll())]);
}

/**
 * @param array<string, mixed> $r
 * @return array<string, mixed>
 */
function fmt_vlan(array $r): array
{
    return [
        'id'          => to_int($r['id']),
        'vlan_id'     => to_int($r['vlan_id']),
        'name'        => to_str($r['name']),
        'description' => to_str($r['description']),
        'site_id'     => $r['site_id'] !== null ? to_int($r['site_id']) : null,
        'site_name'   => $r['site_name'] !== null ? to_str($r['site_name']) : null,
        'created_at'  => $r['created_at'],
        'updated_at'  => $r['updated_at'],
    ];
}

function api_history(PDO $db): never
{
    if (!isset($_GET['address_id'])) {
        api_error(400, 'address_id is required.');
    }
    $addressId = to_int($_GET['address_id']);

    // Fetch current address if it still exists (deleted addresses may still have history)
    $addrSt = $db->prepare("SELECT ip FROM addresses WHERE id = :id");
    $addrSt->execute([':id' => $addressId]);
    /** @var array<string, mixed>|false $addr */
    $addr = $addrSt->fetch();

    $page   = max(1, to_int($_GET['page']  ?? 1));
    $limit  = max(1, min(200, to_int($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM address_history WHERE address_id = :id");
    $cntSt->execute([':id' => $addressId]);
    /** @var array<string, mixed>|false $cntRow */

    $cntRow = $cntSt->fetch();

    $total = is_array($cntRow) ? to_int($cntRow['c']) : 0;

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
        $before = $r['before_json'] !== null ? json_decode(to_str($r['before_json']), true) : null;
        $after  = $r['after_json']  !== null ? json_decode(to_str($r['after_json']),  true) : null;
        return [
            'id'         => to_int($r['id']),
            'action'     => $r['action'],
            'before'     => $before,
            'after'      => $after,
            'username'   => to_str($r['username']),
            'created_at' => $r['created_at'],
        ];
    }, $st->fetchAll());

    api_json([
        'address_id' => $addressId,
        'ip'         => $addr ? to_str($addr['ip']) : null,
        'total'      => $total,
        'page'       => $page,
        'limit'      => $limit,
        'history'    => $rows,
    ]);
}

// ---- Search endpoint ----

function api_search(PDO $db): never
{
    $q = substr(trim(to_str($_GET['q'] ?? '')), 0, 500);
    if ($q === '') api_error(400, 'q parameter is required.');

    $where  = ["(a.ip LIKE :q ESCAPE '\\' OR a.hostname LIKE :q ESCAPE '\\' OR a.owner LIKE :q ESCAPE '\\' OR a.note LIKE :q ESCAPE '\\' OR a.grp LIKE :q ESCAPE '\\')"];
    $params = [':q' => '%' . like_escape($q) . '%'];

    if (isset($_GET['status'])) {
        $s = strtolower(trim(to_str($_GET['status'])));
        if (in_array($s, ['used', 'reserved', 'free'], true)) {
            $where[] = 'a.status = :st'; $params[':st'] = $s;
        }
    }
    if (isset($_GET['site_id'])) {
        $where[] = 's.site_id = :site_id'; $params[':site_id'] = to_int($_GET['site_id']);
    }
    if (isset($_GET['ip_version'])) {
        $v = to_int($_GET['ip_version']);
        if (in_array($v, [4, 6], true)) {
            $where[] = 's.ip_version = :ipver'; $params[':ipver'] = $v;
        }
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $page   = max(1, to_int($_GET['page']  ?? 1));
    $limit  = max(1, min(500, to_int($_GET['limit'] ?? 100)));
    $offset = ($page - 1) * $limit;

    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM addresses a JOIN subnets s ON s.id = a.subnet_id $whereSql");
    $cntSt->execute($params);
    /** @var array<string, mixed>|false $cntRow */

    $cntRow = $cntSt->fetch();

    $total = is_array($cntRow) ? to_int($cntRow['c']) : 0;

    $st = $db->prepare("
        SELECT a.id, a.subnet_id, a.ip, a.hostname, a.owner, a.status, a.note, a.grp,
               a.mac, a.expires_at, a.created_at, a.updated_at, s.cidr AS subnet_cidr
        FROM addresses a
        JOIN subnets s ON s.id = a.subnet_id
        $whereSql
        ORDER BY a.ip_bin
        LIMIT :lim OFFSET :off
    ");
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();

    $rows = array_map(fn(array $r) => [
        'id' => to_int($r['id']), 'subnet_id' => to_int($r['subnet_id']),
        'subnet_cidr' => $r['subnet_cidr'], 'ip' => $r['ip'],
        'hostname' => to_str($r['hostname']), 'owner' => to_str($r['owner']),
        'status' => $r['status'], 'note' => to_str($r['note']),
        'group' => to_str($r['grp']),
        'mac' => to_str($r['mac']),
        'expires_at' => isset($r['expires_at']) ? to_str($r['expires_at']) : null,
        'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'],
    ], $st->fetchAll());

    api_json(['total' => $total, 'page' => $page, 'limit' => $limit, 'results' => $rows]);
}

// ---- Audit log endpoint ----

function api_audit_log(PDO $db): never
{
    $where  = [];
    $params = [];

    if (isset($_GET['action'])) {
        $where[] = 'action LIKE :act'; $params[':act'] = '%' . like_escape(trim(to_str($_GET['action']))) . '%';
    }
    if (isset($_GET['from'])) {
        $where[] = 'created_at >= :from_dt'; $params[':from_dt'] = trim(to_str($_GET['from']));
    }
    if (isset($_GET['to'])) {
        $where[] = 'created_at <= :to_dt'; $params[':to_dt'] = trim(to_str($_GET['to']));
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $page   = max(1, to_int($_GET['page']  ?? 1));
    $limit  = max(1, min(500, to_int($_GET['limit'] ?? 100)));
    $offset = ($page - 1) * $limit;

    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM audit_log $whereSql");
    $cntSt->execute($params);
    /** @var array<string, mixed>|false $cntRow */

    $cntRow = $cntSt->fetch();

    $total = is_array($cntRow) ? to_int($cntRow['c']) : 0;

    $st = $db->prepare("
        SELECT id, created_at, username, action, entity_type, entity_id, ip, details
        FROM audit_log $whereSql
        ORDER BY id DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();

    $rows = array_map(fn(array $r) => [
        'id' => to_int($r['id']), 'created_at' => $r['created_at'],
        'username' => to_str($r['username']), 'action' => $r['action'],
        'entity_type' => $r['entity_type'],
        'entity_id' => $r['entity_id'] !== null ? to_int($r['entity_id']) : null,
        'ip' => to_str($r['ip'] ?? ''), 'details' => to_str($r['details'] ?? ''),
    ], $st->fetchAll());

    api_json(['total' => $total, 'page' => $page, 'limit' => $limit, 'entries' => $rows]);
}

// ---- Unassigned IPs endpoint ----

function api_unassigned(PDO $db): never
{
    if (!isset($_GET['subnet_id'])) {
        api_error(400, 'subnet_id is required.');
    }
    $subnetId = to_int($_GET['subnet_id']);

    $st = $db->prepare("SELECT id, cidr, ip_version, network_bin, prefix FROM subnets WHERE id = :id");
    $st->execute([':id' => $subnetId]);
    /** @var array<string, mixed>|false $subnet */
    $subnet = $st->fetch();
    if (!$subnet) api_error(404, 'Subnet not found.');

    $prefix  = to_int($subnet['prefix']);
    $version = to_int($subnet['ip_version']);
    $maxIPs  = 256;

    if ($version === 4) {
        $networkInt = ipv4_bin_to_int(to_str($subnet['network_bin']));
        $bcastInt   = ipv4_broadcast_int($networkInt, $prefix);
        $assignable = ipv4_assignable_count($prefix);

        if ($assignable > $maxIPs) {
            api_error(400, "Subnet is too large ($assignable assignable IPs; limit is $maxIPs). Use a /24 or smaller.");
        }

        if ($prefix <= 30) {
            $first = $networkInt + 1;
            $last  = $bcastInt - 1;
        } else {
            $first = $networkInt;
            $last  = $bcastInt;
        }

        $ipSt = $db->prepare("SELECT ip_bin FROM addresses WHERE subnet_id = :sid");
        $ipSt->execute([':sid' => $subnetId]);
        $assigned = [];
        foreach ($ipSt->fetchAll() as $row) $assigned[ipv4_bin_to_int(to_str($row['ip_bin']))] = true;

        $all = [];
        for ($i = $first; $i <= $last; $i++) {
            if (!isset($assigned[$i])) $all[] = ipv4_int_to_text($i);
        }
    } else {
        // IPv6 — enumerate first $maxIPs unassigned addresses
        $all = ipv6_enumerate_first_n($db, $subnetId, to_str($subnet['network_bin']), $prefix, $maxIPs);
    }

    $total  = count($all);
    $page   = max(1, to_int($_GET['page']  ?? 1));
    $limit  = max(1, min(256, to_int($_GET['limit'] ?? 100)));
    $pages  = (int)max(1, ceil($total / $limit));
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $limit;

    api_json([
        'subnet_id'        => $subnetId,
        'cidr'             => $subnet['cidr'],
        'total_unassigned' => $total,
        'page'             => $page,
        'pages'            => $pages,
        'limit'            => $limit,
        'unassigned'       => array_slice($all, $offset, $limit),
    ]);
}

// ---- Bulk write ----

/**
 * @param array<string, mixed> $apiKey
 * @param array<mixed> $body
 */
function api_addresses_bulk_create(PDO $db, array $apiKey, array $body, int $bulkLimit): never
{
    if (!array_is_list($body)) {
        api_error(400, 'Bulk create expects a JSON array of address objects.');
    }
    if (count($body) === 0) {
        api_error(400, 'Array must not be empty.');
    }
    if (count($body) > $bulkLimit) {
        api_error(400, "Too many items. Maximum is $bulkLimit per request.");
    }

    $results = [];
    foreach ($body as $item) {
        if (!is_array($item)) {
            $results[] = ['success' => false, 'error' => 'Item must be an object.'];
            continue;
        }

        $subnetId  = isset($item['subnet_id']) ? to_int($item['subnet_id']) : 0;
        $ip        = trim(to_str($item['ip'] ?? ''));
        $hostname  = trim(to_str($item['hostname'] ?? ''));
        $owner     = trim(to_str($item['owner'] ?? ''));
        $note      = trim(to_str($item['note'] ?? ''));
        $grp       = trim(to_str($item['group'] ?? ''));
        $mac       = substr(trim(to_str($item['mac'] ?? '')), 0, 64);
        $expiresAt = trim(to_str($item['expires_at'] ?? ''));
        if ($expiresAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) $expiresAt = '';
        $status = strtolower(trim(to_str($item['status'] ?? 'used')));

        if ($subnetId <= 0) { $results[] = ['success' => false, 'error' => 'subnet_id is required.']; continue; }
        if ($ip === '')     { $results[] = ['success' => false, 'error' => 'ip is required.']; continue; }
        if (!in_array($status, ['used', 'reserved', 'free'], true)) {
            $results[] = ['success' => false, 'error' => 'status must be used, reserved, or free.'];
            continue;
        }

        $subnetSt = $db->prepare("SELECT id, cidr, ip_version FROM subnets WHERE id = :id");
        $subnetSt->execute([':id' => $subnetId]);
        /** @var array<string, mixed>|false $subnet */
        $subnet = $subnetSt->fetch();
        if (!$subnet) { $results[] = ['success' => false, 'error' => "Subnet $subnetId not found."]; continue; }

        $n = normalize_ip($ip);
        if (!$n) { $results[] = ['success' => false, 'error' => 'Invalid IP address.']; continue; }
        if (to_int($n['version']) !== to_int($subnet['ip_version'])) {
            $results[] = ['success' => false, 'error' => 'IP version does not match subnet.'];
            continue;
        }

        $p = parse_cidr(to_str($subnet['cidr']));
        if ($p === null || !ip_in_cidr($n['ip'], $p['network'], $p['prefix'])) {
            $results[] = ['success' => false, 'error' => 'IP is not within the subnet.'];
            continue;
        }

        $dupSt = $db->prepare("SELECT id FROM addresses WHERE subnet_id = :sid AND ip_bin = :b");
        $dupSt->execute([':sid' => $subnetId, ':b' => $n['bin']]);
        if ($dupSt->fetch()) { $results[] = ['success' => false, 'error' => 'Address already exists in this subnet.']; continue; }

        try {
            $db->prepare(
                "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, grp, mac, expires_at, status)
                 VALUES (:sid, :ip, :b, :hn, :ow, :nt, :grp, :mac, :exp, :st)"
            )->execute([
                ':sid' => $subnetId, ':ip' => $n['ip'], ':b' => $n['bin'],
                ':hn'  => $hostname,  ':ow' => $owner,   ':nt' => $note,
                ':grp' => $grp,       ':mac' => $mac,
                ':exp' => $expiresAt !== '' ? $expiresAt : null,
                ':st'  => $status,
            ]);
            $newId = (int)$db->lastInsertId();

            api_audit($db, $apiKey, 'address.create', 'address', $newId,
                "bulk ip={$n['ip']} subnet_id={$subnetId} status={$status}");
            api_history_log_address($db, $apiKey, 'create', $subnetId, $n['ip'], $newId, null, [
                'hostname' => $hostname, 'owner' => $owner, 'note' => $note,
                'grp' => $grp, 'mac' => $mac,
                'expires_at' => $expiresAt !== '' ? $expiresAt : null,
                'status' => $status,
            ]);

            $results[] = ['success' => true, 'id' => $newId];
        } catch (Throwable $ex) {
            $results[] = ['success' => false, 'error' => 'Database error: ' . $ex->getMessage()];
        }
    }

    $succeeded = count(array_filter($results, fn($r) => $r['success'] === true));
    $failed    = count($results) - $succeeded;
    $code = $succeeded > 0 ? ($failed > 0 ? 207 : 201) : 400;
    http_response_code($code);
    api_json(['created' => $succeeded, 'failed' => $failed, 'results' => $results]);
}

/**
 * @param array<string, mixed> $apiKey
 * @param array<mixed> $body
 */
function api_subnets_bulk_create(PDO $db, array $apiKey, array $body, int $bulkLimit): never
{
    if (!array_is_list($body)) {
        api_error(400, 'Bulk create expects a JSON array of subnet objects.');
    }
    if (count($body) === 0) {
        api_error(400, 'Array must not be empty.');
    }
    if (count($body) > $bulkLimit) {
        api_error(400, "Too many items. Maximum is $bulkLimit per request.");
    }

    $results = [];
    foreach ($body as $item) {
        if (!is_array($item)) {
            $results[] = ['success' => false, 'error' => 'Item must be an object.'];
            continue;
        }

        $cidr        = trim(to_str($item['cidr'] ?? ''));
        $description = trim(to_str($item['description'] ?? ''));
        $siteId      = isset($item['site_id']) ? to_int($item['site_id']) : null;
        $vlanId      = isset($item['vlan_id']) ? to_int($item['vlan_id']) : null;

        if ($cidr === '') { $results[] = ['success' => false, 'error' => 'cidr is required.']; continue; }

        $p = parse_cidr($cidr);
        if (!$p) { $results[] = ['success' => false, 'error' => 'Invalid CIDR notation.']; continue; }

        if ($vlanId !== null && ($vlanId < 1 || $vlanId > 4094)) {
            $results[] = ['success' => false, 'error' => 'vlan_id must be between 1 and 4094.'];
            continue;
        }

        if ($siteId !== null) {
            $siteSt = $db->prepare("SELECT id FROM sites WHERE id = :id");
            $siteSt->execute([':id' => $siteId]);
            if (!$siteSt->fetch()) { $results[] = ['success' => false, 'error' => "Site $siteId not found."]; continue; }
        }

        $normalized = $p['network'] . '/' . $p['prefix'];
        $dupSt = $db->prepare("SELECT id FROM subnets WHERE cidr = :cidr");
        $dupSt->execute([':cidr' => $normalized]);
        if ($dupSt->fetch()) { $results[] = ['success' => false, 'error' => "Subnet $normalized already exists."]; continue; }

        $inheritedSiteId = find_parent_site_id($db, $normalized);
        if ($inheritedSiteId !== null) $siteId = $inheritedSiteId;

        try {
            $db->prepare(
                "INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, site_id, vlan_id)
                 VALUES (:cidr, :ver, :net, :nbin, :pfx, :desc, :sid, :vlan)"
            )->execute([
                ':cidr' => $normalized,
                ':ver'  => $p['version'],
                ':net'  => $p['network'],
                ':nbin' => $p['net_bin'],
                ':pfx'  => $p['prefix'],
                ':desc' => $description,
                ':sid'  => $siteId,
                ':vlan' => $vlanId,
            ]);
            $newId = (int)$db->lastInsertId();

            api_audit($db, $apiKey, 'subnet.create', 'subnet', $newId, "bulk cidr={$normalized}");

            $results[] = ['success' => true, 'id' => $newId];
        } catch (Throwable $ex) {
            $results[] = ['success' => false, 'error' => 'Database error: ' . $ex->getMessage()];
        }
    }

    $succeeded = count(array_filter($results, fn($r) => $r['success'] === true));
    $failed    = count($results) - $succeeded;
    $code = $succeeded > 0 ? ($failed > 0 ? 207 : 201) : 400;
    http_response_code($code);
    api_json(['created' => $succeeded, 'failed' => $failed, 'results' => $results]);
}

// ---- Write: Sites ----

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_sites_create(PDO $db, array $apiKey, array $body): never
{
    $name = trim(to_str($body['name'] ?? ''));
    $desc = trim(to_str($body['description'] ?? ''));
    if ($name === '') api_error(400, 'name is required.');

    $dup = $db->prepare("SELECT id FROM sites WHERE name = :n");
    $dup->execute([':n' => $name]);
    if ($dup->fetch()) api_error(409, 'A site with this name already exists.');

    $db->prepare("INSERT INTO sites (name, description) VALUES (:n, :d)")
       ->execute([':n' => $name, ':d' => $desc]);
    $newId = (int)$db->lastInsertId();
    api_audit($db, $apiKey, 'site.create', 'site', $newId, "name=$name");
    http_response_code(201);
    api_json(['id' => $newId]);
}

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_sites_update(PDO $db, array $apiKey, int $id, array $body): never
{
    if ($id <= 0) api_error(400, 'id is required as a query parameter.');
    $st = $db->prepare("SELECT id, name, description FROM sites WHERE id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string, mixed>|false $site */
    $site = $st->fetch();
    if (!$site) api_error(404, 'Site not found.');

    $name = array_key_exists('name', $body) ? trim(to_str($body['name'])) : to_str($site['name']);
    $desc = array_key_exists('description', $body) ? trim(to_str($body['description'])) : to_str($site['description']);
    if ($name === '') api_error(400, 'name cannot be empty.');

    // Check for duplicate name (excluding self)
    $dupSt = $db->prepare("SELECT id FROM sites WHERE name = :n AND id != :id");
    $dupSt->execute([':n' => $name, ':id' => $id]);
    if ($dupSt->fetch()) api_error(409, 'A site with this name already exists.');

    $db->prepare("UPDATE sites SET name = :n, description = :d WHERE id = :id")
       ->execute([':n' => $name, ':d' => $desc, ':id' => $id]);
    api_audit($db, $apiKey, 'site.update', 'site', $id, "name=$name");
    api_json(['id' => $id]);
}

/** @param array<string, mixed> $apiKey */
function api_sites_delete(PDO $db, array $apiKey, int $id): never
{
    if ($id <= 0) api_error(400, 'id is required as a query parameter.');
    $st = $db->prepare("SELECT id, name FROM sites WHERE id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string, mixed>|false $site */
    $site = $st->fetch();
    if (!$site) api_error(404, 'Site not found.');

    $db->beginTransaction();
    $db->prepare("UPDATE subnets SET site_id = NULL WHERE site_id = :id")->execute([':id' => $id]);
    $db->prepare("DELETE FROM sites WHERE id = :id")->execute([':id' => $id]);
    $db->commit();
    api_audit($db, $apiKey, 'site.delete', 'site', $id, 'name=' . to_str($site['name']));
    http_response_code(204);
    exit;
}

// ---- Write: Addresses ----

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_addresses_create(PDO $db, array $apiKey, array $body): never
{
    $subnetId = isset($body['subnet_id']) ? to_int($body['subnet_id']) : 0;
    $ip       = trim(to_str($body['ip'] ?? ''));
    $hostname  = trim(to_str($body['hostname']   ?? ''));
    $owner     = trim(to_str($body['owner']     ?? ''));
    $note      = trim(to_str($body['note']      ?? ''));
    $grp       = trim(to_str($body['group']     ?? ''));
    $mac       = substr(trim(to_str($body['mac'] ?? '')), 0, 64);
    $expiresAt = trim(to_str($body['expires_at'] ?? ''));
    if ($expiresAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
        $expiresAt = '';
    }
    $status = strtolower(trim(to_str($body['status'] ?? 'used')));

    if ($subnetId <= 0)  api_error(400, 'subnet_id is required.');
    if ($ip === '')      api_error(400, 'ip is required.');
    if (!in_array($status, ['used', 'reserved', 'free'], true)) {
        api_error(400, 'status must be used, reserved, or free.');
    }

    $subnetSt = $db->prepare("SELECT id, cidr, ip_version FROM subnets WHERE id = :id");
    $subnetSt->execute([':id' => $subnetId]);
    /** @var array<string, mixed>|false $subnet */
    $subnet = $subnetSt->fetch();
    if (!$subnet) api_error(404, 'Subnet not found.');

    $n = normalize_ip($ip);
    if (!$n) api_error(400, 'Invalid IP address.');
    if (to_int($n['version']) !== to_int($subnet['ip_version'])) {
        api_error(400, 'IP version does not match subnet.');
    }

    $p = parse_cidr(to_str($subnet['cidr']));
    if ($p === null || !ip_in_cidr($n['ip'], $p['network'], $p['prefix'])) {
        api_error(400, 'IP is not within the specified subnet (' . to_str($subnet['cidr']) . ')');
    }

    // Check for duplicate
    $dupSt = $db->prepare("SELECT id FROM addresses WHERE subnet_id = :sid AND ip_bin = :b");
    $dupSt->execute([':sid' => $subnetId, ':b' => $n['bin']]);
    if ($dupSt->fetch()) api_error(409, 'An address record for this IP already exists in the subnet.');

    $db->prepare(
        "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, grp, mac, expires_at, status)
         VALUES (:sid, :ip, :b, :hn, :ow, :nt, :grp, :mac, :exp, :st)"
    )->execute([
        ':sid' => $subnetId, ':ip' => $n['ip'], ':b' => $n['bin'],
        ':hn'  => $hostname,  ':ow' => $owner,   ':nt' => $note,
        ':grp' => $grp,       ':mac' => $mac,
        ':exp' => $expiresAt !== '' ? $expiresAt : null,
        ':st'  => $status,
    ]);
    $newId = (int)$db->lastInsertId();

    api_audit($db, $apiKey, 'address.create', 'address', $newId,
        "ip={$n['ip']} subnet_id={$subnetId} status={$status}");

    api_history_log_address($db, $apiKey, 'create', $subnetId, $n['ip'], $newId, null, [
        'hostname' => $hostname, 'owner' => $owner, 'note' => $note,
        'grp' => $grp, 'mac' => $mac,
        'expires_at' => $expiresAt !== '' ? $expiresAt : null,
        'status' => $status,
    ]);

    http_response_code(201);
    api_json(['id' => $newId]);
}

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_addresses_update(PDO $db, array $apiKey, int $id, array $body): never
{
    if ($id <= 0) api_error(400, 'id is required as a query parameter.');

    $st = $db->prepare("SELECT id, ip, subnet_id, hostname, owner, note, grp, mac, expires_at, status FROM addresses WHERE id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string, mixed>|false $addr */
    $addr = $st->fetch();
    if (!$addr) api_error(404, 'Address not found.');

    $hostname  = array_key_exists('hostname',   $body) ? trim(to_str($body['hostname']))   : to_str($addr['hostname']);
    $owner     = array_key_exists('owner',      $body) ? trim(to_str($body['owner']))      : to_str($addr['owner']);
    $note      = array_key_exists('note',       $body) ? trim(to_str($body['note']))       : to_str($addr['note']);
    $grp       = array_key_exists('group',      $body) ? trim(to_str($body['group']))      : to_str($addr['grp']);
    $mac       = array_key_exists('mac',        $body) ? substr(trim(to_str($body['mac'])), 0, 64) : to_str($addr['mac']);
    $expiresAt = array_key_exists('expires_at', $body)
        ? (to_str($body['expires_at'] ?? '') === '' ? null : to_str($body['expires_at']))
        : (isset($addr['expires_at']) ? to_str($addr['expires_at']) : null);
    if ($expiresAt !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
        $expiresAt = null;
    }
    $status = array_key_exists('status', $body) ? strtolower(trim(to_str($body['status']))) : to_str($addr['status']);

    if (!in_array($status, ['used', 'reserved', 'free'], true)) {
        api_error(400, 'status must be used, reserved, or free.');
    }

    $db->prepare(
        "UPDATE addresses SET hostname = :hn, owner = :ow, note = :nt, grp = :grp, mac = :mac, expires_at = :exp, status = :st WHERE id = :id"
    )->execute([':hn' => $hostname, ':ow' => $owner, ':nt' => $note, ':grp' => $grp,
                ':mac' => $mac, ':exp' => $expiresAt, ':st' => $status, ':id' => $id]);

    api_audit($db, $apiKey, 'address.update', 'address', $id,
        'ip=' . to_str($addr['ip']) . ' status=' . $status);

    api_history_log_address($db, $apiKey, 'update', to_int($addr['subnet_id']), to_str($addr['ip']), $id,
        ['hostname' => to_str($addr['hostname']), 'owner' => to_str($addr['owner']),
         'note' => to_str($addr['note']), 'grp' => to_str($addr['grp']),
         'mac' => to_str($addr['mac']),
         'expires_at' => isset($addr['expires_at']) ? to_str($addr['expires_at']) : null,
         'status' => to_str($addr['status'])],
        ['hostname' => $hostname, 'owner' => $owner, 'note' => $note,
         'grp' => $grp, 'mac' => $mac, 'expires_at' => $expiresAt, 'status' => $status]
    );

    api_json(['id' => $id]);
}

/** @param array<string, mixed> $apiKey */
function api_addresses_delete(PDO $db, array $apiKey, int $id): never
{
    if ($id <= 0) api_error(400, 'id is required as a query parameter.');

    $st = $db->prepare("SELECT id, ip, subnet_id, hostname, owner, note, grp, mac, expires_at, status FROM addresses WHERE id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string, mixed>|false $addr */
    $addr = $st->fetch();
    if (!$addr) api_error(404, 'Address not found.');

    $db->prepare("DELETE FROM addresses WHERE id = :id")->execute([':id' => $id]);

    api_audit($db, $apiKey, 'address.delete', 'address', $id, 'ip=' . to_str($addr['ip']));

    api_history_log_address($db, $apiKey, 'delete', to_int($addr['subnet_id']), to_str($addr['ip']), $id,
        ['hostname' => to_str($addr['hostname']), 'owner' => to_str($addr['owner']),
         'note' => to_str($addr['note']), 'grp' => to_str($addr['grp']),
         'mac' => to_str($addr['mac']),
         'expires_at' => isset($addr['expires_at']) ? to_str($addr['expires_at']) : null,
         'status' => to_str($addr['status'])],
        null
    );

    http_response_code(204);
    exit;
}

// ---- Write: Subnets ----

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_subnets_create(PDO $db, array $apiKey, array $body): never
{
    $cidr        = trim(to_str($body['cidr']        ?? ''));
    $description = trim(to_str($body['description'] ?? ''));
    $siteId      = isset($body['site_id']) ? to_int($body['site_id']) : null;
    $vlanId      = isset($body['vlan_id']) ? to_int($body['vlan_id']) : null;

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

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_subnets_update(PDO $db, array $apiKey, int $id, array $body): never
{
    if ($id <= 0) api_error(400, 'id is required as a query parameter.');

    $st = $db->prepare("SELECT id, cidr, description, site_id, vlan_id FROM subnets WHERE id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string, mixed>|false $subnet */
    $subnet = $st->fetch();
    if (!$subnet) api_error(404, 'Subnet not found.');

    $description = array_key_exists('description', $body) ? trim(to_str($body['description'])) : to_str($subnet['description']);
    $siteId = array_key_exists('site_id', $body)
        ? ($body['site_id'] !== null ? to_int($body['site_id']) : null)
        : ($subnet['site_id'] !== null ? to_int($subnet['site_id']) : null);
    $vlanId = array_key_exists('vlan_id', $body)
        ? ($body['vlan_id'] !== null ? to_int($body['vlan_id']) : null)
        : ($subnet['vlan_id'] !== null ? to_int($subnet['vlan_id']) : null);

    if ($vlanId !== null && ($vlanId < 1 || $vlanId > 4094)) {
        api_error(400, 'vlan_id must be between 1 and 4094.');
    }

    if ($siteId !== null) {
        $siteSt = $db->prepare("SELECT id FROM sites WHERE id = :id");
        $siteSt->execute([':id' => $siteId]);
        if (!$siteSt->fetch()) api_error(404, 'Site not found.');
    }

    // Inherit site from tightest parent if one exists
    $inheritedSiteId = find_parent_site_id($db, to_str($subnet['cidr']), $id);
    if ($inheritedSiteId !== null) $siteId = $inheritedSiteId;

    $db->prepare(
        "UPDATE subnets SET description = :desc, site_id = :sid, vlan_id = :vlan WHERE id = :id"
    )->execute([':desc' => $description, ':sid' => $siteId, ':vlan' => $vlanId, ':id' => $id]);

    api_audit($db, $apiKey, 'subnet.update', 'subnet', $id, 'cidr=' . to_str($subnet['cidr']));

    api_json(['id' => $id]);
}

/** @param array<string, mixed> $apiKey */
function api_subnets_delete(PDO $db, array $apiKey, int $id): never
{
    if ($id <= 0) api_error(400, 'id is required as a query parameter.');

    $st = $db->prepare("SELECT id, cidr FROM subnets WHERE id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string, mixed>|false $subnet */
    $subnet = $st->fetch();
    if (!$subnet) api_error(404, 'Subnet not found.');

    // Block deletion if subnet has addresses
    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM addresses WHERE subnet_id = :id");
    $cntSt->execute([':id' => $id]);
    /** @var array<string, mixed>|false $cntRow */

    $cntRow = $cntSt->fetch();

    $count = is_array($cntRow) ? to_int($cntRow['c']) : 0;
    if ($count > 0) {
        api_error(409, "Cannot delete subnet with {$count} address record(s). Delete addresses first.");
    }

    $db->prepare("DELETE FROM subnets WHERE id = :id")->execute([':id' => $id]);

    api_audit($db, $apiKey, 'subnet.delete', 'subnet', $id, 'cidr=' . to_str($subnet['cidr']));

    http_response_code(204);
    exit;
}

// ---- Audit helper for API writes ----

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed>|null $before
 * @param array<string, mixed>|null $after
 */
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
        ':un'  => 'api:' . to_str($apiKey['name']),
        ':cip' => client_ip(),
        ':ua'  => to_str($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ':bj'  => $before ? json_encode($before, JSON_UNESCAPED_SLASHES) : null,
        ':aj'  => $after ? json_encode($after, JSON_UNESCAPED_SLASHES) : null,
    ]);
}

/** @param array<string, mixed> $apiKey */
function api_audit(PDO $db, array $apiKey, string $action, string $entityType, int $entityId, string $details): void
{
    $username = 'api:' . to_str($apiKey['name']);
    $db->prepare(
        "INSERT INTO audit_log (action, entity_type, entity_id, user_id, username, ip, user_agent, details)
         VALUES (:act, :et, :eid, NULL, :un, :ip, :ua, :det)"
    )->execute([
        ':act' => $action,
        ':et'  => $entityType,
        ':eid' => $entityId,
        ':un'  => $username,
        ':ip'  => client_ip(),
        ':ua'  => to_str($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ':det' => $details,
    ]);
}
