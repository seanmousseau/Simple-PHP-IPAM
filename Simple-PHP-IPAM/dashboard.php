<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

/* --- Summary counts --- */
$st = $db->prepare("SELECT COUNT(*) AS c FROM subnets");
$st->execute();
$totalSubnets = (int)$st->fetch()['c'];

$st = $db->prepare("SELECT COUNT(*) AS c FROM addresses");
$st->execute();
$totalAddrs = (int)$st->fetch()['c'];

$st = $db->prepare("SELECT status, COUNT(*) AS c FROM addresses GROUP BY status");
$st->execute();
$statusMap = ['used' => 0, 'reserved' => 0, 'free' => 0];
foreach ($st->fetchAll() as $r) {
    if (isset($statusMap[$r['status']])) $statusMap[$r['status']] = (int)$r['c'];
}

/* --- IPv4 / IPv6 subnet split --- */
$st = $db->prepare("SELECT ip_version, COUNT(*) AS c FROM subnets GROUP BY ip_version");
$st->execute();
$verCounts = [4 => 0, 6 => 0];
foreach ($st->fetchAll() as $r) $verCounts[(int)$r['ip_version']] = (int)$r['c'];

/* --- Top IPv4 subnets by used-address count (/8–/30 only, avoids huge/tiny edge cases) --- */
$st = $db->prepare("
    SELECT s.id, s.cidr, s.prefix, s.description,
           COUNT(a.id) AS used_count
    FROM subnets s
    LEFT JOIN addresses a ON a.subnet_id = s.id AND a.status = 'used'
    WHERE s.ip_version = 4 AND s.prefix BETWEEN 8 AND 30
    GROUP BY s.id
    ORDER BY used_count DESC
    LIMIT 10
");
$st->execute();
$topSubnets = $st->fetchAll();

/* --- Address counts grouped by site --- */
$st = $db->prepare("
    SELECT COALESCE(si.name, 'Ungrouped') AS site_name,
           SUM(CASE WHEN a.status = 'used'     THEN 1 ELSE 0 END) AS used,
           SUM(CASE WHEN a.status = 'reserved' THEN 1 ELSE 0 END) AS reserved,
           SUM(CASE WHEN a.status = 'free'     THEN 1 ELSE 0 END) AS free,
           COUNT(a.id) AS total
    FROM subnets s
    LEFT JOIN sites si ON si.id = s.site_id
    LEFT JOIN addresses a ON a.subnet_id = s.id
    GROUP BY s.site_id, si.name
    ORDER BY site_name ASC
");
$st->execute();
$bySite = $st->fetchAll();

/* --- Recent audit events --- */
$st = $db->prepare("
    SELECT created_at, username, action, details
    FROM audit_log
    ORDER BY id DESC
    LIMIT 10
");
$st->execute();
$recentAudit = $st->fetchAll();

page_header('Dashboard');
?>

<div class="breadcrumbs">
  <span>🏠 Dashboard</span>
</div>

<div class="toolbar">
  <div>
    <h1>Dashboard</h1>
    <div class="muted">System overview</div>
  </div>
</div>

<div class="grid cols-3" style="margin-top:16px">
  <div class="metric"><div class="label">Subnets</div><div class="value"><?= e((string)$totalSubnets) ?></div></div>
  <div class="metric"><div class="label">Addresses (rows)</div><div class="value"><?= e((string)$totalAddrs) ?></div></div>
  <div class="metric"><div class="label">Used</div><div class="value status-used"><?= e((string)$statusMap['used']) ?></div></div>
  <div class="metric"><div class="label">Reserved</div><div class="value status-reserved"><?= e((string)$statusMap['reserved']) ?></div></div>
  <div class="metric"><div class="label">Free</div><div class="value status-free"><?= e((string)$statusMap['free']) ?></div></div>
  <div class="metric"><div class="label">IPv4 / IPv6 Subnets</div><div class="value"><?= e((string)$verCounts[4]) ?> / <?= e((string)$verCounts[6]) ?></div></div>
</div>

<div class="grid cols-2" style="margin-top:16px">

  <div class="card">
    <h2>Top IPv4 Subnets by Usage</h2>
    <?php if (!$topSubnets): ?>
      <div class="empty-state">No IPv4 subnets in /8–/30 range.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Subnet</th><th>Description</th><th>Used</th><th>Capacity</th><th>Fill</th></tr>
        </thead>
        <tbody>
        <?php foreach ($topSubnets as $s):
            $cap  = ipv4_assignable_count((int)$s['prefix']);
            $used = (int)$s['used_count'];
            $pct  = $cap > 0 ? min(100, (int)round($used / $cap * 100)) : 0;
            $bar  = $pct >= 90 ? 'var(--danger)' : ($pct >= 70 ? 'var(--warn)' : 'var(--success)');
        ?>
          <tr>
            <td><a href="addresses.php?subnet_id=<?= (int)$s['id'] ?>"><?= e($s['cidr']) ?></a></td>
            <td class="muted"><?= e((string)$s['description']) ?></td>
            <td><?= e((string)$used) ?></td>
            <td><?= e((string)$cap) ?></td>
            <td style="min-width:90px">
              <div style="background:var(--border);border-radius:4px;height:8px;overflow:hidden">
                <div style="width:<?= $pct ?>%;background:<?= $bar ?>;height:100%"></div>
              </div>
              <span class="muted" style="font-size:.85rem"><?= $pct ?>%</span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <div style="margin-top:10px"><a class="action-pill" href="subnets.php">🌐 All Subnets</a></div>
  </div>

  <div class="card">
    <h2>Addresses by Site</h2>
    <?php if (!$bySite): ?>
      <div class="empty-state">No data yet.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Site</th><th>Used</th><th>Reserved</th><th>Free</th><th>Total</th></tr>
        </thead>
        <tbody>
        <?php foreach ($bySite as $r): ?>
          <tr>
            <td><?= e((string)$r['site_name']) ?></td>
            <td class="status-used"><?= e((string)$r['used']) ?></td>
            <td class="status-reserved"><?= e((string)$r['reserved']) ?></td>
            <td class="status-free"><?= e((string)$r['free']) ?></td>
            <td><b><?= e((string)$r['total']) ?></b></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <?php if (current_user()['role'] === 'admin'): ?>
      <div style="margin-top:10px"><a class="action-pill" href="sites.php">📍 Manage Sites</a></div>
    <?php endif; ?>
  </div>

</div>

<div class="card" style="margin-top:16px">
  <h2>Recent Activity</h2>
  <?php if (!$recentAudit): ?>
    <div class="empty-state">No audit events yet.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>Time</th><th>User</th><th>Action</th><th>Details</th></tr>
      </thead>
      <tbody>
      <?php foreach ($recentAudit as $r): ?>
        <tr>
          <td class="muted" style="white-space:nowrap"><?= e((string)$r['created_at']) ?></td>
          <td><?= e((string)$r['username']) ?></td>
          <td><?= e((string)$r['action']) ?></td>
          <td class="muted"><?= e((string)$r['details']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div style="margin-top:10px"><a class="action-pill" href="audit.php">📜 Full Audit Log</a></div>
  <?php endif; ?>
</div>

<?php page_footer();
