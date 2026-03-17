<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_role('admin');

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$st = $db->prepare("SELECT id, created_at, username, action, entity_type, entity_id, ip, details
                    FROM audit_log
                    ORDER BY id DESC
                    LIMIT :lim OFFSET :off");
$st->bindValue(':lim', $limit, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

page_header('Audit Log');
?>
<h1>Audit Log</h1>

<table>
  <thead>
    <tr>
      <th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Client IP</th><th>Details</th>
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

<p>
  <?php if ($page > 1): ?>
    <a href="audit.php?page=<?= $page - 1 ?>">&laquo; Prev</a>
  <?php endif; ?>
  <a href="audit.php?page=<?= $page + 1 ?>" style="margin-left:12px">Next &raquo;</a>
</p>

<?php page_footer();
