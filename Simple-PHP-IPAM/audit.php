<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_role('admin');

$page = q_int('page', 1, 1, 1000000);
$limit = q_int('page_size', 100, 1, 500);
$offset = ($page - 1) * $limit;

$st = $db->prepare("SELECT COUNT(*) AS c FROM audit_log");
$st->execute();
$total = (int)$st->fetch()['c'];
$pages = (int)max(1, ceil($total / $limit));

if ($page > $pages) {
    $page = $pages;
    $offset = ($page - 1) * $limit;
}

$st = $db->prepare("
    SELECT id, created_at, username, action, entity_type, entity_id, ip, details
    FROM audit_log
    ORDER BY id DESC
    LIMIT :lim OFFSET :off
");
$st->bindValue(':lim', $limit, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

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
      <?php if ($total > 0): ?>
        &nbsp;|&nbsp; Page <b><?= e((string)$page) ?></b> of <b><?= e((string)$pages) ?></b>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="page-actions">
  <a class="action-pill" href="export_audit.php">⬇ Export CSV</a>
</div>

<div class="card" style="margin-top:16px">
  <?php if (!$rows): ?>
    <div class="empty-state">No audit entries yet.</div>
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
        <a href="audit.php?page=<?= $page - 1 ?>&page_size=<?= $limit ?>">&laquo; Prev</a>
      <?php endif; ?>
      <?php if ($page < $pages): ?>
        <a href="audit.php?page=<?= $page + 1 ?>&page_size=<?= $limit ?>" style="margin-left:12px">Next &raquo;</a>
      <?php endif; ?>
    </p>
  <?php endif; ?>
</div>

<?php page_footer();
