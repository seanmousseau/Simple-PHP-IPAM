<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

$st = $db->prepare("SELECT COUNT(*) AS c FROM subnets");
$st->execute();
$subnets = (int)$st->fetch()['c'];

$st = $db->prepare("SELECT COUNT(*) AS c FROM addresses");
$st->execute();
$addrs = (int)$st->fetch()['c'];

$st = $db->prepare("SELECT status, COUNT(*) AS c FROM addresses GROUP BY status ORDER BY status");
$st->execute();
$byStatus = $st->fetchAll();

page_header('Dashboard');
?>
<h1>Dashboard</h1>
<ul>
  <li>Subnets: <b><?= e((string)$subnets) ?></b></li>
  <li>Addresses: <b><?= e((string)$addrs) ?></b></li>
</ul>

<h2>Addresses by status</h2>
<ul>
  <?php foreach ($byStatus as $r): ?>
    <li><?= e($r['status']) ?>: <b><?= e((string)$r['c']) ?></b></li>
  <?php endforeach; ?>
</ul>

<p class="muted">Role-based access: readonly users cannot create/update/delete.</p>
<?php page_footer();
