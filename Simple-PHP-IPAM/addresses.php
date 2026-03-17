<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';
$msg = '';

$st = $db->prepare("SELECT id, cidr, network, prefix, ip_version FROM subnets ORDER BY ip_version ASC, cidr ASC");
$st->execute();
$subnetList = $st->fetchAll();

$selectedSubnetId = (int)($_GET['subnet_id'] ?? ($_POST['subnet_id'] ?? 0));

$selectedSubnet = null;
if ($selectedSubnetId > 0) {
    $st = $db->prepare("SELECT id, cidr, network, prefix, ip_version FROM subnets WHERE id = :id");
    $st->execute([':id' => $selectedSubnetId]);
    $selectedSubnet = $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        require_write_access();

        $subnetId = (int)($_POST['subnet_id'] ?? 0);
        $ipInput = trim((string)($_POST['ip'] ?? ''));

        $hostname = trim((string)($_POST['hostname'] ?? ''));
        $owner = trim((string)($_POST['owner'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        $status = (string)($_POST['status'] ?? 'used');

        $st = $db->prepare("SELECT id, network, prefix, ip_version FROM subnets WHERE id = :id");
        $st->execute([':id' => $subnetId]);
        $sub = $st->fetch();

        if (!$sub) $err = 'Invalid subnet.';
        else {
            $norm = normalize_ip($ipInput);
            if (!$norm) $err = 'Invalid IP (IPv4/IPv6).';
            elseif ((int)$sub['ip_version'] !== (int)$norm['version']) $err = 'IP version does not match subnet.';
            elseif (!ip_in_cidr($norm['ip'], (string)$sub['network'], (int)$sub['prefix'])) $err = 'IP is not within selected subnet.';
            elseif (!in_array($status, ['used','reserved','free'], true)) $err = 'Invalid status.';
            else {
                try {
                    $ins = $db->prepare("INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, status)
                                         VALUES (:sid,:ip,:bin,:hn,:ow,:nt,:st)");
                    $ins->execute([
                        ':sid' => $subnetId,
                        ':ip'  => $norm['ip'],
                        ':bin' => $norm['bin'],
                        ':hn'  => $hostname,
                        ':ow'  => $owner,
                        ':nt'  => $note,
                        ':st'  => $status,
                    ]);
                    audit($db, 'address.create', 'address', (int)$db->lastInsertId(), $norm['ip'] . " subnet_id=$subnetId");
                    header('Location: addresses.php?subnet_id=' . $subnetId);
                    exit;
                } catch (PDOException $e) {
                    $err = 'Could not add address (duplicate within that subnet?).';
                }
            }
        }
    } elseif ($action === 'update') {
        require_write_access();

        $id = (int)($_POST['id'] ?? 0);
        $subnetId = (int)($_POST['subnet_id'] ?? 0);
        $hostname = trim((string)($_POST['hostname'] ?? ''));
        $owner = trim((string)($_POST['owner'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        $status = (string)($_POST['status'] ?? 'used');

        if (!in_array($status, ['used','reserved','free'], true)) $err = 'Invalid status.';
        else {
            $up = $db->prepare("UPDATE addresses
                                SET hostname=:hn, owner=:ow, note=:nt, status=:st
                                WHERE id=:id AND subnet_id=:sid");
            $up->execute([
                ':hn' => $hostname,
                ':ow' => $owner,
                ':nt' => $note,
                ':st' => $status,
                ':id' => $id,
                ':sid' => $subnetId,
            ]);
            audit($db, 'address.update', 'address', $id, "subnet_id=$subnetId");
            $msg = 'Address updated.';
        }
    } elseif ($action === 'delete') {
        require_write_access();

        $id = (int)($_POST['id'] ?? 0);
        $subnetId = (int)($_POST['subnet_id'] ?? 0);
        $del = $db->prepare("DELETE FROM addresses WHERE id = :id AND subnet_id = :sid");
        $del->execute([':id' => $id, ':sid' => $subnetId]);
        audit($db, 'address.delete', 'address', $id, "subnet_id=$subnetId");
        header('Location: addresses.php?subnet_id=' . $subnetId);
        exit;
    }
}

$addresses = [];
if ($selectedSubnetId > 0) {
    $st = $db->prepare("SELECT id, ip, hostname, owner, note, status, updated_at
                        FROM addresses WHERE subnet_id = :sid ORDER BY ip_bin ASC");
    $st->execute([':sid' => $selectedSubnetId]);
    $addresses = $st->fetchAll();
}

page_header('Addresses');
?>
<h1>Addresses</h1>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p><?= e($msg) ?></p><?php endif; ?>

<form method="get" action="addresses.php" class="row">
  <label>Subnet<br>
    <select name="subnet_id" onchange="this.form.submit()">
      <option value="0">-- Select --</option>
      <?php foreach ($subnetList as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === $selectedSubnetId) ? 'selected' : '' ?>>
          <?= e($s['cidr']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <noscript><button type="submit">Load</button></noscript>
</form>

<h2>Add address</h2>
<form method="post" action="addresses.php">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="create">
  <input type="hidden" name="subnet_id" value="<?= (int)$selectedSubnetId ?>">

  <div class="row">
    <label>IP<br><input name="ip" placeholder="<?= ($selectedSubnet && (int)$selectedSubnet['ip_version']===6) ? '2001:db8::10' : '10.0.0.10' ?>" required></label>
    <label>Hostname<br><input name="hostname"></label>
    <label>Owner<br><input name="owner"></label>
    <label>Status<br>
      <select name="status">
        <option value="used">used</option>
        <option value="reserved">reserved</option>
        <option value="free">free</option>
      </select>
    </label>
  </div>
  <div class="row">
    <label style="flex:1">Note<br><input name="note" style="width:100%"></label>
  </div>

  <p>
    <button type="submit" <?= ($selectedSubnetId>0 && current_user()['role']!=='readonly') ? '' : 'disabled' ?>>
      Add
    </button>
  </p>
  <?php if ($selectedSubnetId <= 0): ?><p class="muted">Select a subnet first.</p><?php endif; ?>
  <?php if (current_user()['role']==='readonly'): ?><p class="muted">Read-only account.</p><?php endif; ?>
</form>

<h2>List</h2>
<?php if ($selectedSubnetId <= 0): ?>
  <p class="muted">No subnet selected.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>IP</th><th>Hostname</th><th>Owner</th><th>Status</th><th>Note</th><th>Updated</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($addresses as $a): ?>
      <tr>
        <td><?= e($a['ip']) ?></td>
        <td><?= e($a['hostname']) ?></td>
        <td><?= e($a['owner']) ?></td>
        <td><?= e($a['status']) ?></td>
        <td><?= e($a['note']) ?></td>
        <td class="muted"><?= e($a['updated_at']) ?></td>
        <td>
          <details>
            <summary>Edit/Delete</summary>

            <form method="post" action="addresses.php" style="margin-top:8px">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="subnet_id" value="<?= (int)$selectedSubnetId ?>">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">

              <div class="row">
                <label>Hostname<br><input name="hostname" value="<?= e($a['hostname']) ?>"></label>
                <label>Owner<br><input name="owner" value="<?= e($a['owner']) ?>"></label>
                <label>Status<br>
                  <select name="status">
                    <option value="used" <?= ($a['status']==='used')?'selected':'' ?>>used</option>
                    <option value="reserved" <?= ($a['status']==='reserved')?'selected':'' ?>>reserved</option>
                    <option value="free" <?= ($a['status']==='free')?'selected':'' ?>>free</option>
                  </select>
                </label>
              </div>

              <div class="row">
                <label style="flex:1">Note<br><input name="note" style="width:100%" value="<?= e($a['note']) ?>"></label>
              </div>

              <button type="submit" <?= (current_user()['role']==='readonly')?'disabled':'' ?>>Save</button>
            </form>

            <form method="post" action="addresses.php" onsubmit="return confirm('Delete this address?');" style="margin-top:8px">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="subnet_id" value="<?= (int)$selectedSubnetId ?>">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <button type="submit" <?= (current_user()['role']==='readonly')?'disabled':'' ?>>Delete</button>
            </form>

            <?php if (current_user()['role']==='readonly'): ?><p class="muted">Read-only account.</p><?php endif; ?>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php page_footer();
