<?php
declare(strict_types=1);

/**
 * Simple-PHP-IPAM — REST API
 *
 * Authentication: pass your API key via the Authorization header
 *   Authorization: Bearer <key>
 *
 * Resources (read):
 *   GET  ?resource=subnets             — list subnets (paginated, filterable by ip_version, vlan_id, vrf_id)
 *   GET  ?resource=addresses           — list addresses (paginated, filterable)
 *   GET  ?resource=sites               — list all sites
 *   GET  ?resource=vlans               — list all VLANs
 *   GET  ?resource=vrfs                — list all VRFs
 *   GET  ?resource=history&address_id= — address change history (paginated)
 *   GET  ?resource=search&q=           — search addresses by IP/hostname/owner/note
 *   GET  ?resource=audit               — audit log (paginated, filterable by action/date)
 *
 * Resources (write):
 *   POST/PUT/DELETE ?resource=subnets  — create/update/delete subnets
 *   POST/PUT/DELETE ?resource=addresses — create/update/delete addresses
 *   POST/PUT/DELETE ?resource=sites    — create/update/delete sites
 *   POST/PUT/DELETE ?resource=vrfs     — create/update/delete VRFs
 *   POST/PUT/DELETE ?resource=contacts — create/update/delete contacts
 */

// No session; stateless API request
$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

$db = ipam_db($config);
ipam_db_init($db);

// ---- API key authentication ----

$apiMaxAttempts    = max(1, to_int(ipam_setting('api.max_attempts')));
$apiLockoutSeconds = max(1, to_int(ipam_setting('api.lockout_seconds')));
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
}

