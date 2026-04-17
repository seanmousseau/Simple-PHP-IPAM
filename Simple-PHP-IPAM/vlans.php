<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = to_str($_POST['action'] ?? '');

    if (demo_mode_enabled() && in_array($action, ['create', 'update', 'delete', 'create_range', 'update_range', 'delete_range'], true)) {
        $err = 'This action is disabled in demo mode.';
        $action = '';
    }

    if ($action === 'create') {
        $vlanId = to_int($_POST['vlan_id'] ?? 0);
        $name   = trim(to_str($_POST['name'] ?? ''));
        $desc   = trim(to_str($_POST['description'] ?? ''));
        $siteId = to_int($_POST['site_id'] ?? 0) ?: null;

        if ($vlanId < 1 || $vlanId > 4094) {
            $err = 'VLAN ID must be between 1 and 4094.';
        } elseif ($name === '') {
            $err = 'VLAN name is required.';
        } else {
            try {
                $st = $db->prepare("INSERT INTO vlans (vlan_id, name, description, site_id) VALUES (:vid,:n,:d,:sid)");
                $st->execute([':vid' => $vlanId, ':n' => $name, ':d' => $desc, ':sid' => $siteId]);
                $newId = ipam_last_insert_id($db, 'vlans');
                audit($db, 'vlan.create', 'vlan', $newId, "vlan_id=$vlanId name=$name");
                flash_set("VLAN $vlanId created.");
                header('Location: vlans.php');
                exit;
            } catch (PDOException $e) {
                $err = 'Could not create VLAN (duplicate VLAN ID for this site?).';
            }
        }
    } elseif ($action === 'update') {
        $id     = to_int($_POST['id'] ?? 0);
        $vlanId = to_int($_POST['vlan_id'] ?? 0);
        $name   = trim(to_str($_POST['name'] ?? ''));
        $desc   = trim(to_str($_POST['description'] ?? ''));
        $siteId = to_int($_POST['site_id'] ?? 0) ?: null;

        if ($id <= 0 || $vlanId < 1 || $vlanId > 4094 || $name === '') {
            $err = 'Valid VLAN ID (1–4094) and name are required.';
        } else {
            try {
                $st = $db->prepare("UPDATE vlans SET vlan_id=:vid, name=:n, description=:d, site_id=:sid, updated_at=" . ipam_dialect()->now() . " WHERE id=:id");
                $st->execute([':vid' => $vlanId, ':n' => $name, ':d' => $desc, ':sid' => $siteId, ':id' => $id]);
                audit($db, 'vlan.update', 'vlan', $id, "vlan_id=$vlanId name=$name");
                flash_set("VLAN $vlanId updated.");
                header('Location: vlans.php');
                exit;
            } catch (PDOException $e) {
                $err = 'Could not update VLAN (duplicate VLAN ID for this site?).';
            }
        }
    } elseif ($action === 'delete') {
        $id = to_int($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $db->prepare("SELECT vlan_id FROM vlans WHERE id = :id");
            $st->execute([':id' => $id]);
            /** @var array<string,mixed>|false $row */
            $row = $st->fetch();
            // Detach subnets referencing this VLAN before deleting
            $db->prepare("UPDATE subnets SET vlan_fk = NULL WHERE vlan_fk = :id")->execute([':id' => $id]);
            $db->prepare("DELETE FROM vlans WHERE id = :id")->execute([':id' => $id]);
            audit($db, 'vlan.delete', 'vlan', $id, $row ? 'vlan_id=' . to_int($row['vlan_id']) : '');
            flash_set('VLAN deleted.');
            header('Location: vlans.php');
            exit;
        }
    } elseif ($action === 'create_range') {
        $rName   = trim(to_str($_POST['range_name'] ?? ''));
        $rMin    = to_int($_POST['vlan_min'] ?? 0);
        $rMax    = to_int($_POST['vlan_max'] ?? 0);
        $rDesc   = trim(to_str($_POST['range_desc'] ?? ''));
        $rSiteId = to_int($_POST['range_site_id'] ?? 0) ?: null;

        if ($rName === '') {
            $err = 'Range name is required.';
        } elseif ($rMin < 1 || $rMin > 4094 || $rMax < 1 || $rMax > 4094) {
            $err = 'VLAN min/max must be between 1 and 4094.';
        } elseif ($rMin > $rMax) {
            $err = 'VLAN min must not exceed VLAN max.';
        } else {
            try {
                $st = $db->prepare("INSERT INTO vlan_ranges (name, vlan_min, vlan_max, description, site_id) VALUES (:n,:min,:max,:d,:sid)");
                $st->execute([':n' => $rName, ':min' => $rMin, ':max' => $rMax, ':d' => $rDesc, ':sid' => $rSiteId]);
                $newId = ipam_last_insert_id($db, 'vlan_ranges');
                audit($db, 'vlan.range_create', 'vlan_range', $newId, "name=$rName min=$rMin max=$rMax");
                flash_set("VLAN range \"$rName\" created.");
            } catch (PDOException $e) {
                $err = 'Could not create VLAN range.';
            }
        }
        header('Location: vlans.php');
        exit;
    } elseif ($action === 'update_range') {
        $rId     = to_int($_POST['range_id'] ?? 0);
        $rName   = trim(to_str($_POST['range_name'] ?? ''));
        $rMin    = to_int($_POST['vlan_min'] ?? 0);
        $rMax    = to_int($_POST['vlan_max'] ?? 0);
        $rDesc   = trim(to_str($_POST['range_desc'] ?? ''));
        $rSiteId = to_int($_POST['range_site_id'] ?? 0) ?: null;

        if ($rId <= 0 || $rName === '') {
            $err = 'Range name is required.';
        } elseif ($rMin < 1 || $rMin > 4094 || $rMax < 1 || $rMax > 4094) {
            $err = 'VLAN min/max must be between 1 and 4094.';
        } elseif ($rMin > $rMax) {
            $err = 'VLAN min must not exceed VLAN max.';
        } else {
            try {
                $st = $db->prepare("UPDATE vlan_ranges SET name=:n, vlan_min=:min, vlan_max=:max, description=:d, site_id=:sid, updated_at=" . ipam_dialect()->now() . " WHERE id=:id");
                $st->execute([':n' => $rName, ':min' => $rMin, ':max' => $rMax, ':d' => $rDesc, ':sid' => $rSiteId, ':id' => $rId]);
                audit($db, 'vlan.range_update', 'vlan_range', $rId, "name=$rName min=$rMin max=$rMax");
                flash_set("VLAN range \"$rName\" updated.");
            } catch (PDOException $e) {
                $err = 'Could not update VLAN range.';
            }
        }
        header('Location: vlans.php');
        exit;
    } elseif ($action === 'delete_range') {
        $rId = to_int($_POST['range_id'] ?? 0);
        if ($rId > 0) {
            $st = $db->prepare("SELECT name FROM vlan_ranges WHERE id = :id");
            $st->execute([':id' => $rId]);
            /** @var array<string,mixed>|false $rRow */
            $rRow = $st->fetch();
            $db->prepare("DELETE FROM vlan_ranges WHERE id = :id")->execute([':id' => $rId]);
            audit($db, 'vlan.range_delete', 'vlan_range', $rId, $rRow ? 'name=' . to_str($rRow['name']) : '');
            flash_set('VLAN range deleted.');
        }
        header('Location: vlans.php');
        exit;
    }
}

