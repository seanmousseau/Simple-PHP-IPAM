<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
}

$err = '';
$msg = '';

$st = $db->prepare("SELECT id, cidr, network, prefix, ip_version FROM subnets ORDER BY ip_version ASC, cidr ASC");
$st->execute();
$subnetList = $st->fetchAll();

$selectedSubnetId = (int)($_GET['subnet_id'] ?? ($_POST['subnet_id'] ?? 0));
$page = q_int('page', 1, 1, 1000000);
$pageSize = q_int('page_size', 254, 1, 500);

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

        $hostname = substr(trim((string)($_POST['hostname'] ?? '')), 0, 253);
        $owner    = substr(trim((string)($_POST['owner']    ?? '')), 0, 255);
        $note     = substr(trim((string)($_POST['note']     ?? '')), 0, 1000);
        $status = (string)($_POST['status'] ?? 'used');

        $st = $db->prepare("SELECT id, network, prefix, ip_version FROM subnets WHERE id = :id");
        $st->execute([':id' => $subnetId]);
        $sub = $st->fetch();

        if (!$sub) {
            $err = 'Invalid subnet.';
        } else {
            $norm = normalize_ip($ipInput);
            if (!$norm) {
                $err = 'Invalid IP (IPv4/IPv6).';
            } elseif ((int)$sub['ip_version'] !== (int)$norm['version']) {
                $err = 'IP version does not match subnet.';
            } elseif (!ip_in_cidr($norm['ip'], (string)$sub['network'], (int)$sub['prefix'])) {
                $err = 'IP is not within selected subnet.';
            } elseif (!in_array($status, ['used','reserved','free'], true)) {
                $err = 'Invalid status.';
            } else {
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
                    $aid = (int)$db->lastInsertId();

                    history_log_address($db, 'create', $subnetId, $norm['ip'], $aid, null, [
                        'hostname' => $hostname,
                        'owner' => $owner,
                        'note' => $note,
                        'status' => $status,
                    ]);
                    audit($db, 'address.create', 'address', $aid, "ip={$norm['ip']} subnet_id=$subnetId");

                    header('Location: addresses.php?subnet_id=' . $subnetId);
                    exit;
                } catch (PDOException $e) {
                    $err = 'Could not add address (duplicate within that subnet?).';
                }
            }
        }
    }

    if ($action === 'update') {
        require_write_access();

        $id = (int)($_POST['id'] ?? 0);
        $subnetId = (int)($_POST['subnet_id'] ?? 0);
        $hostname = substr(trim((string)($_POST['hostname'] ?? '')), 0, 253);
        $owner    = substr(trim((string)($_POST['owner']    ?? '')), 0, 255);
        $note     = substr(trim((string)($_POST['note']     ?? '')), 0, 1000);
        $status = (string)($_POST['status'] ?? 'used');

        if (!in_array($status, ['used','reserved','free'], true)) {
            $err = 'Invalid status.';
        } else {
            $sel = $db->prepare("SELECT id, ip, hostname, owner, note, status FROM addresses WHERE id=:id AND subnet_id=:sid");
            $sel->execute([':id' => $id, ':sid' => $subnetId]);
            $before = $sel->fetch();

            if (!$before) {
                $err = 'Address not found.';
            } else {
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

                history_log_address($db, 'update', $subnetId, (string)$before['ip'], $id,
                    [
                        'hostname' => (string)$before['hostname'],
                        'owner' => (string)$before['owner'],
                        'note' => (string)$before['note'],
                        'status' => (string)$before['status'],
                    ],
                    [
                        'hostname' => $hostname,
                        'owner' => $owner,
                        'note' => $note,
                        'status' => $status,
                    ]
                );

                audit($db, 'address.update', 'address', $id, "subnet_id=$subnetId");
                $msg = 'Address updated.';
            }
        }
    }

    if ($action === 'delete') {
        require_write_access();

        $id = (int)($_POST['id'] ?? 0);
        $subnetId = (int)($_POST['subnet_id'] ?? 0);

        $sel = $db->prepare("SELECT id, ip, hostname, owner, note, status FROM addresses WHERE id=:id AND subnet_id=:sid");
        $sel->execute([':id' => $id, ':sid' => $subnetId]);
        $before = $sel->fetch();

        $del = $db->prepare("DELETE FROM addresses WHERE id = :id AND subnet_id = :sid");
        $del->execute([':id' => $id, ':sid' => $subnetId]);

        if ($before) {
            history_log_address($db, 'delete', $subnetId, (string)$before['ip'], $id,
                [
                    'hostname' => (string)$before['hostname'],
                    'owner' => (string)$before['owner'],
                    'note' => (string)$before['note'],
                    'status' => (string)$before['status'],
                ],
                null
            );
        }

        audit($db, 'address.delete', 'address', $id, "subnet_id=$subnetId");
        header('Location: addresses.php?subnet_id=' . $subnetId);
        exit;
    }
}

