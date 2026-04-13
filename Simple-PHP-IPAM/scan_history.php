<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

$subnetId = to_int($_GET['subnet_id'] ?? 0);

// Load subnet details
$subnet = null;
if ($subnetId > 0) {
    $st = $db->prepare("SELECT id, cidr, description FROM subnets WHERE id = :id");
    $st->execute([':id' => $subnetId]);
    $subnet = $st->fetch() ?: null;
}

// Paginate scan runs: group by scanned_at minute window, show last 50
/** @var list<array<string, mixed>> $runs */
$runs = [];
if ($subnet !== null) {
    $st = $db->prepare("
        SELECT
            strftime('%Y-%m-%d %H:%M:00', scanned_at) AS run_minute,
            COUNT(*) AS total,
            SUM(is_up) AS up_count,
            COUNT(*) - SUM(is_up) AS down_count,
            MIN(scanned_at) AS started_at
        FROM scan_results
        WHERE subnet_id = :sid
        GROUP BY run_minute
        ORDER BY run_minute DESC
        LIMIT 50
    ");
    $st->execute([':sid' => $subnetId]);
    $runs = $st->fetchAll();
}

// Per-address scan summary for this subnet
/** @var list<array<string, mixed>> $addrSummary */
$addrSummary = [];
if ($subnet !== null) {
    $st = $db->prepare("
        SELECT
            a.id,
            a.ip,
            a.hostname,
            a.is_stale,
            a.last_seen_at,
            (SELECT COUNT(*) FROM scan_results r WHERE r.address_id = a.id AND r.is_up = 0
             AND r.scanned_at > (SELECT COALESCE(MAX(scanned_at), '2000-01-01') FROM scan_results
                                  WHERE address_id = a.id AND is_up = 1)) AS consecutive_misses,
            (SELECT COUNT(*) FROM scan_results r WHERE r.address_id = a.id) AS total_scans
        FROM addresses a
        WHERE a.subnet_id = :sid
        ORDER BY a.ip_bin
    ");
    $st->execute([':sid' => $subnetId]);
    $addrSummary = $st->fetchAll();
}

// Subnet list for selector
/** @var list<array<string, mixed>> $subnetList */
$subnetList = ($db->query("
    SELECT s.id, s.cidr, s.description, ss.is_active, ss.last_run_at
    FROM subnets s
    LEFT JOIN scan_schedules ss ON ss.subnet_id = s.id
    ORDER BY s.ip_version, s.network_bin
") ?: throw new \RuntimeException('Query failed'))->fetchAll();

page_header('Scan History');
?>
<div class="container">
  <div class="row" style="align-items:center;margin-bottom:16px">
    <h1 style="margin:0">Scan History</h1>
  </div>

  <form method="get" class="card" style="padding:12px 16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <label for="subnet_id"><strong>Subnet</strong></label>
    <select name="subnet_id" id="subnet_id" style="min-width:220px" data-auto-submit>
      <option value="">— select a subnet —</option>
      <?php foreach ($subnetList as $s): ?>
        <option value="<?= e(to_str($s['id'])) ?>"<?= to_int($s['id']) === $subnetId ? ' selected' : '' ?>>
          <?= e(to_str($s['cidr'])) ?>
          <?php if (to_str($s['description']) !== ''): ?> — <?= e(to_str($s['description'])) ?><?php endif ?>
          <?php if ($s['is_active'] ?? false): ?> 📡<?php endif ?>
        </option>
      <?php endforeach ?>
    </select>
  </form>

<?php if ($subnet !== null): ?>
  <?php /** @var array<string, mixed> $subnet */ ?>
  <h2><?= e(to_str($subnet['cidr'])) ?><?php if (to_str($subnet['description']) !== ''): ?> <span class="muted">— <?= e(to_str($subnet['description'])) ?></span><?php endif ?></h2>

  <?php if (count($runs) === 0): ?>
    <div class="card"><p class="muted">No scan results yet. Schedule a scan or run <code>php scan_run.php --subnet-id=<?= (int)$subnetId ?></code> from the CLI.</p></div>
  <?php else: ?>
  <div class="card" style="overflow-x:auto;margin-bottom:24px">
    <h3 style="margin:0 0 12px">Scan Run History <span class="muted" style="font-weight:normal;font-size:.875rem">(last <?= count($runs) ?> runs)</span></h3>
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Run time</th>
          <th>Hosts up</th>
          <th>Hosts down</th>
          <th>Total</th>
          <th>Availability</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($runs as $run):
            $up   = to_int($run['up_count']);
            $down = to_int($run['down_count']);
            $tot  = to_int($run['total']);
            $pct  = $tot > 0 ? round($up / $tot * 100) : 0;
        ?>
        <tr>
          <td><?= e(to_str($run['run_minute'])) ?></td>
          <td class="success"><strong><?= $up ?></strong></td>
          <td class="<?= $down > 0 ? 'danger' : 'muted' ?>"><?= $down ?></td>
          <td><?= $tot ?></td>
          <td>
            <div class="util-bar" style="width:120px">
              <div class="util-bar-fill<?= $pct < 80 ? ' util-bar-fill--warn' : '' ?><?= $pct < 50 ? ' util-bar-fill--crit' : '' ?>"
                   data-pct="<?= $pct ?>" style="width:<?= $pct ?>%"></div>
            </div>
            <?= $pct ?>%
          </td>
        </tr>
        <?php endforeach ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif ?>

  <?php if (count($addrSummary) > 0): ?>
  <div class="card">
    <h3 style="margin:0 0 12px">Address Scan Status</h3>
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>IP</th>
          <th>Hostname</th>
          <th>Status</th>
          <th>Last seen</th>
          <th>Consecutive misses</th>
          <th>Total scans</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($addrSummary as $a):
            $stale = (bool)$a['is_stale'];
            $misses = to_int($a['consecutive_misses']);
        ?>
        <tr>
          <td><a href="addresses.php?subnet_id=<?= (int)$subnetId ?>"><?= e(to_str($a['ip'])) ?></a></td>
          <td><?= e(to_str($a['hostname'])) ?></td>
          <td>
            <?php if ($stale): ?>
              <span class="badge" style="background:var(--danger);color:#fff">Stale</span>
            <?php elseif ($misses > 0): ?>
              <span class="badge" style="background:var(--warn)">Intermittent</span>
            <?php else: ?>
              <span class="badge" style="background:var(--success);color:#fff">OK</span>
            <?php endif ?>
          </td>
          <td class="<?= to_str($a['last_seen_at']) === '' || $a['last_seen_at'] === null ? 'muted' : '' ?>">
            <?= $a['last_seen_at'] !== null ? e(to_str($a['last_seen_at'])) : '—' ?>
          </td>
          <td class="<?= $misses > 0 ? 'danger' : 'muted' ?>"><?= $misses ?></td>
          <td class="muted"><?= to_int($a['total_scans']) ?></td>
        </tr>
        <?php endforeach ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif ?>

<?php elseif ($subnetId > 0): ?>
  <p class="danger">Subnet not found.</p>
<?php endif ?>

</div>
<?php page_footer(); ?>
