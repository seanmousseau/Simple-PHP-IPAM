<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');

$page = max(1, to_int($_GET['page'] ?? 1));
$perPage = 50;

$filterDest   = to_int($_GET['destination_id'] ?? 0);
$filterStatus = to_str($_GET['status'] ?? '');
// Validate date inputs strictly — only YYYY-MM-DD digits accepted
// $filterFrom/$filterTo are safe for SQL params; $safeFrom/$safeTo are HTML-escaped for output
$rawFrom    = to_str($_GET['from'] ?? '');
$rawTo      = to_str($_GET['to']   ?? '');
$filterFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFrom) ? $rawFrom : '';
$filterTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawTo)   ? $rawTo   : '';
$safeFrom   = htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8');
$safeTo     = htmlspecialchars($filterTo,   ENT_QUOTES, 'UTF-8');

$where = [];
$params = [];
if ($filterDest > 0) { $where[] = 'l.destination_id = :d'; $params[':d'] = $filterDest; }
if (in_array($filterStatus, ['running', 'success', 'failed', 'retention_pruned'], true)) {
    $where[] = 'l.status = :s'; $params[':s'] = $filterStatus;
}
if ($filterFrom !== '') { $where[] = 'l.started_at >= :from'; $params[':from'] = $filterFrom; }
if ($filterTo !== '')   { $where[] = 'l.started_at <= :to';   $params[':to']   = $filterTo;   }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM backup_log l $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$pages = max(1, (int) ceil($total / $perPage));
if ($page > $pages) { $page = $pages; }
$offset = ($page - 1) * $perPage;

// Page rows — LIMIT/OFFSET via bound params to satisfy static-analysis tools
$rowSql = "SELECT l.*, d.name AS dest_name
           FROM backup_log l
           LEFT JOIN backup_destinations d ON d.id = l.destination_id
           $whereSql
           ORDER BY l.started_at DESC
           LIMIT :lim OFFSET :off";
$rowsStmt = $db->prepare($rowSql);
foreach ($params as $k => $v) { $rowsStmt->bindValue($k, $v); }
$rowsStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$rowsStmt->bindValue(':off', $offset,  PDO::PARAM_INT);
$rowsStmt->execute();
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

// Stats: last successful per destination, total storage, next scheduled
$stats = $db->query(
    "SELECT d.id AS dest_id, d.name AS dest_name,
            (SELECT MAX(started_at) FROM backup_log
              WHERE destination_id = d.id AND status = 'success') AS last_success,
            (SELECT COALESCE(SUM(size_bytes), 0) FROM backup_log
              WHERE destination_id = d.id AND status = 'success') AS total_bytes,
            (SELECT MIN(next_run_at) FROM backup_schedules
              WHERE destination_id = d.id AND is_active = 1) AS next_run
     FROM backup_destinations d
     WHERE d.is_active = 1
     ORDER BY d.name");
$stats = ($stats !== false) ? $stats->fetchAll(PDO::FETCH_ASSOC) : [];

$destStmt = $db->query("SELECT id, name FROM backup_destinations ORDER BY name");
$destinations = ($destStmt !== false) ? $destStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Safe query string for pagination links — only known scalar filter values
function bh_qs(int $dest, string $status, string $from, string $to, int $page): string {
    $parts = [];
    if ($dest > 0)     { $parts[] = 'destination_id=' . $dest; }
    if ($status !== '') { $parts[] = 'status=' . urlencode($status); }
    if ($from !== '')   { $parts[] = 'from=' . urlencode($from); }
    if ($to !== '')     { $parts[] = 'to=' . urlencode($to); }
    if ($page > 1)     { $parts[] = 'page=' . $page; }
    return $parts ? ('?' . implode('&', $parts)) : '';
}

page_header('Backup History');
?>
<main class="container">
  <h1>Backup History</h1>
  <p class="muted">Read-only log of all backup runs. <a href="destinations.php">Manage destinations →</a></p>

  <?php if (!empty($stats)): ?>
  <section class="card">
    <h2>Status by destination</h2>
    <table class="data-table">
      <thead><tr><th>Destination</th><th>Last successful</th><th>Total stored</th><th>Next scheduled</th></tr></thead>
      <tbody>
      <?php foreach ($stats as $s): ?>
        <tr>
          <td><?= e(to_str($s['dest_name'])) ?></td>
          <td><?= e(to_str($s['last_success'] ?? '—')) ?></td>
          <td><?= number_format((int) $s['total_bytes']) ?> bytes</td>
          <td><?= e(to_str($s['next_run'] ?? '—')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2>Filter</h2>
    <form method="get" class="filter-bar">
      <label>Destination
        <select name="destination_id">
          <option value="0">— Any —</option>
          <?php foreach ($destinations as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= $filterDest === (int)$d['id'] ? 'selected' : '' ?>><?= e(to_str($d['name'])) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Status
        <select name="status">
          <option value="">— Any —</option>
          <?php foreach (['running','success','failed','retention_pruned'] as $st): ?>
            <option value="<?= e($st) ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= e($st) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>From <input type="date" name="from" value="<?= e($filterFrom) ?>"></label>
      <label>To <input type="date" name="to" value="<?= e($filterTo) ?>"></label>
      <button type="submit" class="action-pill">Filter</button>
      <a class="action-pill" href="backup_history.php">Reset</a>
    </form>
  </section>

  <section class="card">
    <h2>Log entries (<?= number_format($total) ?>)</h2>
    <?php if (count($rows) === 0): ?>
      <p class="muted">No backup runs found.</p>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Started</th><th>Destination</th><th>Trigger</th><th>Status</th><th>Filename</th><th>Size</th><th>Duration</th><th>Checksum</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          $started   = to_str($r['started_at']);
          $completed = to_str($r['completed_at'] ?? '');
          $duration  = '—';
          if ($completed !== '' && $started !== '') {
              $secs     = max(0, strtotime($completed) - strtotime($started));
              $duration = $secs . 's';
          }
          $statusVal   = to_str($r['status']);
          $statusClass = 'badge-' . $statusVal;
          $cs          = to_str($r['checksum'] ?? '');
          $csShort     = $cs !== '' ? (substr($cs, 0, 12) . '…') : '—';
        ?>
          <tr>
            <td><?= e($started) ?></td>
            <td><?= e(to_str($r['dest_name'] ?? 'unknown')) ?></td>
            <td><?= e(to_str($r['triggered_by'])) ?></td>
            <td><span class="badge <?= e($statusClass) ?>"><?= e($statusVal) ?></span>
                <?php if ($statusVal === 'failed' && to_str($r['error_message'] ?? '') !== ''): ?>
                  <details><summary class="muted">error</summary><pre><?= e(to_str($r['error_message'])) ?></pre></details>
                <?php endif; ?>
            </td>
            <td><?= e(to_str($r['filename'] ?? '—')) ?></td>
            <td><?= $r['size_bytes'] !== null ? number_format((int)$r['size_bytes']) : '—' ?></td>
            <td><?= e($duration) ?></td>
            <td title="<?= e($cs) ?>"><?= e($csShort) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($pages > 1): ?>
      <nav class="pagination">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
          <a class="action-pill <?= $p === $page ? 'is-active' : '' ?>"
             href="<?= e(bh_qs($filterDest, $filterStatus, $filterFrom, $filterTo, $p)) ?>"><?= $p ?></a>
        <?php endfor; ?>
      </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</main>
<?php page_footer();
