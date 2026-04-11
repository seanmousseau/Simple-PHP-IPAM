<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */
require_login();

/* --- Summary counts --- */
$st = $db->prepare("SELECT COUNT(*) AS c FROM subnets");
$st->execute();
/** @var array<string, mixed>|false $cntRow */

$cntRow = $st->fetch();

$totalSubnets = is_array($cntRow) ? to_int($cntRow['c']) : 0;

$st = $db->prepare("SELECT COUNT(*) AS c FROM addresses");
$st->execute();
/** @var array<string, mixed>|false $cntRow */

$cntRow = $st->fetch();

$totalAddrs = is_array($cntRow) ? to_int($cntRow['c']) : 0;

$st = $db->prepare("SELECT status, COUNT(*) AS c FROM addresses GROUP BY status");
$st->execute();
$statusMap = ['used' => 0, 'reserved' => 0, 'free' => 0];
foreach ($st->fetchAll() as $r) {
    if (isset($statusMap[to_str($r['status'])])) $statusMap[to_str($r['status'])] = to_int($r['c']);
}

/* --- IPv4 / IPv6 subnet split --- */
$st = $db->prepare("SELECT ip_version, COUNT(*) AS c FROM subnets GROUP BY ip_version");
$st->execute();
$verCounts = [4 => 0, 6 => 0];
foreach ($st->fetchAll() as $r) $verCounts[to_int($r['ip_version'])] = to_int($r['c']);