// Session-based auth for browser-only GET endpoints (no API key required)
$sessionApiKey = null;
if ($rawKey === '') {
    $resourcePeek = strtolower(trim(to_str($_GET['resource'] ?? '')));
    $methodPeek   = strtoupper(to_str($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($methodPeek === 'GET' && in_array($resourcePeek, ['contacts', 'subnet_stats'], true)) {
        $sesName = to_str($config['session_name']);
        if ($sesName === '' || $sesName === 'IPAMSESSID') {
            $sesName = 'IPAMSESSID_' . substr(hash('sha256', __DIR__), 0, 8);
        }
        session_name($sesName);
        $cookiePath = to_str($config['session_cookie_path'] ?? '');
        if ($cookiePath === '') {
            $sn = to_str($_SERVER['SCRIPT_NAME'] ?? '');
            if ($sn !== '') { $d = str_replace('\\', '/', dirname($sn)); $cookiePath = ($d === '' || $d === '.' || $d === '/') ? '/' : $d . '/'; } else { $cookiePath = '/'; }
        }
        session_set_cookie_params(['lifetime' => 0, 'path' => $cookiePath, 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
        $sesDir = __DIR__ . '/data/sessions';
        if (is_dir($sesDir) && is_writable($sesDir)) ini_set('session.save_path', $sesDir);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        @session_start();
        if (!empty($_SESSION['uid'])) {
            $sessionApiKey = ['id' => 0, 'name' => 'session', 'is_readonly' => '1'];
        }
    }
}

if ($rawKey === '' && $sessionApiKey === null) {
    http_response_code(401);
    echo json_encode(['error' => 'API key required. Pass via Authorization: Bearer <key> header.']);
    exit;
}

if ($sessionApiKey !== null) {
    $apiKey = $sessionApiKey;
} else {
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

    $db->prepare("UPDATE api_keys SET last_used_at = " . ipam_dialect()->now() . " WHERE id = :id")
       ->execute([':id' => to_int($apiKey['id'])]);
}

// ---- Route ----

$resource  = strtolower(trim(to_str($_GET['resource'] ?? '')));
$method    = strtoupper(to_str($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$isBulk    = !empty($_GET['bulk']);
$bulkLimit = max(1, to_int(ipam_setting('api.bulk_limit')));

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
    'vlans' => match ($method) {
        'GET'    => api_vlans($db),
        'POST'   => api_vlans_create($db, $apiKey, $body),
        'PUT'    => api_vlans_update($db, $apiKey, to_int($_GET['id'] ?? 0), $body),
        'DELETE' => api_vlans_delete($db, $apiKey, to_int($_GET['id'] ?? 0)),
        default  => api_error(405, 'Method not allowed.'),
    },
    'vrfs'       => match ($method) {
        'GET'    => api_vrfs($db),
        'POST'   => api_vrfs_create($db, $apiKey, $body),
        'PUT'    => api_vrfs_update($db, $apiKey, to_int($_GET['id'] ?? 0), $body),
        'DELETE' => api_vrfs_delete($db, $apiKey, to_int($_GET['id'] ?? 0)),
        default  => api_error(405, 'Method not allowed.'),
    },
    'contacts'   => match ($method) {
        'GET'    => api_contacts($db),
        'POST'   => api_contacts_create($db, $apiKey, $body),
        'PUT'    => api_contacts_update($db, $apiKey, to_int($_GET['id'] ?? 0), $body),
        'DELETE' => api_contacts_delete($db, $apiKey, to_int($_GET['id'] ?? 0)),
        default  => api_error(405, 'Method not allowed.'),
    },
    // #310: write API for tags + association endpoints
    'tags' => match ($method) {
        'GET'    => api_tags_list($db),
        'POST'   => api_tags_create($db, $apiKey, $body),
        'PUT'    => api_tags_update($db, $apiKey, to_int($_GET['id'] ?? 0), $body),
        'DELETE' => api_tags_delete($db, $apiKey, to_int($_GET['id'] ?? 0)),
        default  => api_error(405, 'Method not allowed.'),
    },
    'subnet_tags' => match ($method) {
        'POST'   => api_subnet_tags_attach($db, $apiKey, $body),
        'DELETE' => api_subnet_tags_detach($db, $apiKey, to_int($_GET['subnet_id'] ?? 0), to_int($_GET['tag_id'] ?? 0)),
        default  => api_error(405, 'Method not allowed.'),
    },
    'address_tags' => match ($method) {
        'POST'   => api_address_tags_attach($db, $apiKey, $body),
        'DELETE' => api_address_tags_detach($db, $apiKey, to_int($_GET['address_id'] ?? 0), to_int($_GET['tag_id'] ?? 0)),
        default  => api_error(405, 'Method not allowed.'),
    },
    'search'          => $method === 'GET' ? api_search($db)            : api_error(405, 'Method not allowed.'),
    'audit'           => $method === 'GET' ? api_audit_log($db)         : api_error(405, 'Method not allowed.'),
    'unassigned'      => $method === 'GET' ? api_unassigned($db)        : api_error(405, 'Method not allowed.'),
    'scan_results'    => $method === 'GET' ? api_scan_results($db)      : api_error(405, 'Method not allowed.'),
    'scan_history'    => $method === 'GET' ? api_scan_history($db)      : api_error(405, 'Method not allowed.'),
    'scan_schedules'  => match ($method) {
        'GET'    => api_scan_schedules_list($db),
        'POST'   => api_scan_schedules_save($db, $apiKey, $body),
        'DELETE' => api_scan_schedules_delete($db, $apiKey),
        default  => api_error(405, 'Method not allowed.'),
    },
    'scan_run'        => $method === 'POST' ? api_scan_run($db, $apiKey) : api_error(405, 'Method not allowed.'),
    'subnet_stats'           => $method === 'GET' ? api_subnet_stats($db)           : api_error(405, 'Method not allowed.'),
    'utilization_snapshots'  => $method === 'GET' ? api_utilization_snapshots($db)  : api_error(405, 'Method not allowed.'),
    'devices' => match ($method) {
        'GET'    => api_devices($db),
        'POST'   => api_devices_create($db, $apiKey, $body),
        'PUT'    => api_devices_update($db, $apiKey, to_int($_GET['id'] ?? 0), $body),
        'DELETE' => api_devices_delete($db, $apiKey, to_int($_GET['id'] ?? 0)),
        default  => api_error(405, 'Method not allowed.'),
    },
    'device_interfaces' => match ($method) {
        'GET'    => api_device_interfaces($db),
        'POST'   => api_device_interfaces_create($db, $apiKey, $body),
        'PUT'    => api_device_interfaces_update($db, $apiKey, to_int($_GET['id'] ?? 0), $body),
        'DELETE' => api_device_interfaces_delete($db, $apiKey, to_int($_GET['id'] ?? 0)),
        default  => api_error(405, 'Method not allowed.'),
    },
    default      => api_error(404, 'Unknown resource. Valid: subnets, addresses, sites, vlans, vrfs, contacts, tags, subnet_tags, address_tags, history, search, audit, unassigned, scan_results, scan_history, scan_schedules, scan_run, subnet_stats, utilization_snapshots, devices, device_interfaces'),
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

/**
 * CodeRabbit m1 (PR #450): validate tag_ids[] before any DB write so a bad
 * ID returns 400 instead of 500 + an orphan entity. Returns the unique
 * positive int list. api_error()'s on unknown IDs.
 *
 * @param array<mixed> $rawTagIds
 * @return list<int>
 */
function api_validate_tag_ids(PDO $db, array $rawTagIds): array
{
    $tagIds = array_values(array_unique(array_filter(
        array_map(fn($v) => (int)to_str($v), $rawTagIds),
        fn($i) => $i > 0
    )));
    if ($tagIds === []) return [];
    $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
    $st = $db->prepare("SELECT id FROM tags WHERE id IN ($placeholders)");
    $st->execute($tagIds);
    /** @var list<int> $found */
    $found = array_map(fn($v) => (int)to_str($v), $st->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_diff($tagIds, $found));
    if ($missing !== []) {
        api_error(400, 'tag_ids contains unknown tag IDs: ' . implode(', ', $missing));
    }
    return $tagIds;
}

/**
 * #314: build a paginated response shape and emit the X-Total-Count header.
 *
 * Default flat shape (BC) wraps the items under $listKey alongside total/page/
 * limit. When the caller passes ?envelope=1, the shape switches to a generic
 * `{data, meta}` wrapper so paginating UIs can parse one shape across every
 * resource.
 *
 * @param array<int|string, mixed> $items
 * @param array<string, mixed>     $extra extra top-level keys to merge into the
 *                                        flat-shape response (e.g. address_id
 *                                        on the history endpoint). Ignored in
 *                                        envelope mode.
 * @return array<string, mixed>
 */
function api_paginated_response(string $listKey, array $items, int $total, int $page, int $limit, array $extra = []): array
{
    header('X-Total-Count: ' . $total);
    $envelope = isset($_GET['envelope']) && $_GET['envelope'] !== '0' && $_GET['envelope'] !== '';
    if ($envelope) {
        $pages = $limit > 0 ? (int)ceil($total / $limit) : 1;
        // CodeRabbit third sweep on PR #450: merge $extra into meta so the
        // envelope mode does not drop endpoint context (history loses
        // address_id/ip, unassigned loses subnet_id/cidr/total_unassigned).
        return [
            'data' => $items,
            'meta' => array_merge($extra, [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $limit,
                'pages'    => max(1, $pages),
            ]),
        ];
    }
    return array_merge($extra, [
        'total'  => $total,
        'page'   => $page,
        'limit'  => $limit,
        $listKey => $items,
    ]);
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

    // v2.11.0 #388: `SUM(status = 'used')` treats booleans as 0/1 on
    // SQLite/MySQL but Postgres raises "function sum(boolean) does not
    // exist". CASE WHEN works on all three engines.
    $countsJoin = $withCounts
        ? "LEFT JOIN (
               SELECT subnet_id,
                      SUM(CASE WHEN status = 'used'     THEN 1 ELSE 0 END) AS used_count,
                      SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) AS reserved_count,
                      SUM(CASE WHEN status = 'free'     THEN 1 ELSE 0 END) AS free_count
               FROM addresses
               GROUP BY subnet_id
           ) ac ON ac.subnet_id = s.id"
        : '';

    $baseSql = "SELECT s.id, s.cidr, s.ip_version, s.network, s.prefix,
                       s.description, s.notes, s.vlan_id, s.vlan_fk, s.site_id, s.vrf_id, s.alerts_enabled, s.created_at,
                       si.name AS site, v.vlan_id AS vlan_number, v.name AS vlan_name,
                       vr.name AS vrf_name$countsSelect
                FROM subnets s
                LEFT JOIN sites si ON si.id = s.site_id
                LEFT JOIN vlans v ON v.id = s.vlan_fk
                LEFT JOIN vrfs vr ON vr.id = s.vrf_id
                $countsJoin";

    if (isset($_GET['id'])) {
        $id = to_int($_GET['id']);
        $st = $db->prepare($baseSql . " WHERE s.id = :id");
        $st->execute([':id' => $id]);
        /** @var array<string, mixed>|false $row */
        $row = $st->fetch();
        if (!$row) api_error(404, 'Subnet not found.');
        $subnetTags = api_fetch_tags_for_ids($db, 'subnet', [$id]);
        api_json(['subnet' => fmt_subnet($row, $withCounts, $subnetTags[$id] ?? [])]);
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

    if (isset($_GET['vrf_id'])) {
        $v = to_int($_GET['vrf_id']);
        $where[]         = 's.vrf_id = :vrf_id';
        $params[':vrf_id'] = $v;
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

    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM subnets s LEFT JOIN vlans v ON v.id = s.vlan_fk $tagJoin $whereSql");
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

    $items = array_map(fn($r) => fmt_subnet($r, $withCounts, $tagsBySubnet[to_int($r['id'])] ?? []), $rawSubnets);
    api_json(api_paginated_response('subnets', $items, $total, $page, $limit));
}

/**
 * @param array<string, mixed> $r
 * @param list<array{id: int, name: string, colour: string}> $tags
 * @return array<string, mixed>
 */
function fmt_subnet(array $r, bool $withCounts = false, array $tags = []): array
{
    $vlanFk = $r['vlan_fk'] !== null ? to_int($r['vlan_fk']) : null;
    $vrfId  = $r['vrf_id']  !== null ? to_int($r['vrf_id'])  : null;
    $out = [
        'id'          => to_int($r['id']),
        'cidr'        => $r['cidr'],
        'ip_version'  => to_int($r['ip_version']),
        'network'     => $r['network'],
        'prefix'      => to_int($r['prefix']),
        'description' => to_str($r['description']),
        'notes'       => to_str($r['notes'] ?? ''),
        'vlan_id'     => $r['vlan_id'] !== null ? to_int($r['vlan_id']) : null,
        'vlan_fk'     => $vlanFk,
        'vlan_name'   => $vlanFk !== null ? to_str($r['vlan_name'] ?? '') : null,
        'vrf_id'      => $vrfId,
        'vrf_name'    => $vrfId !== null ? to_str($r['vrf_name'] ?? '') : null,
        'site_id'     => $r['site_id'] !== null ? to_int($r['site_id']) : null,
        'site'        => $r['site'],
        'tags'           => $tags,
        'alerts_enabled' => (bool) to_int($r['alerts_enabled'] ?? 1),
        'created_at'     => $r['created_at'],
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

    if (isset($_GET['contact_id'])) {
        $where[]             = 'a.owner_contact_id = :contact_id';
        $params[':contact_id'] = to_int($_GET['contact_id']);
    }
    if (isset($_GET['expired']) && to_int($_GET['expired']) === 1) {
        $where[] = "(a.expires_at IS NOT NULL AND a.expires_at < :exp_today)";
        $params[':exp_today'] = date('Y-m-d');
    }
    if (isset($_GET['expiring_days'])) {
        $expDays = max(1, min(365, to_int($_GET['expiring_days'])));
        $where[] = "(a.expires_at IS NOT NULL AND a.expires_at >= :exp_from AND a.expires_at < :exp_to)";
        $params[':exp_from'] = date('Y-m-d');
        $params[':exp_to']   = date('Y-m-d', (int)strtotime("+{$expDays} days"));
    }

    $joins   = ($joinSite ? ' JOIN subnets s ON s.id = a.subnet_id' : '');
    $joins  .= ($joinTag  ? ' JOIN address_tags atf ON atf.address_id = a.id JOIN tags tf ON tf.id = atf.tag_id' : '');
    $joins  .= ' LEFT JOIN contacts co ON co.id = a.owner_contact_id';
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
                   a.status, a.note, a.grp, a.mac, a.expires_at, a.created_at, a.updated_at,
                   a.owner_contact_id, co.name AS owner_contact_name
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
        $contactId = $r['owner_contact_id'] !== null ? to_int($r['owner_contact_id']) : null;
        return [
            'id'                 => $id,
            'subnet_id'          => to_int($r['subnet_id']),
            'ip'                 => $r['ip'],
            'hostname'           => to_str($r['hostname']),
            'owner'              => to_str($r['owner']),
            'owner_contact_id'   => $contactId,
            'owner_contact_name' => $contactId !== null ? to_str($r['owner_contact_name'] ?? '') : null,
            'status'             => $r['status'],
            'note'               => to_str($r['note']),
            'group'              => to_str($r['grp']),
            'mac'                => to_str($r['mac']),
            'expires_at'         => isset($r['expires_at']) ? to_str($r['expires_at']) : null,
            'tags'               => $tagsByAddr[$id] ?? [],
            'created_at'         => $r['created_at'],
            'updated_at'         => $r['updated_at'],
        ];
    }, $rawRows);

    api_json(api_paginated_response('addresses', $rows, $total, $page, $limit));
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
        api_json(['site' => fmt_site($row)]);
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
        api_json(['vlan' => fmt_vlan($row)]);
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

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_vlans_create(PDO $db, array $apiKey, array $body): never
{

    $vlanId = to_int($body['vlan_id'] ?? 0);
    $name   = trim(to_str($body['name']   ?? ''));
    $desc   = trim(to_str($body['description'] ?? ''));
    $siteId = isset($body['site_id']) ? to_int($body['site_id']) : null;

    if ($vlanId < 1 || $vlanId > 4094) api_error(400, 'vlan_id must be between 1 and 4094.');
    if ($name === '') api_error(400, 'name is required.');

    $dup = $db->prepare("SELECT id FROM vlans WHERE vlan_id = :vid AND " . ipam_dialect()->null_safe_eq("site_id", ":sid") . "");
    $dup->execute([':vid' => $vlanId, ':sid' => $siteId]);
    if ($dup->fetch()) api_error(409, 'A VLAN with this vlan_id already exists in this site.');

    $db->prepare("INSERT INTO vlans (vlan_id, name, description, site_id) VALUES (:vid, :n, :d, :sid)")
       ->execute([':vid' => $vlanId, ':n' => $name, ':d' => $desc, ':sid' => $siteId]);
    $newId = ipam_last_insert_id($db, 'vlans');
    api_audit($db, $apiKey, 'vlan.create', 'vlan', $newId, "vlan_id=$vlanId name=$name");
    http_response_code(201);
    api_json(['id' => $newId]);
}

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_vlans_update(PDO $db, array $apiKey, int $id, array $body): never
{

    if ($id <= 0) api_error(400, 'id is required as a query parameter.');
    $st = $db->prepare("SELECT id, vlan_id, name, description, site_id FROM vlans WHERE id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string, mixed>|false $vlan */
    $vlan = $st->fetch();
    if (!$vlan) api_error(404, 'VLAN not found.');

    $vlanId = array_key_exists('vlan_id', $body) ? to_int($body['vlan_id']) : to_int($vlan['vlan_id']);
    $name   = array_key_exists('name', $body) ? trim(to_str($body['name'])) : to_str($vlan['name']);
    $desc   = array_key_exists('description', $body) ? trim(to_str($body['description'])) : to_str($vlan['description']);
    $siteId = array_key_exists('site_id', $body)
        ? ($body['site_id'] !== null ? to_int($body['site_id']) : null)
        : ($vlan['site_id'] !== null ? to_int($vlan['site_id']) : null);

    if ($vlanId < 1 || $vlanId > 4094) api_error(400, 'vlan_id must be between 1 and 4094.');
    if ($name === '') api_error(400, 'name cannot be empty.');

    $dup = $db->prepare("SELECT id FROM vlans WHERE vlan_id = :vid AND " . ipam_dialect()->null_safe_eq("site_id", ":sid") . " AND id != :id");
    $dup->execute([':vid' => $vlanId, ':sid' => $siteId, ':id' => $id]);
    if ($dup->fetch()) api_error(409, 'A VLAN with this vlan_id already exists in this site.');

    $db->prepare("UPDATE vlans SET vlan_id = :vid, name = :n, description = :d, site_id = :sid WHERE id = :id")
       ->execute([':vid' => $vlanId, ':n' => $name, ':d' => $desc, ':sid' => $siteId, ':id' => $id]);
    api_audit($db, $apiKey, 'vlan.update', 'vlan', $id, "vlan_id=$vlanId name=$name");
    api_json(['id' => $id]);
}

/** @param array<string, mixed> $apiKey */
function api_vlans_delete(PDO $db, array $apiKey, int $id): never
{

    if ($id <= 0) api_error(400, 'id is required as a query parameter.');
    $st = $db->prepare("SELECT id FROM vlans WHERE id = :id");
    $st->execute([':id' => $id]);
    if (!$st->fetch()) api_error(404, 'VLAN not found.');

    $db->prepare("DELETE FROM vlans WHERE id = :id")->execute([':id' => $id]);
    api_audit($db, $apiKey, 'vlan.delete', 'vlan', $id, "id=$id");
    http_response_code(204);
    api_json([]);
}

function api_vrfs(PDO $db): never
{
    if (isset($_GET['id'])) {
        $id = to_int($_GET['id']);
        $st = $db->prepare("SELECT id, name, description, rd, created_at, updated_at FROM vrfs WHERE id = :id");
        $st->execute([':id' => $id]);
        /** @var array<string, mixed>|false $row */
        $row = $st->fetch();
        if (!$row) api_error(404, 'VRF not found.');
        api_json(['vrf' => fmt_vrf($row)]);
    }

    $st = $db->prepare("SELECT id, name, description, rd, created_at, updated_at FROM vrfs ORDER BY name");
    $st->execute();
    api_json(['vrfs' => array_map('fmt_vrf', $st->fetchAll())]);
}

/**
 * @param array<string, mixed> $r
 * @return array<string, mixed>
 */
function fmt_vrf(array $r): array
{
    return [
        'id'          => to_int($r['id']),
        'name'        => to_str($r['name']),
        'description' => to_str($r['description']),
        'rd'          => to_str($r['rd']),
        'created_at'  => $r['created_at'],
        'updated_at'  => $r['updated_at'],
    ];
}

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_vrfs_create(PDO $db, array $apiKey, array $body): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    $name = trim(to_str($body['name'] ?? ''));
    $desc = trim(to_str($body['description'] ?? ''));
    $rd   = trim(to_str($body['rd'] ?? ''));
    if ($name === '') api_error(400, 'name is required.');
    try {
        $st = $db->prepare("INSERT INTO vrfs (name, description, rd) VALUES (:n,:d,:rd)");
        $st->execute([':n' => $name, ':d' => $desc, ':rd' => $rd]);
        $newId = ipam_last_insert_id($db, 'vrfs');
        audit($db, 'vrf.create', 'vrf', $newId, "name=$name");
    } catch (PDOException $e) {
        api_error(409, 'A VRF with that name already exists.');
    }
    http_response_code(201);
    $st = $db->prepare("SELECT id, name, description, rd, created_at, updated_at FROM vrfs WHERE id = :id");
    $st->execute([':id' => $newId]);
    /** @var array<string, mixed>|false $row */
    $row = $st->fetch();
    api_json(fmt_vrf($row ?: []));
}

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_vrfs_update(PDO $db, array $apiKey, int $id, array $body): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    if ($id <= 0) api_error(400, 'id is required.');
    $name = trim(to_str($body['name'] ?? ''));
    $desc = trim(to_str($body['description'] ?? ''));
    $rd   = trim(to_str($body['rd'] ?? ''));
    if ($name === '') api_error(400, 'name is required.');
    $checkSt = $db->prepare("SELECT id FROM vrfs WHERE id = :id");
    $checkSt->execute([':id' => $id]);
    if (!$checkSt->fetch()) api_error(404, 'VRF not found.');
    try {
        $st = $db->prepare("UPDATE vrfs SET name=:n, description=:d, rd=:rd, updated_at=" . ipam_dialect()->now() . " WHERE id=:id");
        $st->execute([':n' => $name, ':d' => $desc, ':rd' => $rd, ':id' => $id]);
        audit($db, 'vrf.update', 'vrf', $id, "name=$name");
    } catch (PDOException $e) {
        api_error(409, 'A VRF with that name already exists.');
    }
    $st = $db->prepare("SELECT id, name, description, rd, created_at, updated_at FROM vrfs WHERE id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string, mixed>|false $row */
    $row = $st->fetch();
    api_json(fmt_vrf($row ?: []));
}

/** @param array<string, mixed> $apiKey */
function api_vrfs_delete(PDO $db, array $apiKey, int $id): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    if ($id <= 0) api_error(400, 'id is required.');
    $countSt = $db->prepare("SELECT COUNT(*) FROM subnets WHERE vrf_id = :id");
    $countSt->execute([':id' => $id]);
    $count = (int)$countSt->fetchColumn();
    if ($count > 0) api_error(409, "Cannot delete: $count subnet(s) are assigned to this VRF.");
    $nameSt = $db->prepare("SELECT name FROM vrfs WHERE id = :id");
    $nameSt->execute([':id' => $id]);
    /** @var array<string, mixed>|false $row */
    $row = $nameSt->fetch();
    if (!$row) api_error(404, 'VRF not found.');
    $db->prepare("DELETE FROM vrfs WHERE id = :id")->execute([':id' => $id]);
    audit($db, 'vrf.delete', 'vrf', $id, 'name=' . to_str($row['name']));
    http_response_code(204);
    api_json(['deleted' => true]);
}

function api_contacts(PDO $db): never
{
    if (isset($_GET['id'])) {
        $id = to_int($_GET['id']);
        $st = $db->prepare("SELECT id, name, email, phone, org, note, created_at, updated_at FROM contacts WHERE id = :id");
        $st->execute([':id' => $id]);
        /** @var array<string, mixed>|false $row */
        $row = $st->fetch();
        if (!$row) api_error(404, 'Contact not found.');
        api_json(['contact' => fmt_contact($row)]);
    }

    $where  = [];
    $params = [];

    // ?q= fuzzy name/email search
    if (isset($_GET['q'])) {
        $q = trim(to_str($_GET['q']));
        if ($q !== '') {
            $like            = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $where[]         = '(name LIKE :q OR email LIKE :q2)';
            $params[':q']    = $like;
            $params[':q2']   = $like;
        }
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $page   = max(1, to_int($_GET['page']  ?? 1));
    $limit  = max(1, min(200, to_int($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM contacts $whereSql");
    $cntSt->execute($params);
    /** @var array<string, mixed>|false $cntRow */
    $cntRow = $cntSt->fetch();
    $total = is_array($cntRow) ? to_int($cntRow['c']) : 0;

    $st = $db->prepare("SELECT id, name, email, phone, org, note, created_at, updated_at FROM contacts $whereSql ORDER BY name LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v2) {
        $st->bindValue($k, $v2, PDO::PARAM_STR);
    }
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    api_json(api_paginated_response('contacts', array_map('fmt_contact', $st->fetchAll()), $total, $page, $limit));
}

/**
 * @param array<string, mixed> $r
 * @return array<string, mixed>
 */
function fmt_contact(array $r): array
{
    return [
        'id'         => to_int($r['id']),
        'name'       => to_str($r['name']),
        'email'      => to_str($r['email']),
        'phone'      => to_str($r['phone']),
        'org'        => to_str($r['org']),
        'note'       => to_str($r['note']),
        'created_at' => $r['created_at'],
        'updated_at' => $r['updated_at'],
    ];
}

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_contacts_create(PDO $db, array $apiKey, array $body): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    $name  = trim(to_str($body['name']  ?? ''));
    $email = trim(to_str($body['email'] ?? ''));
    $phone = trim(to_str($body['phone'] ?? ''));
    $org   = trim(to_str($body['org']   ?? ''));
    $note  = trim(to_str($body['note']  ?? ''));
    if ($name === '') api_error(400, 'name is required.');
    $st = $db->prepare("INSERT INTO contacts (name, email, phone, org, note) VALUES (:n,:e,:p,:o,:nt)");
    $st->execute([':n' => $name, ':e' => $email, ':p' => $phone, ':o' => $org, ':nt' => $note]);
    $newId = ipam_last_insert_id($db, 'contacts');
    audit($db, 'contact.create', 'contact', $newId, "name=$name");
    http_response_code(201);
    $st = $db->prepare("SELECT id, name, email, phone, org, note, created_at, updated_at FROM contacts WHERE id = :id");
    $st->execute([':id' => $newId]);
    /** @var array<string, mixed>|false $row */
    $row = $st->fetch();
    api_json(fmt_contact($row ?: []));
}

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_contacts_update(PDO $db, array $apiKey, int $id, array $body): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    if ($id <= 0) api_error(400, 'id is required.');
    $name  = trim(to_str($body['name']  ?? ''));
    $email = trim(to_str($body['email'] ?? ''));
    $phone = trim(to_str($body['phone'] ?? ''));
    $org   = trim(to_str($body['org']   ?? ''));
    $note  = trim(to_str($body['note']  ?? ''));
    if ($name === '') api_error(400, 'name is required.');
    $checkSt = $db->prepare("SELECT id FROM contacts WHERE id = :id");
    $checkSt->execute([':id' => $id]);
    if (!$checkSt->fetch()) api_error(404, 'Contact not found.');
    $st = $db->prepare("UPDATE contacts SET name=:n, email=:e, phone=:p, org=:o, note=:nt, updated_at=" . ipam_dialect()->now() . " WHERE id=:id");
    $st->execute([':n' => $name, ':e' => $email, ':p' => $phone, ':o' => $org, ':nt' => $note, ':id' => $id]);
    audit($db, 'contact.update', 'contact', $id, "name=$name");
    $st = $db->prepare("SELECT id, name, email, phone, org, note, created_at, updated_at FROM contacts WHERE id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string, mixed>|false $row */
    $row = $st->fetch();
    api_json(fmt_contact($row ?: []));
}

/** @param array<string, mixed> $apiKey */
function api_contacts_delete(PDO $db, array $apiKey, int $id): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    if ($id <= 0) api_error(400, 'id is required.');
    $nameSt = $db->prepare("SELECT name FROM contacts WHERE id = :id");
    $nameSt->execute([':id' => $id]);
    /** @var array<string, mixed>|false $row */
    $row = $nameSt->fetch();
    if (!$row) api_error(404, 'Contact not found.');
    $db->prepare("DELETE FROM contacts WHERE id = :id")->execute([':id' => $id]);
    audit($db, 'contact.delete', 'contact', $id, 'name=' . to_str($row['name']));
    http_response_code(204);
    api_json(['deleted' => true]);
}

// ---- #310: write API for tags + association endpoints ----

/**
 * @param array<string, mixed> $r
 * @return array<string, mixed>
 */
function fmt_tag(array $r): array
{
    return [
        'id'         => to_int($r['id']),
        'name'       => to_str($r['name']),
        'colour'     => to_str($r['colour']),
        'created_at' => to_str($r['created_at']),
    ];
}

function api_tags_list(PDO $db): never
{
    if (isset($_GET['id'])) {
        $st = $db->prepare("SELECT id, name, colour, created_at FROM tags WHERE id = :id");
        $st->execute([':id' => to_int($_GET['id'])]);
        /** @var array<string, mixed>|false $row */
        $row = $st->fetch();
        if (!$row) api_error(404, 'Tag not found.');
        api_json(['tag' => fmt_tag($row)]);
    }
    $st = $db->query("SELECT id, name, colour, created_at FROM tags ORDER BY name");
    $rows = $st !== false ? $st->fetchAll() : [];
    api_json(['tags' => array_map('fmt_tag', $rows)]);
}

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_tags_create(PDO $db, array $apiKey, array $body): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    $name   = trim(to_str($body['name'] ?? ''));
    $colour = trim(to_str($body['colour'] ?? '#6c757d'));
    if ($name === '') api_error(400, 'name is required.');
    if (mb_strlen($name) > 50) api_error(400, 'name must be 50 characters or fewer.');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) api_error(400, 'colour must be a 6-digit hex string like #6c757d.');

    $dup = $db->prepare("SELECT id FROM tags WHERE name = :n");
    $dup->execute([':n' => $name]);
    if ($dup->fetch()) api_error(409, 'A tag with this name already exists.');

    $db->prepare("INSERT INTO tags (name, colour) VALUES (:n, :c)")
       ->execute([':n' => $name, ':c' => $colour]);
    $newId = ipam_last_insert_id($db, 'tags');
    api_audit($db, $apiKey, 'tag.create', 'tag', $newId, "name={$name}");
    http_response_code(201);
    api_json(['id' => $newId, 'name' => $name, 'colour' => $colour]);
}

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_tags_update(PDO $db, array $apiKey, int $id, array $body): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    if ($id <= 0) api_error(400, 'id is required.');

    $st = $db->prepare("SELECT id, name, colour FROM tags WHERE id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string, mixed>|false $tag */
    $tag = $st->fetch();
    if (!$tag) api_error(404, 'Tag not found.');

    $name   = array_key_exists('name', $body) ? trim(to_str($body['name'])) : to_str($tag['name']);
    $colour = array_key_exists('colour', $body) ? trim(to_str($body['colour'])) : to_str($tag['colour']);

    if ($name === '') api_error(400, 'name is required.');
    if (mb_strlen($name) > 50) api_error(400, 'name must be 50 characters or fewer.');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) api_error(400, 'colour must be a 6-digit hex string like #6c757d.');

    if ($name !== to_str($tag['name'])) {
        $dup = $db->prepare("SELECT id FROM tags WHERE name = :n AND id != :id");
        $dup->execute([':n' => $name, ':id' => $id]);
        if ($dup->fetch()) api_error(409, 'Another tag with this name already exists.');
    }

    $db->prepare("UPDATE tags SET name = :n, colour = :c WHERE id = :id")
       ->execute([':n' => $name, ':c' => $colour, ':id' => $id]);
    api_audit($db, $apiKey, 'tag.update', 'tag', $id, "name={$name}");
    api_json(['id' => $id, 'name' => $name, 'colour' => $colour]);
}

/** @param array<string, mixed> $apiKey */
function api_tags_delete(PDO $db, array $apiKey, int $id): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    if ($id <= 0) api_error(400, 'id is required.');

    $st = $db->prepare("SELECT name FROM tags WHERE id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string, mixed>|false $row */
    $row = $st->fetch();
    if (!$row) api_error(404, 'Tag not found.');

    // ON DELETE CASCADE on the join tables removes attachments automatically.
    $db->prepare("DELETE FROM tags WHERE id = :id")->execute([':id' => $id]);
    api_audit($db, $apiKey, 'tag.delete', 'tag', $id, 'name=' . to_str($row['name']));
    http_response_code(204);
    exit;
}

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_subnet_tags_attach(PDO $db, array $apiKey, array $body): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    $subnetId = to_int($body['subnet_id'] ?? 0);
    $tagId    = to_int($body['tag_id'] ?? 0);
    if ($subnetId <= 0 || $tagId <= 0) api_error(400, 'subnet_id and tag_id are required.');

    $sChk = $db->prepare("SELECT id FROM subnets WHERE id = :id");
    $sChk->execute([':id' => $subnetId]);
    if (!$sChk->fetch()) api_error(404, 'Subnet not found.');

    $tChk = $db->prepare("SELECT id FROM tags WHERE id = :id");
    $tChk->execute([':id' => $tagId]);
    if (!$tChk->fetch()) api_error(404, 'Tag not found.');

    // CodeRabbit third sweep on PR #450: only audit + return 201 on a real
    // insertion. INSERT OR IGNORE silently no-ops on duplicates; without
    // the rowCount() check the audit log fills with phantom tag.attach
    // entries and the client gets a misleading 201 for a no-op.
    $st = $db->prepare("INSERT INTO subnet_tags (subnet_id, tag_id) VALUES (:s, :t) " . ipam_dialect()->upsert_or_ignore("subnet_tags", ["subnet_id", "tag_id"]) . "");
    $st->execute([':s' => $subnetId, ':t' => $tagId]);
    if ($st->rowCount() > 0) {
        api_audit($db, $apiKey, 'tag.attach', 'subnet', $subnetId, "tag_id={$tagId}");
        http_response_code(201);
    } else {
        http_response_code(200);
    }
    api_json(['subnet_id' => $subnetId, 'tag_id' => $tagId]);
}

/** @param array<string, mixed> $apiKey */
function api_subnet_tags_detach(PDO $db, array $apiKey, int $subnetId, int $tagId): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    if ($subnetId <= 0 || $tagId <= 0) api_error(400, 'subnet_id and tag_id are required.');
    // CodeRabbit second sweep on PR #450: only audit when DELETE actually
    // removed a row, otherwise calling DELETE with bogus IDs creates
    // misleading tag.detach entries in the audit log.
    $st = $db->prepare("DELETE FROM subnet_tags WHERE subnet_id = :s AND tag_id = :t");
    $st->execute([':s' => $subnetId, ':t' => $tagId]);
    if ($st->rowCount() > 0) {
        api_audit($db, $apiKey, 'tag.detach', 'subnet', $subnetId, "tag_id={$tagId}");
    }
    http_response_code(204);
    exit;
}

/**
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_address_tags_attach(PDO $db, array $apiKey, array $body): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    $addressId = to_int($body['address_id'] ?? 0);
    $tagId     = to_int($body['tag_id'] ?? 0);
    if ($addressId <= 0 || $tagId <= 0) api_error(400, 'address_id and tag_id are required.');

    $aChk = $db->prepare("SELECT id FROM addresses WHERE id = :id");
    $aChk->execute([':id' => $addressId]);
    if (!$aChk->fetch()) api_error(404, 'Address not found.');

    $tChk = $db->prepare("SELECT id FROM tags WHERE id = :id");
    $tChk->execute([':id' => $tagId]);
    if (!$tChk->fetch()) api_error(404, 'Tag not found.');

    // CodeRabbit third sweep on PR #450: gate audit + 201 on a real insert.
    $st = $db->prepare("INSERT INTO address_tags (address_id, tag_id) VALUES (:a, :t) " . ipam_dialect()->upsert_or_ignore("address_tags", ["address_id", "tag_id"]) . "");
    $st->execute([':a' => $addressId, ':t' => $tagId]);
    if ($st->rowCount() > 0) {
        api_audit($db, $apiKey, 'tag.attach', 'address', $addressId, "tag_id={$tagId}");
        http_response_code(201);
    } else {
        http_response_code(200);
    }
    api_json(['address_id' => $addressId, 'tag_id' => $tagId]);
}

/** @param array<string, mixed> $apiKey */
function api_address_tags_detach(PDO $db, array $apiKey, int $addressId, int $tagId): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    if ($addressId <= 0 || $tagId <= 0) api_error(400, 'address_id and tag_id are required.');
    // CodeRabbit second sweep on PR #450: only audit on a real deletion.
    $st = $db->prepare("DELETE FROM address_tags WHERE address_id = :a AND tag_id = :t");
    $st->execute([':a' => $addressId, ':t' => $tagId]);
    if ($st->rowCount() > 0) {
        api_audit($db, $apiKey, 'tag.detach', 'address', $addressId, "tag_id={$tagId}");
    }
    http_response_code(204);
    exit;
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

    api_json(api_paginated_response('history', $rows, $total, $page, $limit, [
        'address_id' => $addressId,
        'ip'         => $addr ? to_str($addr['ip']) : null,
    ]));
}

// ---- Search endpoint ----

function api_search(PDO $db): never
{
    $q = substr(trim(to_str($_GET['q'] ?? '')), 0, 500);
    if ($q === '') api_error(400, 'q parameter is required.');

    // Each LIKE clause gets its own distinct placeholder (:q1..:q5) so the
    // query works under PDO's native prepared statements on every engine.
    // MySQL's PDO in non-emulated mode rejects reusing a single named
    // placeholder across multiple positions; SQLite allows it but we keep
    // the same shape for consistency. All five bindings hold the same
    // pre-escaped value.
    $where  = [
        "(a.ip LIKE :q1 ESCAPE '!' OR a.hostname LIKE :q2 ESCAPE '!' "
        . "OR a.owner LIKE :q3 ESCAPE '!' OR a.note LIKE :q4 ESCAPE '!' "
        . "OR a.grp LIKE :q5 ESCAPE '!')"
    ];
    $qLike  = '%' . like_escape($q) . '%';
    $params = [
        ':q1' => $qLike,
        ':q2' => $qLike,
        ':q3' => $qLike,
        ':q4' => $qLike,
        ':q5' => $qLike,
    ];

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

    api_json(api_paginated_response('results', $rows, $total, $page, $limit));
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

    api_json(api_paginated_response('entries', $rows, $total, $page, $limit));
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

    api_json(api_paginated_response('unassigned', array_slice($all, $offset, $limit), $total, $page, $limit, [
        'subnet_id'        => $subnetId,
        'cidr'             => $subnet['cidr'],
        'total_unassigned' => $total,
        'pages'            => $pages,
    ]));
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

        // #410/#388: bind ip_bin via ipam_bind_binary() — see the
        // single-address create path below for the full rationale.
        $dupSt = $db->prepare("SELECT id FROM addresses WHERE subnet_id = :sid AND ip_bin = :b");
        $dupSt->bindValue(':sid', $subnetId, PDO::PARAM_INT);
        ipam_bind_binary($dupSt, ':b', to_str($n['bin']));
        $dupSt->execute();
        if ($dupSt->fetch()) { $results[] = ['success' => false, 'error' => 'Address already exists in this subnet.']; continue; }

        try {
            $bulkIns = $db->prepare(
                "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, grp, mac, expires_at, status)
                 VALUES (:sid, :ip, :b, :hn, :ow, :nt, :grp, :mac, :exp, :st)"
            );
            $bulkIns->bindValue(':sid', $subnetId, PDO::PARAM_INT);
            $bulkIns->bindValue(':ip',  $n['ip']);
            ipam_bind_binary($bulkIns, ':b', to_str($n['bin']));
            $bulkIns->bindValue(':hn',  $hostname);
            $bulkIns->bindValue(':ow',  $owner);
            $bulkIns->bindValue(':nt',  $note);
            $bulkIns->bindValue(':grp', $grp);
            $bulkIns->bindValue(':mac', $mac);
            $bulkIns->bindValue(':exp', $expiresAt !== '' ? $expiresAt : null,
                $expiresAt !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $bulkIns->bindValue(':st',  $status);
            $bulkIns->execute();
            $newId = ipam_last_insert_id($db, 'addresses');

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
        $notes       = trim(to_str($item['notes'] ?? ''));
        $siteId      = isset($item['site_id']) ? to_int($item['site_id']) : null;
        $vlanId      = isset($item['vlan_id']) ? to_int($item['vlan_id']) : null;
        $vrfId       = isset($item['vrf_id'])  ? to_int($item['vrf_id'])  : null;

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
        $dupSt = $db->prepare("SELECT id FROM subnets WHERE cidr = :cidr AND " . ipam_dialect()->null_safe_eq("vrf_id", ":vrf") . "");
        $dupSt->execute([':cidr' => $normalized, ':vrf' => $vrfId]);
        if ($dupSt->fetch()) { $results[] = ['success' => false, 'error' => "Subnet $normalized already exists."]; continue; }

        $inheritedSiteId = find_parent_site_id($db, $normalized, null, $vrfId);
        if ($inheritedSiteId !== null) $siteId = $inheritedSiteId;

        try {
                        // #410/#388: bind network_bin via ipam_bind_binary() — see the
            // single-subnet create path below for the full rationale.
            $bulkIns = $db->prepare(
                "INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, notes, site_id, vlan_id, vrf_id)
                 VALUES (:cidr, :ver, :net, :nbin, :pfx, :desc, :notes, :sid, :vlan, :vrf)"
            );
            $bulkIns->bindValue(':cidr',  $normalized);
            $bulkIns->bindValue(':ver',   $p['version'], PDO::PARAM_INT);
            $bulkIns->bindValue(':net',   $p['network']);
            ipam_bind_binary($bulkIns, ':nbin', to_str($p['net_bin']));
            $bulkIns->bindValue(':pfx',   $p['prefix'],  PDO::PARAM_INT);
            $bulkIns->bindValue(':desc',  $description);
            $bulkIns->bindValue(':notes', $notes);
            $bulkIns->bindValue(':sid',   $siteId,       $siteId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $bulkIns->bindValue(':vlan',  $vlanId,       $vlanId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $bulkIns->bindValue(':vrf',   $vrfId,        $vrfId  === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $bulkIns->execute();
            $newId = ipam_last_insert_id($db, 'subnets');

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
    $name     = trim(to_str($body['name'] ?? ''));
    $desc     = trim(to_str($body['description'] ?? ''));
    $parentId = array_key_exists('parent_id', $body) && $body['parent_id'] !== null
        ? to_int($body['parent_id']) : null;
    if ($name === '') api_error(400, 'name is required.');

    if ($parentId !== null) {
        $pSt = $db->prepare("SELECT id FROM sites WHERE id = :id");
        $pSt->execute([':id' => $parentId]);
        if (!$pSt->fetch()) api_error(400, 'parent_id refers to a non-existent site.');
    }

    $dup = $db->prepare("SELECT id FROM sites WHERE name = :n");
    $dup->execute([':n' => $name]);
    if ($dup->fetch()) api_error(409, 'A site with this name already exists.');

    $db->prepare("INSERT INTO sites (name, description, parent_id) VALUES (:n, :d, :p)")
       ->execute([':n' => $name, ':d' => $desc, ':p' => $parentId]);
    $newId = ipam_last_insert_id($db, 'sites');
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
    $st = $db->prepare("SELECT id, name, description, parent_id FROM sites WHERE id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string, mixed>|false $site */
    $site = $st->fetch();
    if (!$site) api_error(404, 'Site not found.');

    $name     = array_key_exists('name', $body) ? trim(to_str($body['name'])) : to_str($site['name']);
    $desc     = array_key_exists('description', $body) ? trim(to_str($body['description'])) : to_str($site['description']);
    $parentId = array_key_exists('parent_id', $body)
        ? ($body['parent_id'] !== null ? to_int($body['parent_id']) : null)
        : ($site['parent_id'] !== null ? to_int($site['parent_id']) : null);
    if ($name === '') api_error(400, 'name cannot be empty.');

    if ($parentId !== null) {
        if ($parentId === $id) api_error(400, 'A site cannot be its own parent.');
        $pSt = $db->prepare("SELECT id FROM sites WHERE id = :id");
        $pSt->execute([':id' => $parentId]);
        if (!$pSt->fetch()) api_error(400, 'parent_id refers to a non-existent site.');
    }

    // Check for duplicate name (excluding self)
    $dupSt = $db->prepare("SELECT id FROM sites WHERE name = :n AND id != :id");
    $dupSt->execute([':n' => $name, ':id' => $id]);
    if ($dupSt->fetch()) api_error(409, 'A site with this name already exists.');

    $db->prepare("UPDATE sites SET name = :n, description = :d, parent_id = :p WHERE id = :id")
       ->execute([':n' => $name, ':d' => $desc, ':p' => $parentId, ':id' => $id]);
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
    $subnetId       = isset($body['subnet_id'])       ? to_int($body['subnet_id'])       : 0;
    $ip             = trim(to_str($body['ip'] ?? ''));
    // Validate tag_ids up-front (CodeRabbit m1 + third sweep / PR #450).
    if (array_key_exists('tag_ids', $body)) {
        if (!is_array($body['tag_ids'])) api_error(400, 'tag_ids must be an array.');
        $validatedTagIds = api_validate_tag_ids($db, $body['tag_ids']);
    } else {
        $validatedTagIds = null;
    }
    $hostname       = trim(to_str($body['hostname']        ?? ''));
    $owner          = trim(to_str($body['owner']           ?? ''));
    $ownerContactId = isset($body['owner_contact_id']) ? to_int($body['owner_contact_id']) : null;
    $note           = trim(to_str($body['note']            ?? ''));
    $grp            = trim(to_str($body['group']           ?? ''));
    $mac            = substr(trim(to_str($body['mac'] ?? '')), 0, 64);
    $expiresAt      = trim(to_str($body['expires_at'] ?? ''));
    if ($expiresAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
        $expiresAt = '';
    }
    $status = strtolower(trim(to_str($body['status'] ?? 'used')));

    if ($subnetId <= 0)  api_error(400, 'subnet_id is required.');
    if ($ip === '')      api_error(400, 'ip is required.');
    if (!in_array($status, ['used', 'reserved', 'free'], true)) {
        api_error(400, 'status must be used, reserved, or free.');
    }

    if ($ownerContactId !== null) {
        $cSt = $db->prepare("SELECT id FROM contacts WHERE id = :id");
        $cSt->execute([':id' => $ownerContactId]);
        if (!$cSt->fetch()) api_error(404, 'Contact not found.');
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

    // Check for duplicate. ip_bin is BYTEA on pgsql / VARBINARY on mysql /
    // BLOB on sqlite — must bind via ipam_bind_binary() so high bytes
    // round-trip the WHERE comparison correctly.
    $dupSt = $db->prepare("SELECT id FROM addresses WHERE subnet_id = :sid AND ip_bin = :b");
    $dupSt->bindValue(':sid', $subnetId, PDO::PARAM_INT);
    ipam_bind_binary($dupSt, ':b', to_str($n['bin']));
    $dupSt->execute();
    if ($dupSt->fetch()) api_error(409, 'An address record for this IP already exists in the subnet.');

    // #410/#388: bind ip_bin via ipam_bind_binary() (PARAM_LOB).
    $ins = $db->prepare(
        "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, owner_contact_id, note, grp, mac, expires_at, status)
         VALUES (:sid, :ip, :b, :hn, :ow, :cid, :nt, :grp, :mac, :exp, :st)"
    );
    $ins->bindValue(':sid', $subnetId, PDO::PARAM_INT);
    $ins->bindValue(':ip',  $n['ip']);
    ipam_bind_binary($ins, ':b', to_str($n['bin']));
    $ins->bindValue(':hn',  $hostname);
    $ins->bindValue(':ow',  $owner);
    $ins->bindValue(':cid', $ownerContactId,
        $ownerContactId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $ins->bindValue(':nt',  $note);
    $ins->bindValue(':grp', $grp);
    $ins->bindValue(':mac', $mac);
    $ins->bindValue(':exp', $expiresAt !== '' ? $expiresAt : null,
        $expiresAt !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $ins->bindValue(':st',  $status);
    $ins->execute();
    $newId = ipam_last_insert_id($db, 'addresses');

    // #310 + CodeRabbit m1: tag_ids[] validated up-front.
    if ($validatedTagIds !== null) {
        save_tags_for_entity($db, 'address', $newId, $validatedTagIds);
    }

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

    // Validate tag_ids up-front (CodeRabbit m1 + third sweep / PR #450).
    if (array_key_exists('tag_ids', $body)) {
        if (!is_array($body['tag_ids'])) api_error(400, 'tag_ids must be an array.');
        $validatedTagIds = api_validate_tag_ids($db, $body['tag_ids']);
    } else {
        $validatedTagIds = null;
    }
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

    // #310 + CodeRabbit m1: tag_ids[] validated up-front.
    if ($validatedTagIds !== null) {
        save_tags_for_entity($db, 'address', $id, $validatedTagIds);
    }

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
    $notes       = trim(to_str($body['notes']       ?? ''));
    $siteId      = isset($body['site_id']) ? to_int($body['site_id']) : null;
    // Validate tag_ids up-front so we never INSERT a subnet and then 500 on
    // the join (CodeRabbit m1 / PR #450). Reject non-array tag_ids
    // explicitly so clients fail fast instead of silently dropping the
    // requested tags (CodeRabbit third sweep). null = key absent (no-op).
    if (array_key_exists('tag_ids', $body)) {
        if (!is_array($body['tag_ids'])) api_error(400, 'tag_ids must be an array.');
        $validatedTagIds = api_validate_tag_ids($db, $body['tag_ids']);
    } else {
        $validatedTagIds = null;
    }
    $vlanId      = isset($body['vlan_id']) ? to_int($body['vlan_id']) : null;
    $vrfId       = isset($body['vrf_id'])  ? to_int($body['vrf_id'])  : null;

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

    if ($vrfId !== null) {
        $vrfSt = $db->prepare("SELECT id FROM vrfs WHERE id = :id");
        $vrfSt->execute([':id' => $vrfId]);
        if (!$vrfSt->fetch()) api_error(404, 'VRF not found.');
    }

    // Check duplicate CIDR within the same VRF (uses IS for NULL-safe comparison)
    $normalized = $p['network'] . '/' . $p['prefix'];
    $dupSt = $db->prepare("SELECT id FROM subnets WHERE cidr = :cidr AND " . ipam_dialect()->null_safe_eq("vrf_id", ":vrf") . "");
    $dupSt->execute([':cidr' => $normalized, ':vrf' => $vrfId]);
    if ($dupSt->fetch()) api_error(409, 'A subnet with this CIDR already exists.');

    // Inherit site from tightest parent if one exists
    $inheritedSiteId = find_parent_site_id($db, $normalized, null, $vrfId);
    if ($inheritedSiteId !== null) $siteId = $inheritedSiteId;

    // #410/#388: bind network_bin via ipam_bind_binary() (PARAM_LOB) so the
    // stored value has BLOB affinity on SQLite, round-trips high bytes
    // correctly through MySQL VARBINARY, and does not UTF-8-validate on
    // Postgres BYTEA. Positional execute() binds PARAM_STR, which Postgres
    // rejects on any network_bin containing a non-ASCII byte.
    $insSt = $db->prepare(
        "INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, notes, site_id, vlan_id, vrf_id)
         VALUES (:cidr, :ver, :net, :nbin, :pfx, :desc, :notes, :sid, :vlan, :vrf)"
    );
    $insSt->bindValue(':cidr',  $p['network'] . '/' . $p['prefix']);
    $insSt->bindValue(':ver',   $p['version'],  PDO::PARAM_INT);
    $insSt->bindValue(':net',   $p['network']);
    ipam_bind_binary($insSt, ':nbin', to_str($p['net_bin']));
    $insSt->bindValue(':pfx',   $p['prefix'],   PDO::PARAM_INT);
    $insSt->bindValue(':desc',  $description);
    $insSt->bindValue(':notes', $notes);
    $insSt->bindValue(':sid',   $siteId,        $siteId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $insSt->bindValue(':vlan',  $vlanId,        $vlanId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $insSt->bindValue(':vrf',   $vrfId,         $vrfId  === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $insSt->execute();
    $newId = ipam_last_insert_id($db, 'subnets');

    // #310 + CodeRabbit m1: tag_ids[] is validated up-front (see below) so
    // we already know every ID exists; the only DB work left is the join.
    if ($validatedTagIds !== null) {
        save_tags_for_entity($db, 'subnet', $newId, $validatedTagIds);
    }

    api_audit($db, $apiKey, 'subnet.create', 'subnet', $newId,
        "cidr={$p['network']}/{$p['prefix']}");

    // Non-blocking overlap warnings
    $overlaps = detect_subnet_overlaps($db, $normalized, null, $vrfId);
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

    $st = $db->prepare("SELECT id, cidr, description, notes, site_id, vlan_id, vlan_fk, vrf_id, alerts_enabled FROM subnets WHERE id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string, mixed>|false $subnet */
    $subnet = $st->fetch();
    if (!$subnet) api_error(404, 'Subnet not found.');

    $description = array_key_exists('description', $body) ? trim(to_str($body['description'])) : to_str($subnet['description']);
    $notes       = array_key_exists('notes', $body)       ? trim(to_str($body['notes']))       : to_str($subnet['notes'] ?? '');
    // Validate tag_ids up-front (CodeRabbit m1 + third sweep / PR #450).
    if (array_key_exists('tag_ids', $body)) {
        if (!is_array($body['tag_ids'])) api_error(400, 'tag_ids must be an array.');
        $validatedTagIds = api_validate_tag_ids($db, $body['tag_ids']);
    } else {
        $validatedTagIds = null;
    }
    $siteId = array_key_exists('site_id', $body)
        ? ($body['site_id'] !== null ? to_int($body['site_id']) : null)
        : ($subnet['site_id'] !== null ? to_int($subnet['site_id']) : null);
    $vlanId = array_key_exists('vlan_id', $body)
        ? ($body['vlan_id'] !== null ? to_int($body['vlan_id']) : null)
        : ($subnet['vlan_id'] !== null ? to_int($subnet['vlan_id']) : null);
    $vlanFk = array_key_exists('vlan_fk', $body)
        ? ($body['vlan_fk'] !== null ? to_int($body['vlan_fk']) : null)
        : ($subnet['vlan_fk'] !== null ? to_int($subnet['vlan_fk']) : null);
    $vrfId = array_key_exists('vrf_id', $body)
        ? ($body['vrf_id'] !== null ? to_int($body['vrf_id']) : null)
        : ($subnet['vrf_id'] !== null ? to_int($subnet['vrf_id']) : null);
    if (array_key_exists('alerts_enabled', $body)) {
        $alertsEnabled = filter_var($body['alerts_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($alertsEnabled === null) api_error(400, 'alerts_enabled must be a boolean.');
        $alertsEnabled = $alertsEnabled ? 1 : 0;
    } else {
        $alertsEnabled = to_int($subnet['alerts_enabled'] ?? 1);
    }

    if ($vlanId !== null && ($vlanId < 1 || $vlanId > 4094)) {
        api_error(400, 'vlan_id must be between 1 and 4094.');
    }

    if ($vlanFk !== null) {
        $vfSt = $db->prepare("SELECT id FROM vlans WHERE id = :id");
        $vfSt->execute([':id' => $vlanFk]);
        if (!$vfSt->fetch()) api_error(400, 'vlan_fk refers to a non-existent VLAN.');
    }

    if ($siteId !== null) {
        $siteSt = $db->prepare("SELECT id FROM sites WHERE id = :id");
        $siteSt->execute([':id' => $siteId]);
        if (!$siteSt->fetch()) api_error(404, 'Site not found.');
    }

    if ($vrfId !== null) {
        $vrfSt = $db->prepare("SELECT id FROM vrfs WHERE id = :id");
        $vrfSt->execute([':id' => $vrfId]);
        if (!$vrfSt->fetch()) api_error(400, 'vrf_id refers to a non-existent VRF.');
    }

    // Inherit site from tightest parent if one exists (VRF-scoped)
    $inheritedSiteId = find_parent_site_id($db, to_str($subnet['cidr']), $id, $vrfId);
    if ($inheritedSiteId !== null) $siteId = $inheritedSiteId;

    $db->prepare(
        "UPDATE subnets SET description = :desc, notes = :notes, site_id = :sid, vlan_id = :vlan, vlan_fk = :vfk, vrf_id = :vrf, alerts_enabled = :ae WHERE id = :id"
    )->execute([':desc' => $description, ':notes' => $notes, ':sid' => $siteId, ':vlan' => $vlanId, ':vfk' => $vlanFk, ':vrf' => $vrfId, ':ae' => $alertsEnabled, ':id' => $id]);
    if ($alertsEnabled === 0) {
        $db->prepare("DELETE FROM alert_state WHERE subnet_id = :sid")->execute([':sid' => $id]);
    }

    // #310 + CodeRabbit m1: tag_ids[] validated up-front; null = no-op.
    if ($validatedTagIds !== null) {
        save_tags_for_entity($db, 'subnet', $id, $validatedTagIds);
    }

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

// ---------------------------------------------------------------------------
// Scan API endpoints — v2.3.0
// ---------------------------------------------------------------------------

/** GET ?resource=scan_results&subnet_id=N — latest scan run for a subnet */
function api_scan_results(PDO $db): never
{
    $subnetId = to_int($_GET['subnet_id'] ?? 0);
    if ($subnetId <= 0) api_error(400, 'subnet_id is required.');

    // Return results from the most recent scan run, defined as anything
    // within 60 seconds of the latest scanned_at timestamp for this subnet.
    // Compute the cutoff in PHP so the query stays engine-agnostic — the
    // SQLite-specific `datetime(col, '-N seconds')` idiom is not portable.
    $maxSt = $db->prepare("SELECT MAX(scanned_at) AS m FROM scan_results WHERE subnet_id = :sid");
    $maxSt->execute([':sid' => $subnetId]);
    /** @var array<string, mixed>|false $maxRow */
    $maxRow = $maxSt->fetch();
    $maxAt  = is_array($maxRow) ? to_str($maxRow['m'] ?? '') : '';
    if ($maxAt === '') {
        api_json([]);
    }
    $cutoffTs = strtotime($maxAt . ' UTC');
    if ($cutoffTs === false) {
        api_json([]);
    }
    $cutoff = gmdate('Y-m-d H:i:s', $cutoffTs - 60);

    $st = $db->prepare("
        SELECT r.id, r.address_id, r.ip, r.method, r.is_up, r.latency_ms, r.scanned_at
        FROM scan_results r
        WHERE r.subnet_id = :sid
          AND r.scanned_at >= :cutoff
        ORDER BY r.ip
    ");
    $st->execute([':sid' => $subnetId, ':cutoff' => $cutoff]);
    api_json($st->fetchAll());
}

/** GET ?resource=scan_history&subnet_id=N[&limit=N] — paginated scan history */
function api_scan_history(PDO $db): never
{
    $subnetId = to_int($_GET['subnet_id'] ?? 0);
    if ($subnetId <= 0) api_error(400, 'subnet_id is required.');
    $limit = max(1, min(200, to_int($_GET['limit'] ?? 50)));

    // Minute-precision truncation. SQLite stores scanned_at as TEXT so
    // SUBSTR works natively. MySQL auto-coerces DATETIME to string in
    // string-function contexts. Postgres rejects SUBSTR(timestamp) so we
    // cast via ::text. No portable CAST target exists: MySQL's CAST AS
    // CHAR is variable-length, Postgres's CAST AS CHAR is CHAR(1) and
    // would truncate. v2.11.0 #388.
    $scannedAtText = ipam_dialect()->driver_name() === 'pgsql'
        ? '(scanned_at)::text'
        : 'scanned_at';
    $st = $db->prepare("
        SELECT
            SUBSTR($scannedAtText, 1, 16) AS run_minute,
            COUNT(*) AS total,
            SUM(is_up) AS up_count,
            COUNT(*) - SUM(is_up) AS down_count,
            MIN(scanned_at) AS started_at
        FROM scan_results
        WHERE subnet_id = :sid
        GROUP BY run_minute
        ORDER BY run_minute DESC
        LIMIT :lim
    ");
    $st->bindValue(':sid', $subnetId, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit,    PDO::PARAM_INT);
    $st->execute();
    api_json($st->fetchAll());
}

/** GET ?resource=scan_schedules[&subnet_id=N] — list scan schedules */
function api_scan_schedules_list(PDO $db): never
{
    $subnetId = to_int($_GET['subnet_id'] ?? 0);
    if ($subnetId > 0) {
        $st = $db->prepare("SELECT * FROM scan_schedules WHERE subnet_id = :sid");
        $st->execute([':sid' => $subnetId]);
        $row = $st->fetch();
        api_json($row ?: null);
    }
    $rows = ($db->query("SELECT * FROM scan_schedules ORDER BY subnet_id") ?: throw new \RuntimeException('Query failed'))->fetchAll();
    api_json($rows);
}

/**
 * POST ?resource=scan_schedules — create or update a scan schedule
 * @param array<string, mixed> $apiKey
 * @param array<string, mixed> $body
 */
function api_scan_schedules_save(PDO $db, array $apiKey, array $body): never
{
    if (isset($apiKey['is_readonly']) && $apiKey['is_readonly']) {
        api_error(403, 'Read-only API key cannot modify scan schedules.');
    }
    $subnetId = to_int($body['subnet_id'] ?? 0);
    if ($subnetId <= 0) api_error(400, 'subnet_id is required.');

    $subnetChk = $db->prepare("SELECT id FROM subnets WHERE id = :id");
    $subnetChk->execute([':id' => $subnetId]);
    if (!$subnetChk->fetch()) api_error(404, 'Subnet not found.');

    $method   = to_str($body['method'] ?? 'icmp');
    if (!in_array($method, ['icmp', 'tcp', 'both'], true)) api_error(400, "method must be icmp, tcp, or both.");
    $tcpPort  = isset($body['tcp_port']) ? to_int($body['tcp_port']) : null;
    if (in_array($method, ['tcp', 'both'], true)) {
        if ($tcpPort === null || $tcpPort < 1 || $tcpPort > 65535) {
            api_error(400, 'tcp_port must be an integer between 1 and 65535 when method is tcp or both.');
        }
    } else {
        $tcpPort = null; // clear irrelevant port for icmp-only schedules
    }
    $interval = max(1, to_int($body['interval_minutes'] ?? 60));
    $active   = isset($body['is_active']) ? ((bool) $body['is_active'] ? 1 : 0) : 1;

    // #380: route through the dialect so v2.10.0+ swaps upsert + timestamp
    // idioms without touching this call site.
    $d = ipam_dialect();
    $upsertClause = $d->upsert('scan_schedules', ['subnet_id'], ['method', 'tcp_port', 'interval_minutes', 'is_active', 'updated_at']);
    $st = $db->prepare("
        INSERT INTO scan_schedules (subnet_id, method, tcp_port, interval_minutes, is_active, updated_at)
        VALUES (:sid, :method, :port, :interval, :active, {$d->now()})
        $upsertClause
    ");
    $st->execute([':sid' => $subnetId, ':method' => $method, ':port' => $tcpPort, ':interval' => $interval, ':active' => $active]);

    api_audit($db, $apiKey, 'scan.schedule_update', 'subnet', $subnetId, "method=$method interval={$interval}m active=$active");

    $fetch = $db->prepare("SELECT * FROM scan_schedules WHERE subnet_id = :sid");
    $fetch->execute([':sid' => $subnetId]);
    http_response_code(201);
    api_json($fetch->fetch());
}

/** DELETE ?resource=scan_schedules&subnet_id=N — remove a scan schedule */
/** @param array<string, mixed> $apiKey */
function api_scan_schedules_delete(PDO $db, array $apiKey): never
{
    if (isset($apiKey['is_readonly']) && $apiKey['is_readonly']) {
        api_error(403, 'Read-only API key cannot delete scan schedules.');
    }
    $subnetId = to_int($_GET['subnet_id'] ?? 0);
    if ($subnetId <= 0) api_error(400, 'subnet_id is required.');

    $st = $db->prepare("DELETE FROM scan_schedules WHERE subnet_id = :sid");
    $st->execute([':sid' => $subnetId]);
    if ($st->rowCount() === 0) api_error(404, 'No scan schedule found for this subnet.');

    api_audit($db, $apiKey, 'scan.schedule_delete', 'subnet', $subnetId, '');

    http_response_code(204);
    exit;
}

/**
 * POST ?resource=scan_run&subnet_id=N — trigger an immediate synchronous scan.
 * Capped at /28 (16 IPs) to avoid HTTP timeout on large subnets.
 * @param array<string, mixed> $apiKey
 */
function api_scan_run(PDO $db, array $apiKey): never
{
    if (isset($apiKey['is_readonly']) && $apiKey['is_readonly']) {
        api_error(403, 'Read-only API key cannot trigger scans.');
    }
    $subnetId = to_int($_GET['subnet_id'] ?? 0);
    if ($subnetId <= 0) api_error(400, 'subnet_id is required.');

    // Load subnet and enforce /28 (16 addresses) cap
    $st = $db->prepare("SELECT id, cidr, prefix FROM subnets WHERE id = :id");
    $st->execute([':id' => $subnetId]);
    /** @var array<string, mixed>|false $subnet */
    $subnet = $st->fetch();
    if (!$subnet) api_error(404, 'Subnet not found.');

    if (to_int($subnet['prefix']) < 28) {
        api_error(400, 'Synchronous scan is limited to /28 or smaller (16 IPs). Use the CLI scanner for larger subnets: php scan_run.php --subnet-id=' . $subnetId);
    }

    // Load schedule for method/port defaults
    $schedSt = $db->prepare("SELECT method, tcp_port FROM scan_schedules WHERE subnet_id = :sid");
    $schedSt->execute([':sid' => $subnetId]);
    /** @var array<string, mixed>|false $sched */
    $sched   = $schedSt->fetch();
    $method  = $sched ? to_str($sched['method'] ?? 'icmp') : 'icmp';
    $tcpPort = ($sched && isset($sched['tcp_port'])) ? to_int($sched['tcp_port']) : null;

    $stats = ipam_scan_subnet($db, $subnetId, $method, $tcpPort);

    $db->prepare("UPDATE scan_schedules SET last_run_at = " . ipam_dialect()->now() . " WHERE subnet_id = :sid")
       ->execute([':sid' => $subnetId]);

    api_audit($db, $apiKey, 'scan.run', 'subnet', $subnetId,
        "method=$method scanned={$stats['scanned']} up={$stats['up']} down={$stats['down']}");

    api_json(array_merge(['subnet_id' => $subnetId, 'cidr' => to_str($subnet['cidr'] ?? '')], $stats));
}

/** GET ?resource=subnet_stats — session-auth only, returns counts + utilization for all subnets */
function api_subnet_stats(PDO $db): never
{
    $st = $db->prepare(
        "SELECT s.id, s.cidr, s.ip_version, s.network, s.network_bin, s.prefix,
                s.description, s.site_id, s.vlan_id, s.vlan_fk, s.vrf_id
         FROM subnets s
         ORDER BY s.ip_version ASC, s.prefix ASC, s.network_bin ASC"
    );
    $st->execute();
    /** @var list<array<string, mixed>> $list */
    $list = $st->fetchAll();

    $tree    = build_subnet_tree($list);
    $direct  = subnet_direct_counts($db);
    $agg     = subnet_aggregated_counts($tree, $direct);
    $util    = ipv4_unassigned_summary($db);
    $utilAgg = ipv4_unassigned_aggregated($tree, $util);

    /** @var array<string, array<string, mixed>> $dOut */
    $dOut = [];
    foreach ($direct as $sid => $v) $dOut[(string)$sid] = $v;
    /** @var array<string, array<string, mixed>> $aOut */
    $aOut = [];
    foreach ($agg as $sid => $v) $aOut[(string)$sid] = $v;
    /** @var array<string, array<string, mixed>> $uOut */
    $uOut = [];
    foreach ($util as $sid => $v) $uOut[(string)$sid] = $v;
    /** @var array<string, array<string, mixed>> $uaOut */
    $uaOut = [];
    foreach ($utilAgg as $sid => $v) $uaOut[(string)$sid] = $v;

    api_json([
        'data' => [
            'direct'  => $dOut,
            'agg'     => $aOut,
            'util'    => $uOut,
            'utilAgg' => $uaOut,
        ],
    ]);
}

function api_utilization_snapshots(PDO $db): never
{
    $subnetId = to_int($_GET['subnet_id'] ?? 0);
    if ($subnetId <= 0) {
        api_error(400, 'subnet_id is required.');
    }
    $subnetRow = $db->prepare("SELECT id FROM subnets WHERE id = :id");
    $subnetRow->execute([':id' => $subnetId]);
    if (!$subnetRow->fetch()) {
        api_error(404, 'Subnet not found.');
    }
    $days   = max(1, min(365, to_int($_GET['days'] ?? 30)));
    $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);

    $st = $db->prepare(
        "SELECT id, subnet_id, snapped_at, used_count, free_count, total_hosts
         FROM utilization_snapshots
         WHERE subnet_id = :sid AND snapped_at >= :cutoff
         ORDER BY snapped_at ASC"
    );
    $st->bindValue(':sid',    $subnetId, PDO::PARAM_INT);
    $st->bindValue(':cutoff', $cutoff);
    $st->execute();

    $rows = array_map(function(array $r): array {
        $total = to_int($r['total_hosts']);
        $used  = to_int($r['used_count']);
        return [
            'id'              => to_int($r['id']),
            'subnet_id'       => to_int($r['subnet_id']),
            'snapped_at'      => to_str($r['snapped_at']),
            'used_count'      => $used,
            'free_count'      => to_int($r['free_count']),
            'total_hosts'     => $total,
            'utilization_pct' => $total > 0 ? round($used / $total * 100, 2) : 0.0,
        ];
    }, $st->fetchAll());

    api_json(['utilization_snapshots' => $rows, 'count' => count($rows), 'subnet_id' => $subnetId, 'days' => $days]);
}

// ── Devices (#394 / #396) ─────────────────────────────────────────────────────

/** GET ?resource=devices[&id=N][&type=][&site_id=][&q=][&page=][&limit=] */
function api_devices(PDO $db): never
{
    /** @var list<string> $validTypes */
    $validTypes = ['router', 'switch', 'server', 'vm', 'firewall', 'other'];

    if (isset($_GET['id'])) {
        $id  = to_int($_GET['id']);
        $st  = $db->prepare("SELECT d.*, s.name AS site_name FROM devices d LEFT JOIN sites s ON s.id = d.site_id WHERE d.id = :id");
        $st->execute([':id' => $id]);
        /** @var array<string,mixed>|false $row */
        $row = $st->fetch();
        if (!$row) api_error(404, 'Device not found.');
        api_json(['device' => fmt_device($row, $db)]);
    }

    $where  = [];
    $params = [];

    if (isset($_GET['type'])) {
        $t = to_str($_GET['type']);
        if (!in_array($t, $validTypes, true)) api_error(400, 'Invalid type.');
        $where[] = 'd.type = :type';
        $params[':type'] = $t;
    }
    if (isset($_GET['site_id']) && to_int($_GET['site_id']) > 0) {
        $where[] = 'd.site_id = :sid';
        $params[':sid'] = to_int($_GET['site_id']);
    }
    if (isset($_GET['q'])) {
        $q = trim(to_str($_GET['q']));
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $where[] = '(d.name LIKE :q OR d.vendor LIKE :q2 OR d.model LIKE :q3)';
            $params[':q'] = $like; $params[':q2'] = $like; $params[':q3'] = $like;
        }
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $page   = max(1, to_int($_GET['page']  ?? 1));
    $limit  = max(1, min(200, to_int($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM devices d $whereSql");
    $cntSt->execute($params);
    /** @var array<string,mixed>|false $cntRow */
    $cntRow = $cntSt->fetch();
    $total = is_array($cntRow) ? to_int($cntRow['c']) : 0;

    $st = $db->prepare("SELECT d.*, s.name AS site_name FROM devices d LEFT JOIN sites s ON s.id = d.site_id $whereSql ORDER BY d.name LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v, PDO::PARAM_STR);
    }
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    api_json(api_paginated_response('devices', array_map(fn($r) => fmt_device($r, $db), $st->fetchAll()), $total, $page, $limit));
}

/**
 * @param array<string,mixed> $r
 * @return array<string,mixed>
 */
function fmt_device(array $r, PDO $db): array
{
    $id = to_int($r['id']);
    $ifSt = $db->prepare("SELECT COUNT(*) AS c FROM device_interfaces WHERE device_id = :id");
    $ifSt->execute([':id' => $id]);
    /** @var array<string,mixed>|false $ifRow */
    $ifRow = $ifSt->fetch();
    return [
        'id'              => $id,
        'name'            => to_str($r['name']),
        'type'            => to_str($r['type']),
        'site_id'         => $r['site_id'] !== null ? to_int($r['site_id']) : null,
        'site_name'       => isset($r['site_name']) ? to_str($r['site_name']) : null,
        'vendor'          => to_str($r['vendor']),
        'model'           => to_str($r['model']),
        'serial'          => to_str($r['serial']),
        'note'            => to_str($r['note']),
        'interface_count' => is_array($ifRow) ? to_int($ifRow['c']) : 0,
        'created_at'      => $r['created_at'],
        'updated_at'      => $r['updated_at'],
    ];
}

/**
 * @param array<string,mixed> $apiKey
 * @param array<string,mixed> $body
 */
function api_devices_create(PDO $db, array $apiKey, array $body): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    /** @var list<string> $validTypes */
    $validTypes = ['router', 'switch', 'server', 'vm', 'firewall', 'other'];
    $name   = trim(to_str($body['name']    ?? ''));
    $type   = to_str($body['type']   ?? 'other');
    $siteId = isset($body['site_id']) && to_int($body['site_id']) > 0 ? to_int($body['site_id']) : null;
    $vendor = trim(to_str($body['vendor']  ?? ''));
    $model  = trim(to_str($body['model']   ?? ''));
    $serial = trim(to_str($body['serial']  ?? ''));
    $note   = trim(to_str($body['note']    ?? ''));
    if ($name === '') api_error(400, 'name is required.');
    if (!in_array($type, $validTypes, true)) api_error(400, 'Invalid type.');
    try {
        $st = $db->prepare("INSERT INTO devices (name, type, site_id, vendor, model, serial, note) VALUES (:n,:t,:sid,:v,:m,:sr,:nt)");
        $st->execute([':n' => $name, ':t' => $type, ':sid' => $siteId, ':v' => $vendor, ':m' => $model, ':sr' => $serial, ':nt' => $note]);
    } catch (PDOException) {
        api_error(409, 'A device with that name already exists.');
    }
    $newId = ipam_last_insert_id($db, 'devices');
    audit($db, 'device.create', 'device', $newId, "name=$name type=$type");
    http_response_code(201);
    $st = $db->prepare("SELECT d.*, s.name AS site_name FROM devices d LEFT JOIN sites s ON s.id = d.site_id WHERE d.id = :id");
    $st->execute([':id' => $newId]);
    /** @var array<string,mixed>|false $row */
    $row = $st->fetch();
    api_json(fmt_device($row ?: [], $db));
}

/**
 * @param array<string,mixed> $apiKey
 * @param array<string,mixed> $body
 */
function api_devices_update(PDO $db, array $apiKey, int $id, array $body): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    if ($id <= 0) api_error(400, 'id is required.');
    /** @var list<string> $validTypes */
    $validTypes = ['router', 'switch', 'server', 'vm', 'firewall', 'other'];
    $chk = $db->prepare("SELECT id FROM devices WHERE id = :id");
    $chk->execute([':id' => $id]);
    if (!$chk->fetch()) api_error(404, 'Device not found.');
    $name   = trim(to_str($body['name']    ?? ''));
    $type   = to_str($body['type']   ?? 'other');
    $siteId = isset($body['site_id']) && to_int($body['site_id']) > 0 ? to_int($body['site_id']) : null;
    $vendor = trim(to_str($body['vendor']  ?? ''));
    $model  = trim(to_str($body['model']   ?? ''));
    $serial = trim(to_str($body['serial']  ?? ''));
    $note   = trim(to_str($body['note']    ?? ''));
    if ($name === '') api_error(400, 'name is required.');
    if (!in_array($type, $validTypes, true)) api_error(400, 'Invalid type.');
    try {
        $st = $db->prepare("UPDATE devices SET name=:n, type=:t, site_id=:sid, vendor=:v, model=:m, serial=:sr, note=:nt, updated_at=" . ipam_dialect()->now() . " WHERE id=:id");
        $st->execute([':n' => $name, ':t' => $type, ':sid' => $siteId, ':v' => $vendor, ':m' => $model, ':sr' => $serial, ':nt' => $note, ':id' => $id]);
    } catch (PDOException) {
        api_error(409, 'Duplicate device name.');
    }
    audit($db, 'device.update', 'device', $id, "name=$name");
    $st = $db->prepare("SELECT d.*, s.name AS site_name FROM devices d LEFT JOIN sites s ON s.id = d.site_id WHERE d.id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string,mixed>|false $row */
    $row = $st->fetch();
    api_json(fmt_device($row ?: [], $db));
}

/** @param array<string,mixed> $apiKey */
function api_devices_delete(PDO $db, array $apiKey, int $id): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    if ($id <= 0) api_error(400, 'id is required.');
    $st = $db->prepare("SELECT name FROM devices WHERE id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string,mixed>|false $row */
    $row = $st->fetch();
    if (!$row) api_error(404, 'Device not found.');
    $db->prepare("DELETE FROM devices WHERE id = :id")->execute([':id' => $id]);
    audit($db, 'device.delete', 'device', $id, 'name=' . to_str($row['name']));
    http_response_code(204);
    api_json([]);
}

// ── Device Interfaces (#394 / #396) ───────────────────────────────────────────

/** GET ?resource=device_interfaces[&id=N][&device_id=N] */
function api_device_interfaces(PDO $db): never
{
    if (isset($_GET['id'])) {
        $id  = to_int($_GET['id']);
        $st  = $db->prepare("SELECT * FROM device_interfaces WHERE id = :id");
        $st->execute([':id' => $id]);
        /** @var array<string,mixed>|false $row */
        $row = $st->fetch();
        if (!$row) api_error(404, 'Interface not found.');
        api_json(['device_interface' => fmt_device_interface($row)]);
    }

    $where  = [];
    $params = [];

    if (isset($_GET['device_id']) && to_int($_GET['device_id']) > 0) {
        $did = to_int($_GET['device_id']);
        $chk = $db->prepare("SELECT id FROM devices WHERE id = :id");
        $chk->execute([':id' => $did]);
        if (!$chk->fetch()) api_error(404, 'Device not found.');
        $where[] = 'device_id = :did';
        $params[':did'] = $did;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $page   = max(1, to_int($_GET['page']  ?? 1));
    $limit  = max(1, min(200, to_int($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM device_interfaces $whereSql");
    $cntSt->execute($params);
    /** @var array<string,mixed>|false $cntRow */
    $cntRow = $cntSt->fetch();
    $total  = is_array($cntRow) ? to_int($cntRow['c']) : 0;

    $st = $db->prepare("SELECT * FROM device_interfaces $whereSql ORDER BY name LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v, PDO::PARAM_STR);
    }
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    api_json(api_paginated_response('device_interfaces', array_map('fmt_device_interface', $st->fetchAll()), $total, $page, $limit));
}

/**
 * @param array<string,mixed> $r
 * @return array<string,mixed>
 */
function fmt_device_interface(array $r): array
{
    return [
        'id'          => to_int($r['id']),
        'device_id'   => to_int($r['device_id']),
        'name'        => to_str($r['name']),
        'description' => to_str($r['description']),
        'created_at'  => $r['created_at'],
        'updated_at'  => $r['updated_at'],
    ];
}

/**
 * @param array<string,mixed> $apiKey
 * @param array<string,mixed> $body
 */
function api_device_interfaces_create(PDO $db, array $apiKey, array $body): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    $deviceId = to_int($body['device_id'] ?? 0);
    $name     = trim(to_str($body['name']        ?? ''));
    $desc     = trim(to_str($body['description'] ?? ''));
    if ($deviceId <= 0) api_error(400, 'device_id is required.');
    if ($name === '')   api_error(400, 'name is required.');
    $chk = $db->prepare("SELECT id FROM devices WHERE id = :id");
    $chk->execute([':id' => $deviceId]);
    if (!$chk->fetch()) api_error(404, 'Device not found.');
    try {
        $st = $db->prepare("INSERT INTO device_interfaces (device_id, name, description) VALUES (:did,:n,:d)");
        $st->execute([':did' => $deviceId, ':n' => $name, ':d' => $desc]);
    } catch (PDOException) {
        api_error(409, 'An interface with that name already exists on this device.');
    }
    $newId = ipam_last_insert_id($db, 'device_interfaces');
    audit($db, 'device_interface.create', 'device_interface', $newId, "device_id=$deviceId name=$name");
    http_response_code(201);
    $st = $db->prepare("SELECT * FROM device_interfaces WHERE id = :id");
    $st->execute([':id' => $newId]);
    /** @var array<string,mixed>|false $row */
    $row = $st->fetch();
    api_json(fmt_device_interface($row ?: []));
}

/**
 * @param array<string,mixed> $apiKey
 * @param array<string,mixed> $body
 */
function api_device_interfaces_update(PDO $db, array $apiKey, int $id, array $body): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    if ($id <= 0) api_error(400, 'id is required.');
    $chk = $db->prepare("SELECT id FROM device_interfaces WHERE id = :id");
    $chk->execute([':id' => $id]);
    if (!$chk->fetch()) api_error(404, 'Interface not found.');
    $name = trim(to_str($body['name']        ?? ''));
    $desc = trim(to_str($body['description'] ?? ''));
    if ($name === '') api_error(400, 'name is required.');
    try {
        $st = $db->prepare("UPDATE device_interfaces SET name=:n, description=:d, updated_at=" . ipam_dialect()->now() . " WHERE id=:id");
        $st->execute([':n' => $name, ':d' => $desc, ':id' => $id]);
    } catch (PDOException) {
        api_error(409, 'Duplicate interface name on this device.');
    }
    audit($db, 'device_interface.update', 'device_interface', $id, "name=$name");
    $st = $db->prepare("SELECT * FROM device_interfaces WHERE id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string,mixed>|false $row */
    $row = $st->fetch();
    api_json(fmt_device_interface($row ?: []));
}

/** @param array<string,mixed> $apiKey */
function api_device_interfaces_delete(PDO $db, array $apiKey, int $id): never
{
    if (to_int($apiKey['is_readonly'] ?? 0) === 1) api_error(403, 'Read-only key.');
    if ($id <= 0) api_error(400, 'id is required.');
    $st = $db->prepare("SELECT name FROM device_interfaces WHERE id = :id");
    $st->execute([':id' => $id]);
    /** @var array<string,mixed>|false $row */
    $row = $st->fetch();
    if (!$row) api_error(404, 'Interface not found.');
    $db->prepare("DELETE FROM device_interfaces WHERE id = :id")->execute([':id' => $id]);
    audit($db, 'device_interface.delete', 'device_interface', $id, 'name=' . to_str($row['name']));
    http_response_code(204);
    api_json([]);
}