// Sites for the picker
$sites = ($db->query("SELECT id, name FROM sites ORDER BY name") ?: throw new \RuntimeException('Query failed'))->fetchAll();

$sortCols = ['vlan_id' => 'v.vlan_id', 'name' => 'v.name', 'site' => 's.name', 'created' => 'v.created_at'];
$sort = parse_sort($sortCols, 'vlan_id');

$vlans = ($db->query("
    SELECT v.id, v.vlan_id, v.name, v.description, v.site_id, v.created_at,
           s.name AS site_name,
           (SELECT COUNT(*) FROM subnets sn WHERE sn.vlan_fk = v.id) AS subnet_count
    FROM vlans v
    LEFT JOIN sites s ON s.id = v.site_id
    ORDER BY {$sort['sql']}
") ?: throw new \RuntimeException('Query failed'))->fetchAll();

// VLAN ranges (table added in v2.4.0 — guard against pre-migration DBs)
/** @var list<array<string,mixed>> $vlanRanges */
$vlanRanges = [];
try {
    $vlanRanges = ($db->query("
        SELECT r.id, r.name, r.vlan_min, r.vlan_max, r.description, r.site_id, r.created_at,
               s.name AS site_name
        FROM vlan_ranges r
        LEFT JOIN sites s ON s.id = r.site_id
        ORDER BY r.vlan_min
    ") ?: throw new \RuntimeException('Query failed'))->fetchAll();
} catch (PDOException) {
    // table not yet created — migration pending
}

page_header('VLANs');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="#">Admin</a><span class="sep">›</span>
  <span>VLANs</span>
</div>

<div class="toolbar">
  <div>
    <h1>VLANs</h1>
    <div class="muted">Manage 802.1Q VLANs and associate them with subnets.</div>
  </div>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>

<div class="grid cols-2">
  <div class="card" id="add-vlan">
    <h2>Add VLAN</h2>
    <form method="post" action="vlans.php">
      <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <div class="row">
        <label>VLAN ID (1–4094)<br><input type="number" name="vlan_id" min="1" max="4094" required class="mw-120"></label>
        <label class="flex-1">Name<br><input name="name" required placeholder="e.g. Management" class="w-full"></label>
      </div>
      <div class="row">
        <label class="flex-1">Description<br><input name="description" class="w-full"></label>
        <?php if ($sites): ?>
        <label>Site<br>
          <select name="site_id">
            <option value="">— Global —</option>
            <?php foreach ($sites as $site): ?>
              <option value="<?= to_int($site['id']) ?>"><?= e(to_str($site['name'])) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php endif; ?>
      </div>
      <p><button type="submit">Create VLAN</button></p>
    </form>
  </div>

  <div class="card">
    <h2>Overview</h2>
    <div class="grid cols-2">
      <div class="metric">
        <div class="label">VLANs</div>
        <div class="value"><?= e((string)count($vlans)) ?></div>
      </div>
      <div class="metric">
        <div class="label">Subnets assigned</div>
        <div class="value"><?= e((string)array_sum(array_map(fn($v) => to_int($v['subnet_count']), $vlans))) ?></div>
      </div>
    </div>
  </div>
</div>

<div class="card mt-16">
  <h2>Existing VLANs</h2>

  <?php if (!$vlans): ?>
    <div class="empty-state">No VLANs yet. <a class="action-pill" href="#add-vlan">+ Add VLAN</a></div>
  <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <?php $qs = '?';
                echo sort_th('vlan_id', 'VLAN ID', $sort['col'], $sort['dir'], $qs);
                echo sort_th('name',    'Name',    $sort['col'], $sort['dir'], $qs);
                echo sort_th('site',    'Site',    $sort['col'], $sort['dir'], $qs);
          ?>
          <th>Description</th>
          <th>Subnets</th>
          <?php echo sort_th('created', 'Created', $sort['col'], $sort['dir'], $qs); ?>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($vlans as $v): ?>
        <tr>
          <td><b><?= to_int($v['vlan_id']) ?></b></td>
          <td><?= e(to_str($v['name'])) ?></td>
          <td><?= $v['site_name'] ? e(to_str($v['site_name'])) : '<span class="muted">Global</span>' ?></td>
          <td><?= $v['description'] !== '' ? e(to_str($v['description'])) : '<span class="muted">—</span>' ?></td>
          <td><?= to_int($v['subnet_count']) ?></td>
          <td class="muted"><?= e(ipam_format_datetime(to_str($v['created_at']))) ?></td>
          <td>
            <details>
              <summary>Edit/Delete</summary>
              <form method="post" action="vlans.php" class="row mt-8">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id"     value="<?= to_int($v['id']) ?>">
                <label>VLAN ID<br><input type="number" name="vlan_id" min="1" max="4094" value="<?= to_int($v['vlan_id']) ?>" required class="mw-120"></label>
                <label>Name<br><input name="name" value="<?= e(to_str($v['name'])) ?>" required></label>
                <label>Description<br><input name="description" value="<?= e(to_str($v['description'])) ?>"></label>
                <?php if ($sites): ?>
                <label>Site<br>
                  <select name="site_id">
                    <option value="">— Global —</option>
                    <?php foreach ($sites as $site): ?>
                      <option value="<?= to_int($site['id']) ?>"
                        <?= to_int($v['site_id']) === to_int($site['id']) ? 'selected' : '' ?>>
                        <?= e(to_str($site['name'])) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <?php endif; ?>
                <button type="submit">Save</button>
              </form>
              <form method="post" action="vlans.php" class="mt-8"
                    data-confirm="Delete this VLAN? Subnets will be unassigned, not deleted.">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= to_int($v['id']) ?>">
                <button type="submit" class="button-danger">Delete</button>
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

<div class="card mt-16" id="vlan-ranges">
  <h2>VLAN Ranges</h2>
  <p class="muted" style="margin-bottom:12px">Define named 802.1Q VLAN ID ranges to organise allocation blocks (e.g. Management: 1–99, User Access: 100–199).</p>

  <form method="post" action="vlans.php" class="row" style="flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:16px">
    <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_range">
    <label>Name<br><input name="range_name" required placeholder="e.g. Management" style="width:160px"></label>
    <label>Min<br><input type="number" name="vlan_min" min="1" max="4094" required placeholder="1" style="width:70px"></label>
    <label>Max<br><input type="number" name="vlan_max" min="1" max="4094" required placeholder="99" style="width:70px"></label>
    <label class="flex-1">Description<br><input name="range_desc" class="w-full"></label>
    <?php if ($sites): ?>
    <label>Site<br>
      <select name="range_site_id">
        <option value="">— Global —</option>
        <?php foreach ($sites as $site): ?>
          <option value="<?= to_int($site['id']) ?>"><?= e(to_str($site['name'])) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <div><button type="submit">Add Range</button></div>
  </form>

  <?php if (!$vlanRanges): ?>
    <div class="empty-state">No VLAN ranges defined.</div>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>Min</th>
        <th>Max</th>
        <th>Site</th>
        <th>Description</th>
        <th>Created</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($vlanRanges as $r): ?>
      <tr>
        <td><b><?= e(to_str($r['name'])) ?></b></td>
        <td><?= to_int($r['vlan_min']) ?></td>
        <td><?= to_int($r['vlan_max']) ?></td>
        <td><?= $r['site_name'] ? e(to_str($r['site_name'])) : '<span class="muted">Global</span>' ?></td>
        <td><?= to_str($r['description']) !== '' ? e(to_str($r['description'])) : '<span class="muted">—</span>' ?></td>
        <td class="muted"><?= e(ipam_format_datetime(to_str($r['created_at']))) ?></td>
        <td>
          <details>
            <summary>Edit/Delete</summary>
            <form method="post" action="vlans.php" class="row mt-8">
              <input type="hidden" name="csrf"     value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action"   value="update_range">
              <input type="hidden" name="range_id" value="<?= to_int($r['id']) ?>">
              <label>Name<br><input name="range_name" value="<?= e(to_str($r['name'])) ?>" required style="width:140px"></label>
              <label>Min<br><input type="number" name="vlan_min" min="1" max="4094" value="<?= to_int($r['vlan_min']) ?>" required style="width:70px"></label>
              <label>Max<br><input type="number" name="vlan_max" min="1" max="4094" value="<?= to_int($r['vlan_max']) ?>" required style="width:70px"></label>
              <label class="flex-1">Description<br><input name="range_desc" value="<?= e(to_str($r['description'])) ?>" class="w-full"></label>
              <?php if ($sites): ?>
              <label>Site<br>
                <select name="range_site_id">
                  <option value="">— Global —</option>
                  <?php foreach ($sites as $site): ?>
                    <option value="<?= to_int($site['id']) ?>"
                      <?= to_int($r['site_id']) === to_int($site['id']) ? 'selected' : '' ?>>
                      <?= e(to_str($site['name'])) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <?php endif; ?>
              <button type="submit">Save</button>
            </form>
            <form method="post" action="vlans.php" class="mt-8"
                  data-confirm="Delete this VLAN range?">
              <input type="hidden" name="csrf"     value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action"   value="delete_range">
              <input type="hidden" name="range_id" value="<?= to_int($r['id']) ?>">
              <button type="submit" class="button-danger">Delete</button>
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
