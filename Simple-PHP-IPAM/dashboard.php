<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */
require_login();

/* --- KPI totals (new, for KPI card row) --- */
$kpis = ipam_dashboard_kpis($db);

/* --- Growth data for uPlot chart --- */
$growthData = ipam_dashboard_growth($db, 30);
$growthTs   = [];
$growthNs   = [];
foreach ($growthData as $row) {
    $growthTs[] = (int)strtotime(to_str($row['d']));
    $growthNs[] = to_int($row['n']);
}

/* --- Legacy summary counts (kept for existing metric grid) --- */
$statusMap = ['used' => 0, 'reserved' => 0, 'free' => 0];
$stStatus = $db->prepare("SELECT status, COUNT(*) AS c FROM addresses GROUP BY status");
$stStatus->execute();
foreach ($stStatus->fetchAll() as $r) {
    if (isset($statusMap[to_str($r['status'])])) $statusMap[to_str($r['status'])] = to_int($r['c']);
}

$stVer = $db->prepare("SELECT ip_version, COUNT(*) AS c FROM subnets GROUP BY ip_version");
$stVer->execute();
$verCounts = [4 => 0, 6 => 0];
foreach ($stVer->fetchAll() as $r) $verCounts[to_int($r['ip_version'])] = to_int($r['c']);

/* --- Growth trend (7 and 30 day totals) --- */
$cutoffWeek  = gmdate('Y-m-d H:i:s', time() - 7  * 86400);
$cutoffMonth = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
$gWkSt = $db->prepare("SELECT COUNT(*) FROM addresses WHERE created_at >= :c");
$gWkSt->execute([':c' => $cutoffWeek]);
$growthWeek = to_int($gWkSt->fetchColumn());
$gMoSt = $db->prepare("SELECT COUNT(*) FROM addresses WHERE created_at >= :c");
$gMoSt->execute([':c' => $cutoffMonth]);
$growthMonth = to_int($gMoSt->fetchColumn());

/* --- Top IPv4 subnets by utilization % (/8–/32 only) --- */
$utilData = ipv4_unassigned_summary($db);
$stTop = $db->prepare("SELECT id, cidr, prefix, description FROM subnets WHERE ip_version = 4 AND prefix BETWEEN 8 AND 32");
$stTop->execute();
/** @var list<array<string, mixed>> $topSubnets */
$topSubnets = [];
foreach ($stTop->fetchAll() as $row) {
    $sid = to_int($row['id']);
    $u   = $utilData[$sid] ?? null;
    if ($u === null || $u['assigned_assignable'] <= 0) continue;
    $row['used_count'] = $u['assigned_assignable'];
    $topSubnets[] = $row;
}
usort($topSubnets, function (array $a, array $b): int {
    $capA = ipv4_assignable_count(to_int($a['prefix']));
    $capB = ipv4_assignable_count(to_int($b['prefix']));
    $pctA = $capA > 0 ? to_int($a['used_count']) / $capA : 0;
    $pctB = $capB > 0 ? to_int($b['used_count']) / $capB : 0;
    return $pctB <=> $pctA;
});
$topSubnets = array_slice($topSubnets, 0, 10);

/* --- Sparkline data for top subnets --- */
/** @var array<int, list<float>> $sparklines */
$sparklines = [];
if ($topSubnets) {
    $topIds       = array_map(fn($r) => to_int($r['id']), $topSubnets);
    $placeholders = implode(',', array_fill(0, count($topIds), '?'));
    $spkSt        = $db->prepare(
        "SELECT subnet_id, used_count, total_hosts
         FROM utilization_snapshots
         WHERE subnet_id IN ($placeholders)
         ORDER BY subnet_id, snapped_at ASC"
    );
    foreach ($topIds as $i => $sid) $spkSt->bindValue($i + 1, $sid, PDO::PARAM_INT);
    $spkSt->execute();
    foreach ($spkSt->fetchAll() as $r) {
        $sid             = to_int($r['subnet_id']);
        $total           = to_int($r['total_hosts']);
        $sparklines[$sid][] = $total > 0 ? (float)round(to_int($r['used_count']) / $total * 100, 1) : 0.0;
    }
}

