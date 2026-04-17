<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

// --- Valid action prefixes (categories) ---
const AUDIT_PREFIXES = ['auth', 'subnet', 'address', 'user', 'site', 'apikey', 'dhcp_pool', 'db', 'export', 'import'];

$filterPrefix = trim(to_str($_GET['prefix'] ?? ''));
if ($filterPrefix !== '' && !in_array($filterPrefix, AUDIT_PREFIXES, true)) {
    $filterPrefix = '';
}

// --- Date range filter (sanitised through strtotime → Y-m-d) ---
$filterFrom = '';
$filterTo   = '';
$rawFrom = trim(to_str($_GET['from'] ?? ''));
$rawTo   = trim(to_str($_GET['to']   ?? ''));
if ($rawFrom !== '' && ($ts = strtotime($rawFrom)) !== false) {
    $filterFrom = date('Y-m-d', $ts);
}
if ($rawTo !== '' && ($ts = strtotime($rawTo)) !== false) {
    $filterTo = date('Y-m-d', $ts);
}

$page  = q_int('page', 1, 1, 1000000);
$limit = q_int('page_size', 50, 1, 500);

$auditSortCols = ['time' => 'created_at', 'user' => 'username',
                  'action' => 'action', 'entity' => 'entity_type'];
$auditSort = parse_sort($auditSortCols, 'time', 'desc');

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
    $params[':to'] = date('Y-m-d', (int)strtotime($filterTo . ' +1 day')) . ' 00:00:00';
}
$where = $wheres ? 'WHERE ' . implode(' AND ', $wheres) : '';

// --- Count ---
$cntSt = $db->prepare("SELECT COUNT(*) AS c FROM audit_log $where");
$cntSt->execute($params);
/** @var array<string, mixed>|false $cntRow */

$cntRow = $cntSt->fetch();

$total = is_array($cntRow) ? to_int($cntRow['c']) : 0;
$pages = (int)max(1, ceil($total / $limit));

if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $limit;

// --- Fetch rows ---
$st = $db->prepare("
    SELECT id, created_at, username, action, entity_type, entity_id, ip, details
    FROM audit_log
    $where
    ORDER BY {$auditSort['sql']}
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':lim', $limit,  PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
/** @var list<array<string, mixed>> $rows */
$rows = $st->fetchAll();

// Build a query string preserving all filters across pagination links
function audit_qs(int $page, int $limit, string $prefix, string $from, string $to,
                  string $sort = 'time', string $dir = 'desc'): string
{
    $p = ['page' => $page, 'page_size' => $limit, 'sort' => $sort, 'dir' => $dir];
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

<div class="page-actions align-center gap-12 flex-wrap">
  <a class="action-pill" href="export_audit.php">⬇ Export CSV</a>
  <form method="get" action="audit.php" class="row gap-8 m-0 align-center">
    <label class="m-0 label-inline">Category:
      <select name="prefix" data-auto-submit>
        <option value=""<?= $filterPrefix === '' ? ' selected' : '' ?>>All</option>
        <?php foreach (AUDIT_PREFIXES as $pfx): ?>
          <option value="<?= e($pfx) ?>"<?= $filterPrefix === $pfx ? ' selected' : '' ?>><?= e($pfx) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="m-0 label-inline">From:
      <input type="date" name="from" value="<?= e($filterFrom) ?>">
    </label>
    <label class="m-0 label-inline">To:
      <input type="date" name="to" value="<?= e($filterTo) ?>">
    </label>
    <button type="submit">Apply</button>
    <input type="hidden" name="page_size" value="<?= $limit ?>">
    <?php if ($hasFilter): ?>
      <a href="audit.php?page_size=<?= $limit ?>">✕ Clear</a>
    <?php endif; ?>
  </form>
</div>

<div class="card mt-16">
  <?php if (!$rows): ?>
    <div class="empty-state">No audit entries<?= $hasFilter ? ' matching the current filter' : '' ?>.<?= $hasFilter ? ' <a class="action-pill" href="audit.php">Clear filters</a>' : '' ?></div>
  <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <?php $auditQsBase = '?' . http_build_query(array_filter([
                    'page_size' => $limit,
                    'prefix'    => $filterPrefix !== '' ? $filterPrefix : null,
                    'from'      => $filterFrom !== '' ? $filterFrom : null,
                    'to'        => $filterTo !== '' ? $filterTo : null,
                ], fn($v) => $v !== null));
                echo sort_th('time',   'Time',      $auditSort['col'], $auditSort['dir'], $auditQsBase);
                echo sort_th('user',   'User',      $auditSort['col'], $auditSort['dir'], $auditQsBase);
                echo sort_th('action', 'Action',    $auditSort['col'], $auditSort['dir'], $auditQsBase);
                echo sort_th('entity', 'Entity',    $auditSort['col'], $auditSort['dir'], $auditQsBase);
          ?>
          <th>Client IP</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="muted"><?= e(display_datetime(to_str($r['created_at']))) ?></td>
          <td><?= e(to_str($r['username'] ?? '')) ?></td>
          <td><?= e(to_str($r['action'])) ?></td>
          <td><?= e(to_str($r['entity_type'])) ?>#<?= e(to_str($r['entity_id'] ?? '')) ?></td>
          <td class="muted ip-cell"><?= $r['ip'] !== null ? e(to_str($r['ip'])) : '—' ?></td>
          <td class="muted"><span class="audit-details" tabindex="0" title="<?= e(to_str($r['details'] ?? '')) ?>"><?= e(to_str($r['details'] ?? '')) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <p class="mt-12">
      <?php if ($page > 1): ?>
        <a href="<?= e(audit_qs($page - 1, $limit, $filterPrefix, $filterFrom, $filterTo, $auditSort['col'], $auditSort['dir'])) ?>">&laquo; Prev</a>
      <?php endif; ?>
      <?php if ($page < $pages): ?>
        <a href="<?= e(audit_qs($page + 1, $limit, $filterPrefix, $filterFrom, $filterTo, $auditSort['col'], $auditSort['dir'])) ?>" class="ml-12">Next &raquo;</a>
      <?php endif; ?>
    </p>
  <?php endif; ?>
</div>

<?php page_footer();
