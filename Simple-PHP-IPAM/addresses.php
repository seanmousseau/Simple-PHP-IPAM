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

$st = $db->prepare("SELECT id, cidr, network, prefix, ip_version FROM subnets ORDER BY ip_version ASC, cidr ASC");
$st->execute();
/** @var list<array<string, mixed>> $subnetList */
$subnetList = $st->fetchAll();

$selectedSubnetId = to_int($_GET['subnet_id'] ?? ($_POST['subnet_id'] ?? 0));
$highlightId = to_int($_GET['highlight'] ?? 0);
$page = q_int('page', 1, 1, 1000000);
$pageSize = q_int('page_size', 254, 1, 500);

$addrSortCols = ['ip' => 'ip_bin', 'hostname' => 'hostname', 'owner' => 'owner',
                 'status' => 'status', 'updated' => 'updated_at'];
$addrSort = parse_sort($addrSortCols, 'ip');

$selectedSubnet = null;
if ($selectedSubnetId > 0) {
    $st = $db->prepare("SELECT id, cidr, network, prefix, ip_version, description, notes FROM subnets WHERE id = :id");
    $st->execute([':id' => $selectedSubnetId]);
    /** @var array<string, mixed>|false $selRow */
    $selRow = $st->fetch();
    $selectedSubnet = $selRow ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = to_str($_POST['action'] ?? '');

    if ($action === 'create') {
        require_write_access();

        $subnetId = to_int($_POST['subnet_id'] ?? 0);
        $ipInput = trim(to_str($_POST['ip'] ?? ''));

        $hostname        = substr(trim(to_str($_POST['hostname']        ?? '')), 0, 253);
        $owner           = substr(trim(to_str($_POST['owner']          ?? '')), 0, 255);
        $note            = substr(trim(to_str($_POST['note']           ?? '')), 0, 1000);
        $grp             = substr(trim(to_str($_POST['grp']            ?? '')), 0, 100);
        $mac             = substr(trim(to_str($_POST['mac']            ?? '')), 0, 64);
        $ownerContactId  = to_int($_POST['owner_contact_id'] ?? 0) ?: null;
        if ($ownerContactId !== null) {
            $cck = $db->prepare("SELECT id FROM contacts WHERE id = :id");
            $cck->execute([':id' => $ownerContactId]);
            if (!$cck->fetch()) $ownerContactId = null;
        }
        $expiresAt = trim(to_str($_POST['expires_at'] ?? ''));
        if ($expiresAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
            $expiresAt = '';
        }
        $status = to_str($_POST['status'] ?? 'used');

        $st = $db->prepare("SELECT id, network, prefix, ip_version FROM subnets WHERE id = :id");
        $st->execute([':id' => $subnetId]);
        /** @var array<string, mixed>|false $sub */
        $sub = $st->fetch();

        if (!$sub) {
            $err = 'Invalid subnet.';
        } else {
            $norm = normalize_ip($ipInput);
            if (!$norm) {
                $err = 'Invalid IP (IPv4/IPv6).';
            } elseif (to_int($sub['ip_version']) !== to_int($norm['version'])) {
                $err = 'IP version does not match subnet.';
            } elseif (!ip_in_cidr($norm['ip'], to_str($sub['network']), to_int($sub['prefix']))) {
                $err = 'IP is not within selected subnet.';
            } elseif (!in_array($status, ['used','reserved','free'], true)) {
                $err = 'Invalid status.';
            } else {
                try {
                    // #410/#388: bind ip_bin via ipam_bind_binary() (PARAM_LOB)
                    // so the stored value has BLOB affinity on SQLite,
                    // round-trips high bytes correctly through MySQL
                    // VARBINARY, and does not UTF-8-validate on Postgres BYTEA.
                    $ins = $db->prepare("INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, grp, mac, expires_at, status, owner_contact_id)
                                         VALUES (:sid,:ip,:bin,:hn,:ow,:nt,:grp,:mac,:exp,:st,:cid)");
                    $ins->bindValue(':sid', $subnetId, PDO::PARAM_INT);
                    $ins->bindValue(':ip',  $norm['ip']);
                    ipam_bind_binary($ins, ':bin', to_str($norm['bin']));
                    $ins->bindValue(':hn',  $hostname);
                    $ins->bindValue(':ow',  $owner);
                    $ins->bindValue(':nt',  $note);
                    $ins->bindValue(':grp', $grp);
                    $ins->bindValue(':mac', $mac);
                    $ins->bindValue(':exp', $expiresAt !== '' ? $expiresAt : null,
                        $expiresAt !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                    $ins->bindValue(':st',  $status);
                    $ins->bindValue(':cid', $ownerContactId,
                        $ownerContactId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $ins->execute();
                    $aid = ipam_last_insert_id($db, 'addresses');

                    history_log_address($db, 'create', $subnetId, $norm['ip'], $aid, null, [
                        'hostname'        => $hostname,
                        'owner'           => $owner,
                        'note'            => $note,
                        'grp'             => $grp,
                        'mac'             => $mac,
                        'expires_at'      => $expiresAt !== '' ? $expiresAt : null,
                        'status'          => $status,
                        'owner_contact_id' => $ownerContactId,
                    ]);
                    audit($db, 'address.create', 'address', $aid, "ip={$norm['ip']} subnet_id=$subnetId");

                    flash_set('Address created.');
                    header('Location: addresses.php?subnet_id=' . $subnetId);
                    exit;
                } catch (PDOException $e) {
                    $err = str_contains($e->getMessage(), 'UNIQUE')
                        ? 'An address record for this IP already exists in the subnet.'
                        : 'Could not add address. Please try again.';
                }
            }
        }
    } elseif ($action === 'update') {
        require_write_access();

        $id = to_int($_POST['id'] ?? 0);
        $subnetId = to_int($_POST['subnet_id'] ?? 0);
        $hostname       = substr(trim(to_str($_POST['hostname']       ?? '')), 0, 253);
        $owner          = substr(trim(to_str($_POST['owner']         ?? '')), 0, 255);
        $note           = substr(trim(to_str($_POST['note']          ?? '')), 0, 1000);
        $grp            = substr(trim(to_str($_POST['grp']           ?? '')), 0, 100);
        $mac            = substr(trim(to_str($_POST['mac']           ?? '')), 0, 64);
        $ownerContactId = to_int($_POST['owner_contact_id'] ?? 0) ?: null;
        if ($ownerContactId !== null) {
            $cck = $db->prepare("SELECT id FROM contacts WHERE id = :id");
            $cck->execute([':id' => $ownerContactId]);
            if (!$cck->fetch()) $ownerContactId = null;
        }
        $expiresAt = trim(to_str($_POST['expires_at'] ?? ''));
        if ($expiresAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
            $expiresAt = '';
        }
        $status = to_str($_POST['status'] ?? 'used');

        if (!in_array($status, ['used','reserved','free'], true)) {
            $err = 'Invalid status.';
        } else {
            $sel = $db->prepare("SELECT id, ip, hostname, owner, note, grp, mac, expires_at, status, owner_contact_id FROM addresses WHERE id=:id AND subnet_id=:sid");
            $sel->execute([':id' => $id, ':sid' => $subnetId]);
            /** @var array<string, mixed>|false $before */
            $before = $sel->fetch();

            if (!$before) {
                $err = 'Address not found.';
            } else {
                $up = $db->prepare("UPDATE addresses
                                    SET hostname=:hn, owner=:ow, note=:nt, grp=:grp, mac=:mac, expires_at=:exp, status=:st, owner_contact_id=:cid
                                    WHERE id=:id AND subnet_id=:sid");
                $up->execute([
                    ':hn'  => $hostname,
                    ':ow'  => $owner,
                    ':nt'  => $note,
                    ':grp' => $grp,
                    ':mac' => $mac,
                    ':exp' => $expiresAt !== '' ? $expiresAt : null,
                    ':st'  => $status,
                    ':cid' => $ownerContactId,
                    ':id'  => $id,
                    ':sid' => $subnetId,
                ]);

                history_log_address($db, 'update', $subnetId, to_str($before['ip']), $id,
                    [
                        'hostname'        => to_str($before['hostname']),
                        'owner'           => to_str($before['owner']),
                        'note'            => to_str($before['note']),
                        'grp'             => to_str($before['grp']),
                        'mac'             => to_str($before['mac']),
                        'expires_at'      => isset($before['expires_at']) ? to_str($before['expires_at']) : null,
                        'status'          => to_str($before['status']),
                        'owner_contact_id' => $before['owner_contact_id'] !== null ? to_int($before['owner_contact_id']) : null,
                    ],
                    [
                        'hostname'        => $hostname,
                        'owner'           => $owner,
                        'note'            => $note,
                        'grp'             => $grp,
                        'mac'             => $mac,
                        'expires_at'      => $expiresAt !== '' ? $expiresAt : null,
                        'status'          => $status,
                        'owner_contact_id' => $ownerContactId,
                    ]
                );

                audit($db, 'address.update', 'address', $id, "subnet_id=$subnetId");
                $msg = 'Address updated.';
            }
        }
    } elseif ($action === 'update_status') {
        // Inline status toggle — JSON response for JS fetch; graceful-degrades on non-JS
        require_write_access();
        $id       = to_int($_POST['id'] ?? 0);
        $newStatus = to_str($_POST['status'] ?? '');
        if (!in_array($newStatus, ['used', 'reserved', 'free'], true) || $id <= 0) {
            header('Content-Type: application/json');
            echo '{"ok":false,"error":"Invalid request"}';
            exit;
        }
        $st = $db->prepare("UPDATE addresses SET status=:s, updated_at=" . ipam_dialect()->now() . " WHERE id=:id");
        $st->execute([':s' => $newStatus, ':id' => $id]);
        if ($st->rowCount()) {
            audit($db, 'address.update', 'address', $id, "status=$newStatus via inline toggle");
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'status' => $newStatus]); // nosemgrep: php.lang.security.xss
        exit;
    } elseif ($action === 'update_cell') {
        // Inline cell edit — JSON response; CSRF already verified above
        require_write_access();
        header('Content-Type: application/json');
        $id     = to_int($_POST['id']    ?? 0);
        $field  = to_str($_POST['field'] ?? '');
        $value  = to_str($_POST['value'] ?? '');
        $allowed = ['hostname', 'owner', 'note', 'grp'];
        if ($id <= 0 || !in_array($field, $allowed, true)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
            exit;
        }
        $maxLen = ['hostname' => 253, 'owner' => 255, 'note' => 1000, 'grp' => 100];
        $value = substr(trim($value), 0, $maxLen[$field]);
        $sel = $db->prepare("SELECT id, ip, hostname, owner, note, grp FROM addresses WHERE id=:id");
        $sel->execute([':id' => $id]);
        /** @var array<string, mixed>|false $before */
        $before = $sel->fetch();
        if (!$before) {
            echo json_encode(['ok' => false, 'error' => 'Address not found.']);
            exit;
        }
        // Static SQL per field — no interpolation of user-controlled data
        // Editing 'owner' free-text clears the structured contact link to keep them in sync.
        $updateSql = match ($field) {
            'hostname' => "UPDATE addresses SET hostname=:v, updated_at=" . ipam_dialect()->now() . " WHERE id=:id",
            'owner'    => "UPDATE addresses SET owner=:v, owner_contact_id=NULL, updated_at=" . ipam_dialect()->now() . " WHERE id=:id",
            'note'     => "UPDATE addresses SET note=:v, updated_at=" . ipam_dialect()->now() . " WHERE id=:id",
            'grp'      => "UPDATE addresses SET grp=:v, updated_at=" . ipam_dialect()->now() . " WHERE id=:id",
        };
        $db->prepare($updateSql)->execute([':v' => $value, ':id' => $id]);
        $after = array_merge(
            ['hostname' => to_str($before['hostname']), 'owner' => to_str($before['owner']),
             'note'     => to_str($before['note']),     'grp'   => to_str($before['grp'])],
            [$field => $value]
        );
        $subnetIdForHistory = to_int((function() use ($db, $id) {
            $r = $db->prepare("SELECT subnet_id FROM addresses WHERE id=:id");
            $r->execute([':id' => $id]);
            /** @var array<string, mixed>|false $row */
            $row = $r->fetch();
            return $row ? to_int($row['subnet_id']) : 0;
        })());
        history_log_address($db, 'update', $subnetIdForHistory, to_str($before['ip']), $id,
            ['hostname' => to_str($before['hostname']), 'owner' => to_str($before['owner']),
             'note'     => to_str($before['note']),     'grp'   => to_str($before['grp'])],
            $after
        );
        audit($db, 'address.update', 'address', $id, "inline_cell=$field");
        echo json_encode(['ok' => true, 'value' => $value]);
        exit;
    } elseif ($action === 'delete') {
        require_write_access();

        $id = to_int($_POST['id'] ?? 0);
        $subnetId = to_int($_POST['subnet_id'] ?? 0);

        $sel = $db->prepare("SELECT id, ip, hostname, owner, note, grp, mac, expires_at, status FROM addresses WHERE id=:id AND subnet_id=:sid");
        $sel->execute([':id' => $id, ':sid' => $subnetId]);
        /** @var array<string, mixed>|false $before */
        $before = $sel->fetch();

        $del = $db->prepare("DELETE FROM addresses WHERE id = :id AND subnet_id = :sid");
        $del->execute([':id' => $id, ':sid' => $subnetId]);

        if ($before) {
            history_log_address($db, 'delete', $subnetId, to_str($before['ip']), $id,
                [
                    'hostname'   => to_str($before['hostname']),
                    'owner'      => to_str($before['owner']),
                    'note'       => to_str($before['note']),
                    'grp'        => to_str($before['grp']),
                    'mac'        => to_str($before['mac']),
                    'expires_at' => isset($before['expires_at']) ? to_str($before['expires_at']) : null,
                    'status'     => to_str($before['status']),
                ],
                null
            );
        }

        audit($db, 'address.delete', 'address', $id, "subnet_id=$subnetId");
        flash_set('Address deleted.');
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
    /** @var array<string, mixed>|false $cntRow */

    $cntRow = $st->fetch();

    $total = is_array($cntRow) ? to_int($cntRow['c']) : 0;

    $p = paginate($total, $page, $pageSize);

    $st = $db->prepare("SELECT a.id, a.ip, a.ip_bin, a.hostname, a.owner, a.note, a.grp, a.mac, a.expires_at, a.status, a.updated_at,
                               a.owner_contact_id, c.name AS owner_contact_name, c.email AS owner_contact_email,
                               a.last_seen_at, a.is_stale
                        FROM addresses a
                        LEFT JOIN contacts c ON c.id = a.owner_contact_id
                        WHERE a.subnet_id = :sid
                        ORDER BY {$addrSort['sql']}
                        LIMIT :lim OFFSET :off");
    $st->bindValue(':sid', $selectedSubnetId, PDO::PARAM_INT);
    $st->bindValue(':lim', $p['limit'], PDO::PARAM_INT);
    $st->bindValue(':off', $p['offset'], PDO::PARAM_INT);
    $st->execute();
    /** @var list<array<string, mixed>> $addresses */
    $addresses = $st->fetchAll();
}

// Compute network/broadcast/gateway bins for badge rendering
$networkBin = null;
$broadcastBin = null;
$gatewayBin = null;
if ($selectedSubnet) {
    $parsed = parse_cidr(to_str($selectedSubnet['cidr']));
    if ($parsed !== null) {
        $networkBin = $parsed['net_bin'];
        $broadcastBin = ipam_compute_broadcast_bin($parsed['net_bin'], $parsed['prefix']);
        $gatewayBin = ipam_compute_gateway_bin($parsed['net_bin'], $parsed['prefix']);
    }
}

// Next available IP (IPv4 only, for subnets with room)
$nextAvailableIp = null;
if ($selectedSubnet && to_int($selectedSubnet['ip_version']) === 4) {
    $nextAvailableIp = find_next_available_ipv4($db, $selectedSubnetId,
        to_str($selectedSubnet['network']), to_int($selectedSubnet['prefix']));
}

page_header('Addresses', ['page' => 'addresses']);
ipam_skeleton_flush();
?>

<div class="breadcrumbs">
  <a href="dashboard.php">🏠 Dashboard</a>
  <span class="sep">›</span>
  <?php if ($selectedSubnet): ?>
    <a href="subnets.php">🌐 Subnets</a>
    <span class="sep">›</span>
    <span><?= e(to_str($selectedSubnet['cidr'])) ?></span>
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
      <a class="action-pill" href="#add-address" data-open-drawer="add-address" data-drawer-title="Add Address">➕ Add Address <kbd class="kbd-hint">⌘N</kbd></a>
      <a class="action-pill" href="bulk_update.php?subnet_id=<?= (int)$selectedSubnetId ?>">✏ Bulk Update</a>
    <?php endif; ?>
    <?php if ($selectedSubnet && to_int($selectedSubnet['ip_version']) === 4): ?>
      <a class="action-pill" href="unassigned.php?subnet_id=<?= (int)$selectedSubnetId ?>">✨ Unassigned</a>
    <?php endif; ?>
    <a class="action-pill" href="search.php?subnet_id=<?= (int)$selectedSubnetId ?>">🔎 Search in Subnet</a>
    <a class="action-pill" href="export_addresses.php?subnet_id=<?= (int)$selectedSubnetId ?>">⬇ Export CSV</a>
    <a class="action-pill" href="export_dns.php?subnet_id=<?= (int)$selectedSubnetId ?>">🌐 DNS Export</a>
  <?php endif; ?>
</div>

<div class="card mt-16">
  <form method="get" action="addresses.php" class="row">
    <label>Subnet<br>
      <select name="subnet_id">
        <option value="0">-- Select --</option>
        <?php foreach ($subnetList as $s): ?>
          <option value="<?= to_int($s['id']) ?>" <?= (to_int($s['id']) === $selectedSubnetId) ? 'selected' : '' ?>>
            <?= e(to_str($s['cidr'])) ?>
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
  <div class="card mt-16">
    <div class="toolbar">
      <div>
        <h2>Subnet: <?= e(to_str($selectedSubnet['cidr'] ?? '')) ?></h2>
        <div class="muted">Rows: <b><?= e((string)$total) ?></b><?php if ($p): ?> | Page <b><?= e(to_str($p['page'])) ?></b> of <b><?= e(to_str($p['pages'])) ?></b><?php endif; ?></div>
      </div>
    </div>
  </div>
  <?php
    // #316: render long-form subnet notes (if any) above the address table.
    // <details> keeps it collapsible so a long runbook doesn't dominate the
    // page; default-open if non-empty so the operator notices it.
    $subnetNotes = to_str($selectedSubnet['notes'] ?? '');
    if ($subnetNotes !== '') {
        echo '<details class="card mt-16 subnet-notes" open>'
           . '<summary><b>📝 Subnet notes</b></summary>'
           . '<div class="subnet-notes-body">' . nl2br(e($subnetNotes)) . '</div>'
           . '</details>';
    }
  ?>
<?php endif; ?>

<div class="card mt-16 drawer-form-card" id="add-address">
  <h2>Add address</h2>
  <?php if ($nextAvailableIp): ?>
    <p class="muted">Next available: <b><?= e($nextAvailableIp) ?></b>
      <a class="action-pill" href="addresses.php?subnet_id=<?= (int)$selectedSubnetId ?>&next_ip=<?= urlencode($nextAvailableIp) ?>#add-address">Use</a>
    </p>
  <?php endif; ?>
  <form method="post" action="addresses.php" id="add-address">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="subnet_id" value="<?= (int)$selectedSubnetId ?>">
    <?php $prefillIp = trim(to_str($_GET['next_ip'] ?? '')); ?>
    <div class="row">
      <label>IP<br><input name="ip" value="<?= e($prefillIp) ?>" placeholder="<?= ($selectedSubnet && to_int($selectedSubnet['ip_version'])===6) ? '2001:db8::10' : '10.0.0.10' ?>" required data-validate="ip"></label>
      <label>Hostname<br><input name="hostname" maxlength="253"></label>
      <label>Owner<br>
        <input name="owner" maxlength="255" autocomplete="off" data-contact-typeahead>
        <input type="hidden" name="owner_contact_id" value="0">
      </label>
      <label>Group<br><input name="grp" maxlength="100" placeholder="e.g. web-tier" class="mw-160"></label>
      <label>MAC<br><input name="mac" maxlength="64" placeholder="e.g. aa:bb:cc:dd:ee:ff" class="mw-160"></label>
      <label>Expires<br><input name="expires_at" type="date" class="mw-160"></label>
      <label>Status<br>
        <select name="status">
          <option value="used">used</option>
          <option value="reserved">reserved</option>
          <option value="free">free</option>
        </select>
      </label>
    </div>
    <div class="row">
      <label class="flex-1">Note<br><input name="note" maxlength="1000" class="w-full"></label>
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

<div class="card mt-16">
  <h2>List</h2>
  <?php if ($selectedSubnetId <= 0): ?>
    <div class="empty-state">No subnet selected. <a href="subnets.php">Go to Subnets</a> to create or select one.</div>
  <?php elseif (!$addresses): ?>
    <div class="empty-state">No addresses in this subnet yet. <a class="action-pill" href="#add-address">+ Add Address</a></div>
  <?php else: ?>
    <div class="table-wrap">
    <table data-col-table="addresses">
      <thead>
        <tr>
          <?php $addrQs = '?subnet_id=' . $selectedSubnetId . '&page_size=' . $pageSize;
                echo sort_th('ip',       'IP',       $addrSort['col'], $addrSort['dir'], $addrQs, 'ip');
                echo sort_th('hostname', 'Hostname', $addrSort['col'], $addrSort['dir'], $addrQs, 'hostname');
                echo sort_th('owner',    'Owner',    $addrSort['col'], $addrSort['dir'], $addrQs, 'owner');
                echo sort_th('status',   'Status',   $addrSort['col'], $addrSort['dir'], $addrQs, 'status');
          ?>
          <th data-col="group">Group</th>
          <th data-col="mac">MAC</th>
          <th data-col="expires">Expires</th>
          <th data-col="note">Note</th>
          <?php echo sort_th('updated', 'Updated', $addrSort['col'], $addrSort['dir'], $addrQs, 'updated'); ?>
          <th data-col="last-seen" data-col-default-hidden="1">Last Seen</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php
        $isWrite = (current_user()['role'] !== 'readonly');
        foreach ($addresses as $a):
          $isHighlighted = $highlightId > 0 && to_int($a['id']) === $highlightId;
          $isExpired = isset($a['expires_at']) && to_str($a['expires_at']) < date('Y-m-d');
          $aid = to_int($a['id']);
          $rowClasses = array_filter([$isHighlighted ? 'highlight-row' : '', $isExpired ? 'expired-row' : '']);
      ?>
        <tr id="addr-<?= $aid ?>"<?= $rowClasses ? ' class="' . e(implode(' ', $rowClasses)) . '"' : '' ?>>
          <td class="ip-cell"><?= e(to_str($a['ip'])) ?><?php
            $ipBin = is_string($a['ip_bin'] ?? null) ? $a['ip_bin'] : '';
            if ($ipBin !== '') {
                if ($networkBin !== null && hash_equals($networkBin, $ipBin)) echo ' <span class="badge badge-network" title="Network address">Net</span>';
                if ($broadcastBin !== null && hash_equals($broadcastBin, $ipBin)) echo ' <span class="badge badge-broadcast" title="Broadcast address">Bcast</span>';
                if ($gatewayBin !== null && hash_equals($gatewayBin, $ipBin)) echo ' <span class="badge badge-gateway" title="Gateway address">GW</span>';
            }
            if (!empty($a['is_stale'])): ?> <span class="badge" style="background:var(--danger);color:#fff;font-size:.7rem" title="Host missed recent scans">Stale</span><?php endif ?>
          </td>
          <td<?= $isWrite ? ' data-editable="hostname" data-addr-id="' . $aid . '"' : '' ?>><?= e(to_str($a['hostname'])) ?></td>
          <td<?= $isWrite ? ' data-editable="owner" data-addr-id="' . $aid . '"' : '' ?>><?php
            $ownContactId    = to_int($a['owner_contact_id'] ?? 0);
            $ownContactName  = to_str($a['owner_contact_name'] ?? '');
            $ownContactEmail = to_str($a['owner_contact_email'] ?? '');
            if ($ownContactId > 0 && $ownContactName !== '') {
                echo '<a href="contacts.php">' . e($ownContactName) . '</a>';
                if ($ownContactEmail !== '') echo ' <a href="mailto:' . e($ownContactEmail) . '" class="muted" title="' . e($ownContactEmail) . '">✉</a>';
            } else {
                echo e(to_str($a['owner']));
            }
          ?></td>
          <td><?php
            $addrStatus = e(to_str($a['status']));
            $canToggle  = $isWrite ? ' data-addr-id="' . $aid . '"' : '';
            echo "<span class='status-badge status-{$addrStatus}'{$canToggle} title='Click to cycle status'>{$addrStatus}</span>";
          ?></td>
          <td<?= $isWrite ? ' data-editable="grp" data-addr-id="' . $aid . '"' : '' ?>><?php if ($a['grp'] !== ''): ?><span class="badge"><?= e(to_str($a['grp'])) ?></span><?php endif; ?></td>
          <td class="muted ip-cell"><?= e(to_str($a['mac'])) ?></td>
          <td class="muted"><?= e(to_str($a['expires_at'] ?? '')) ?></td>
          <td<?= $isWrite ? ' data-editable="note" data-addr-id="' . $aid . '"' : '' ?>><?= e(to_str($a['note'])) ?></td>
          <td class="muted"><?= e(display_datetime(to_str($a['updated_at']))) ?></td>
          <td data-col="last-seen" class="muted"><?= isset($a['last_seen_at']) && to_str($a['last_seen_at']) !== '' ? e(display_datetime(to_str($a['last_seen_at']))) : '—' ?></td>
          <td>
            <div class="actions-inline">
              <a href="address_history.php?address_id=<?= to_int($a['id']) ?>">History</a>
              <button type="button" class="ping-btn" data-address-id="<?= to_int($a['id']) ?>"
                      data-csrf="<?= e(csrf_token()) ?>" style="font-size:.8rem;padding:2px 8px">Ping</button>
              <span class="ping-result-<?= to_int($a['id']) ?> muted" style="font-size:.8rem"></span>
            </div>

            <details class="mt-6"<?= $isHighlighted ? ' open' : '' ?>>
              <summary>Edit/Delete</summary>

              <form method="post" action="addresses.php" class="mt-8">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="subnet_id" value="<?= (int)$selectedSubnetId ?>">
                <input type="hidden" name="id" value="<?= to_int($a['id']) ?>">

                <div class="row">
                  <label>Hostname<br><input name="hostname" maxlength="253" value="<?= e(to_str($a['hostname'])) ?>"></label>
                  <label>Owner<br>
                    <input name="owner" maxlength="255" value="<?= e(to_str($a['owner'])) ?>" autocomplete="off" data-contact-typeahead>
                    <input type="hidden" name="owner_contact_id" value="<?= to_int($a['owner_contact_id'] ?? 0) ?>">
                  </label>
                  <label>Group<br><input name="grp" value="<?= e(to_str($a['grp'] ?? '')) ?>" maxlength="100" placeholder="e.g. web-tier" class="mw-160"></label>
                  <label>MAC<br><input name="mac" maxlength="64" value="<?= e(to_str($a['mac'])) ?>" placeholder="e.g. aa:bb:cc:dd:ee:ff" class="mw-160"></label>
                  <label>Expires<br><input name="expires_at" type="date" value="<?= e(to_str($a['expires_at'] ?? '')) ?>" class="mw-160"></label>
                  <label>Status<br>
                    <select name="status">
                      <option value="used" <?= ($a['status']==='used')?'selected':'' ?>>used</option>
                      <option value="reserved" <?= ($a['status']==='reserved')?'selected':'' ?>>reserved</option>
                      <option value="free" <?= ($a['status']==='free')?'selected':'' ?>>free</option>
                    </select>
                  </label>
                </div>

                <div class="row">
                  <label class="flex-1">Note<br><input name="note" maxlength="1000" class="w-full" value="<?= e(to_str($a['note'])) ?>"></label>
                </div>

                <button type="submit" <?= (current_user()['role']==='readonly')?'disabled':'' ?>>Save</button>
              </form>

              <form method="post" action="addresses.php" data-confirm="Delete this address?" class="mt-8">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="subnet_id" value="<?= (int)$selectedSubnetId ?>">
                <input type="hidden" name="id" value="<?= to_int($a['id']) ?>">
                <button type="submit" class="button-danger" <?= (current_user()['role']==='readonly')?'disabled':'' ?>>Delete</button>
              </form>
            </details>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php
      $qsBase = ['subnet_id' => $selectedSubnetId, 'page_size' => $pageSize,
                 'sort' => $addrSort['col'], 'dir' => $addrSort['dir']];
      $base = 'addresses.php?' . http_build_query($qsBase);
    ?>
    <p class="mt-12">
      <?php if ($p && $p['page'] > 1): ?>
        <a href="<?= e($base . '&page=' . ($p['page']-1)) ?>">&laquo; Prev</a>
      <?php endif; ?>
      <?php if ($p && $p['page'] < $p['pages']): ?>
        <a class="ml-12" href="<?= e($base . '&page=' . ($p['page']+1)) ?>">Next &raquo;</a>
      <?php endif; ?>
    </p>
  <?php endif; ?>
</div>

<?php ipam_skeleton_remove(); page_footer();
