<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

$q          = substr(trim(to_str($_GET['q'] ?? '')), 0, 500);
$status     = trim(to_str($_GET['status'] ?? ''));
$subnetId   = to_int($_GET['subnet_id'] ?? 0);
$siteId     = to_int($_GET['site_id'] ?? 0);
$ipVersion  = to_int($_GET['ip_version'] ?? 0);

$format   = trim(to_str($_GET['format'] ?? ''));
$page     = q_int('page', 1, 1, 1000000);
$pageSize = q_int('page_size', 254, 1, 500);

$searchSortCols = ['ip' => 'a.ip_bin', 'hostname' => 'a.hostname', 'owner' => 'a.owner',
                   'status' => 'a.status', 'subnet' => 's.cidr'];
$searchSort = parse_sort($searchSortCols, 'ip');

$allowedStatus = ['', 'used', 'reserved', 'free'];
if (!in_array($status, $allowedStatus, true)) $status = '';
if (!in_array($ipVersion, [0, 4, 6], true)) $ipVersion = 0;

/* Build WHERE clause — all conditions reference `a` or `s` (subnets already joined) */
$where  = [];
$params = [];

if ($q !== '') {
    // Distinct :q1..:q6 placeholders for PDO native-prepared safety.
    // See api.php::api_search() for the full rationale.
    // #750: case-insensitive across all engines via dialect-aware LOWER(col).
    // SQLite NOCASE only folds ASCII; MySQL depends on column collation;
    // Postgres LIKE is strictly case-sensitive and our text columns use
    // COLLATE "C". The PgsqlDialect overrides the COLLATE per LOWER() call so
    // non-ASCII folding works on every engine. See lower_expr() on each Dialect.
    $d = ipam_dialect();
    $where[] = '(' . $d->lower_expr('a.ip')       . " LIKE :q1 ESCAPE '!' OR "
             .       $d->lower_expr('a.hostname') . " LIKE :q2 ESCAPE '!' OR "
             .       $d->lower_expr('a.owner')    . " LIKE :q3 ESCAPE '!' OR "
             .       $d->lower_expr('a.note')     . " LIKE :q4 ESCAPE '!' OR "
             .       $d->lower_expr('a.grp')      . " LIKE :q5 ESCAPE '!' OR "
             .       $d->lower_expr('a.mac')      . " LIKE :q6 ESCAPE '!')";
    $qLike = '%' . like_escape($d->case_fold_value($q)) . '%';
    $params[':q1'] = $qLike;
    $params[':q2'] = $qLike;
    $params[':q3'] = $qLike;
    $params[':q4'] = $qLike;
    $params[':q5'] = $qLike;
    $params[':q6'] = $qLike;
}
if ($status !== '') {
    $where[]       = "a.status = :st";
    $params[':st'] = $status;
}
if ($subnetId > 0) {
    $where[]        = "a.subnet_id = :sid";
    $params[':sid'] = $subnetId;
}
if ($siteId > 0) {
    $where[]          = "s.site_id = :site_id";
    $params[':site_id'] = $siteId;
}
if ($ipVersion > 0) {
    $where[]           = "s.ip_version = :ipver";
    $params[':ipver']  = $ipVersion;
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/* --- format=json branch: used by the ⌘K overlay — exits before loading dropdown data --- */
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $st = $db->prepare("
        SELECT a.id, a.subnet_id, a.ip, a.hostname, a.status
        FROM addresses a
        JOIN subnets s ON s.id = a.subnet_id
        $whereSql
        ORDER BY a.ip_bin ASC
        LIMIT 20
    ");
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->execute();
    /** @var list<array<string, mixed>> $jRows */
    $jRows = $st->fetchAll();
    $out = [];
    foreach ($jRows as $jr) {
        $out[] = [
            'ip'          => to_str($jr['ip']),
            'hostname'    => to_str($jr['hostname']),
            'status'      => to_str($jr['status']),
            'url'         => 'addresses.php?subnet_id=' . to_int($jr['subnet_id'])
                             . '&highlight=' . to_int($jr['id'])
                             . '#addr-' . to_int($jr['id']),
        ];
    }
    echo json_encode($out, JSON_UNESCAPED_SLASHES);
    exit;
}

/* Fetch sites and subnets for filter dropdowns (HTML path only) */
$st = $db->prepare("SELECT id, name FROM sites ORDER BY name ASC");
$st->execute();
/** @var list<array<string, mixed>> $siteList */
$siteList = $st->fetchAll();

$st = $db->prepare("SELECT id, cidr, ip_version, site_id FROM subnets ORDER BY ip_version ASC, cidr ASC");
$st->execute();
/** @var list<array<string, mixed>> $subnets */
$subnets = $st->fetchAll();

/* Count query — must JOIN subnets when site/version filters are active */
$st = $db->prepare("
    SELECT COUNT(*) AS c
    FROM addresses a
    JOIN subnets s ON s.id = a.subnet_id
    $whereSql
");
$st->execute($params);
/** @var array<string, mixed>|false $cntRow */

$cntRow = $st->fetch();

$total = is_array($cntRow) ? to_int($cntRow['c']) : 0;

$p = paginate($total, $page, $pageSize);

$st = $db->prepare("
    SELECT a.id, a.subnet_id, a.ip, a.hostname, a.owner, a.grp, a.mac, a.expires_at, a.status, a.note, a.updated_at,
           s.cidr AS subnet_cidr, si.name AS site_name
    FROM addresses a
    JOIN subnets s ON s.id = a.subnet_id
    LEFT JOIN sites si ON si.id = s.site_id
    $whereSql
    ORDER BY {$searchSort['sql']}
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':lim', $p['limit'], PDO::PARAM_INT);
$st->bindValue(':off', $p['offset'], PDO::PARAM_INT);
$st->execute();
/** @var list<array<string, mixed>> $rows */
$rows = $st->fetchAll();

/* --- Device search (always runs when $q is non-empty) --- */
/** @var list<array<string, mixed>> $deviceResults */
$deviceResults = [];
if ($q !== '') {
    // #750: case-insensitive search via dialect-aware LOWER() — see addresses block above.
    $d = ipam_dialect();
    $dLike = '%' . like_escape($d->case_fold_value($q)) . '%';
    $dWhere  = ['(' . $d->lower_expr('d.name')   . " LIKE :dq1 ESCAPE '!' OR "
                .    $d->lower_expr('d.vendor') . " LIKE :dq2 ESCAPE '!' OR "
                .    $d->lower_expr('d.model')  . " LIKE :dq3 ESCAPE '!' OR "
                .    $d->lower_expr('d.serial') . " LIKE :dq4 ESCAPE '!' OR "
                .    $d->lower_expr('d.note')   . " LIKE :dq5 ESCAPE '!' OR "
                .    $d->lower_expr('dif.name') . " LIKE :dq6 ESCAPE '!')"];
    $dParams = [':dq1' => $dLike, ':dq2' => $dLike, ':dq3' => $dLike,
                ':dq4' => $dLike, ':dq5' => $dLike, ':dq6' => $dLike];
    if ($siteId > 0) {
        $dWhere[]           = 'd.site_id = :dsite';
        $dParams[':dsite']  = $siteId;
    }
    $dst = $db->prepare("
        SELECT DISTINCT d.id, d.name, d.type, d.vendor, d.model,
               COALESCE(s.name, '') AS site_name,
               (SELECT COUNT(*) FROM device_interfaces di WHERE di.device_id = d.id) AS iface_count
        FROM devices d
        LEFT JOIN sites s ON s.id = d.site_id
        LEFT JOIN device_interfaces dif ON dif.device_id = d.id
        WHERE " . implode(' AND ', $dWhere) . "
        ORDER BY d.name
        LIMIT 50
    ");
    $dst->execute($dParams);
    $deviceResults = $dst->fetchAll();
}

/* --- Subnet search (always runs when $q is non-empty) --- */
$subnetResults = [];
if ($q !== '') {
    $subWhere  = [];
    $subParams = [];
    // Distinct :sq1..:sq3 placeholders — same PDO native-prepared rule
    // as the addresses search above. See api.php::api_search() for the
    // full rationale.
    // #750: case-insensitive search via dialect-aware LOWER() — see addresses block above.
    $d = ipam_dialect();
    $subWhere[] = '(' . $d->lower_expr('s.cidr')        . " LIKE :sq1 ESCAPE '!' OR "
                .       $d->lower_expr('s.description') . " LIKE :sq2 ESCAPE '!' OR "
                .       $d->lower_expr('s.notes')       . " LIKE :sq3 ESCAPE '!')";
    $sqLike = '%' . like_escape($d->case_fold_value($q)) . '%';
    $subParams[':sq1'] = $sqLike;
    $subParams[':sq2'] = $sqLike;
    $subParams[':sq3'] = $sqLike;
    if ($siteId > 0) {
        $subWhere[]          = "s.site_id = :site_id";
        $subParams[':site_id'] = $siteId;
    }
    if ($ipVersion > 0) {
        $subWhere[]           = "s.ip_version = :ipver";
        $subParams[':ipver']  = $ipVersion;
    }
    $subWhereSql = 'WHERE ' . implode(' AND ', $subWhere);
    $st = $db->prepare("
        SELECT s.id, s.cidr, s.ip_version, s.description, s.vlan_id,
               COALESCE(si.name, '') AS site_name,
               COUNT(a.id) AS addr_count
        FROM subnets s
        LEFT JOIN sites si ON si.id = s.site_id
        LEFT JOIN addresses a ON a.subnet_id = s.id
        $subWhereSql
        GROUP BY s.id, si.name
        ORDER BY s.ip_version ASC, s.cidr ASC
        LIMIT 50
    ");
    foreach ($subParams as $k => $v) $st->bindValue($k, $v);
    $st->execute();
    /** @var list<array<string, mixed>> $subnetResults */
    $subnetResults = $st->fetchAll();
}

/** @param array<string, mixed> $overrides */
function build_query_search(array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    return http_build_query($q);
}

page_header('Search');
?>

<div class="breadcrumbs">
  <a href="dashboard.php"><?= icon('home') ?> Dashboard</a>
  <span class="sep">›</span>
  <span><?= icon('magnifying-glass') ?> Search</span>
</div>

<div class="toolbar">
  <div>
    <h1>Search</h1>
    <div class="muted">Search address records across the system.</div>
  </div>
</div>

<div class="page-actions">
  <a class="action-pill" href="export_search.php?<?= e(build_query_search()) ?>"><?= icon('download') ?> Export CSV</a>
  <a class="action-pill" href="addresses.php"><?= icon('map-pin') ?> Addresses</a>
  <?php if ($subnetId > 0): ?>
    <a class="action-pill" href="addresses.php?subnet_id=<?= (int)$subnetId ?>"><?= icon('server-stack') ?> View Subnet Addresses</a>
  <?php endif; ?>
</div>

<div class="card mt-16">
  <form method="get" action="search.php" class="row">

    <label>Query<br>
      <input name="q" value="<?= e($q) ?>" placeholder="ip / hostname / owner / note">
    </label>

    <label>Status<br>
      <select name="status">
        <option value="" <?= $status===''?'selected':'' ?>>(any)</option>
        <option value="used"     <?= $status==='used'    ?'selected':'' ?>>used</option>
        <option value="reserved" <?= $status==='reserved'?'selected':'' ?>>reserved</option>
        <option value="free"     <?= $status==='free'    ?'selected':'' ?>>free</option>
      </select>
    </label>

    <label>IP Version<br>
      <select name="ip_version">
        <option value="0" <?= $ipVersion===0?'selected':'' ?>>(any)</option>
        <option value="4" <?= $ipVersion===4?'selected':'' ?>>IPv4</option>
        <option value="6" <?= $ipVersion===6?'selected':'' ?>>IPv6</option>
      </select>
    </label>

    <?php if ($siteList): ?>
    <label>Site<br>
      <select name="site_id" id="filter-site">
        <option value="0">(any site)</option>
        <?php foreach ($siteList as $site): ?>
          <option value="<?= to_int($site['id']) ?>" <?= (to_int($site['id'])===$siteId)?'selected':'' ?>>
            <?= e(to_str($site['name'])) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>

    <label>Subnet<br>
      <select name="subnet_id" id="filter-subnet">
        <option value="0">(any)</option>
        <?php foreach ($subnets as $s): ?>
          <option value="<?= to_int($s['id']) ?>"
                  data-site="<?= to_int($s['site_id'] ?? 0) ?>"
                  data-ver="<?= to_int($s['ip_version']) ?>"
                  <?= (to_int($s['id'])===$subnetId)?'selected':'' ?>>
            <?= e(to_str($s['cidr'])) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Page size<br>
      <select name="page_size">
        <?php foreach ([50, 100, 254, 500] as $sz): ?>
          <option value="<?= $sz ?>" <?= $pageSize===$sz?'selected':'' ?>><?= $sz ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <button type="submit">Search</button>
    <?php if ($q !== '' || $status !== '' || $subnetId > 0 || $siteId > 0 || $ipVersion > 0): ?>
      <a class="action-pill" href="search.php">Clear filters</a>
    <?php endif; ?>

  </form>
</div>

<div class="card mt-16">
  <div class="muted">
    Results: <b><?= e((string)$total) ?></b>
    <?php if ($total > 0): ?>
      &nbsp;|&nbsp; Page <b><?= e(to_str($p['page'])) ?></b> of <b><?= e(to_str($p['pages'])) ?></b>
    <?php endif; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="empty-state mt-12">No results. <a class="action-pill" href="search.php">Clear filters</a></div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="mt-12">
      <thead>
        <tr>
          <th>Site</th>
          <?php $srchQs = '?page_size=' . $pageSize . '&q=' . urlencode($q)
                        . ($status ? '&status=' . urlencode($status) : '')
                        . ($subnetId ? '&subnet_id=' . $subnetId : '')
                        . ($siteId ? '&site_id=' . $siteId : '')
                        . ($ipVersion ? '&ip_version=' . $ipVersion : '');
                echo sort_th('subnet',   'Subnet',   $searchSort['col'], $searchSort['dir'], $srchQs);
                echo sort_th('ip',       'IP',       $searchSort['col'], $searchSort['dir'], $srchQs);
                echo sort_th('hostname', 'Hostname', $searchSort['col'], $searchSort['dir'], $srchQs);
                echo sort_th('owner',    'Owner',    $searchSort['col'], $searchSort['dir'], $srchQs);
                echo sort_th('status',   'Status',   $searchSort['col'], $searchSort['dir'], $srchQs);
          ?>
          <th>Group</th>
          <th>MAC</th>
          <th>Expires</th>
          <th>Note</th>
          <th>Updated</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= $r['site_name'] !== null ? e(to_str($r['site_name'])) : '<span class="muted">—</span>' ?></td>
          <td><?= e(to_str($r['subnet_cidr'])) ?></td>
          <td class="ip-cell"><?= e(to_str($r['ip'])) ?></td>
          <td><?= e(to_str($r['hostname'])) ?></td>
          <td><?= e(to_str($r['owner'])) ?></td>
          <td><span class="status-<?= e(to_str($r['status'])) ?>"><?= e(to_str($r['status'])) ?></span></td>
          <td><?= $r['grp'] !== '' ? '<span class="badge">' . e(to_str($r['grp'])) . '</span>' : '' ?></td>
          <td class="muted ip-cell"><?= e(to_str($r['mac'])) ?></td>
          <td class="muted"><?= e(to_str($r['expires_at'] ?? '')) ?></td>
          <td><?= e(to_str($r['note'])) ?></td>
          <td class="muted"><?= e(ipam_format_datetime(to_str($r['updated_at']))) ?></td>
          <td><a href="addresses.php?subnet_id=<?= to_int($r['subnet_id']) ?>&highlight=<?= to_int($r['id']) ?>#addr-<?= to_int($r['id']) ?>">Edit</a> <a href="address_history.php?address_id=<?= to_int($r['id']) ?>">History</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <p class="mt-12">
      <?php if ($p['page'] > 1): ?>
        <a href="search.php?<?= e(build_query_search(['page' => $p['page'] - 1])) ?>">&laquo; Prev</a>
      <?php endif; ?>
      <?php if ($p['page'] < $p['pages']): ?>
        <a class="ml-12" href="search.php?<?= e(build_query_search(['page' => $p['page'] + 1])) ?>">Next &raquo;</a>
      <?php endif; ?>
    </p>
  <?php endif; ?>
</div>

<?php if ($deviceResults): ?>
<div class="card mt-16">
  <h2>Matching Devices (<?= count($deviceResults) ?>)</h2>
  <div class="table-wrap">
  <table>
    <thead>
      <tr><th>Name</th><th>Type</th><th>Site</th><th>Vendor / Model</th><th>Interfaces</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($deviceResults as $dr): ?>
      <tr>
        <td><b><?= e(to_str($dr['name'])) ?></b></td>
        <td><span class="badge badge-type-<?= e(to_str($dr['type'])) ?>"><?= e(ucfirst(to_str($dr['type']))) ?></span></td>
        <td><?= $dr['site_name'] !== '' ? e(to_str($dr['site_name'])) : '<span class="muted">—</span>' ?></td>
        <td><?php
          $vm = trim(e(to_str($dr['vendor'])) . ' ' . e(to_str($dr['model'])));
          echo $vm !== '' ? $vm : '<span class="muted">—</span>';
        ?></td>
        <td><?= to_int($dr['iface_count']) ?></td>
        <td><a href="devices.php">Manage</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if ($subnetResults): ?>
<div class="card mt-16">
  <h2>Matching Subnets (<?= count($subnetResults) ?>)</h2>
  <div class="table-wrap">
  <table>
    <thead>
      <tr><th>CIDR</th><th>Description</th><th>IP Version</th><th>VLAN</th><th>Site</th><th>Addresses</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($subnetResults as $sr): ?>
      <tr>
        <td class="ip-cell"><a href="addresses.php?subnet_id=<?= to_int($sr['id']) ?>"><?= e(to_str($sr['cidr'])) ?></a></td>
        <td><?= e(to_str($sr['description'])) ?></td>
        <td>IPv<?= to_int($sr['ip_version']) ?></td>
        <td><?= $sr['vlan_id'] !== null ? e(to_str($sr['vlan_id'])) : '<span class="muted">—</span>' ?></td>
        <td><?= $sr['site_name'] !== '' ? e(to_str($sr['site_name'])) : '<span class="muted">—</span>' ?></td>
        <td><?= to_int($sr['addr_count']) ?></td>
        <td><a href="addresses.php?subnet_id=<?= to_int($sr['id']) ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php page_footer();
