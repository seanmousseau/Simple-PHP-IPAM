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
$q = trim((string)($_GET['q'] ?? ($_POST['q'] ?? '')));

$addresses = [];
$subnet = null;

if ($subnetId > 0) {
    $st = $db->prepare("SELECT id, cidr FROM subnets WHERE id = :id");
    $st->execute([':id' => $subnetId]);
    $subnet = $st->fetch() ?: null;

    $sql = "SELECT id, ip, hostname, owner, status, note, updated_at
            FROM addresses
            WHERE subnet_id = :sid";
    $params = [':sid' => $subnetId];

    if ($q !== '') {
        $sql .= " AND (ip LIKE :q OR hostname LIKE :q OR owner LIKE :q OR note LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $sql .= " ORDER BY ip_bin ASC";

    $st = $db->prepare($sql);
    $st->execute($params);
    $addresses = $st->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write_access();

    $subnetId = (int)($_POST['subnet_id'] ?? 0);
    $q = trim((string)($_POST['q'] ?? ''));

    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_filter($ids, fn($v) => $v > 0);

    $doHostname = !empty($_POST['do_hostname']);
    $doOwner    = !empty($_POST['do_owner']);
    $doStatus   = !empty($_POST['do_status']);
    $doNote     = !empty($_POST['do_note']);

    $newHostname = trim((string)($_POST['hostname'] ?? ''));
    $newOwner    = trim((string)($_POST['owner'] ?? ''));
    $newStatus   = (string)($_POST['status'] ?? 'used');
    $newNote     = trim((string)($_POST['note'] ?? ''));

    if ($subnetId <= 0) {
        $err = "Select a subnet.";
    } elseif (count($ids) === 0) {
        $err = "Select at least one address.";
    } elseif (!$doHostname && !$doOwner && !$doStatus && !$doNote) {
        $err = "Select at least one field to update.";
    } elseif ($doStatus && !in_array($newStatus, ['used','reserved','free'], true)) {
        $err = "Invalid status.";
    } else {
        try {
            $db->beginTransaction();

            // Load before states for history
            $in = [];
            $paramsBefore = [':sid' => $subnetId];
            foreach ($ids as $i => $id) {
                $k = ":id$i";
                $in[] = $k;
                $paramsBefore[$k] = $id;
            }

            $sel = $db->prepare("SELECT id, ip, hostname, owner, note, status
                                 FROM addresses
                                 WHERE subnet_id=:sid AND id IN (" . implode(',', $in) . ")");
            $sel->execute($paramsBefore);
            $beforeRows = $sel->fetchAll();
            $beforeMap = [];
            foreach ($beforeRows as $r) $beforeMap[(int)$r['id']] = $r;

            // Build dynamic SET
            $set = [];
            $params = [':sid' => $subnetId];

            if ($doHostname) { $set[] = "hostname = :hn"; $params[':hn'] = $newHostname; }
            if ($doOwner)    { $set[] = "owner = :ow";    $params[':ow'] = $newOwner; }
            if ($doStatus)   { $set[] = "status = :st";   $params[':st'] = $newStatus; }
            if ($doNote)     { $set[] = "note = :nt";     $params[':nt'] = $newNote; }

            // IN clause (reuse)
            foreach ($paramsBefore as $k => $v) {
                if ($k !== ':sid') $params[$k] = $v;
            }

            $sql = "UPDATE addresses SET " . implode(', ', $set) .
                   " WHERE subnet_id = :sid AND id IN (" . implode(',', $in) . ")";
            $st = $db->prepare($sql);
            $st->execute($params);
            $affected = $st->rowCount();

            // Write history per row
            foreach ($ids as $id) {
                if (!isset($beforeMap[$id])) continue;
                $b = $beforeMap[$id];

                $after = [
                    'hostname' => $doHostname ? $newHostname : (string)$b['hostname'],
                    'owner'    => $doOwner ? $newOwner : (string)$b['owner'],
                    'note'     => $doNote ? $newNote : (string)$b['note'],
                    'status'   => $doStatus ? $newStatus : (string)$b['status'],
                ];

                history_log_address($db, 'bulk_update', $subnetId, (string)$b['ip'], (int)$b['id'], [
                    'hostname' => (string)$b['hostname'],
                    'owner' => (string)$b['owner'],
                    'note' => (string)$b['note'],
                    'status' => (string)$b['status'],
                ], $after);
            }

            audit($db, 'address.bulk_update', 'address', null,
                "subnet_id=$subnetId selected=" . count($ids) . " affected=$affected fields=" .
                implode(',', array_filter([
                    $doHostname ? 'hostname' : '',
                    $doOwner ? 'owner' : '',
                    $doStatus ? 'status' : '',
                    $doNote ? 'note' : '',
                ]))
            );

            $db->commit();
            $msg = "Bulk update complete. Rows affected: $affected";

            header('Location: bulk_update.php?subnet_id=' . $subnetId . '&q=' . urlencode($q));
            exit;
        } catch (Throwable $e) {
            $db->rollBack();
            $err = "Bulk update failed: " . $e->getMessage();
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

  <form method="post" action="bulk_update.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="subnet_id" value="<?= (int)$subnetId ?>">
    <input type="hidden" name="q" value="<?= e($q) ?>">

    <h3>Set fields</h3>
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
    <p class="muted">Select one or more rows to update.</p>

    <p>
      <button type="button" onclick="document.querySelectorAll('input.addrbox').forEach(cb=>cb.checked=true)">Select all</button>
      <button type="button" onclick="document.querySelectorAll('input.addrbox').forEach(cb=>cb.checked=false)">Select none</button>
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
      </tbody>
    </table>

    <p style="margin-top:12px">
      <button type="submit" <?= $isReadonly ? 'disabled' : '' ?>
        onclick="return confirm('Apply bulk update to selected rows?');">
        Apply Bulk Update
      </button>
    </p>
  </form>
<?php else: ?>
  <p class="muted">Select a subnet to begin.</p>
<?php endif; ?>

<?php page_footer();
