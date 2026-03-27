<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_write_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err     = '';
$msg     = '';
$results = null;

// Load all IPv4 subnets for the selector
$st = $db->prepare(
    "SELECT s.id, s.cidr, s.description, s.network_bin, s.prefix,
            si.name AS site_name
     FROM subnets s LEFT JOIN sites si ON si.id = s.site_id
     WHERE s.ip_version = 4
     ORDER BY s.network_bin ASC"
);
$st->execute();
$subnets = $st->fetchAll();

// Selected subnet from query string or POST
$subnetId = (int)($_GET['subnet_id'] ?? $_POST['subnet_id'] ?? 0);

$subnet = null;
foreach ($subnets as $s) {
    if ((int)$s['id'] === $subnetId) { $subnet = $s; break; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = (string)($_POST['action']   ?? '');
    $subnetId = (int)($_POST['subnet_id']   ?? 0);
    $startIp  = trim((string)($_POST['start_ip'] ?? ''));
    $endIp    = trim((string)($_POST['end_ip']   ?? ''));
    $note     = trim((string)($_POST['note']     ?? ''));

    // Re-resolve subnet from POST in case it changed
    $subnet = null;
    foreach ($subnets as $s) {
        if ((int)$s['id'] === $subnetId) { $subnet = $s; break; }
    }

    if ($action === 'reserve_pool') {
        if (!$subnet) {
            $err = 'Subnet not found.';
        } elseif ($startIp === '' || $endIp === '') {
            $err = 'Start and end IPs are required.';
        } else {
            $p      = parse_cidr($subnet['cidr']);
            $startN = normalize_ip($startIp);
            $endN   = normalize_ip($endIp);

            if (!$startN || $startN['version'] !== 4) {
                $err = 'Invalid start IP.';
            } elseif (!$endN || $endN['version'] !== 4) {
                $err = 'Invalid end IP.';
            } elseif (!ip_in_cidr($startN['ip'], (string)$p['network'], (int)$p['prefix'])) {
                $err = 'Start IP is not within the selected subnet (' . $subnet['cidr'] . ').';
            } elseif (!ip_in_cidr($endN['ip'], (string)$p['network'], (int)$p['prefix'])) {
                $err = 'End IP is not within the selected subnet (' . $subnet['cidr'] . ').';
            } else {
                $startInt = ipv4_bin_to_int($startN['bin']);
                $endInt   = ipv4_bin_to_int($endN['bin']);

                if ($startInt > $endInt) {
                    $err = 'Start IP must be less than or equal to End IP.';
                } elseif (($endInt - $startInt + 1) > 1024) {
                    $err = 'Range too large (max 1,024 IPs per operation). Split into smaller ranges.';
                } else {
                    $created = 0;
                    $updated = 0;
                    $skipped = 0;

                    $db->beginTransaction();
                    try {
                        $stCheck = $db->prepare(
                            "SELECT id, status FROM addresses WHERE subnet_id = :sid AND ip_bin = :b"
                        );
                        $stUpd = $db->prepare(
                            "UPDATE addresses SET status = 'reserved', note = :n WHERE id = :id"
                        );
                        $stIns = $db->prepare(
                            "INSERT INTO addresses (subnet_id, ip, ip_bin, ip_version, status, note)
                             VALUES (:sid, :ip, :b, 4, 'reserved', :n)"
                        );

                        for ($ipInt = $startInt; $ipInt <= $endInt; $ipInt++) {
                            $ipBin = ipv4_int_to_bin($ipInt);
                            $ipStr = (string)inet_ntop($ipBin);

                            $stCheck->execute([':sid' => $subnetId, ':b' => $ipBin]);
                            $existing = $stCheck->fetch();

                            if ($existing) {
                                if ($existing['status'] === 'used') {
                                    $skipped++;
                                } else {
                                    $stUpd->execute([':n' => $note, ':id' => $existing['id']]);
                                    $updated++;
                                }
                            } else {
                                $stIns->execute([
                                    ':sid' => $subnetId, ':ip' => $ipStr,
                                    ':b' => $ipBin, ':n' => $note,
                                ]);
                                $created++;
                            }
                        }

                        $db->commit();

                        audit($db, 'dhcp_pool.reserve', 'subnet', $subnetId,
                            "start={$startIp} end={$endIp} created={$created} updated={$updated} skipped={$skipped}");

                        $results = compact('created', 'updated', 'skipped', 'startIp', 'endIp');
                        $msg = "{$created} reserved (new), {$updated} updated, {$skipped} skipped (already used).";
                    } catch (Throwable $e) {
                        $db->rollBack();
                        $err = 'Failed to reserve pool: ' . $e->getMessage();
                    }
                }
            }
        }
    } elseif ($action === 'clear_pool') {
        if (!$subnet) {
            $err = 'Subnet not found.';
        } elseif ($startIp === '' || $endIp === '') {
            $err = 'Start and end IPs are required.';
        } else {
            $p      = parse_cidr($subnet['cidr']);
            $startN = normalize_ip($startIp);
            $endN   = normalize_ip($endIp);

            if (!$startN || $startN['version'] !== 4 || !$endN || $endN['version'] !== 4) {
                $err = 'Invalid IP address.';
            } elseif (!ip_in_cidr($startN['ip'], (string)$p['network'], (int)$p['prefix'])
                   || !ip_in_cidr($endN['ip'], (string)$p['network'], (int)$p['prefix'])) {
                $err = 'IPs are not within the selected subnet.';
            } else {
                $startInt = ipv4_bin_to_int($startN['bin']);
                $endInt   = ipv4_bin_to_int($endN['bin']);

                if ($startInt > $endInt) {
                    $err = 'Start IP must be less than or equal to End IP.';
                } elseif (($endInt - $startInt + 1) > 1024) {
                    $err = 'Range too large (max 1,024 IPs per operation).';
                } else {
                    $deleted = 0;

                    $db->beginTransaction();
                    try {
                        $stDel = $db->prepare(
                            "DELETE FROM addresses WHERE subnet_id = :sid AND ip_bin = :b AND status = 'reserved'"
                        );
                        for ($ipInt = $startInt; $ipInt <= $endInt; $ipInt++) {
                            $stDel->execute([':sid' => $subnetId, ':b' => ipv4_int_to_bin($ipInt)]);
                            $deleted += $stDel->rowCount();
                        }
                        $db->commit();

                        audit($db, 'dhcp_pool.clear', 'subnet', $subnetId,
                            "start={$startIp} end={$endIp} deleted={$deleted}");
                        $msg = "{$deleted} reserved address record(s) removed from the range.";
                    } catch (Throwable $e) {
                        $db->rollBack();
                        $err = 'Failed to clear pool: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Load existing reserved addresses for the selected subnet (for display)
$reserved = [];
if ($subnet) {
    $stR = $db->prepare(
        "SELECT ip, note FROM addresses WHERE subnet_id = :sid AND status = 'reserved'
         ORDER BY ip_bin ASC"
    );
    $stR->execute([':sid' => $subnetId]);
    $reserved = $stR->fetchAll();
}

page_header('DHCP Pools');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">🏠 Dashboard</a><span class="sep">›</span>
  <a href="subnets.php">🌐 Subnets</a><span class="sep">›</span>
  <span>🔒 DHCP Pools</span>
</div>

<div class="toolbar">
  <div>
    <h1>DHCP Pool Reservation</h1>
    <div class="muted">Bulk-reserve a contiguous IP range within an IPv4 subnet. Addresses already marked <em>used</em> are never overwritten.</div>
  </div>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>

<div class="card" style="margin-top:16px">
  <h2>Reserve a range</h2>
  <form method="post" action="dhcp_pool.php">
    <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="reserve_pool">
    <div class="row" style="flex-wrap:wrap;gap:10px">
      <label>Subnet<br>
        <select name="subnet_id" onchange="this.form.submit()" style="min-width:200px">
          <option value="0">— select subnet —</option>
          <?php foreach ($subnets as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === $subnetId) ? 'selected' : '' ?>>
              <?= e($s['cidr']) ?><?= $s['description'] ? ' — ' . e($s['description']) : '' ?>
              <?= $s['site_name'] ? ' [' . e($s['site_name']) . ']' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($subnet): ?>
      <label>Start IP<br><input name="start_ip" placeholder="e.g. <?= e(explode('/', $subnet['cidr'])[0]) ?>" required style="min-width:140px"></label>
      <label>End IP<br><input name="end_ip" placeholder="e.g. <?= e(explode('/', $subnet['cidr'])[0]) ?>" required style="min-width:140px"></label>
      <label>Note<br><input name="note" placeholder="DHCP pool" value="DHCP pool" style="min-width:160px"></label>
      <label style="align-self:flex-end"><br><button type="submit">Reserve</button></label>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if ($subnet): ?>
<div class="card" style="margin-top:16px">
  <h2>Clear a range <span class="muted" style="font-size:.85em;font-weight:400">(removes <em>reserved</em> records only)</span></h2>
  <form method="post" action="dhcp_pool.php" onsubmit="return confirm('Delete all reserved records in this range?')">
    <input type="hidden" name="csrf"      value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action"    value="clear_pool">
    <input type="hidden" name="subnet_id" value="<?= (int)$subnetId ?>">
    <div class="row" style="gap:10px">
      <label>Start IP<br><input name="start_ip" required style="min-width:140px"></label>
      <label>End IP<br><input name="end_ip"   required style="min-width:140px"></label>
      <label style="align-self:flex-end"><br><button type="submit" class="button-danger">Clear</button></label>
    </div>
  </form>
</div>

<div class="card" style="margin-top:16px">
  <h2>Reserved addresses in <?= e($subnet['cidr']) ?> <span class="muted" style="font-size:.85em;font-weight:400">(<?= count($reserved) ?>)</span></h2>
  <?php if (empty($reserved)): ?>
    <div class="empty-state">No reserved addresses in this subnet.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>IP</th><th>Note</th></tr></thead>
      <tbody>
      <?php foreach ($reserved as $r): ?>
        <tr>
          <td><?= e($r['ip']) ?></td>
          <td class="muted"><?= e((string)$r['note']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin-top:8px"><a href="addresses.php?subnet_id=<?= (int)$subnetId ?>">View all addresses →</a></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php page_footer();
