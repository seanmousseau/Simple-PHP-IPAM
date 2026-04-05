<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write_access();
    csrf_require();
}

$err     = '';
$msg     = '';
$results = null;
$editId  = (int)($_GET['edit_id'] ?? 0);

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

    if (demo_mode_enabled()) {
        $err = 'This action is disabled in demo mode.';
    } elseif ($action === 'reserve_pool') {
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
                            "INSERT INTO addresses (subnet_id, ip, ip_bin, status, note)
                             VALUES (:sid, :ip, :b, 'reserved', :n)"
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
                                    ':b'   => $ipBin,    ':n'  => $note,
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
                        error_log('DHCP pool reserve error: ' . $e->getMessage());
                        $err = 'An unexpected database error occurred while reserving the pool.';
                    }
                }
            }
        }
    } elseif ($action === 'edit_address') {
        $addressId = (int)($_POST['address_id'] ?? 0);
        $hostname  = trim((string)($_POST['hostname'] ?? ''));
        $owner     = trim((string)($_POST['owner']    ?? ''));
        $note      = trim((string)($_POST['note']     ?? ''));

        if (!$subnet) {
            $err = 'Subnet not found.';
        } elseif ($addressId <= 0) {
            $err = 'Invalid address.';
        } else {
            $stChk = $db->prepare(
                "SELECT id, ip, hostname, owner, note, grp, status FROM addresses WHERE id = :id AND subnet_id = :sid AND status = 'reserved'"
            );
            $stChk->execute([':id' => $addressId, ':sid' => $subnetId]);
            $beforeAddr = $stChk->fetch();
            if (!$beforeAddr) {
                $err = 'Address not found or not a reserved address in this subnet.';
            } else {
                $db->prepare(
                    "UPDATE addresses SET hostname = :hn, owner = :ow, note = :nt WHERE id = :id"
                )->execute([':hn' => $hostname, ':ow' => $owner, ':nt' => $note, ':id' => $addressId]);
                audit($db, 'dhcp_pool.edit_address', 'address', $addressId,
                    "subnet_id={$subnetId} hostname={$hostname} owner={$owner}");
                history_log_address($db, 'update', $subnetId, (string)$beforeAddr['ip'], $addressId,
                    ['hostname' => (string)$beforeAddr['hostname'], 'owner' => (string)$beforeAddr['owner'],
                     'note' => (string)$beforeAddr['note'], 'grp' => (string)$beforeAddr['grp'],
                     'status' => (string)$beforeAddr['status']],
                    ['hostname' => $hostname, 'owner' => $owner, 'note' => $note,
                     'grp' => (string)$beforeAddr['grp'], 'status' => (string)$beforeAddr['status']]
                );
                $msg = 'Address updated.';
            }
        }
    } elseif ($action === 'delete_address') {
        $addressId = (int)($_POST['address_id'] ?? 0);

        if (!$subnet) {
            $err = 'Subnet not found.';
        } elseif ($addressId <= 0) {
            $err = 'Invalid address.';
        } else {
            $stChk = $db->prepare(
                "SELECT id, ip, hostname, owner, note, grp, status FROM addresses WHERE id = :id AND subnet_id = :sid AND status = 'reserved'"
            );
            $stChk->execute([':id' => $addressId, ':sid' => $subnetId]);
            $beforeAddr = $stChk->fetch();
            if (!$beforeAddr) {
                $err = 'Address not found or not a reserved address in this subnet.';
            } else {
                $db->prepare("DELETE FROM addresses WHERE id = :id")->execute([':id' => $addressId]);
                audit($db, 'dhcp_pool.delete_address', 'address', $addressId,
                    "subnet_id={$subnetId}");
                history_log_address($db, 'delete', $subnetId, (string)$beforeAddr['ip'], $addressId,
                    ['hostname' => (string)$beforeAddr['hostname'], 'owner' => (string)$beforeAddr['owner'],
                     'note' => (string)$beforeAddr['note'], 'grp' => (string)$beforeAddr['grp'],
                     'status' => (string)$beforeAddr['status']],
                    null
                );
                $msg = 'Reserved address deleted.';
                $editId = 0;
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
                        error_log('DHCP pool clear error: ' . $e->getMessage());
                        $err = 'An unexpected database error occurred while clearing the pool.';
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
        "SELECT id, ip, ip_bin, hostname, owner, note FROM addresses
         WHERE subnet_id = :sid AND status = 'reserved'
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

<p class="muted" style="margin:8px 0 0">DHCP pool management is available for IPv4 subnets only.</p>

<div class="toolbar">
  <div>
    <h1>DHCP Pool Reservation</h1>
    <div class="muted">Bulk-reserve a contiguous IP range within an IPv4 subnet. Addresses already marked <em>used</em> are never overwritten.</div>
  </div>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>

<div class="card mt-16">
  <h2>Reserve a range</h2>
  <form method="post" action="dhcp_pool.php">
    <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="reserve_pool">
    <div class="row gap-10">
      <label>Subnet<br>
        <select name="subnet_id" data-auto-submit class="mw-200">
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
      <label>Start IP<br><input name="start_ip" placeholder="e.g. <?= e(explode('/', $subnet['cidr'])[0]) ?>" required class="mw-140"></label>
      <label>End IP<br><input name="end_ip" placeholder="e.g. <?= e(explode('/', $subnet['cidr'])[0]) ?>" required class="mw-140"></label>
      <label>Note<br><input name="note" placeholder="DHCP pool" value="DHCP pool" class="mw-160"></label>
      <label class="flex-self-end"><br><button type="submit">Reserve</button></label>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if ($subnet): ?>
<div class="card mt-16">
  <h2>Clear a range <span class="muted font-xs">(removes <em>reserved</em> records only)</span></h2>
  <form method="post" action="dhcp_pool.php" data-confirm="Delete all reserved records in this range?">
    <input type="hidden" name="csrf"      value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action"    value="clear_pool">
    <input type="hidden" name="subnet_id" value="<?= (int)$subnetId ?>">
    <div class="row gap-10">
      <label>Start IP<br><input name="start_ip" required class="mw-140"></label>
      <label>End IP<br><input name="end_ip"   required class="mw-140"></label>
      <label class="flex-self-end"><br><button type="submit" class="button-danger">Clear</button></label>
    </div>
  </form>
</div>

<div class="card mt-16">
  <h2>Reserved addresses in <?= e($subnet['cidr']) ?> <span class="muted font-xs">(<?= count($reserved) ?>)</span></h2>
  <?php if (empty($reserved)): ?>
    <div class="empty-state">No reserved addresses in this subnet.</div>
  <?php else: ?>
    <?php
    // Build contiguous range groups
    $groups = [];
    $curGroup = null;
    foreach ($reserved as $r) {
        $ipInt = ipv4_bin_to_int((string)$r['ip_bin']);
        if ($curGroup === null || $ipInt !== $curGroup['end_int'] + 1) {
            if ($curGroup !== null) $groups[] = $curGroup;
            $curGroup = ['start_ip' => $r['ip'], 'end_ip' => $r['ip'],
                         'start_int' => $ipInt, 'end_int' => $ipInt, 'rows' => []];
        } else {
            $curGroup['end_ip']  = $r['ip'];
            $curGroup['end_int'] = $ipInt;
        }
        $curGroup['rows'][] = $r;
    }
    if ($curGroup !== null) $groups[] = $curGroup;
    ?>
    <div class="table-wrap">
    <table>
      <thead><tr><th>IP</th><th>Hostname</th><th>Owner</th><th>Note</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($groups as $g): ?>
        <?php if (count($g['rows']) > 1 || count($groups) > 1): ?>
        <tr class="dhcp-range-header">
          <td colspan="5" class="muted" style="font-size:.82rem;padding:4px 6px;background:var(--card);">
            <?php if ($g['start_ip'] === $g['end_ip']): ?>
              <?= e($g['start_ip']) ?>
            <?php else: ?>
              <?= e($g['start_ip']) ?> &ndash; <?= e($g['end_ip']) ?>
              &middot; <?= count($g['rows']) ?> addresses
            <?php endif; ?>
          </td>
        </tr>
        <?php endif; ?>
        <?php foreach ($g['rows'] as $r): ?>
          <?php if ($editId === (int)$r['id']): ?>
          <tr>
            <td colspan="5">
              <form method="post" action="dhcp_pool.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                <input type="hidden" name="csrf"       value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action"     value="edit_address">
                <input type="hidden" name="subnet_id"  value="<?= (int)$subnetId ?>">
                <input type="hidden" name="address_id" value="<?= (int)$r['id'] ?>">
                <span style="font-weight:600;align-self:center;"><?= e($r['ip']) ?></span>
                <label style="display:flex;flex-direction:column;font-size:.85rem;">Hostname<br>
                  <input name="hostname" value="<?= e((string)$r['hostname']) ?>" class="mw-160">
                </label>
                <label style="display:flex;flex-direction:column;font-size:.85rem;">Owner<br>
                  <input name="owner" value="<?= e((string)$r['owner']) ?>" class="mw-140">
                </label>
                <label style="display:flex;flex-direction:column;font-size:.85rem;">Note<br>
                  <input name="note" value="<?= e((string)$r['note']) ?>" class="mw-160">
                </label>
                <div style="align-self:flex-end;display:flex;gap:6px;">
                  <button type="submit">Save</button>
                  <a class="action-pill" href="dhcp_pool.php?subnet_id=<?= (int)$subnetId ?>">Cancel</a>
                </div>
              </form>
            </td>
          </tr>
          <?php else: ?>
          <tr>
            <td><?= e($r['ip']) ?></td>
            <td><?= e((string)$r['hostname']) ?></td>
            <td><?= e((string)$r['owner']) ?></td>
            <td class="muted"><?= e((string)$r['note']) ?></td>
            <td style="white-space:nowrap;">
              <a class="action-pill" href="dhcp_pool.php?subnet_id=<?= (int)$subnetId ?>&edit_id=<?= (int)$r['id'] ?>">Edit</a>
              <form method="post" action="dhcp_pool.php" style="display:inline;"
                    data-confirm="Delete reserved address <?= e($r['ip']) ?>?">
                <input type="hidden" name="csrf"       value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action"     value="delete_address">
                <input type="hidden" name="subnet_id"  value="<?= (int)$subnetId ?>">
                <input type="hidden" name="address_id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="action-pill button-danger" style="border:none;cursor:pointer;">Delete</button>
              </form>
            </td>
          </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="mt-8"><a href="addresses.php?subnet_id=<?= (int)$subnetId ?>">View all addresses →</a></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php page_footer();
