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
$page     = max(1, to_int($_GET['page'] ?? 1));
$perPage  = 100;
$offset   = ($page - 1) * $perPage;

$cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);

// --- Build WHERE ---
$wheres = ['us.snapped_at >= :cutoff'];
$params = [':cutoff' => $cutoff];

if ($subnetId > 0) {
    $wheres[] = 'us.subnet_id = :sid';
    $params[':sid'] = $subnetId;
}

$where = 'WHERE ' . implode(' AND ', $wheres);

// --- Count total rows for pagination ---
$countSt = $db->prepare(
    "SELECT COUNT(*) FROM utilization_snapshots us
     JOIN subnets s ON s.id = us.subnet_id
     $where"
);
foreach ($params as $k => $v) $countSt->bindValue($k, $v);
$countSt->execute();
$totalRows  = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// --- Fetch rows (paginated) ---
$st = $db->prepare(
    "SELECT us.subnet_id, s.cidr, us.snapped_at, us.used_count, us.free_count, us.total_hosts
     FROM utilization_snapshots us
     JOIN subnets s ON s.id = us.subnet_id
     $where
     ORDER BY us.subnet_id ASC, us.snapped_at DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':limit', $perPage, PDO::PARAM_INT);
$st->bindValue(':offset', $offset, PDO::PARAM_INT);
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

// Build base pagination URL preserving active filters (page excluded — appended per link).
$baseParams = array_filter(
    ['days' => $days !== 90 ? $days : null, 'subnet_id' => $subnetId > 0 ? $subnetId : null],
    fn($v) => $v !== null
);
$baseUrl = 'reports.php' . ($baseParams ? '?' . http_build_query($baseParams) . '&' : '?');

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
</div>
<?php
$from = $totalRows === 0 ? 0 : $offset + 1;
$to   = min($offset + $perPage, $totalRows);
?>
<div class="pagination-bar">
  <span class="muted"><?= e("Showing {$from}–{$to} of " . number_format($totalRows) . " rows") ?></span>
  <div class="pagination-controls">
    <?php if ($page > 1): ?>
      <a href="<?= e($baseUrl . 'page=' . ($page - 1)) ?>" class="action-pill">&laquo; Prev</a>
    <?php endif; ?>
    <span class="page-indicator">Page <?= e((string)$page) ?> of <?= e((string)$totalPages) ?></span>
    <?php if ($page < $totalPages): ?>
      <a href="<?= e($baseUrl . 'page=' . ($page + 1)) ?>" class="action-pill">Next &raquo;</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php page_footer();
