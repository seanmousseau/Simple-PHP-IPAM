<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

// --- Valid action prefixes (categories) ---
const AUDIT_PREFIXES = ['auth', 'subnet', 'address', 'user', 'site', 'apikey', 'dhcp_pool', 'export', 'import'];

$filterPrefix = trim((string)($_GET['prefix'] ?? ''));
if ($filterPrefix !== '' && !in_array($filterPrefix, AUDIT_PREFIXES, true)) {
    $filterPrefix = '';
}

// --- Date range filter (sanitised through strtotime → Y-m-d) ---
$filterFrom = '';
$filterTo   = '';
$rawFrom = trim((string)($_GET['from'] ?? ''));
$rawTo   = trim((string)($_GET['to']   ?? ''));
if ($rawFrom !== '' && ($ts = strtotime($rawFrom)) !== false) {
    $filterFrom = date('Y-m-d', $ts);
}
if ($rawTo !== '' && ($ts = strtotime($rawTo)) !== false) {
    $filterTo = date('Y-m-d', $ts);
}

$page  = q_int('page', 1, 1, 1000000);
$limit = q_int('page_size', 100, 1, 500);

// --- Build WHERE clause ---
$wheres = [];
$params = [];
if ($filterPrefix !== '') {
    $wheres[] = 'action LIKE :pfx';
    $params[':pfx'] = $filterPrefix . '.%';
}
if ($filterFrom !== '') {
    $wheres[] = 'created_at >= :from';
    $params[':from'] = $filterFrom . ' 00:00:00';
}
if ($filterTo !== '') {
    $wheres[] = 'created_at < :to';
    $params[':to'] = date('Y-m-d', strtotime($filterTo . ' +1 day')) . ' 00:00:00';
}
$where = $wheres ? 'WHERE ' . implode(' AND ', $wheres) : '';

// --- Count ---
$cntSt = $db->prepare("SELECT COUNT(*) AS c FROM audit_log $where");
$cntSt->execute($params);
$total = (int)$cntSt->fetch()['c'];
$pages = (int)max(1, ceil($total / $limit));

if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $limit;

// --- Fetch rows ---
$st = $db->prepare("
    SELECT id, created_at, username, action, entity_type, entity_id, ip, details
    FROM audit_log
    $where
    ORDER BY id DESC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':lim', $limit,  PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

// Build a query string preserving all filters across pagination links
function audit_qs(int $page, int $limit, string $prefix, string $from, string $to): string
{
    $p = ['page' => $page, 'page_size' => $limit];
    if ($prefix !== '') $p['prefix'] = $prefix;
    if ($from   !== '') $p['from']   = $from;
    if ($to     !== '') $p['to']     = $to;
    return '?' . http_build_query($p);
}

$hasFilter = $filterPrefix !== '' || $filterFrom !== '' || $filterTo !== '';

page_header('Audit Log');
?>

<div class="breadcrumbs">
  <a href="dashboard.php">🏠 Dashboard</a>
  <span class="sep">›</span>
  <span>📜 Audit</span>
</div>

<div class="toolbar">
  <div>
    <h1>Audit Log</h1>
    <div class="muted">
      Events: <b><?= e((string)$total) ?></b>
      <?php if ($filterPrefix !== ''): ?>
        <span class="badge"><?= e($filterPrefix) ?></span>
      <?php endif; ?>
      <?php if ($total > 0): ?>
        &nbsp;|&nbsp; Page <b><?= e((string)$page) ?></b> of <b><?= e((string)$pages) ?></b>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="page-actions" style="align-items:center;gap:12px;flex-wrap:wrap">
  <a class="action-pill" href="export_audit.php">⬇ Export CSV</a>
  <form method="get" action="audit.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0">
    <label style="margin:0">Category:
      <select name="prefix" data-auto-submit style="margin-left:4px">
        <option value=""<?= $filterPrefix === '' ? ' selected' : '' ?>>All</option>
        <?php foreach (AUDIT_PREFIXES as $pfx): ?>
          <option value="<?= e($pfx) ?>"<?= $filterPrefix === $pfx ? ' selected' : '' ?>><?= e($pfx) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="margin:0">From:
      <input type="date" name="from" value="<?= e($filterFrom) ?>" style="margin-left:4px">
    </label>
    <label style="margin:0">To:
      <input type="date" name="to" value="<?= e($filterTo) ?>" style="margin-left:4px">
    </label>
    <button type="submit">Apply</button>
    <input type="hidden" name="page_size" value="<?= $limit ?>">
    <?php if ($hasFilter): ?>
      <a href="audit.php?page_size=<?= $limit ?>">✕ Clear</a>
    <?php endif; ?>
  </form>
</div>

<div class="card" style="margin-top:16px">
  <?php if (!$rows): ?>
    <div class="empty-state">No audit entries<?= $hasFilter ? ' matching the current filter' : '' ?>.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Time</th>
          <th>User</th>
          <th>Action</th>
          <th>Entity</th>
          <th>Client IP</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="muted"><?= e($r['created_at']) ?></td>
          <td><?= e((string)($r['username'] ?? '')) ?></td>
          <td><?= e($r['action']) ?></td>
          <td><?= e($r['entity_type']) ?>#<?= e((string)($r['entity_id'] ?? '')) ?></td>
          <td class="muted"><?= e((string)($r['ip'] ?? '')) ?></td>
          <td class="muted"><?= e((string)($r['details'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <p style="margin-top:12px">
      <?php if ($page > 1): ?>
        <a href="<?= e(audit_qs($page - 1, $limit, $filterPrefix, $filterFrom, $filterTo)) ?>">&laquo; Prev</a>
      <?php endif; ?>
      <?php if ($page < $pages): ?>
        <a href="<?= e(audit_qs($page + 1, $limit, $filterPrefix, $filterFrom, $filterTo)) ?>" style="margin-left:12px">Next &raquo;</a>
      <?php endif; ?>
    </p>
  <?php endif; ?>
</div>

<?php page_footer();
