<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
}

$err = '';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));

        if ($name === '') {
            $err = 'Site name is required.';
        } else {
            try {
                $st = $db->prepare("INSERT INTO sites (name, description) VALUES (:n, :d)");
                $st->execute([':n' => $name, ':d' => $desc]);
                audit($db, 'site.create', 'site', (int)$db->lastInsertId(), "name=$name");
                header('Location: sites.php');
                exit;
            } catch (PDOException $e) {
                $err = 'Could not create site (duplicate name?).';
            }
        }
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));

        if ($id <= 0 || $name === '') {
            $err = 'Valid site id and name are required.';
        } else {
            try {
                $st = $db->prepare("UPDATE sites SET name = :n, description = :d WHERE id = :id");
                $st->execute([':n' => $name, ':d' => $desc, ':id' => $id]);
                audit($db, 'site.update', 'site', $id, "name=$name");
                $msg = 'Site updated.';
            } catch (PDOException $e) {
                $err = 'Could not update site (duplicate name?).';
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // First, detach subnets from this site
            $st = $db->prepare("UPDATE subnets SET site_id = NULL WHERE site_id = :id");
            $st->execute([':id' => $id]);

            $st = $db->prepare("DELETE FROM sites WHERE id = :id");
            $st->execute([':id' => $id]);

            audit($db, 'site.delete', 'site', $id, '');
            header('Location: sites.php');
            exit;
        }
    }
}

$st = $db->prepare("
    SELECT s.id, s.name, s.description, s.created_at,
           (SELECT COUNT(*) FROM subnets sn WHERE sn.site_id = s.id) AS subnet_count
    FROM sites s
    ORDER BY s.name ASC
");
$st->execute();
$sites = $st->fetchAll();

page_header('Sites');
?>

<div class="toolbar">
  <div>
    <h1>Sites</h1>
    <div class="muted">Group subnets by site for easier organization and navigation.</div>
  </div>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>

<div class="grid cols-2">
  <div class="card">
    <h2>Add Site</h2>
    <form method="post" action="sites.php">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">

      <div class="row">
        <label>Site name<br><input name="name" required></label>
      </div>
      <div class="row">
        <label style="flex:1">Description<br><input name="description" style="width:100%"></label>
      </div>

      <p><button type="submit">Create Site</button></p>
    </form>
  </div>

  <div class="card">
    <h2>Overview</h2>
    <div class="grid cols-2">
      <div class="metric">
        <div class="label">Sites</div>
        <div class="value"><?= e((string)count($sites)) ?></div>
      </div>
      <div class="metric">
        <div class="label">Subnets grouped</div>
        <div class="value"><?= e((string)array_sum(array_map(fn($s) => (int)$s['subnet_count'], $sites))) ?></div>
      </div>
    </div>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <h2>Existing Sites</h2>

  <?php if (!$sites): ?>
    <div class="empty-state">No sites yet.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>Description</th>
          <th>Subnets</th>
          <th>Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($sites as $site): ?>
        <tr>
          <td><b><?= e($site['name']) ?></b></td>
          <td><?= e($site['description']) ?></td>
          <td><?= e((string)$site['subnet_count']) ?></td>
          <td class="muted"><?= e($site['created_at']) ?></td>
          <td>
            <details>
              <summary>Edit/Delete</summary>

              <form method="post" action="sites.php" class="row" style="margin-top:8px">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
                <label>Name<br><input name="name" value="<?= e($site['name']) ?>" required></label>
                <label>Description<br><input name="description" value="<?= e($site['description']) ?>"></label>
                <button type="submit">Save</button>
              </form>

              <form method="post" action="sites.php" style="margin-top:8px"
                    onsubmit="return confirm('Delete this site? Subnets will be ungrouped, not deleted.');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
                <button type="submit" class="button-danger">Delete</button>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php page_footer();
