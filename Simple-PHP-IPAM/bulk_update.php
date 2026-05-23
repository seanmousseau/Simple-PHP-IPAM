<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
}

$err = '';
$msg = '';

$role = current_user()['role'] ?? '';
$isReadonly = ($role === 'readonly');

$st = $db->prepare("SELECT id, cidr, ip_version FROM subnets ORDER BY ip_version ASC, cidr ASC");
$st->execute();
/** @var list<array<string, mixed>> $subnets */
$subnets = $st->fetchAll();

$subnetId = to_int($_GET['subnet_id'] ?? ($_POST['subnet_id'] ?? 0));
$q = substr(trim(to_str($_GET['q'] ?? ($_POST['q'] ?? ''))), 0, 500);

$addresses = [];
$subnet = null;

// Bulk display state — initialised here so PHPStan knows these are always defined
$bulkSort     = ['col' => 'ip', 'dir' => 'asc', 'sql' => 'ip_bin ASC'];
$bulkTotal    = 0;
$bulkPageSize = 254;
$bulkPag      = paginate(0, 1, 254);

// Unconfigured-IP display state (IPv4 only)
$unconfigured        = [];   // array of IP strings not yet in addresses table
$unconfiguredCapped  = false;
$unconfiguredTotal   = 0;    // count when capped