/* --- Addresses by site --- */
$stSite = $db->prepare("
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
$stSite->execute();
/** @var list<array<string, mixed>> $bySite */
$bySite = $stSite->fetchAll();

/* --- Recent audit events --- */
$stAudit = $db->prepare("SELECT created_at, username, action, details FROM audit_log ORDER BY id DESC LIMIT 10");
$stAudit->execute();
/** @var list<array<string, mixed>> $recentAudit */
$recentAudit = $stAudit->fetchAll();

/* --- Address expiry counts --- */
$today  = date('Y-m-d');
$in7d   = date('Y-m-d', (int)strtotime('+7 days'));
$in30d  = date('Y-m-d', (int)strtotime('+30 days'));
$expSt  = $db->prepare("
    SELECT
        SUM(CASE WHEN expires_at < :today                         THEN 1 ELSE 0 END) AS cnt_expired,
        SUM(CASE WHEN expires_at >= :f7 AND expires_at <= :t7     THEN 1 ELSE 0 END) AS cnt_7d,
        SUM(CASE WHEN expires_at >= :f30 AND expires_at <= :t30   THEN 1 ELSE 0 END) AS cnt_30d
    FROM addresses
    WHERE expires_at IS NOT NULL
");
$expSt->execute([':today' => $today, ':f7' => $today, ':t7' => $in7d, ':f30' => $today, ':t30' => $in30d]);
/** @var array<string, mixed>|false $expRow */
$expRow     = $expSt->fetch();
$cntExpired = is_array($expRow) ? to_int($expRow['cnt_expired']) : 0;
$cnt7d      = is_array($expRow) ? to_int($expRow['cnt_7d'])      : 0;
$cnt30d     = is_array($expRow) ? to_int($expRow['cnt_30d'])     : 0;

/**
 * Render a small inline SVG sparkline from percentage values (0–100).
 *
 * @param list<float> $points percentage values 0–100
 */
function render_sparkline(array $points, int $w = 80, int $h = 24): string
{
    if (count($points) < 2) {
        return '<span class="muted" style="font-size:.75rem">Collecting&hellip;</span>';
    }
    $n   = count($points);
    $pts = [];
    foreach ($points as $i => $v) {
        $x     = (int)round($i / ($n - 1) * $w);
        $y     = (int)round($h - ($v / 100.0) * $h);
        $pts[] = "$x,$y";
    }
    $poly = implode(' ', $pts);
    return '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" class="sparkline" aria-hidden="true">'
         . '<polyline points="' . htmlspecialchars($poly, ENT_QUOTES) . '" fill="none" stroke="var(--link)" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>'
         . '</svg>';
}

page_header('Dashboard');
?>

<div class="toolbar">
  <div>
    <h1>Dashboard</h1>
    <div class="muted">System overview</div>
  </div>
</div>

<?php
/** @var list<string> $_staleKeys */
$_staleKeys = $GLOBALS['config_stale_keys'] ?? [];
if ($_staleKeys && current_user()['role'] === 'admin'):
?>
  <div class="admin-notice admin-notice--warning" role="alert">
    <?= icon('warning') ?> <strong>config.php cleanup needed:</strong>
    <?= count($_staleKeys) ?> non-bootstrap key(s) found in <code>config.php</code> that are no longer read.
    Remove them manually: <code><?= e(implode(', ', $_staleKeys)) ?></code>
  </div>
<?php endif; unset($_staleKeys); ?>

<div class="page-actions">
  <a class="action-pill" href="subnets.php#add-subnet"><?= icon('plus') ?> Add Subnet</a>
  <a class="action-pill" href="search.php"><?= icon('magnifying-glass') ?> Search</a>
  <a class="action-pill" href="subnets.php"><?= icon('server-stack') ?> Subnets</a>
  <?php if (current_user()['role'] === 'admin'): ?>
    <a class="action-pill" href="import_csv.php"><?= icon('upload') ?> Import CSV</a>
  <?php endif; ?>
  <a class="action-pill muted" href="#" id="dash-reset" title="Reset widget layout and filters"><?= icon('refresh') ?> Reset widgets</a>
</div>

<!-- KPI cards -->
<div class="kpi-grid">
<?php
$pctStatus   = $kpis['pct_used'] >= 90 ? 'crit' : ($kpis['pct_used'] >= 75 ? 'warn' : 'ok');
$alertStatus = $kpis['alerts'] > 0 ? 'crit' : 'ok';
ipam_render('dashboard_kpi_card', ['label' => 'Subnets',     'value' => $kpis['subnets'],        'sub' => '',                                         'status' => 'ok']);
ipam_render('dashboard_kpi_card', ['label' => 'Addresses',   'value' => $kpis['addresses'],      'sub' => '',                                         'status' => 'ok']);
ipam_render('dashboard_kpi_card', ['label' => 'Used',        'value' => $kpis['pct_used'] . '%', 'sub' => $kpis['used'] . ' used',                   'status' => $pctStatus]);
ipam_render('dashboard_kpi_card', ['label' => 'Crit Alerts', 'value' => $kpis['alerts'],         'sub' => $kpis['alerts'] > 0 ? 'active alerts' : '', 'status' => $alertStatus]);
?>
</div>

<!-- uPlot growth chart -->
<div class="chart-section">
  <h2 class="chart-title">Address growth (last 30 days)</h2>
  <?php if (empty($growthTs)): ?>
    <div class="empty-state muted">No address creation data in the last 30 days.</div>
  <?php else: ?>
    <div id="growth-chart"
         style="min-height:180px"
         data-uplot-xs="<?= e(json_encode($growthTs)) ?>"
         data-uplot-ys="<?= e(json_encode($growthNs)) ?>"></div>
  <?php endif; ?>
</div>

<!-- Legacy metric grid -->
<div class="grid cols-3 mt-16" data-widget="metrics">
  <div class="metric"><div class="label">Used</div><div class="value status-used"><?= e(to_str($statusMap['used'])) ?></div></div>
  <div class="metric"><div class="label">Reserved</div><div class="value status-reserved"><?= e(to_str($statusMap['reserved'])) ?></div></div>
  <div class="metric"><div class="label">Free</div><div class="value status-free"><?= e(to_str($statusMap['free'])) ?></div></div>
  <div class="metric"><div class="label">IPv4 Subnets</div><div class="value"><?= e(to_str($verCounts[4])) ?></div></div>
  <div class="metric"><div class="label">IPv6 Subnets</div><div class="value"><?= e(to_str($verCounts[6])) ?></div></div>
  <div class="metric"><div class="label">Added (7d / 30d)</div><div class="value"><?= e((string)$growthWeek) ?> / <?= e((string)$growthMonth) ?></div></div>
</div>

<div class="grid cols-2 mt-16">

  <div class="card" data-widget="top-subnets">
    <div class="widget-header">
      <h2>Top IPv4 Subnets by Usage</h2>
      <button class="widget-hide-btn" data-widget-key="top-subnets" aria-label="Hide widget"><?= icon('x') ?></button>
    </div>
    <?php if (!$topSubnets): ?>
      <div class="empty-state">No IPv4 subnets in /8&ndash;/32 range.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Subnet</th><th>Description</th><th>Used</th><th>Capacity</th><th>Fill</th><th>Trend</th></tr>
        </thead>
        <tbody>
        <?php
          $dashWarn = to_int(ipam_setting('display.utilization_warn'));
          $dashCrit = to_int(ipam_setting('display.utilization_critical'));
        ?>
        <?php foreach ($topSubnets as $s):
            $cap      = ipv4_assignable_count(to_int($s['prefix']));
            $used     = to_int($s['used_count']);
            $pct      = $cap > 0 ? min(100, (int)round($used / $cap * 100)) : 0;
            $barClass = $pct >= $dashCrit ? 'util-bar-fill--crit' : ($pct >= $dashWarn ? 'util-bar-fill--warn' : '');
        ?>
          <tr>
            <td><a href="addresses.php?subnet_id=<?= to_int($s['id']) ?>"><?= e(to_str($s['cidr'])) ?></a></td>
            <td class="muted"><?= e(to_str($s['description'])) ?></td>
            <td><?= e((string)$used) ?></td>
            <td><?= e((string)$cap) ?></td>
            <td class="mw-90">
              <div class="util-bar-block">
                <div class="util-bar-fill <?= $barClass ?>" data-pct="<?= $pct ?>"></div>
              </div>
              <span class="muted font-xs"><?= $pct ?>%</span>
            </td>
            <td><?= render_sparkline($sparklines[to_int($s['id'])] ?? []) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <div class="mt-10"><a class="action-pill" href="subnets.php"><?= icon('server-stack') ?> All Subnets</a></div>
  </div>

  <div class="card" data-widget="by-site">
    <div class="widget-header">
      <h2>Addresses by Site</h2>
      <button class="widget-hide-btn" data-widget-key="by-site" aria-label="Hide widget"><?= icon('x') ?></button>
    </div>
    <?php if (!$bySite): ?>
      <div class="empty-state">No data yet.</div>
    <?php else: ?>
      <div class="mb-8">
        <select id="dash-site-filter" class="action-pill">
          <option value="">(all sites)</option>
          <?php foreach ($bySite as $r): ?>
            <option value="<?= e(to_str($r['site_name'])) ?>"><?= e(to_str($r['site_name'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <table>
        <thead>
          <tr><th>Site</th><th>Used</th><th>Reserved</th><th>Free</th><th>Total</th></tr>
        </thead>
        <tbody>
        <?php foreach ($bySite as $r): ?>
          <tr data-site-row="<?= e(to_str($r['site_name'])) ?>">
            <td><?= e(to_str($r['site_name'])) ?></td>
            <td class="status-used"><?= e(to_str($r['used'])) ?></td>
            <td class="status-reserved"><?= e(to_str($r['reserved'])) ?></td>
            <td class="status-free"><?= e(to_str($r['free'])) ?></td>
            <td><b><?= e(to_str($r['total'])) ?></b></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <?php if (current_user()['role'] === 'admin'): ?>
      <div class="mt-10"><a class="action-pill" href="sites.php"><?= icon('map-pin') ?> Manage Sites</a></div>
    <?php endif; ?>
  </div>

</div>

<div class="card mt-16" data-widget="recent-activity">
  <div class="widget-header">
    <h2>Recent Activity</h2>
    <button class="widget-hide-btn" data-widget-key="recent-activity" aria-label="Hide widget"><?= icon('x') ?></button>
  </div>
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
          <td class="muted nowrap"><?= e(ipam_format_datetime(to_str($r['created_at']))) ?></td>
          <td><?= e(to_str($r['username'])) ?></td>
          <td><?= e(to_str($r['action'])) ?></td>
          <td class="muted"><?= e(to_str($r['details'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="mt-10"><a class="action-pill" href="audit.php"><?= icon('audit') ?> Full Audit Log</a></div>
  <?php endif; ?>
</div>

<?php if ($cntExpired > 0 || $cnt7d > 0 || $cnt30d > 0): ?>
<div class="card mt-16" data-widget="expiring-addresses">
  <div class="widget-header">
    <h2>Expiring Addresses</h2>
    <button class="widget-hide-btn" data-widget-key="expiring-addresses" aria-label="Hide widget"><?= icon('x') ?></button>
  </div>
  <div class="grid cols-3">
    <div class="metric">
      <div class="label">Expired</div>
      <div class="value<?= $cntExpired > 0 ? ' danger' : '' ?>">
        <?php if ($cntExpired > 0): ?>
          <a href="addresses.php?filter=expired" style="color:inherit"><?= e((string)$cntExpired) ?></a>
        <?php else: ?>
          0
        <?php endif; ?>
      </div>
    </div>
    <div class="metric">
      <div class="label">Expiring &le;7 days</div>
      <div class="value<?= $cnt7d > 0 ? ' warn' : '' ?>">
        <?php if ($cnt7d > 0): ?>
          <a href="addresses.php?filter=expiring&amp;days=7" style="color:inherit"><?= e((string)$cnt7d) ?></a>
        <?php else: ?>
          0
        <?php endif; ?>
      </div>
    </div>
    <div class="metric">
      <div class="label">Expiring &le;30 days</div>
      <div class="value">
        <?php if ($cnt30d > 0): ?>
          <a href="addresses.php?filter=expiring&amp;days=30" style="color:inherit"><?= e((string)$cnt30d) ?></a>
        <?php else: ?>
          0
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="assets/vendor/uplot.min.js"></script>
<?php page_footer();
