<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

$msg    = '';
$errors = [];

// --- Admin: handle manual prune request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('admin');
    csrf_require();
    if (to_str($_POST['action'] ?? '') === 'prune_now') {
        $retDays = to_int(ipam_setting('housekeeping.audit_log_retention_days'));
        if ($retDays > 0) {
            $pruned = prune_audit_log($db, $retDays);
            if ($pruned > 0) {
                audit($db, 'audit.pruned', 'system', null,
                    "Manually pruned {$pruned} audit log " . ($pruned === 1 ? 'entry' : 'entries')
                    . " older than {$retDays} days.");
            }
            $msg = "Pruned " . number_format($pruned) . " audit log "
                 . ($pruned === 1 ? 'entry' : 'entries') . " (retention: {$retDays} days).";
        } else {
            $errors[] = 'Retention is set to 0 (keep forever). Update the setting to enable pruning.';
        }
    }
}

// --- Valid action prefixes (categories) ---
const AUDIT_PREFIXES = [
    'address', 'aggregate', 'alert', 'apikey', 'audit', 'auth', 'backup',
    'config', 'contact', 'custom_field', 'db', 'device', 'device_interface',
    'dhcp_pool', 'export', 'import', 'mail', 'pd_pool', 'restore', 'scan',
    'setting', 'settings', 'site', 'subnet', 'tag', 'user', 'vlan', 'vrf',
    'webhook',
];

$filterPrefix = trim(to_str($_GET['prefix'] ?? ''));
if ($filterPrefix !== '' && !in_array($filterPrefix, AUDIT_PREFIXES, true)) {
    $filterPrefix = '';
}

// --- Exact action filter (e.g. ?action=auth.login from login-history links) ---
$filterAction = trim(to_str($_GET['action'] ?? ''));
// Validate: must be non-empty and match <prefix>.<verb> pattern (no injection surface)
if ($filterAction !== '' && !preg_match('/^[a-z_]+\.[a-z_]+$/', $filterAction)) {
    $filterAction = '';
}

// --- User ID filter (e.g. ?user_id=3 from users.php login-history link) ---
$filterUserId = to_int($_GET['user_id'] ?? 0);

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
if ($filterAction !== '') {
    $wheres[] = 'action = :act';
    $params[':act'] = $filterAction;
}
if ($filterUserId > 0) {
    $wheres[] = 'user_id = :uid';
    $params[':uid'] = $filterUserId;
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
                  string $sort = 'time', string $dir = 'desc',
                  string $action = '', int $userId = 0): string
{
    $p = ['page' => $page, 'page_size' => $limit, 'sort' => $sort, 'dir' => $dir];
    if ($prefix !== '') $p['prefix']  = $prefix;
    if ($from   !== '') $p['from']    = $from;
    if ($to     !== '') $p['to']      = $to;
    if ($action !== '') $p['action']  = $action;
    if ($userId  > 0)   $p['user_id'] = $userId;
    return '?' . http_build_query($p);
}

$hasFilter = $filterPrefix !== '' || $filterFrom !== '' || $filterTo !== ''
          || $filterAction !== '' || $filterUserId > 0;

// --- Admin: gather retention stats for the info panel ---
$isAdmin = current_user()['role'] === 'admin';
$retentionPanel = null;
if ($isAdmin) {
    $retDays  = to_int(ipam_setting('housekeeping.audit_log_retention_days'));
    $statSt   = $db->query("SELECT COUNT(*) AS c, MIN(created_at) AS oldest FROM audit_log");
    $statRow  = $statSt !== false ? $statSt->fetch() : false;
    $retentionPanel = [
        'days'   => $retDays,
        'total'  => is_array($statRow) ? to_int($statRow['c'])         : 0,
        'oldest' => is_array($statRow) ? to_str($statRow['oldest'] ?? '') : '',
    ];
}

page_header('Audit Log');
?>

<div class="breadcrumbs">
  <a href="dashboard.php">🏠 Dashboard</a>
  <span class="sep">›</span>
  <span>📜 Audit</span>
</div>

<?php if ($msg !== ''): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>
<?php if ($errors): ?><ul class="errors"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul><?php endif; ?>

<?php if ($retentionPanel !== null): ?>
<div class="card mb-12" style="padding:10px 16px;">
  <div class="row align-center gap-24 flex-wrap">
    <div>
      <span class="muted">Retention:</span>
      <b><?= $retentionPanel['days'] > 0 ? e((string)$retentionPanel['days']) . ' days' : 'keep forever' ?></b>
      <a href="settings.php" class="muted ml-8" style="font-size:.85em">⚙ Change</a>
    </div>
    <div>
      <span class="muted">Total entries:</span>
      <b><?= e(number_format($retentionPanel['total'])) ?></b>
    </div>
    <?php if ($retentionPanel['oldest'] !== ''): ?>
    <div>
      <span class="muted">Oldest entry:</span>
      <b><?= e(ipam_format_datetime($retentionPanel['oldest'])) ?></b>
    </div>
    <?php endif; ?>
    <?php if ($retentionPanel['days'] > 0): ?>
    <form method="post" action="audit.php" class="m-0" onsubmit="return confirm('Prune audit log entries older than <?= e((string)$retentionPanel['days']) ?> days?')">
      <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="prune_now">
      <button type="submit" class="button-secondary">🗑 Prune now</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

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
    <table class="data-table">
      <thead>
        <tr>
          <?php $auditQsBase = '?' . http_build_query(array_filter([
                    'page_size' => $limit,
                    'prefix'    => $filterPrefix !== '' ? $filterPrefix : null,
                    'action'    => $filterAction !== '' ? $filterAction : null,
                    'user_id'   => $filterUserId > 0 ? $filterUserId : null,
                    'from'      => $filterFrom !== '' ? $filterFrom : null,
                    'to'        => $filterTo !== '' ? $filterTo : null,
                ], fn($v) => $v !== null));
                echo sort_th('time',   'Time',      $auditSort['col'], $auditSort['dir'], $auditQsBase); // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag
                echo sort_th('user',   'User',      $auditSort['col'], $auditSort['dir'], $auditQsBase); // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag
                echo sort_th('action', 'Action',    $auditSort['col'], $auditSort['dir'], $auditQsBase); // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag
                echo sort_th('entity', 'Entity',    $auditSort['col'], $auditSort['dir'], $auditQsBase); // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag
          ?>
          <th>Client IP</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="muted"><?= e(ipam_format_datetime(to_str($r['created_at']))) ?></td>
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
        <a href="<?= e(audit_qs($page - 1, $limit, $filterPrefix, $filterFrom, $filterTo, $auditSort['col'], $auditSort['dir'], $filterAction, $filterUserId)) ?>">&laquo; Prev</a>
      <?php endif; ?>
      <?php if ($page < $pages): ?>
        <a href="<?= e(audit_qs($page + 1, $limit, $filterPrefix, $filterFrom, $filterTo, $auditSort['col'], $auditSort['dir'], $filterAction, $filterUserId)) ?>" class="ml-12">Next &raquo;</a>
      <?php endif; ?>
    </p>
  <?php endif; ?>
</div>

<?php page_footer();
