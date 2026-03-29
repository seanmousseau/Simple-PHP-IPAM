<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
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
$subnets = $st->fetchAll();

$subnetId = (int)($_GET['subnet_id'] ?? ($_POST['subnet_id'] ?? 0));
$q = substr(trim((string)($_GET['q'] ?? ($_POST['q'] ?? ''))), 0, 500);

$addresses = [];
$subnet = null;

// Unconfigured-IP display state (IPv4 only)
$unconfigured        = [];   // array of IP strings not yet in addresses table
$unconfiguredCapped  = false;
$unconfiguredTotal   = 0;    // count when capped

if ($subnetId > 0) {
    $st = $db->prepare("SELECT id, cidr, ip_version, prefix, network, network_bin FROM subnets WHERE id = :id");
    $st->execute([':id' => $subnetId]);
    $subnet = $st->fetch() ?: null;

    $sql = "SELECT id, ip, hostname, owner, status, note, updated_at
            FROM addresses
            WHERE subnet_id = :sid";
    $params = [':sid' => $subnetId];

    if ($q !== '') {
        $sql .= " AND (ip LIKE :q ESCAPE '\\' OR hostname LIKE :q ESCAPE '\\' OR owner LIKE :q ESCAPE '\\' OR note LIKE :q ESCAPE '\\')";
        $params[':q'] = '%' . like_escape($q) . '%';
    }

    $sql .= " ORDER BY ip_bin ASC";

    $st = $db->prepare($sql);
    $st->execute($params);
    $addresses = $st->fetchAll();

    // --- Enumerate unconfigured IPs for IPv4 subnets (prefix 20–30) when no search ---
    if ($subnet && (int)$subnet['ip_version'] === 4 && $q === '') {
        $prefix     = (int)$subnet['prefix'];
        $assignable = ipv4_assignable_count($prefix);

        if ($assignable > 0 && $assignable <= 4094) {
            $configuredIps = array_flip(array_column($addresses, 'ip'));
            $netBin  = $subnet['network_bin'];
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

    $subnetId = (int)($_POST['subnet_id'] ?? 0);
    $q = trim((string)($_POST['q'] ?? ''));

    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_filter($ids, fn($v) => $v > 0);

    $unconfIps = $_POST['unconf_ips'] ?? [];
    if (!is_array($unconfIps)) $unconfIps = [];
    $unconfIps = array_values(array_unique(array_map('trim', $unconfIps)));

    $action = (string)($_POST['bulk_action'] ?? 'update');

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
                $sel = $db->prepare("SELECT id, ip, hostname, owner, note, status
                                     FROM addresses
                                     WHERE subnet_id=:sid AND id IN (" . implode(',', $in) . ")");
                $sel->execute($paramsBefore);
                $beforeRows = $sel->fetchAll();
                foreach ($beforeRows as $r) $beforeMap[(int)$r['id']] = $r;
            }

            if ($action === 'delete') {
                // Unconfigured IPs have nothing to delete — only process existing IDs
                if (!$in) {
                    $db->rollBack();
                    $err = "No existing addresses selected to delete.";
                } else {
                    $confirm = strtoupper(trim((string)($_POST['confirm_delete'] ?? '')));
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
                            history_log_address($db, 'bulk_delete', $subnetId, (string)$b['ip'], (int)$b['id'], [
                                'hostname' => (string)$b['hostname'],
                                'owner'    => (string)$b['owner'],
                                'note'     => (string)$b['note'],
                                'status'   => (string)$b['status'],
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
                $doHostname = !empty($_POST['do_hostname']);
                $doOwner    = !empty($_POST['do_owner']);
                $doStatus   = !empty($_POST['do_status']);
                $doNote     = !empty($_POST['do_note']);

                $newHostname = trim((string)($_POST['hostname'] ?? ''));
                $newOwner    = trim((string)($_POST['owner'] ?? ''));
                $newStatus   = (string)($_POST['status'] ?? 'used');
                $newNote     = trim((string)($_POST['note'] ?? ''));

                if (!$doHostname && !$doOwner && !$doStatus && !$doNote) {
                    $db->rollBack();
                    $err = "Select at least one field to update.";
                } elseif ($doStatus && !in_array($newStatus, ['used','reserved','free'], true)) {
                    $db->rollBack();
                    $err = "Invalid status.";
                } else {
                    // --- INSERT unconfigured IPs first ---
                    $insertedUnconf = 0;
                    if ($unconfIps && $subnet) {
                        $subnetNetwork = (string)$subnet['network'];
                        $subnetPrefix  = (int)$subnet['prefix'];
                        $insStmt = $db->prepare(
                            "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, status, note)
                             VALUES (:sid, :ip, :ib, :hn, :ow, :st, :nt)"
                        );
                        foreach ($unconfIps as $rawIp) {
                            $norm = normalize_ip($rawIp);
                            if (!$norm || $norm['version'] !== 4) continue;
                            if (!ip_in_cidr($norm['ip'], $subnetNetwork, $subnetPrefix)) continue;
                            // Guard: must not already exist
                            $chk = $db->prepare("SELECT id FROM addresses WHERE subnet_id=:sid AND ip=:ip");
                            $chk->execute([':sid' => $subnetId, ':ip' => $norm['ip']]);
                            if ($chk->fetch()) continue;

                            $insStmt->execute([
                                ':sid' => $subnetId,
                                ':ip'  => $norm['ip'],
                                ':ib'  => $norm['bin'],
                                ':hn'  => $doHostname ? $newHostname : '',
                                ':ow'  => $doOwner    ? $newOwner    : '',
                                ':st'  => $doStatus   ? $newStatus   : 'used',
                                ':nt'  => $doNote     ? $newNote     : '',
                            ]);
                            $newId = (int)$db->lastInsertId();
                            $insertedUnconf++;
                            $after = [
                                'hostname' => $doHostname ? $newHostname : '',
                                'owner'    => $doOwner    ? $newOwner    : '',
                                'note'     => $doNote     ? $newNote     : '',
                                'status'   => $doStatus   ? $newStatus   : 'used',
                            ];
                            history_log_address($db, 'bulk_create', $subnetId, $norm['ip'], $newId, null, $after);
                        }
                    }

                    // --- UPDATE existing addresses ---
                    $affected = 0;
                    if ($in) {
                        $set = [];
                        $params = [':sid' => $subnetId];

                        if ($doHostname) { $set[] = "hostname = :hn"; $params[':hn'] = $newHostname; }
                        if ($doOwner)    { $set[] = "owner = :ow";    $params[':ow'] = $newOwner; }
                        if ($doStatus)   { $set[] = "status = :st";   $params[':st'] = $newStatus; }
                        if ($doNote)     { $set[] = "note = :nt";     $params[':nt'] = $newNote; }

                        foreach ($paramsBefore as $k => $v) {
                            if ($k !== ':sid') $params[$k] = $v;
                        }

                        $sql = "UPDATE addresses SET " . implode(', ', $set) .
                               " WHERE subnet_id = :sid AND id IN (" . implode(',', $in) . ")";
                        $st = $db->prepare($sql);
                        $st->execute($params);
                        $affected = $st->rowCount();

                        foreach ($ids as $id) {
                            if (!isset($beforeMap[$id])) continue;
                            $b = $beforeMap[$id];
                            $after = [
                                'hostname' => $doHostname ? $newHostname : (string)$b['hostname'],
                                'owner'    => $doOwner    ? $newOwner    : (string)$b['owner'],
                                'note'     => $doNote     ? $newNote     : (string)$b['note'],
                                'status'   => $doStatus   ? $newStatus   : (string)$b['status'],
                            ];
                            history_log_address($db, 'bulk_update', $subnetId, (string)$b['ip'], (int)$b['id'], [
                                'hostname' => (string)$b['hostname'],
                                'owner'    => (string)$b['owner'],
                                'note'     => (string)$b['note'],
                                'status'   => (string)$b['status'],
                            ], $after);
                        }
                    }

                    audit($db, 'address.bulk_update', 'address', null,
                        "subnet_id=$subnetId selected=" . count($ids) . " affected=$affected"
                        . ($insertedUnconf > 0 ? " created=$insertedUnconf" : "")
                        . " fields=" . implode(',', array_filter([
                            $doHostname ? 'hostname' : '',
                            $doOwner    ? 'owner'    : '',
                            $doStatus   ? 'status'   : '',
                            $doNote     ? 'note'     : '',
                        ]))
                    );

                    $db->commit();
                    header('Location: bulk_update.php?subnet_id=' . $subnetId . '&q=' . urlencode($q));
                    exit;
                }
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $err = "Bulk action failed: " . $e->getMessage();
        }
    }
}

page_header('Bulk Update');
?>
<h1>Bulk Update Addresses</h1>

<?php if ($isReadonly): ?>
  <p class="danger">This account is read-only. Bulk update is disabled.</p>
<?php endif; ?>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p><?= e($msg) ?></p><?php endif; ?>

<form method="get" action="bulk_update.php" class="row">
  <label>Subnet<br>
    <select name="subnet_id">
      <option value="0">-- Select --</option>
      <?php foreach ($subnets as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === $subnetId) ? 'selected' : '' ?>>
          <?= e($s['cidr']) ?>
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
  <h2>Selected subnet: <?= e((string)($subnet['cidr'] ?? '')) ?></h2>

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

    <div class="row" style="margin-top:8px">
      <label><input type="checkbox" name="do_status" value="1"> Status</label>
      <select name="status">
        <option value="used">used</option>
        <option value="reserved">reserved</option>
        <option value="free">free</option>
      </select>

      <label><input type="checkbox" name="do_note" value="1"> Note</label>
      <input name="note" style="min-width:420px" placeholder="new note">
    </div>

    <h3 style="margin-top:18px">Choose addresses</h3>
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

    <table>
      <thead>
        <tr>
          <th>Select</th>
          <th>IP</th>
          <th>Hostname</th>
          <th>Owner</th>
          <th>Status</th>
          <th>Note</th>
          <th>Updated</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($addresses as $a): ?>
        <tr>
          <td><input class="addrbox" type="checkbox" name="ids[]" value="<?= (int)$a['id'] ?>"></td>
          <td><?= e($a['ip']) ?></td>
          <td><?= e($a['hostname']) ?></td>
          <td><?= e($a['owner']) ?></td>
          <td><?= e($a['status']) ?></td>
          <td><?= e($a['note']) ?></td>
          <td class="muted"><?= e($a['updated_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php foreach ($unconfigured as $uip): ?>
        <tr class="muted" style="opacity:.7">
          <td><input class="addrbox" type="checkbox" name="unconf_ips[]" value="<?= e($uip) ?>" data-unconf="1"></td>
          <td><?= e($uip) ?></td>
          <td></td>
          <td></td>
          <td><span class="muted"><em>free (unconfigured)</em></span></td>
          <td></td>
          <td class="muted">—</td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$addresses && !$unconfigured): ?>
        <tr><td colspan="7"><div class="empty-state">No addresses found.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>

    <h3 style="margin-top:18px">Action</h3>

    <div class="row">
      <label>Bulk action<br>
        <select name="bulk_action">
          <option value="update" selected>Update selected</option>
          <option value="delete">Delete selected</option>
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
