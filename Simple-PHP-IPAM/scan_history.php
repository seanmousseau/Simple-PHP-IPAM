<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

// ---- POST: scan schedule management (write role required) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write_access();
    csrf_require();
    $action = to_str($_POST['action'] ?? '');
    $sid    = to_int($_POST['subnet_id'] ?? 0);

    if ($action === 'save_scan_schedule' && $sid > 0) {
        $method       = to_str($_POST['scan_method'] ?? 'icmp');
        $tcpPort      = to_int($_POST['scan_tcp_port'] ?? 0) ?: null;
        $intervalMins = max(1, to_int($_POST['scan_interval'] ?? 60));
        $isActive     = isset($_POST['scan_active']) ? 1 : 0;

        if (!in_array($method, ['icmp', 'tcp', 'both'], true)) $method = 'icmp';
        if ($method === 'icmp') {
            $tcpPort = null;
        } elseif ($tcpPort === null || $tcpPort < 1 || $tcpPort > 65535) {
            flash_set('TCP port must be between 1 and 65535 when method is tcp or both.');
            header('Location: scan_history.php?subnet_id=' . $sid);
            exit;
        }

        // #380: dialect-routed upsert so future engines pick up the right idiom.
        $d = ipam_dialect();
        $upsertClause = $d->upsert('scan_schedules', ['subnet_id'], ['method', 'tcp_port', 'interval_minutes', 'is_active', 'updated_at']);
        $db->prepare("
            INSERT INTO scan_schedules (subnet_id, method, tcp_port, interval_minutes, is_active, updated_at)
            VALUES (:sid, :method, :port, :interval, :active, {$d->now()})
            $upsertClause
        ")->execute([
            ':sid'      => $sid,
            ':method'   => $method,
            ':port'     => $tcpPort,
            ':interval' => $intervalMins,
            ':active'   => $isActive,
        ]);
        audit($db, 'scan.schedule_update', 'subnet', $sid,
            "method=$method interval={$intervalMins}m active=$isActive");
        flash_set('Scan schedule saved.');

    } elseif ($action === 'delete_scan_schedule' && $sid > 0) {
        $db->prepare("DELETE FROM scan_schedules WHERE subnet_id = :sid")->execute([':sid' => $sid]);
        audit($db, 'scan.schedule_delete', 'subnet', $sid, '');
        flash_set('Scan schedule removed.');
    }

    header('Location: scan_history.php?subnet_id=' . $sid);
    exit;
}

$subnetId = to_int($_GET['subnet_id'] ?? 0);

// Load subnet details + current scan schedule
$subnet   = null;
$schedule = null;
if ($subnetId > 0) {
    $st = $db->prepare("SELECT id, cidr, description FROM subnets WHERE id = :id");
    $st->execute([':id' => $subnetId]);
    $subnet = $st->fetch() ?: null;

    $st = $db->prepare("SELECT method, tcp_port, interval_minutes, is_active, last_run_at FROM scan_schedules WHERE subnet_id = :id");
    $st->execute([':id' => $subnetId]);
    /** @var array{method:string,tcp_port:int|null,interval_minutes:int,is_active:int,last_run_at:string|null}|null $schedule */
    $schedule = $st->fetch() ?: null;
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

  <?php
    $isWrite   = current_user()['role'] !== 'readonly';
    $hasSched  = $schedule !== null;
    $schedMeth = $schedule !== null ? to_str($schedule['method']) : 'icmp';
    $schedPort = $schedule !== null ? to_int($schedule['tcp_port']) : 0;
    $schedInt  = $schedule !== null ? to_int($schedule['interval_minutes']) : 60;
    $schedAct  = $schedule !== null && (bool)$schedule['is_active'];
    $schedLast = $schedule !== null ? to_str($schedule['last_run_at'] ?? '') : '';
  ?>
  <div class="card" style="margin-bottom:20px">
    <div class="row" style="align-items:center;margin-bottom:<?= $isWrite ? '14px' : '0' ?>">
      <h3 style="margin:0">📡 Scan Schedule</h3>
      <?php if ($hasSched): ?>
        <?php if ($schedAct): ?>
          <span class="badge" style="background:var(--success);color:#fff">Active</span>
        <?php else: ?>
          <span class="badge">Inactive</span>
        <?php endif ?>
        <?php if ($schedLast !== ''): ?>
          <span class="muted" style="font-size:.85rem">Last run: <?= e(display_datetime($schedLast)) ?></span>
        <?php endif ?>
      <?php else: ?>
        <span class="muted">No schedule configured.</span>
      <?php endif ?>
    </div>
    <?php if ($isWrite): ?>
    <form method="post" class="row" style="flex-wrap:wrap;gap:10px;align-items:flex-end">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_scan_schedule">
      <input type="hidden" name="subnet_id" value="<?= (int)$subnetId ?>">
      <label>Method<br>
        <select name="scan_method">
          <?php foreach (['icmp' => 'ICMP ping', 'tcp' => 'TCP connect', 'both' => 'ICMP + TCP'] as $v => $lbl): ?>
            <option value="<?= e($v) ?>"<?= $schedMeth === $v ? ' selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <label>TCP Port<br>
        <input type="number" name="scan_tcp_port" min="1" max="65535"
               value="<?= $schedPort > 0 ? $schedPort : '' ?>" placeholder="e.g. 22" style="width:90px">
      </label>
      <label>Interval (min)<br>
        <input type="number" name="scan_interval" min="1" max="1440"
               value="<?= $schedInt > 0 ? $schedInt : 60 ?>" style="width:80px">
      </label>
      <label style="display:flex;align-items:center;gap:6px;padding-top:20px">
        <input type="checkbox" name="scan_active" value="1"<?= $schedAct ? ' checked' : '' ?>> Active
      </label>
      <div style="padding-top:20px"><button type="submit">Save Schedule</button></div>
    </form>
    <?php if ($hasSched): ?>
    <form method="post" class="mt-8" data-confirm="Remove scan schedule for this subnet?">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="delete_scan_schedule">
      <input type="hidden" name="subnet_id" value="<?= (int)$subnetId ?>">
      <button type="submit" class="button-secondary">Remove Schedule</button>
    </form>
    <?php endif ?>
    <?php endif ?>
  </div>

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
            <?= $a['last_seen_at'] !== null ? e(display_datetime(to_str($a['last_seen_at']))) : '—' ?>
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
