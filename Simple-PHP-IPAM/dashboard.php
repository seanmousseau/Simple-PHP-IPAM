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
// Reuse the shared utilization function that excludes infrastructure IPs (#566)
$utilData = ipv4_unassigned_summary($db);
$st = $db->prepare("SELECT id, cidr, prefix, description FROM subnets WHERE ip_version = 4 AND prefix BETWEEN 8 AND 32");
$st->execute();
/** @var list<array<string, mixed>> $topSubnets */
$topSubnets = [];
foreach ($st->fetchAll() as $row) {
    $sid = to_int($row['id']);
    $u   = $utilData[$sid] ?? null;
    if ($u === null || $u['assigned_assignable'] <= 0) continue;
    $row['used_count'] = $u['assigned_assignable'];
    $topSubnets[] = $row;
}

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

/* --- Growth trend: addresses added in last 7 and 30 days ---
 * Cutoffs computed in PHP so the query stays engine-agnostic. The SQLite
 * relative-time modifier form is not portable to MySQL / Postgres — an
 * earlier attempt caused a SQL 1064 on every dashboard load against MySQL
 * in v2.10.0 #433 Playwright validation.
 */
$cutoffWeek  = gmdate('Y-m-d H:i:s', time() - 7  * 86400);
$cutoffMonth = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
$gWkSt = $db->prepare("SELECT COUNT(*) FROM addresses WHERE created_at >= :c");
$gWkSt->execute([':c' => $cutoffWeek]);
$growthWeek = to_int($gWkSt->fetchColumn());
$gMoSt = $db->prepare("SELECT COUNT(*) FROM addresses WHERE created_at >= :c");
$gMoSt->execute([':c' => $cutoffMonth]);
$growthMonth = to_int($gMoSt->fetchColumn());

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

<?php
/** @var list<string> $_staleKeys */
$_staleKeys = $GLOBALS['config_stale_keys'] ?? [];
if ($_staleKeys && current_user()['role'] === 'admin'):
?>
  <div class="admin-notice admin-notice--warning" role="alert">
    &#9888; <strong>config.php cleanup needed:</strong>
    <?= count($_staleKeys) ?> non-bootstrap key(s) found in <code>config.php</code> that are no longer read.
    Remove them manually: <code><?= e(implode(', ', $_staleKeys)) ?></code>
  </div>
<?php endif; unset($_staleKeys); ?>

<div class="page-actions">
  <a class="action-pill" href="subnets.php#add-subnet">➕ Add Subnet</a>
  <a class="action-pill" href="search.php">🔎 Search</a>
  <a class="action-pill" href="subnets.php">🌐 Subnets</a>
  <?php if (current_user()['role'] === 'admin'): ?>
    <a class="action-pill" href="import_csv.php">📥 Import CSV</a>
  <?php endif; ?>
  <a class="action-pill muted" href="#" id="dash-reset" title="Reset widget layout and filters">↺ Reset widgets</a>
</div>

<div class="grid cols-3 mt-16" data-widget="metrics">
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

  <div class="card" data-widget="top-subnets">
    <div class="widget-header">
      <h2>Top IPv4 Subnets by Usage</h2>
      <button class="widget-hide-btn" data-widget-key="top-subnets" title="Hide widget">&#10005;</button>
    </div>
    <?php if (!$topSubnets): ?>
      <div class="empty-state">No IPv4 subnets in /8–/32 range.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Subnet</th><th>Description</th><th>Used</th><th>Capacity</th><th>Fill</th></tr>
        </thead>
        <tbody>
        <?php
            $dashWarn = to_int(ipam_setting('display.utilization_warn'));
            $dashCrit = to_int(ipam_setting('display.utilization_critical'));
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

  <div class="card" data-widget="by-site">
    <div class="widget-header">
      <h2>Addresses by Site</h2>
      <button class="widget-hide-btn" data-widget-key="by-site" title="Hide widget">&#10005;</button>
    </div>
    <?php if (!$bySite): ?>
      <div class="empty-state">No data yet.</div>
    <?php else: ?>
      <div class="mb-8">
        <select id="dash-site-filter" class="action-pill">
          <option value="">(all sites)</option>
          <?php foreach ($bySite as $r): ?>
            <option value="<?= e(to_str($r['site_name'])) ?>"><?= e(to_str($r['site_name'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <table>
        <thead>
          <tr><th>Site</th><th>Used</th><th>Reserved</th><th>Free</th><th>Total</th></tr>
        </thead>
        <tbody>
        <?php foreach ($bySite as $r): ?>
          <tr data-site-row="<?= e(to_str($r['site_name'])) ?>">
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

<div class="card mt-16" data-widget="recent-activity">
  <div class="widget-header">
    <h2>Recent Activity</h2>
    <button class="widget-hide-btn" data-widget-key="recent-activity" title="Hide widget">&#10005;</button>
  </div>
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
          <td class="muted nowrap"><?= e(display_datetime(to_str($r['created_at']))) ?></td>
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