/* --- Top IPv4 subnets by utilization % (/8–/32 only) --- */
$st = $db->prepare("
    SELECT s.id, s.cidr, s.prefix, s.description,
           COUNT(a.id) AS used_count
    FROM subnets s
    LEFT JOIN addresses a ON a.subnet_id = s.id AND a.status IN ('used','reserved')
    WHERE s.ip_version = 4 AND s.prefix BETWEEN 8 AND 32
    GROUP BY s.id
    HAVING used_count > 0
    ORDER BY used_count DESC
    LIMIT 50
");
$st->execute();
/** @var list<array<string, mixed>> $topSubnets */
$topSubnets = $st->fetchAll();
// Sort by utilization percentage (highest first)
usort($topSubnets, function (array $a, array $b): int {
    $capA = ipv4_assignable_count(to_int($a['prefix']));
    $capB = ipv4_assignable_count(to_int($b['prefix']));
    $pctA = $capA > 0 ? to_int($a['used_count']) / $capA : 0;
    $pctB = $capB > 0 ? to_int($b['used_count']) / $capB : 0;
    return $pctB <=> $pctA;
});
$topSubnets = array_slice($topSubnets, 0, 10);

/* --- Address counts grouped by site --- */
$st = $db->prepare("
    SELECT COALESCE(si.name, 'Ungrouped') AS site_name,
           SUM(CASE WHEN a.status = 'used'     THEN 1 ELSE 0 END) AS used,
           SUM(CASE WHEN a.status = 'reserved' THEN 1 ELSE 0 END) AS reserved,
           SUM(CASE WHEN a.status = 'free'     THEN 1 ELSE 0 END) AS free,
           COUNT(a.id) AS total
    FROM subnets s
    LEFT JOIN sites si ON si.id = s.site_id
    LEFT JOIN addresses a ON a.subnet_id = s.id
    GROUP BY s.site_id, si.name
    ORDER BY site_name ASC
");
$st->execute();
/** @var list<array<string, mixed>> $bySite */
$bySite = $st->fetchAll();

/* --- Growth trend: addresses added in last 7 and 30 days --- */
$gWkSt = $db->query("SELECT COUNT(*) FROM addresses WHERE created_at >= datetime('now', '-7 days')");
$growthWeek = $gWkSt !== false ? to_int($gWkSt->fetchColumn()) : 0;
$gMoSt = $db->query("SELECT COUNT(*) FROM addresses WHERE created_at >= datetime('now', '-30 days')");
$growthMonth = $gMoSt !== false ? to_int($gMoSt->fetchColumn()) : 0;

/* --- Recent audit events --- */
$st = $db->prepare("
    SELECT created_at, username, action, details
    FROM audit_log
    ORDER BY id DESC
    LIMIT 10
");
$st->execute();
/** @var list<array<string, mixed>> $recentAudit */
$recentAudit = $st->fetchAll();

page_header('Dashboard');
?>

<div class="breadcrumbs">
  <span>🏠 Dashboard</span>
</div>

<div class="toolbar">
  <div>
    <h1>Dashboard</h1>
    <div class="muted">System overview</div>
  </div>
</div>

<div class="page-actions">
  <a class="action-pill" href="subnets.php#add-subnet">➕ Add Subnet</a>
  <a class="action-pill" href="search.php">🔎 Search</a>
  <a class="action-pill" href="subnets.php">🌐 Subnets</a>
  <?php if (current_user()['role'] === 'admin'): ?>
    <a class="action-pill" href="import_csv.php">📥 Import CSV</a>
  <?php endif; ?>
</div>

<div class="grid cols-3 mt-16">
  <div class="metric"><div class="label">Subnets</div><div class="value"><?= e((string)$totalSubnets) ?></div></div>
  <div class="metric"><div class="label">Addresses (rows)</div><div class="value"><?= e((string)$totalAddrs) ?></div></div>
  <div class="metric"><div class="label">Used</div><div class="value status-used"><?= e(to_str($statusMap['used'])) ?></div></div>
  <div class="metric"><div class="label">Reserved</div><div class="value status-reserved"><?= e(to_str($statusMap['reserved'])) ?></div></div>
  <div class="metric"><div class="label">Free</div><div class="value status-free"><?= e(to_str($statusMap['free'])) ?></div></div>
  <div class="metric"><div class="label">IPv4 / IPv6 Subnets</div><div class="value"><?= e(to_str($verCounts[4])) ?> / <?= e(to_str($verCounts[6])) ?></div></div>
  <div class="metric"><div class="label">Added (7 days)</div><div class="value"><?= e((string)$growthWeek) ?></div></div>
  <div class="metric"><div class="label">Added (30 days)</div><div class="value"><?= e((string)$growthMonth) ?></div></div>
</div>

<div class="grid cols-2 mt-16">

  <div class="card">
    <h2>Top IPv4 Subnets by Usage</h2>
    <?php if (!$topSubnets): ?>
      <div class="empty-state">No IPv4 subnets in /8–/32 range.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Subnet</th><th>Description</th><th>Used</th><th>Capacity</th><th>Fill</th></tr>
        </thead>
        <tbody>
        <?php
            $dashWarn = to_int($config['utilization_warn']);
            $dashCrit = to_int($config['utilization_critical']);
        ?>
        <?php foreach ($topSubnets as $s):
            $cap  = ipv4_assignable_count(to_int($s['prefix']));
            $used = to_int($s['used_count']);
            $pct  = $cap > 0 ? min(100, (int)round($used / $cap * 100)) : 0;
            $barClass = $pct >= $dashCrit ? 'util-bar-fill--crit' : ($pct >= $dashWarn ? 'util-bar-fill--warn' : '');
        ?>
          <tr>
            <td><a href="addresses.php?subnet_id=<?= to_int($s['id']) ?>"><?= e(to_str($s['cidr'])) ?></a></td>
            <td class="muted"><?= e(to_str($s['description'])) ?></td>
            <td><?= e((string)$used) ?></td>
            <td><?= e((string)$cap) ?></td>
            <td class="mw-90">
              <div class="util-bar-block">
                <div class="util-bar-fill <?= $barClass ?>" data-pct="<?= $pct ?>"></div>
              </div>
              <span class="muted font-xs"><?= $pct ?>%</span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <div class="mt-10"><a class="action-pill" href="subnets.php">🌐 All Subnets</a></div>
  </div>

  <div class="card">
    <h2>Addresses by Site</h2>
    <?php if (!$bySite): ?>
      <div class="empty-state">No data yet.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Site</th><th>Used</th><th>Reserved</th><th>Free</th><th>Total</th></tr>
        </thead>
        <tbody>
        <?php foreach ($bySite as $r): ?>
          <tr>
            <td><?= e(to_str($r['site_name'])) ?></td>
            <td class="status-used"><?= e(to_str($r['used'])) ?></td>
            <td class="status-reserved"><?= e(to_str($r['reserved'])) ?></td>
            <td class="status-free"><?= e(to_str($r['free'])) ?></td>
            <td><b><?= e(to_str($r['total'])) ?></b></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <?php if (current_user()['role'] === 'admin'): ?>
      <div class="mt-10"><a class="action-pill" href="sites.php">📍 Manage Sites</a></div>
    <?php endif; ?>
  </div>

</div>

<div class="card mt-16">
  <h2>Recent Activity</h2>
  <?php if (!$recentAudit): ?>
    <div class="empty-state">No audit events yet.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>Time</th><th>User</th><th>Action</th><th>Details</th></tr>
      </thead>
      <tbody>
      <?php foreach ($recentAudit as $r): ?>
        <tr>
          <td class="muted nowrap"><?= e(to_str($r['created_at'])) ?></td>
          <td><?= e(to_str($r['username'])) ?></td>
          <td><?= e(to_str($r['action'])) ?></td>
          <td class="muted"><?= e(to_str($r['details'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="mt-10"><a class="action-pill" href="audit.php">📜 Full Audit Log</a></div>
  <?php endif; ?>
</div>

<?php page_footer();
