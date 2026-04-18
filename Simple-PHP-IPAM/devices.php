<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';

/** @var list<string> $validTypes */
$validTypes = ['router', 'switch', 'server', 'vm', 'firewall', 'other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = to_str($_POST['action'] ?? '');

    if (demo_mode_enabled() && in_array($action, ['create', 'update', 'delete', 'create_interface', 'delete_interface'], true)) {
        $err = 'This action is disabled in demo mode.';
        $action = '';
    }

    // ── Device CRUD ───────────────────────────────────────────────────────────

    if ($action === 'create') {
        $name   = trim(to_str($_POST['name']   ?? ''));
        $type   = to_str($_POST['type']   ?? 'other');
        $siteId = to_int($_POST['site_id'] ?? 0) ?: null;
        $vendor = trim(to_str($_POST['vendor'] ?? ''));
        $model  = trim(to_str($_POST['model']  ?? ''));
        $serial = trim(to_str($_POST['serial'] ?? ''));
        $note   = trim(to_str($_POST['note']   ?? ''));

        if (!in_array($type, $validTypes, true)) $type = 'other';

        if ($name === '') {
            $err = 'Device name is required.';
        } else {
            if ($siteId !== null) {
                $sc = $db->prepare("SELECT id FROM sites WHERE id=:id");
                $sc->execute([':id' => $siteId]);
                if (!$sc->fetch()) $err = 'Selected site does not exist.';
            }
            if ($err === '') {
                try {
                    $st = $db->prepare("INSERT INTO devices (name, type, site_id, vendor, model, serial, note) VALUES (:n,:t,:sid,:v,:m,:sr,:nt)");
                    $st->execute([':n' => $name, ':t' => $type, ':sid' => $siteId, ':v' => $vendor, ':m' => $model, ':sr' => $serial, ':nt' => $note]);
                    $newId = ipam_last_insert_id($db, 'devices');
                    audit($db, 'device.create', 'device', $newId, "name=$name type=$type");
                    flash_set("Device \"$name\" created.");
                    header('Location: devices.php');
                    exit;
                } catch (PDOException) {
                    $err = 'Could not create device (a device with that name already exists?).';
                }
            }
        }
    } elseif ($action === 'update') {
        $id     = to_int($_POST['id']      ?? 0);
        $name   = trim(to_str($_POST['name']   ?? ''));
        $type   = to_str($_POST['type']   ?? 'other');
        $siteId = to_int($_POST['site_id'] ?? 0) ?: null;
        $vendor = trim(to_str($_POST['vendor'] ?? ''));
        $model  = trim(to_str($_POST['model']  ?? ''));
        $serial = trim(to_str($_POST['serial'] ?? ''));
        $note   = trim(to_str($_POST['note']   ?? ''));

        if (!in_array($type, $validTypes, true)) $type = 'other';

        if ($id <= 0 || $name === '') {
            $err = 'Device name is required.';
        } else {
            if ($siteId !== null) {
                $sc = $db->prepare("SELECT id FROM sites WHERE id=:id");
                $sc->execute([':id' => $siteId]);
                if (!$sc->fetch()) $err = 'Selected site does not exist.';
            }
            if ($err === '') {
                try {
                    $st = $db->prepare("UPDATE devices SET name=:n, type=:t, site_id=:sid, vendor=:v, model=:m, serial=:sr, note=:nt, updated_at=" . ipam_dialect()->now() . " WHERE id=:id");
                    $st->execute([':n' => $name, ':t' => $type, ':sid' => $siteId, ':v' => $vendor, ':m' => $model, ':sr' => $serial, ':nt' => $note, ':id' => $id]);
                    audit($db, 'device.update', 'device', $id, "name=$name");
                    flash_set("Device \"$name\" updated.");
                    header('Location: devices.php');
                    exit;
                } catch (PDOException) {
                    $err = 'Could not update device (duplicate name?).';
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = to_int($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $db->prepare("SELECT name FROM devices WHERE id=:id");
            $row->execute([':id' => $id]);
            /** @var array<string,mixed>|false $dev */
            $dev = $row->fetch();
            // ON DELETE CASCADE removes device_interfaces; ON DELETE SET NULL clears addresses.device_id
            $db->prepare("DELETE FROM devices WHERE id=:id")->execute([':id' => $id]);
            audit($db, 'device.delete', 'device', $id, $dev ? 'name=' . to_str($dev['name']) : '');
            flash_set('Device deleted.');
            header('Location: devices.php');
            exit;
        }
    }

    // ── Interface CRUD ────────────────────────────────────────────────────────

    if ($action === 'create_interface') {
        $deviceId = to_int($_POST['device_id'] ?? 0);
        $ifName   = trim(to_str($_POST['if_name'] ?? ''));
        $ifDesc   = trim(to_str($_POST['if_desc'] ?? ''));

        if ($deviceId <= 0 || $ifName === '') {
            $err = 'Interface name is required.';
        } else {
            try {
                $st = $db->prepare("INSERT INTO device_interfaces (device_id, name, description) VALUES (:did,:n,:d)");
                $st->execute([':did' => $deviceId, ':n' => $ifName, ':d' => $ifDesc]);
                $newId = ipam_last_insert_id($db, 'device_interfaces');
                audit($db, 'device_interface.create', 'device_interface', $newId, "device_id=$deviceId name=$ifName");
                flash_set("Interface \"$ifName\" added.");
            } catch (PDOException) {
                $err = 'Could not add interface (duplicate name on this device?).';
            }
        }
        $anchor = $deviceId > 0 ? '#device-' . $deviceId : '';
        header('Location: devices.php' . $anchor);
        exit;
    } elseif ($action === 'delete_interface') {
        $ifId     = to_int($_POST['if_id']     ?? 0);
        $deviceId = to_int($_POST['device_id'] ?? 0);
        if ($ifId > 0) {
            $row = $db->prepare("SELECT name FROM device_interfaces WHERE id=:id");
            $row->execute([':id' => $ifId]);
            /** @var array<string,mixed>|false $iface */
            $iface = $row->fetch();
            // ON DELETE SET NULL clears addresses.interface_id
            $db->prepare("DELETE FROM device_interfaces WHERE id=:id")->execute([':id' => $ifId]);
            audit($db, 'device_interface.delete', 'device_interface', $ifId, $iface ? 'name=' . to_str($iface['name']) : '');
            flash_set('Interface deleted.');
        }
        $anchor = $deviceId > 0 ? '#device-' . $deviceId : '';
        header('Location: devices.php' . $anchor);
        exit;
    }
}

// ── GET filters ───────────────────────────────────────────────────────────────

$fType   = to_str($_GET['ftype'] ?? '');
$fSiteId = to_int($_GET['fsite'] ?? 0);
$fQ      = trim(to_str($_GET['q'] ?? ''));

if (!in_array($fType, array_merge([''], $validTypes), true)) $fType = '';

$whereClauses = [];
$bindParams   = [];

if ($fType !== '') {
    $whereClauses[] = 'd.type = :ftype';
    $bindParams[':ftype'] = $fType;
}
if ($fSiteId > 0) {
    $whereClauses[] = 'd.site_id = :fsite';
    $bindParams[':fsite'] = $fSiteId;
}
if ($fQ !== '') {
    $whereClauses[] = '(d.name LIKE :q OR d.vendor LIKE :q OR d.model LIKE :q OR d.serial LIKE :q)';
    $bindParams[':q'] = '%' . $fQ . '%';
}

$where = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$sortCols = ['name' => 'd.name', 'type' => 'd.type', 'site' => 's.name', 'vendor' => 'd.vendor', 'created' => 'd.created_at'];
$sort = parse_sort($sortCols, 'name');

$st = $db->prepare("
    SELECT d.id, d.name, d.type, d.site_id, d.vendor, d.model, d.serial, d.note, d.created_at,
           s.name AS site_name,
           (SELECT COUNT(*) FROM device_interfaces di WHERE di.device_id = d.id) AS iface_count,
           (SELECT COUNT(*) FROM addresses a WHERE a.device_id = d.id) AS address_count
    FROM devices d
    LEFT JOIN sites s ON s.id = d.site_id
    $where
    ORDER BY {$sort['sql']}
");
$st->execute($bindParams);
/** @var list<array<string,mixed>> $devices */
$devices = $st->fetchAll();

// Fetch interfaces for all device IDs (one query, keyed by device_id)
/** @var array<int,list<array<string,mixed>>> $ifacesByDevice */
$ifacesByDevice = [];
if ($devices) {
    $ids  = array_map(fn($d) => to_int($d['id']), $devices);
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $ifs  = $db->prepare("SELECT id, device_id, name, description FROM device_interfaces WHERE device_id IN ($ph) ORDER BY name");
    $ifs->execute($ids);
    foreach ($ifs->fetchAll() as $iface) {
        $did = to_int($iface['device_id']);
        $ifacesByDevice[$did][] = $iface;
    }
}

/** @var list<array<string,mixed>> $sites */
$sites = ($db->query("SELECT id, name FROM sites ORDER BY name") ?: throw new \RuntimeException('Query failed'))->fetchAll();

page_header('Devices');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="#">Admin</a><span class="sep">›</span>
  <span>Devices</span>
</div>

<div class="toolbar">
  <div>
    <h1>Devices</h1>
    <div class="muted">Physical and virtual devices; link interfaces to address records.</div>
  </div>
  <a class="action-pill" href="#add-device">+ Add Device</a>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>

<div class="card" id="add-device">
  <h2>Add Device</h2>
  <form method="post" action="devices.php">
    <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="row">
      <label class="flex-1">Name (required)<br>
        <input name="name" required placeholder="e.g. core-sw-01" class="w-full">
      </label>
      <label>Type<br>
        <select name="type">
          <?php foreach ($validTypes as $t): ?>
            <option value="<?= e($t) ?>"><?= e(ucfirst($t)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Site<br>
        <select name="site_id">
          <option value="0">(none)</option>
          <?php foreach ($sites as $s): ?>
            <option value="<?= to_int($s['id']) ?>"><?= e(to_str($s['name'])) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="row">
      <label class="flex-1">Vendor<br><input name="vendor" placeholder="Cisco" class="w-full"></label>
      <label class="flex-1">Model<br><input name="model"  placeholder="Catalyst 9300" class="w-full"></label>
      <label class="flex-1">Serial<br><input name="serial" placeholder="FDO123456AB" class="w-full"></label>
    </div>
    <div class="row">
      <label class="flex-1">Note<br><textarea name="note" rows="2" class="w-full"></textarea></label>
    </div>
    <p><button type="submit">Create Device</button></p>
  </form>
</div>

<div class="card mt-16">
  <form method="get" action="devices.php" class="filter-bar">
    <select name="ftype">
      <option value="">All Types</option>
      <?php foreach ($validTypes as $t): ?>
        <option value="<?= e($t) ?>"<?= $fType === $t ? ' selected' : '' ?>><?= e(ucfirst($t)) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="fsite">
      <option value="0">All Sites</option>
      <?php foreach ($sites as $s): ?>
        <option value="<?= to_int($s['id']) ?>"<?= $fSiteId === to_int($s['id']) ? ' selected' : '' ?>><?= e(to_str($s['name'])) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= e($fQ) ?>" placeholder="Search name, vendor, model, serial…" class="flex-1">
    <button type="submit">Filter</button>
    <?php if ($fType !== '' || $fSiteId > 0 || $fQ !== ''): ?>
      <a href="devices.php" class="button-secondary">Clear</a>
    <?php endif; ?>
  </form>

  <?php if (!$devices): ?>
    <div class="empty-state">
      No devices<?= ($fType !== '' || $fSiteId > 0 || $fQ !== '') ? ' match the current filters' : ' yet' ?>.
      <?php if (!$fType && !$fSiteId && !$fQ): ?>
        <a class="action-pill" href="#add-device">+ Add Device</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <?php $qs = '?' . http_build_query(array_filter(['ftype' => $fType, 'fsite' => $fSiteId ?: '', 'q' => $fQ])) . '&';
                echo sort_th('name',   'Name',         $sort['col'], $sort['dir'], $qs);
                echo sort_th('type',   'Type',         $sort['col'], $sort['dir'], $qs);
                echo sort_th('site',   'Site',         $sort['col'], $sort['dir'], $qs);
                echo sort_th('vendor', 'Vendor / Model', $sort['col'], $sort['dir'], $qs);
          ?>
          <th>Interfaces</th>
          <th>Addresses</th>
          <?php echo sort_th('created', 'Created', $sort['col'], $sort['dir'], $qs); ?>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($devices as $dev):
            $devId      = to_int($dev['id']);
            $devName    = to_str($dev['name']);
            $devType    = to_str($dev['type']);
            $ifaceCount = to_int($dev['iface_count']);
            $addrCount  = to_int($dev['address_count']);
            $ifaces     = $ifacesByDevice[$devId] ?? [];
      ?>
        <tr id="device-<?= $devId ?>">
          <td><b><?= e($devName) ?></b></td>
          <td><span class="badge badge-type-<?= e($devType) ?>"><?= e(ucfirst($devType)) ?></span></td>
          <td><?= $dev['site_name'] !== null ? e(to_str($dev['site_name'])) : '<span class="muted">—</span>' ?></td>
          <td><?php
            $vm = trim(e(to_str($dev['vendor'])) . ' ' . e(to_str($dev['model'])));
            echo $vm !== '' ? $vm : '<span class="muted">—</span>';
          ?></td>
          <td><?= $ifaceCount ?></td>
          <td><?= $addrCount ?></td>
          <td class="muted"><?= e(ipam_format_datetime(to_str($dev['created_at']))) ?></td>
          <td>
            <details>
              <summary>Edit / Interfaces</summary>

              <form method="post" action="devices.php" class="mt-8">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id"     value="<?= $devId ?>">
                <div class="row">
                  <label>Name<br>
                    <input name="name" value="<?= e($devName) ?>" required>
                  </label>
                  <label>Type<br>
                    <select name="type">
                      <?php foreach ($validTypes as $t): ?>
                        <option value="<?= e($t) ?>"<?= $devType === $t ? ' selected' : '' ?>><?= e(ucfirst($t)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>Site<br>
                    <select name="site_id">
                      <option value="0">(none)</option>
                      <?php foreach ($sites as $s): ?>
                        <option value="<?= to_int($s['id']) ?>"<?= to_int($dev['site_id']) === to_int($s['id']) ? ' selected' : '' ?>><?= e(to_str($s['name'])) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                </div>
                <div class="row">
                  <label>Vendor<br><input name="vendor" value="<?= e(to_str($dev['vendor'])) ?>"></label>
                  <label>Model<br><input name="model"   value="<?= e(to_str($dev['model'])) ?>"></label>
                  <label>Serial<br><input name="serial" value="<?= e(to_str($dev['serial'])) ?>"></label>
                </div>
                <div class="row">
                  <label class="flex-1">Note<br>
                    <textarea name="note" rows="2" class="w-full"><?= e(to_str($dev['note'])) ?></textarea>
                  </label>
                </div>
                <p><button type="submit">Save Changes</button></p>
              </form>

              <hr class="mt-8">

              <div class="device-ifaces mt-8">
                <h4>Interfaces</h4>
                <?php if ($ifaces): ?>
                  <div class="table-wrap">
                  <table class="table-compact">
                    <thead><tr><th>Name</th><th>Description</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($ifaces as $iface): ?>
                      <tr>
                        <td><?= e(to_str($iface['name'])) ?></td>
                        <td><?= $iface['description'] !== '' ? e(to_str($iface['description'])) : '<span class="muted">—</span>' ?></td>
                        <td>
                          <form method="post" action="devices.php"
                                data-confirm="Delete interface <?= e(to_str($iface['name'])) ?>? Addresses using this interface will have the link cleared.">
                            <input type="hidden" name="csrf"      value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action"    value="delete_interface">
                            <input type="hidden" name="if_id"     value="<?= to_int($iface['id']) ?>">
                            <input type="hidden" name="device_id" value="<?= $devId ?>">
                            <button type="submit" class="button-danger button-xs">Delete</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                  </div>
                <?php else: ?>
                  <p class="muted">No interfaces defined.</p>
                <?php endif; ?>

                <form method="post" action="devices.php" class="row mt-8">
                  <input type="hidden" name="csrf"      value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action"    value="create_interface">
                  <input type="hidden" name="device_id" value="<?= $devId ?>">
                  <label>Interface name<br>
                    <input name="if_name" placeholder="e.g. GigabitEthernet0/1" required>
                  </label>
                  <label class="flex-1">Description<br>
                    <input name="if_desc" placeholder="Uplink to core" class="w-full">
                  </label>
                  <div style="align-self:flex-end">
                    <button type="submit">Add Interface</button>
                  </div>
                </form>
              </div>

              <hr class="mt-8">

              <form method="post" action="devices.php" class="mt-8"
                    data-confirm="<?= e("Delete device \"$devName\"? This will also delete all " . $ifaceCount . " interface(s). " . ($addrCount > 0 ? $addrCount . " address(es) linked to this device will have the device link cleared." : '')) ?>">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= $devId ?>">
                <button type="submit" class="button-danger">Delete Device</button>
                <?php if ($addrCount > 0): ?>
                  <span class="muted ml-8"><?= $addrCount ?> address<?= $addrCount !== 1 ? 'es' : '' ?> linked</span>
                <?php endif; ?>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php page_footer(); ?>
