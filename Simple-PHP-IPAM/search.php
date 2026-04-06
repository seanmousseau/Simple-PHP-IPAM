<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

$q          = substr(trim((string)($_GET['q'] ?? '')), 0, 500);
$status     = trim((string)($_GET['status'] ?? ''));
$subnetId   = (int)($_GET['subnet_id'] ?? 0);
$siteId     = (int)($_GET['site_id'] ?? 0);
$ipVersion  = (int)($_GET['ip_version'] ?? 0);

$page     = q_int('page', 1, 1, 1000000);
$pageSize = q_int('page_size', 254, 1, 500);

$searchSortCols = ['ip' => 'a.ip_bin', 'hostname' => 'a.hostname', 'owner' => 'a.owner',
                   'status' => 'a.status', 'subnet' => 's.cidr'];
$searchSort = parse_sort($searchSortCols, 'ip');

$allowedStatus = ['', 'used', 'reserved', 'free'];
if (!in_array($status, $allowedStatus, true)) $status = '';
if (!in_array($ipVersion, [0, 4, 6], true)) $ipVersion = 0;

/* Fetch sites for filter dropdown */
$st = $db->prepare("SELECT id, name FROM sites ORDER BY name ASC");
$st->execute();
$siteList = $st->fetchAll();

/* Fetch subnets for filter dropdown (include site_id for JS filtering) */
$st = $db->prepare("SELECT id, cidr, ip_version, site_id FROM subnets ORDER BY ip_version ASC, cidr ASC");
$st->execute();
$subnets = $st->fetchAll();

/* Build WHERE clause — all conditions reference `a` or `s` (subnets already joined) */
$where  = [];
$params = [];

if ($q !== '') {
    $where[]       = "(a.ip LIKE :q ESCAPE '\\' OR a.hostname LIKE :q ESCAPE '\\' OR a.owner LIKE :q ESCAPE '\\' OR a.note LIKE :q ESCAPE '\\' OR a.grp LIKE :q ESCAPE '\\')";
    $params[':q']  = '%' . like_escape($q) . '%';
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

/* Count query — must JOIN subnets when site/version filters are active */
$st = $db->prepare("
    SELECT COUNT(*) AS c
    FROM addresses a
    JOIN subnets s ON s.id = a.subnet_id
    $whereSql
");
$st->execute($params);
$total = (int)$st->fetch()['c'];

$p = paginate($total, $page, $pageSize);

$st = $db->prepare("
    SELECT a.id, a.subnet_id, a.ip, a.hostname, a.owner, a.grp, a.status, a.note, a.updated_at,
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
$rows = $st->fetchAll();

/* --- Subnet search (always runs when $q is non-empty) --- */
$subnetResults = [];
if ($q !== '') {
    $subWhere  = [];
    $subParams = [];
    $subWhere[]       = "(s.cidr LIKE :sq ESCAPE '\\' OR s.description LIKE :sq ESCAPE '\\')";
    $subParams[':sq'] = '%' . like_escape($q) . '%';
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
        GROUP BY s.id
        ORDER BY s.ip_version ASC, s.cidr ASC
        LIMIT 50
    ");
    foreach ($subParams as $k => $v) $st->bindValue($k, $v);
    $st->execute();
    $subnetResults = $st->fetchAll();
}

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
  <a href="dashboard.php">🏠 Dashboard</a>
  <span class="sep">›</span>
  <span>🔎 Search</span>
</div>

<div class="toolbar">
  <div>
    <h1>Search</h1>
    <div class="muted">Search address records across the system.</div>
  </div>
</div>

<div class="page-actions">
  <a class="action-pill" href="export_search.php?<?= e(build_query_search()) ?>">⬇ Export CSV</a>
  <a class="action-pill" href="addresses.php">🧾 Addresses</a>
  <?php if ($subnetId > 0): ?>
    <a class="action-pill" href="addresses.php?subnet_id=<?= (int)$subnetId ?>">🌐 View Subnet Addresses</a>
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
          <option value="<?= (int)$site['id'] ?>" <?= ((int)$site['id']===$siteId)?'selected':'' ?>>
            <?= e($site['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>

    <label>Subnet<br>
      <select name="subnet_id" id="filter-subnet">
        <option value="0">(any)</option>
        <?php foreach ($subnets as $s): ?>
          <option value="<?= (int)$s['id'] ?>"
                  data-site="<?= (int)($s['site_id'] ?? 0) ?>"
                  data-ver="<?= (int)$s['ip_version'] ?>"
                  <?= ((int)$s['id']===$subnetId)?'selected':'' ?>>
            <?= e($s['cidr']) ?>
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
      &nbsp;|&nbsp; Page <b><?= e((string)$p['page']) ?></b> of <b><?= e((string)$p['pages']) ?></b>
    <?php endif; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="empty-state mt-12">No results.</div>
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
          <th>Note</th>
          <th>Updated</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= $r['site_name'] !== null ? e($r['site_name']) : '<span class="muted">—</span>' ?></td>
          <td><?= e($r['subnet_cidr']) ?></td>
          <td><?= e($r['ip']) ?></td>
          <td><?= e($r['hostname']) ?></td>
          <td><?= e($r['owner']) ?></td>
          <td><span class="status-<?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
          <td><?= $r['grp'] !== '' ? '<span class="badge">' . e($r['grp']) . '</span>' : '' ?></td>
          <td><?= e($r['note']) ?></td>
          <td class="muted"><?= e($r['updated_at']) ?></td>
          <td><a href="addresses.php?subnet_id=<?= (int)$r['subnet_id'] ?>&highlight=<?= (int)$r['id'] ?>#addr-<?= (int)$r['id'] ?>">Edit</a> <a href="address_history.php?address_id=<?= (int)$r['id'] ?>">History</a></td>
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
        <td><a href="addresses.php?subnet_id=<?= (int)$sr['id'] ?>"><?= e($sr['cidr']) ?></a></td>
        <td><?= e((string)$sr['description']) ?></td>
        <td>IPv<?= (int)$sr['ip_version'] ?></td>
        <td><?= $sr['vlan_id'] !== null ? e((string)$sr['vlan_id']) : '<span class="muted">—</span>' ?></td>
        <td><?= $sr['site_name'] !== '' ? e($sr['site_name']) : '<span class="muted">—</span>' ?></td>
        <td><?= (int)$sr['addr_count'] ?></td>
        <td><a href="addresses.php?subnet_id=<?= (int)$sr['id'] ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php page_footer();