if ($subnetId > 0) {
    $st = $db->prepare("SELECT id, cidr, ip_version, prefix, network, network_bin FROM subnets WHERE id = :id");
    $st->execute([':id' => $subnetId]);
    /** @var array<string, mixed>|false $subnetRow */
    $subnetRow = $st->fetch();
    $subnet = $subnetRow ?: null;

    $bulkSortCols = ['ip' => 'ip_bin', 'hostname' => 'hostname', 'status' => 'status'];
    $bulkSort = parse_sort($bulkSortCols, 'ip');

    $whereClause = "WHERE subnet_id = :sid";
    $params = [':sid' => $subnetId];

    if ($q !== '') {
        // Distinct :q1..:q5 placeholders for PDO native-prepared safety —
        // MySQL rejects reusing a single named placeholder across multiple
        // positions. See api.php::api_search() for the same pattern.
        $whereClause .= " AND (ip LIKE :q1 ESCAPE '!' OR hostname LIKE :q2 ESCAPE '!'"
                     . " OR owner LIKE :q3 ESCAPE '!' OR note LIKE :q4 ESCAPE '!'"
                     . " OR grp LIKE :q5 ESCAPE '!')";
        $qLike = '%' . like_escape($q) . '%';
        $params[':q1'] = $qLike;
        $params[':q2'] = $qLike;
        $params[':q3'] = $qLike;
        $params[':q4'] = $qLike;
        $params[':q5'] = $qLike;
    }

    // Pagination
    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM addresses {$whereClause}");
    $cntSt->execute($params);
    /** @var array<string, mixed>|false $cntRow */

    $cntRow = $cntSt->fetch();

    $bulkTotal = is_array($cntRow) ? to_int($cntRow['c']) : 0;

    $bulkPage     = q_int('page', 1, 1, 1000000);
    $bulkPageSize = q_int('page_size', 254, 1, 500);
    $bulkPag      = paginate($bulkTotal, $bulkPage, $bulkPageSize);

    $sql = "SELECT id, ip, ip_bin, hostname, owner, status, note, grp, updated_at
            FROM addresses
            {$whereClause}
            ORDER BY {$bulkSort['sql']}
            LIMIT :lim OFFSET :off";

    $st = $db->prepare($sql);
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':lim', $bulkPag['limit'], PDO::PARAM_INT);
    $st->bindValue(':off', $bulkPag['offset'], PDO::PARAM_INT);
    $st->execute();
    /** @var list<array<string, mixed>> $addresses */
    $addresses = $st->fetchAll();

    // --- Enumerate unconfigured IPs for IPv4 subnets (prefix 20–30) when no search, page 1 ---
    if ($subnet && to_int($subnet['ip_version']) === 4 && $q === '' && $bulkPage === 1) {
        $prefix     = to_int($subnet['prefix']);
        $assignable = ipv4_assignable_count($prefix);

        if ($assignable > 0 && $assignable <= 4094) {
            // Fetch all IPs in this subnet (lightweight: just ip text, not full rows)
            $ipSt = $db->prepare("SELECT ip FROM addresses WHERE subnet_id = :sid");
            $ipSt->execute([':sid' => $subnetId]);
            $configuredIps = array_flip(array_column($ipSt->fetchAll(), 'ip'));
            $netBin  = to_str($subnet['network_bin']);
            $netInt  = ipv4_bin_to_int($netBin);

            if ($prefix >= 32) {
                $ip = (string)inet_ntop($netBin);
                if (!isset($configuredIps[$ip])) $unconfigured[] = $ip;
            } elseif ($prefix === 31) {
                for ($i = 0; $i <= 1; $i++) {
                    $ip = ipv4_int_to_text($netInt + $i);
                    if (!isset($configuredIps[$ip])) $unconfigured[] = $ip;
                }
            } else {
                $broadcastInt = $netInt | ((1 << (32 - $prefix)) - 1);
                for ($i = $netInt + 1; $i < $broadcastInt; $i++) {
                    $ip = ipv4_int_to_text($i);
                    if (!isset($configuredIps[$ip])) $unconfigured[] = $ip;
                }
            }
        } elseif ($assignable > 4094) {
            $unconfiguredCapped = true;
            $unconfiguredTotal  = max(0, $assignable - count($addresses));
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write_access();

    $subnetId = to_int($_POST['subnet_id'] ?? 0);
    $q = trim(to_str($_POST['q'] ?? ''));

    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_map(fn(mixed $v): int => to_int($v), $ids)));
    $ids = array_filter($ids, fn($v) => $v > 0);

    $unconfIps = $_POST['unconf_ips'] ?? [];
    if (!is_array($unconfIps)) $unconfIps = [];
    $unconfIps = array_values(array_unique(array_map(fn(mixed $v): string => trim(to_str($v)), $unconfIps)));

    $action = to_str($_POST['bulk_action'] ?? 'update');

    if ($subnetId <= 0) {
        $err = "Select a subnet.";
    } elseif (count($ids) === 0 && count($unconfIps) === 0) {
        $err = "Select at least one address.";
    } else {
        try {
            $db->beginTransaction();

            // Build IN clause for existing IDs
            $in = [];
            $paramsBefore = [':sid' => $subnetId];
            foreach ($ids as $i => $id) {
                $k = ":id$i";
                $in[] = $k;
                $paramsBefore[$k] = $id;
            }

            $beforeMap = [];
            if ($in) {
                $sel = $db->prepare("SELECT id, ip, hostname, owner, note, grp, mac, expires_at, status
                                     FROM addresses
                                     WHERE subnet_id=:sid AND id IN (" . implode(',', $in) . ")");
                $sel->execute($paramsBefore);
                /** @var list<array<string, mixed>> $beforeRows */
                $beforeRows = $sel->fetchAll();
                foreach ($beforeRows as $r) $beforeMap[to_int($r['id'])] = $r;
            }

            if (in_array($action, ['extend_expiry_30', 'extend_expiry_60', 'extend_expiry_90', 'clear_expiry'], true)) {
                if (!$in) {
                    $db->rollBack();
                    $err = "No existing addresses selected.";
                } else {
                    if ($action === 'clear_expiry') {
                        $upd = $db->prepare("UPDATE addresses SET expires_at = NULL WHERE subnet_id = :sid AND id IN (" . implode(',', $in) . ")");
                        $upd->execute($paramsBefore);
                        $auditDetail = "clear_expiry";
                    } else {
                        $days = match ($action) { 'extend_expiry_30' => 30, 'extend_expiry_60' => 60, default => 90 };
                        $extParams = $paramsBefore;
                        $extParams[':days'] = "+{$days} days";
                        $upd = $db->prepare("UPDATE addresses SET expires_at = date(COALESCE(expires_at, date('now')), :days) WHERE subnet_id = :sid AND id IN (" . implode(',', $in) . ")");
                        $upd->execute($extParams);
                        $auditDetail = "extend_expiry_{$days}d";
                    }
                    $affected = $upd->rowCount();
                    audit($db, 'address.bulk_update', 'address', null,
                        "subnet_id=$subnetId selected=" . count($ids) . " affected=$affected fields={$auditDetail}"
                    );
                    $db->commit();
                    header('Location: bulk_update.php?subnet_id=' . $subnetId . '&q=' . urlencode($q));
                    exit;
                }
            } elseif ($action === 'delete') {
                // Unconfigured IPs have nothing to delete — only process existing IDs
                if (!$in) {
                    $db->rollBack();
                    $err = "No existing addresses selected to delete.";
                } else {
                    $confirm = strtoupper(trim(to_str($_POST['confirm_delete'] ?? '')));
                    if ($confirm !== 'DELETE') {
                        $db->rollBack();
                        $err = "To delete, type DELETE in the confirmation box.";
                    } else {
                        $del = $db->prepare("DELETE FROM addresses WHERE subnet_id=:sid AND id IN (" . implode(',', $in) . ")");
                        $del->execute($paramsBefore);
                        $affected = $del->rowCount();

                        foreach ($ids as $id) {
                            if (!isset($beforeMap[$id])) continue;
                            $b = $beforeMap[$id];
                            history_log_address($db, 'bulk_delete', $subnetId, to_str($b['ip']), to_int($b['id']), [
                                'hostname' => to_str($b['hostname']),
                                'owner'    => to_str($b['owner']),
                                'note'     => to_str($b['note']),
                                'status'   => to_str($b['status']),
                            ], null);
                        }

                        audit($db, 'address.bulk_delete', 'address', null,
                            "subnet_id=$subnetId selected=" . count($ids) . " affected=$affected"
                        );

                        $db->commit();
                        header('Location: bulk_update.php?subnet_id=' . $subnetId . '&q=' . urlencode($q));
                        exit;
                    }
                }
            } else {
                $doHostname  = !empty($_POST['do_hostname']);
                $doOwner     = !empty($_POST['do_owner']);
                $doStatus    = !empty($_POST['do_status']);
                $doNote      = !empty($_POST['do_note']);
                $doGrp       = !empty($_POST['do_grp']);
                $doMac       = !empty($_POST['do_mac']);
                $doExpiresAt = !empty($_POST['do_expires_at']);

                $newHostname  = trim(to_str($_POST['hostname'] ?? ''));
                $newOwner     = trim(to_str($_POST['owner'] ?? ''));
                $newStatus    = to_str($_POST['status'] ?? 'used');
                $newNote      = trim(to_str($_POST['note'] ?? ''));
                $newGrp       = trim(to_str($_POST['grp'] ?? ''));
                $newMac       = substr(trim(to_str($_POST['mac'] ?? '')), 0, 64);
                $newExpiresAt = trim(to_str($_POST['expires_at'] ?? ''));
                if ($newExpiresAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newExpiresAt)) {
                    $newExpiresAt = '';
                }

                if (!$doHostname && !$doOwner && !$doStatus && !$doNote && !$doGrp && !$doMac && !$doExpiresAt) {
                    $db->rollBack();
                    $err = "Select at least one field to update.";
                } elseif ($doStatus && !in_array($newStatus, ['used','reserved','free'], true)) {
                    $db->rollBack();
                    $err = "Invalid status.";
                } else {
                    // --- INSERT unconfigured IPs first ---
                    $insertedUnconf = 0;
                    if ($unconfIps && $subnet) {
                        $subnetNetwork = to_str($subnet['network']);
                        $subnetPrefix  = to_int($subnet['prefix']);
                        $insStmt = $db->prepare(
                            "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, status, note, grp, mac, expires_at)
                             VALUES (:sid, :ip, :ib, :hn, :ow, :st, :nt, :grp, :mac, :exp)"
                        );
                        foreach ($unconfIps as $rawIp) {
                            $norm = normalize_ip($rawIp);
                            if (!$norm || $norm['version'] !== 4) continue;
                            if (!ip_in_cidr($norm['ip'], $subnetNetwork, $subnetPrefix)) continue;
                            // Guard: must not already exist
                            $chk = $db->prepare("SELECT id FROM addresses WHERE subnet_id=:sid AND ip=:ip");
                            $chk->execute([':sid' => $subnetId, ':ip' => $norm['ip']]);
                            if ($chk->fetch()) continue;

                            $insExpAt = ($doExpiresAt && $newExpiresAt !== '') ? $newExpiresAt : null;
                            $insStmt->execute([
                                ':sid'  => $subnetId,
                                ':ip'   => $norm['ip'],
                                ':ib'   => $norm['bin'],
                                ':hn'   => $doHostname  ? $newHostname : '',
                                ':ow'   => $doOwner     ? $newOwner    : '',
                                ':st'   => $doStatus    ? $newStatus   : 'used',
                                ':nt'   => $doNote      ? $newNote     : '',
                                ':grp'  => $doGrp       ? $newGrp      : '',
                                ':mac'  => $doMac       ? $newMac      : '',
                                ':exp'  => $insExpAt,
                            ]);
                            $newId = ipam_last_insert_id($db, 'addresses');
                            $insertedUnconf++;
                            $after = [
                                'hostname'   => $doHostname  ? $newHostname : '',
                                'owner'      => $doOwner     ? $newOwner    : '',
                                'note'       => $doNote      ? $newNote     : '',
                                'grp'        => $doGrp       ? $newGrp      : '',
                                'mac'        => $doMac       ? $newMac      : '',
                                'expires_at' => $insExpAt,
                                'status'     => $doStatus    ? $newStatus   : 'used',
                            ];
                            history_log_address($db, 'bulk_create', $subnetId, $norm['ip'], $newId, null, $after);
                        }
                    }

                    // --- UPDATE existing addresses ---
                    $affected = 0;
                    if ($in) {
                        $set = [];
                        $params = [':sid' => $subnetId];

                        if ($doHostname)  { $set[] = "hostname = :hn";   $params[':hn']  = $newHostname; }
                        if ($doOwner)     { $set[] = "owner = :ow";     $params[':ow']  = $newOwner; }
                        if ($doStatus)    { $set[] = "status = :st";    $params[':st']  = $newStatus; }
                        if ($doNote)      { $set[] = "note = :nt";      $params[':nt']  = $newNote; }
                        if ($doGrp)       { $set[] = "grp = :grp";      $params[':grp'] = $newGrp; }
                        if ($doMac)       { $set[] = "mac = :mac";      $params[':mac'] = $newMac; }
                        if ($doExpiresAt) {
                            $set[] = "expires_at = :exp";
                            $params[':exp'] = $newExpiresAt !== '' ? $newExpiresAt : null;
                        }

                        foreach ($paramsBefore as $k => $v) {
                            if ($k !== ':sid') $params[$k] = $v;
                        }

                        $sql = "UPDATE addresses SET " . implode(', ', $set) .
                               " WHERE subnet_id = :sid AND id IN (" . implode(',', $in) . ")";
                        $st = $db->prepare($sql);
                        $st->execute($params);
                        $affected = $st->rowCount();

                        $updExpAt = $doExpiresAt ? ($newExpiresAt !== '' ? $newExpiresAt : null) : null;
                        foreach ($ids as $id) {
                            if (!isset($beforeMap[$id])) continue;
                            $b = $beforeMap[$id];
                            $after = [
                                'hostname'   => $doHostname  ? $newHostname : to_str($b['hostname']),
                                'owner'      => $doOwner     ? $newOwner    : to_str($b['owner']),
                                'note'       => $doNote      ? $newNote     : to_str($b['note']),
                                'grp'        => $doGrp       ? $newGrp      : to_str($b['grp'] ?? ''),
                                'mac'        => $doMac       ? $newMac      : to_str($b['mac'] ?? ''),
                                'expires_at' => $doExpiresAt ? $updExpAt    : (isset($b['expires_at']) ? to_str($b['expires_at']) : null),
                                'status'     => $doStatus    ? $newStatus   : to_str($b['status']),
                            ];
                            history_log_address($db, 'bulk_update', $subnetId, to_str($b['ip']), to_int($b['id']), [
                                'hostname'   => to_str($b['hostname']),
                                'owner'      => to_str($b['owner']),
                                'note'       => to_str($b['note']),
                                'grp'        => to_str($b['grp'] ?? ''),
                                'mac'        => to_str($b['mac'] ?? ''),
                                'expires_at' => isset($b['expires_at']) ? to_str($b['expires_at']) : null,
                                'status'     => to_str($b['status']),
                            ], $after);
                        }
                    }

                    audit($db, 'address.bulk_update', 'address', null,
                        "subnet_id=$subnetId selected=" . count($ids) . " affected=$affected"
                        . ($insertedUnconf > 0 ? " created=$insertedUnconf" : "")
                        . " fields=" . implode(',', array_filter([
                            $doHostname  ? 'hostname'   : '',
                            $doOwner     ? 'owner'      : '',
                            $doStatus    ? 'status'     : '',
                            $doNote      ? 'note'       : '',
                            $doGrp       ? 'grp'        : '',
                            $doMac       ? 'mac'        : '',
                            $doExpiresAt ? 'expires_at' : '',
                        ]))
                    );

                    $db->commit();
                    header('Location: bulk_update.php?subnet_id=' . $subnetId . '&q=' . urlencode($q));
                    exit;
                }
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Bulk update error: ' . $e->getMessage());
            $err = 'An unexpected database error occurred during bulk update.';
        }
    }
}

