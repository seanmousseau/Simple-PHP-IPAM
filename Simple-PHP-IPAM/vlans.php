<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = to_str($_POST['action'] ?? '');

    if (demo_mode_enabled() && in_array($action, ['create', 'update', 'delete'], true)) {
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
                $newId = (int)$db->lastInsertId();
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
                $st = $db->prepare("UPDATE vlans SET vlan_id=:vid, name=:n, description=:d, site_id=:sid, updated_at=datetime('now') WHERE id=:id");
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
          <td class="muted"><?= e(to_str($v['created_at'])) ?></td>
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

<?php page_footer(); ?>
