<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */
require_login();
require_role('admin');

// --- Parameters ---
$subnetId = to_int($_GET['subnet_id'] ?? 0);
$days     = max(1, min(365, to_int($_GET['days'] ?? 90)));

$cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);

// --- Build WHERE ---
$wheres = ['us.snapped_at >= :cutoff'];
$params = [':cutoff' => $cutoff];

if ($subnetId > 0) {
    $wheres[] = 'us.subnet_id = :sid';
    $params[':sid'] = $subnetId;
}

$where = 'WHERE ' . implode(' AND ', $wheres);

// --- Fetch rows (cap at 2000) ---
$st = $db->prepare(
    "SELECT us.subnet_id, s.cidr, us.snapped_at, us.used_count, us.free_count, us.total_hosts
     FROM utilization_snapshots us
     JOIN subnets s ON s.id = us.subnet_id
     $where
     ORDER BY us.subnet_id ASC, us.snapped_at DESC
     LIMIT 2000"
);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->execute();
/** @var list<array<string, mixed>> $rows */
$rows = $st->fetchAll();

// --- Subnet list for filter dropdown ---
$subnetsSt = $db->prepare(
    "SELECT DISTINCT s.id, s.cidr
     FROM utilization_snapshots us
     JOIN subnets s ON s.id = us.subnet_id
     ORDER BY s.cidr ASC"
);
$subnetsSt->execute();
/** @var list<array<string, mixed>> $allSubnets */
$allSubnets = $subnetsSt->fetchAll();

// Build export URL from sanitised integers — string built before HTML output
// so semgrep sees no taint flow into attribute output.
$exportUrl = 'export_utilization_history.php?' . http_build_query(
    array_filter(
        ['days' => $days, 'subnet_id' => $subnetId > 0 ? $subnetId : null],
        fn($v) => $v !== null
    )
);

page_header('Reports');
?>

<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a> &rsaquo;
  <span>Reports</span>
</div>

<div class="toolbar">
  <div>
    <h1>Utilization History</h1>
    <div class="muted">Snapshot records captured during housekeeping</div>
  </div>
  <div>
    <a class="action-pill" href="<?= e($exportUrl) ?>">&#8595; Export CSV</a>
  </div>
</div>

<form method="get" action="reports.php" class="toolbar mt-8">
  <div>
    <label for="rep-subnet">Subnet:</label>
    <select id="rep-subnet" name="subnet_id">
      <option value="">(all subnets)</option>
      <?php foreach ($allSubnets as $sn): ?>
        <option value="<?= to_int($sn['id']) ?>"<?= to_int($sn['id']) === $subnetId ? ' selected' : '' ?>><?= e(to_str($sn['cidr'])) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="rep-days">Last:</label>
    <select id="rep-days" name="days">
      <?php foreach ([7, 14, 30, 60, 90, 180, 365] as $d): ?>
        <option value="<?= $d ?>"<?= $d === $days ? ' selected' : '' ?>><?= $d ?> days</option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="action-pill">Filter</button>
</form>

<?php if (!$rows): ?>
  <div class="card mt-16">
    <div class="empty-state">No snapshot data yet for the selected criteria. Snapshots are captured during housekeeping.</div>
  </div>
<?php else: ?>
<div class="card mt-16">
  <table>
    <thead>
      <tr>
        <th>Subnet</th>
        <th>Snapshot Time</th>
        <th>Used</th>
        <th>Free</th>
        <th>Total</th>
        <th>Utilization %</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $total = to_int($r['total_hosts']);
        $used  = to_int($r['used_count']);
        $pct   = $total > 0 ? round($used / $total * 100, 1) : 0.0;
    ?>
      <tr>
        <td><a href="addresses.php?subnet_id=<?= to_int($r['subnet_id']) ?>"><?= e(to_str($r['cidr'])) ?></a></td>
        <td class="muted nowrap"><?= e(ipam_format_datetime(to_str($r['snapped_at']))) ?></td>
        <td><?= e((string)$used) ?></td>
        <td><?= e(to_str($r['free_count'])) ?></td>
        <td><?= e((string)$total) ?></td>
        <td><?= e(number_format($pct, 1)) ?>%</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (count($rows) === 2000): ?>
    <div class="muted mt-8" style="font-size:.85rem">Showing first 2000 rows. Use the CSV export for full data.</div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php page_footer();