$addresses = [];
$total = 0;
$p = null;

if ($selectedSubnetId > 0) {
    $st = $db->prepare("SELECT COUNT(*) AS c FROM addresses WHERE subnet_id = :sid");
    $st->execute([':sid' => $selectedSubnetId]);
    $total = (int)$st->fetch()['c'];

    $p = paginate($total, $page, $pageSize);

    $st = $db->prepare("SELECT id, ip, hostname, owner, note, status, updated_at
                        FROM addresses
                        WHERE subnet_id = :sid
                        ORDER BY ip_bin ASC
                        LIMIT :lim OFFSET :off");
    $st->bindValue(':sid', $selectedSubnetId, PDO::PARAM_INT);
    $st->bindValue(':lim', $p['limit'], PDO::PARAM_INT);
    $st->bindValue(':off', $p['offset'], PDO::PARAM_INT);
    $st->execute();
    $addresses = $st->fetchAll();
}

page_header('Addresses');
?>

<div class="breadcrumbs">
  <a href="dashboard.php">🏠 Dashboard</a>
  <span class="sep">›</span>
  <?php if ($selectedSubnet): ?>
    <a href="subnets.php">🌐 Subnets</a>
    <span class="sep">›</span>
    <span><?= e($selectedSubnet['cidr']) ?></span>
    <span class="sep">›</span>
  <?php endif; ?>
  <span>🧾 Addresses</span>
</div>

<div class="toolbar">
  <div>
    <h1>Addresses</h1>
    <div class="muted">Manage address records within a subnet.</div>
  </div>
</div>

<div class="page-actions">
  <?php if ($selectedSubnetId > 0): ?>
    <?php if (current_user()['role'] !== 'readonly'): ?>
      <a class="action-pill" href="bulk_update.php?subnet_id=<?= (int)$selectedSubnetId ?>">✏ Bulk Update</a>
    <?php endif; ?>
    <?php if ($selectedSubnet && (int)$selectedSubnet['ip_version'] === 4): ?>
      <a class="action-pill" href="unassigned.php?subnet_id=<?= (int)$selectedSubnetId ?>">✨ Unassigned</a>
    <?php endif; ?>
    <a class="action-pill" href="search.php?subnet_id=<?= (int)$selectedSubnetId ?>">🔎 Search in Subnet</a>
    <a class="action-pill" href="export_addresses.php?subnet_id=<?= (int)$selectedSubnetId ?>">⬇ Export CSV</a>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:16px">
  <form method="get" action="addresses.php" class="row">
    <label>Subnet<br>
      <select name="subnet_id">
        <option value="0">-- Select --</option>
        <?php foreach ($subnetList as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === $selectedSubnetId) ? 'selected' : '' ?>>
            <?= e($s['cidr']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Page size<br>
      <select name="page_size">
        <?php foreach ([50,100,254,500] as $sz): ?>
          <option value="<?= $sz ?>" <?= $pageSize===$sz?'selected':'' ?>><?= $sz ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <button type="submit">Load</button>
  </form>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>

<?php if ($selectedSubnetId > 0): ?>
  <div class="card" style="margin-top:16px">
    <div class="toolbar">
      <div>
        <h2>Subnet: <?= e((string)($selectedSubnet['cidr'] ?? '')) ?></h2>
        <div class="muted">Rows: <b><?= e((string)$total) ?></b><?php if ($p): ?> | Page <b><?= e((string)$p['page']) ?></b> of <b><?= e((string)$p['pages']) ?></b><?php endif; ?></div>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="card" style="margin-top:16px">
  <h2>Add address</h2>
  <form method="post" action="addresses.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="subnet_id" value="<?= (int)$selectedSubnetId ?>">

    <div class="row">
      <label>IP<br><input name="ip" placeholder="<?= ($selectedSubnet && (int)$selectedSubnet['ip_version']===6) ? '2001:db8::10' : '10.0.0.10' ?>" required></label>
      <label>Hostname<br><input name="hostname" maxlength="253"></label>
      <label>Owner<br><input name="owner" maxlength="255"></label>
      <label>Status<br>
        <select name="status">
          <option value="used">used</option>
          <option value="reserved">reserved</option>
          <option value="free">free</option>
        </select>
      </label>
    </div>
    <div class="row">
      <label style="flex:1">Note<br><input name="note" maxlength="1000" style="width:100%"></label>
    </div>

    <p>
      <button type="submit"
        <?= ($selectedSubnetId>0 && current_user()['role']!=='readonly') ? '' : 'disabled' ?>>
        Add
      </button>
    </p>
    <?php if ($selectedSubnetId <= 0): ?><p class="muted">Select a subnet first.</p><?php endif; ?>
    <?php if (current_user()['role']==='readonly'): ?><p class="muted">Read-only account.</p><?php endif; ?>
  </form>
</div>

<div class="card" style="margin-top:16px">
  <h2>List</h2>
  <?php if ($selectedSubnetId <= 0): ?>
    <div class="empty-state">No subnet selected.</div>
  <?php elseif (!$addresses): ?>
    <div class="empty-state">No addresses in this subnet yet.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>IP</th>
          <th>Hostname</th>
          <th>Owner</th>
          <th>Status</th>
          <th>Note</th>
          <th>Updated</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($addresses as $a): ?>
        <tr>
          <td><?= e($a['ip']) ?></td>
          <td><?= e($a['hostname']) ?></td>
          <td><?= e($a['owner']) ?></td>
          <td><span class="status-<?= e($a['status']) ?>"><?= e($a['status']) ?></span></td>
          <td><?= e($a['note']) ?></td>
          <td class="muted"><?= e($a['updated_at']) ?></td>
          <td>
            <div class="actions-inline">
              <a href="address_history.php?address_id=<?= (int)$a['id'] ?>">History</a>
            </div>

            <details style="margin-top:6px">
              <summary>Edit/Delete</summary>

              <form method="post" action="addresses.php" style="margin-top:8px">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="subnet_id" value="<?= (int)$selectedSubnetId ?>">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">

                <div class="row">
                  <label>Hostname<br><input name="hostname" maxlength="253" value="<?= e($a['hostname']) ?>"></label>
                  <label>Owner<br><input name="owner" maxlength="255" value="<?= e($a['owner']) ?>"></label>
                  <label>Status<br>
                    <select name="status">
                      <option value="used" <?= ($a['status']==='used')?'selected':'' ?>>used</option>
                      <option value="reserved" <?= ($a['status']==='reserved')?'selected':'' ?>>reserved</option>
                      <option value="free" <?= ($a['status']==='free')?'selected':'' ?>>free</option>
                    </select>
                  </label>
                </div>

                <div class="row">
                  <label style="flex:1">Note<br><input name="note" maxlength="1000" style="width:100%" value="<?= e($a['note']) ?>"></label>
                </div>

                <button type="submit" <?= (current_user()['role']==='readonly')?'disabled':'' ?>>Save</button>
              </form>

              <form method="post" action="addresses.php" onsubmit="return confirm('Delete this address?');" style="margin-top:8px">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="subnet_id" value="<?= (int)$selectedSubnetId ?>">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="button-danger" <?= (current_user()['role']==='readonly')?'disabled':'' ?>>Delete</button>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php
      $qsBase = ['subnet_id' => $selectedSubnetId, 'page_size' => $pageSize];
      $base = 'addresses.php?' . http_build_query($qsBase);
    ?>
    <p style="margin-top:12px">
      <?php if ($p && $p['page'] > 1): ?>
        <a href="<?= e($base . '&page=' . ($p['page']-1)) ?>">&laquo; Prev</a>
      <?php endif; ?>
      <?php if ($p && $p['page'] < $p['pages']): ?>
        <a style="margin-left:12px" href="<?= e($base . '&page=' . ($p['page']+1)) ?>">Next &raquo;</a>
      <?php endif; ?>
    </p>
  <?php endif; ?>
</div>

<?php page_footer();
