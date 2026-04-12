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
        $name = trim(to_str($_POST['name'] ?? ''));
        $desc = trim(to_str($_POST['description'] ?? ''));
        $rd   = trim(to_str($_POST['rd'] ?? ''));

        if ($name === '') {
            $err = 'VRF name is required.';
        } else {
            try {
                $st = $db->prepare("INSERT INTO vrfs (name, description, rd) VALUES (:n,:d,:rd)");
                $st->execute([':n' => $name, ':d' => $desc, ':rd' => $rd]);
                $newId = (int)$db->lastInsertId();
                audit($db, 'vrf.create', 'vrf', $newId, "name=$name");
                flash_set("VRF \"$name\" created.");
                header('Location: vrfs.php');
                exit;
            } catch (PDOException $e) {
                $err = 'Could not create VRF (a VRF with that name already exists?).';
            }
        }
    } elseif ($action === 'update') {
        $id   = to_int($_POST['id'] ?? 0);
        $name = trim(to_str($_POST['name'] ?? ''));
        $desc = trim(to_str($_POST['description'] ?? ''));
        $rd   = trim(to_str($_POST['rd'] ?? ''));

        if ($id <= 0 || $name === '') {
            $err = 'VRF name is required.';
        } else {
            try {
                $st = $db->prepare("UPDATE vrfs SET name=:n, description=:d, rd=:rd, updated_at=datetime('now') WHERE id=:id");
                $st->execute([':n' => $name, ':d' => $desc, ':rd' => $rd, ':id' => $id]);
                audit($db, 'vrf.update', 'vrf', $id, "name=$name");
                flash_set("VRF \"$name\" updated.");
                header('Location: vrfs.php');
                exit;
            } catch (PDOException $e) {
                $err = 'Could not update VRF (duplicate name?).';
            }
        }
    } elseif ($action === 'delete') {
        $id = to_int($_POST['id'] ?? 0);
        if ($id > 0) {
            // Block delete if subnets are assigned
            $countSt = $db->prepare("SELECT COUNT(*) FROM subnets WHERE vrf_id = :id");
            $countSt->execute([':id' => $id]);
            $count = (int)$countSt->fetchColumn();
            if ($count > 0) {
                $err = "Cannot delete: $count subnet(s) are assigned to this VRF. Reassign or delete them first.";
            } else {
                $nameSt = $db->prepare("SELECT name FROM vrfs WHERE id = :id");
                $nameSt->execute([':id' => $id]);
                /** @var array<string,mixed>|false $row */
                $row = $nameSt->fetch();
                $db->prepare("DELETE FROM vrfs WHERE id = :id")->execute([':id' => $id]);
                audit($db, 'vrf.delete', 'vrf', $id, $row ? 'name=' . to_str($row['name']) : '');
                flash_set('VRF deleted.');
                header('Location: vrfs.php');
                exit;
            }
        }
    }
}

$sortCols = ['name' => 'v.name', 'rd' => 'v.rd', 'created' => 'v.created_at'];
$sort = parse_sort($sortCols, 'name');

/** @var list<array<string, mixed>> $vrfs */
$vrfs = ($db->query("
    SELECT v.id, v.name, v.description, v.rd, v.created_at,
           (SELECT COUNT(*) FROM subnets sn WHERE sn.vrf_id = v.id) AS subnet_count
    FROM vrfs v
    ORDER BY {$sort['sql']}
") ?: throw new \RuntimeException('Query failed'))->fetchAll();

page_header('VRFs');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="#">Admin</a><span class="sep">›</span>
  <span>VRFs</span>
</div>

<div class="toolbar">
  <div>
    <h1>VRFs</h1>
    <div class="muted">Virtual Routing and Forwarding instances allow overlapping address spaces across separate routing domains.</div>
  </div>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>

<div class="grid cols-2">
  <div class="card" id="add-vrf">
    <h2>Add VRF</h2>
    <form method="post" action="vrfs.php">
      <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <div class="row">
        <label class="flex-1">Name (unique)<br><input name="name" required placeholder="e.g. Customer-A" class="w-full"></label>
        <label class="mw-160">Route Distinguisher<br><input name="rd" placeholder="e.g. 65000:1" class="w-full"></label>
      </div>
      <div class="row">
        <label class="flex-1">Description<br><input name="description" class="w-full"></label>
      </div>
      <p><button type="submit">Create VRF</button></p>
    </form>
  </div>

  <div class="card">
    <h2>Overview</h2>
    <div class="grid cols-2">
      <div class="metric">
        <div class="label">VRFs</div>
        <div class="value"><?= e((string)count($vrfs)) ?></div>
      </div>
      <div class="metric">
        <div class="label">Subnets assigned</div>
        <div class="value"><?= e((string)array_sum(array_map(fn($v) => to_int($v['subnet_count']), $vrfs))) ?></div>
      </div>
    </div>
    <p class="muted mt-8">Subnets with no VRF assigned belong to the global routing table.</p>
  </div>
</div>

<div class="card mt-16">
  <h2>Existing VRFs</h2>

  <?php if (!$vrfs): ?>
    <div class="empty-state">No VRFs yet. <a class="action-pill" href="#add-vrf">+ Add VRF</a></div>
  <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <?php $qs = '?';
                echo sort_th('name',    'Name',    $sort['col'], $sort['dir'], $qs);
                echo sort_th('rd',      'Route Distinguisher', $sort['col'], $sort['dir'], $qs);
          ?>
          <th>Description</th>
          <th>Subnets</th>
          <?php echo sort_th('created', 'Created', $sort['col'], $sort['dir'], $qs); ?>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($vrfs as $v): ?>
        <tr>
          <td><b><?= e(to_str($v['name'])) ?></b></td>
          <td><?= $v['rd'] !== '' ? e(to_str($v['rd'])) : '<span class="muted">—</span>' ?></td>
          <td><?= $v['description'] !== '' ? e(to_str($v['description'])) : '<span class="muted">—</span>' ?></td>
          <td><?= to_int($v['subnet_count']) ?></td>
          <td class="muted"><?= e(to_str($v['created_at'])) ?></td>
          <td>
            <details>
              <summary>Edit/Delete</summary>
              <form method="post" action="vrfs.php" class="row mt-8">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id"     value="<?= to_int($v['id']) ?>">
                <label>Name<br><input name="name" value="<?= e(to_str($v['name'])) ?>" required></label>
                <label>Route Distinguisher<br><input name="rd" value="<?= e(to_str($v['rd'])) ?>"></label>
                <label class="flex-1">Description<br><input name="description" value="<?= e(to_str($v['description'])) ?>"></label>
                <button type="submit">Save</button>
              </form>
              <form method="post" action="vrfs.php" class="mt-8"
                    data-confirm="Delete this VRF? All subnets must be reassigned first.">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= to_int($v['id']) ?>">
                <button type="submit" class="button-danger"
                  <?= to_int($v['subnet_count']) > 0 ? 'disabled title="Reassign subnets before deleting"' : '' ?>>Delete</button>
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