page_header('Bulk Update');
?>
<div class="breadcrumbs">
  <a href="dashboard.php"><?= icon('home') ?> Dashboard</a><span class="sep">›</span>
  <a href="addresses.php"><?= icon('map-pin') ?> Addresses</a><span class="sep">›</span>
  <span>Bulk Update</span>
</div>

<h1>Bulk Update Addresses</h1>

<?php if ($isReadonly): ?>
  <p class="danger">This account is read-only. Bulk update is disabled.</p>
<?php endif; ?>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>

<form method="get" action="bulk_update.php" class="row">
  <label>Subnet<br>
    <select name="subnet_id">
      <option value="0">-- Select --</option>
      <?php foreach ($subnets as $s): ?>
        <option value="<?= to_int($s['id']) ?>" <?= (to_int($s['id']) === $subnetId) ? 'selected' : '' ?>>
          <?= e(to_str($s['cidr'])) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Search (optional)<br>
    <input name="q" value="<?= e($q) ?>" placeholder="ip/hostname/owner/note">
  </label>
  <button type="submit">Load</button>
</form>

<?php if ($subnetId > 0): ?>
  <h2>Selected subnet: <?= e($subnet ? to_str($subnet['cidr']) : '') ?></h2>

  <?php if ($unconfiguredCapped && $unconfiguredTotal > 0): ?>
    <p class="muted">
      <b><?= e((string)$unconfiguredTotal) ?></b> unconfigured IPs not shown (subnet too large to enumerate).
      Use <a href="unassigned.php?subnet_id=<?= $subnetId ?>">Unassigned</a> to browse them.
    </p>
  <?php endif; ?>

  <form method="post" action="bulk_update.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="subnet_id" value="<?= (int)$subnetId ?>">
    <input type="hidden" name="q" value="<?= e($q) ?>">

    <h3>Bulk update fields</h3>
    <p class="muted">Tick which fields to change; unticked fields are not modified.</p>

    <div class="row">
      <label><input type="checkbox" name="do_hostname" value="1"> Hostname</label>
      <input name="hostname" placeholder="new hostname">

      <label><input type="checkbox" name="do_owner" value="1"> Owner</label>
      <input name="owner" placeholder="new owner">
    </div>

    <div class="row mt-8">
      <label><input type="checkbox" name="do_status" value="1"> Status</label>
      <select name="status">
        <option value="used">used</option>
        <option value="reserved">reserved</option>
        <option value="free">free</option>
      </select>

      <label><input type="checkbox" name="do_note" value="1"> Note</label>
      <input name="note" class="mw-420" placeholder="new note">

      <label><input type="checkbox" name="do_grp" value="1"> Group</label>
      <input name="grp" maxlength="100" placeholder="new group">
    </div>

    <div class="row mt-8">
      <label><input type="checkbox" name="do_mac" value="1"> MAC</label>
      <input name="mac" maxlength="64" placeholder="e.g. aa:bb:cc:dd:ee:ff" class="mw-160">

      <label><input type="checkbox" name="do_expires_at" value="1"> Expires</label>
      <input name="expires_at" type="date" class="mw-160">
    </div>

    <h3 class="mt-18">Choose addresses</h3>
    <p class="muted">Select one or more rows to update or delete.
      <?php if ($unconfigured): ?>
        Rows marked <span class="muted">(unconfigured)</span> do not yet have a record — selecting them for
        <b>Update</b> will create them with the chosen field values.
      <?php endif; ?>
    </p>

    <p>
      <button type="button" data-select-addrs="all">Select all</button>
      <button type="button" data-select-addrs="none">Select none</button>
      <?php if ($unconfigured): ?>
        <button type="button" data-select-addrs="unconfigured">Select unconfigured</button>
      <?php endif; ?>
    </p>

    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Select</th>
          <?php $bulkQs = '?subnet_id=' . $subnetId . ($q !== '' ? '&q=' . urlencode($q) : '');
                echo sort_th('ip',       'IP',       $bulkSort['col'], $bulkSort['dir'], $bulkQs);
                echo sort_th('hostname', 'Hostname', $bulkSort['col'], $bulkSort['dir'], $bulkQs);
          ?>
          <th>Owner</th>
          <?php echo sort_th('status', 'Status', $bulkSort['col'], $bulkSort['dir'], $bulkQs); ?>
          <th>Note</th>
          <th>Updated</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($addresses as $a): ?>
        <tr>
          <td><input class="addrbox" type="checkbox" name="ids[]" value="<?= to_int($a['id']) ?>"></td>
          <td><?= e(to_str($a['ip'])) ?></td>
          <td><?= e(to_str($a['hostname'])) ?></td>
          <td><?= e(to_str($a['owner'])) ?></td>
          <td><?= e(to_str($a['status'])) ?></td>
          <td><?= e(to_str($a['note'])) ?></td>
          <td class="muted"><?= e(ipam_format_datetime(to_str($a['updated_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php foreach ($unconfigured as $uip): ?>
        <tr class="muted opacity-70">
          <td><input class="addrbox" type="checkbox" name="unconf_ips[]" value="<?= e($uip) ?>" data-unconf="1"></td>
          <td><?= e($uip) ?></td>
          <td class="muted">—</td>
          <td class="muted">—</td>
          <td><span class="muted"><em>free (unconfigured)</em></span></td>
          <td class="muted">—</td>
          <td class="muted">—</td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$addresses && !$unconfigured): ?>
        <tr><td colspan="7"><div class="empty-state">No addresses found.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>

    <?php if ($bulkPag['pages'] > 1): ?>
      <p class="muted mt-8">
        Page <?= $bulkPag['page'] ?> of <?= $bulkPag['pages'] ?>
        (<?= $bulkTotal ?> addresses)
        <?php
          $pqs = 'subnet_id=' . $subnetId . ($q !== '' ? '&q=' . urlencode($q) : '')
               . '&sort=' . urlencode(to_str($bulkSort['col'])) . '&dir=' . urlencode(to_str($bulkSort['dir']))
               . '&page_size=' . $bulkPageSize;
        ?>
        <?php if ($bulkPag['page'] > 1): ?>
          <a href="bulk_update.php?<?= e($pqs) ?>&page=<?= $bulkPag['page'] - 1 ?>">← Prev</a>
        <?php endif; ?>
        <?php if ($bulkPag['page'] < $bulkPag['pages']): ?>
          <a href="bulk_update.php?<?= e($pqs) ?>&page=<?= $bulkPag['page'] + 1 ?>">Next →</a>
        <?php endif; ?>
      </p>
    <?php endif; ?>

    <h3 class="mt-18">Action</h3>

    <div class="row">
      <label>Bulk action<br>
        <select name="bulk_action">
          <option value="update" selected>Update selected</option>
          <option value="delete">Delete selected</option>
          <option value="extend_expiry_30">Extend expiry by 30 days</option>
          <option value="extend_expiry_60">Extend expiry by 60 days</option>
          <option value="extend_expiry_90">Extend expiry by 90 days</option>
          <option value="clear_expiry">Clear expiry date</option>
        </select>
      </label>

      <label>Delete confirmation (type DELETE)<br>
        <input name="confirm_delete" placeholder="DELETE">
      </label>

      <button type="submit" <?= $isReadonly ? 'disabled' : '' ?>
        data-confirm="Proceed with the selected bulk action?">
        Apply
      </button>
    </div>

    <p class="muted">
      For deletes, you must type <b>DELETE</b> in the confirmation box. Unconfigured rows cannot be deleted.
    </p>

  </form>
<?php else: ?>
  <p class="muted">Select a subnet to begin.</p>
<?php endif; ?>

<?php page_footer();
