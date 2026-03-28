<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_role('admin');

// --- Valid action prefixes (categories) ---
const AUDIT_PREFIXES = ['auth', 'subnet', 'address', 'user', 'site', 'apikey', 'dhcp_pool', 'export', 'import'];

$filterPrefix = trim((string)($_GET['prefix'] ?? ''));
if ($filterPrefix !== '' && !in_array($filterPrefix, AUDIT_PREFIXES, true)) {
    $filterPrefix = '';
}

$page  = q_int('page', 1, 1, 1000000);
$limit = q_int('page_size', 100, 1, 500);

// --- Count with optional filter ---
if ($filterPrefix !== '') {
    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM audit_log WHERE action LIKE :p");
    $cntSt->execute([':p' => $filterPrefix . '.%']);
} else {
    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM audit_log");
    $cntSt->execute();
}
$total = (int)$cntSt->fetch()['c'];
$pages = (int)max(1, ceil($total / $limit));

if ($page > $pages) {
    $page   = $pages;
}
$offset = ($page - 1) * $limit;

// --- Fetch rows ---
if ($filterPrefix !== '') {
    $st = $db->prepare("
        SELECT id, created_at, username, action, entity_type, entity_id, ip, details
        FROM audit_log
        WHERE action LIKE :p
        ORDER BY id DESC
        LIMIT :lim OFFSET :off
    ");
    $st->bindValue(':p',   $filterPrefix . '.%');
    $st->bindValue(':lim', $limit,  PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
} else {
    $st = $db->prepare("
        SELECT id, created_at, username, action, entity_type, entity_id, ip, details
        FROM audit_log
        ORDER BY id DESC
        LIMIT :lim OFFSET :off
    ");
    $st->bindValue(':lim', $limit,  PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
}
$st->execute();
$rows = $st->fetchAll();

// Build a query string preserving filter+page_size across pagination links
function audit_qs(int $page, int $limit, string $prefix): string
{
    $p = ['page' => $page, 'page_size' => $limit];
    if ($prefix !== '') $p['prefix'] = $prefix;
    return '?' . http_build_query($p);
}

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

<div class="page-actions" style="align-items:center;gap:12px">
  <a class="action-pill" href="export_audit.php">⬇ Export CSV</a>
  <form method="get" action="audit.php" style="display:flex;gap:8px;align-items:center;margin:0">
    <label style="margin:0">Filter:
      <select name="prefix" onchange="this.form.submit()" style="margin-left:4px">
        <option value=""<?= $filterPrefix === '' ? ' selected' : '' ?>>All actions</option>
        <?php foreach (AUDIT_PREFIXES as $pfx): ?>
          <option value="<?= e($pfx) ?>"<?= $filterPrefix === $pfx ? ' selected' : '' ?>><?= e($pfx) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <input type="hidden" name="page_size" value="<?= $limit ?>">
    <?php if ($filterPrefix !== ''): ?>
      <a href="audit.php?page_size=<?= $limit ?>">✕ Clear</a>
    <?php endif; ?>
  </form>
</div>

<div class="card" style="margin-top:16px">
  <?php if (!$rows): ?>
    <div class="empty-state">No audit entries<?= $filterPrefix !== '' ? ' for category <b>' . e($filterPrefix) . '</b>' : '' ?>.</div>
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
        <a href="<?= e(audit_qs($page - 1, $limit, $filterPrefix)) ?>">&laquo; Prev</a>
      <?php endif; ?>
      <?php if ($page < $pages): ?>
        <a href="<?= e(audit_qs($page + 1, $limit, $filterPrefix)) ?>" style="margin-left:12px">Next &raquo;</a>
      <?php endif; ?>
    </p>
  <?php endif; ?>
</div>

<?php page_footer();
