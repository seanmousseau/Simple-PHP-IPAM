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
        $name   = trim(to_str($_POST['name'] ?? ''));
        $colour = trim(to_str($_POST['colour'] ?? '#6c757d'));

        if ($name === '') {
            $err = 'Tag name is required.';
        } elseif (strlen($name) > 50) {
            $err = 'Tag name must be 50 characters or less.';
        } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
            $err = 'Colour must be a valid hex code (e.g. #ff0000).';
        } else {
            try {
                $st = $db->prepare("INSERT INTO tags (name, colour) VALUES (:n, :c)");
                $st->execute([':n' => $name, ':c' => $colour]);
                audit($db, 'tag.create', 'tag', ipam_last_insert_id($db, 'tags'), "name=$name");
                flash_set("Tag \"$name\" created.");
                header('Location: tags.php');
                exit;
            } catch (PDOException $e) {
                $err = 'Could not create tag (duplicate name?).';
            }
        }
    } elseif ($action === 'update') {
        $id     = to_int($_POST['id'] ?? 0);
        $name   = trim(to_str($_POST['name'] ?? ''));
        $colour = trim(to_str($_POST['colour'] ?? '#6c757d'));

        if ($id <= 0 || $name === '') {
            $err = 'Valid tag id and name are required.';
        } elseif (strlen($name) > 50) {
            $err = 'Tag name must be 50 characters or less.';
        } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
            $err = 'Colour must be a valid hex code (e.g. #ff0000).';
        } else {
            try {
                $st = $db->prepare("UPDATE tags SET name = :n, colour = :c WHERE id = :id");
                $st->execute([':n' => $name, ':c' => $colour, ':id' => $id]);
                audit($db, 'tag.update', 'tag', $id, "name=$name");
                flash_set("Tag \"$name\" updated.");
                header('Location: tags.php');
                exit;
            } catch (PDOException $e) {
                $err = 'Could not update tag (duplicate name?).';
            }
        }
    } elseif ($action === 'delete') {
        $id = to_int($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $db->prepare("SELECT name FROM tags WHERE id = :id");
            $st->execute([':id' => $id]);
            /** @var array<string,mixed>|false $row */
            $row = $st->fetch();
            $db->prepare("DELETE FROM tags WHERE id = :id")->execute([':id' => $id]);
            audit($db, 'tag.delete', 'tag', $id, $row ? 'name=' . to_str($row['name']) : '');
            flash_set('Tag deleted.');
            header('Location: tags.php');
            exit;
        }
    }
}

$tags = ($db->query("
    SELECT t.id, t.name, t.colour, t.created_at,
           COUNT(DISTINCT st.subnet_id)  AS subnet_count,
           COUNT(DISTINCT at.address_id) AS address_count
    FROM tags t
    LEFT JOIN subnet_tags  st ON st.tag_id  = t.id
    LEFT JOIN address_tags at ON at.tag_id  = t.id
    GROUP BY t.id
    ORDER BY t.name
") ?: throw new \RuntimeException('Query failed'))->fetchAll();

page_header('Tags');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="#">Admin</a><span class="sep">›</span>
  <span>Tags</span>
</div>

<div class="toolbar">
  <div>
    <h1>Tags</h1>
    <div class="muted">Colour-coded labels you can attach to subnets and addresses.</div>
  </div>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>

<div class="grid cols-2">
  <div class="card" id="add-tag">
    <h2>Add Tag</h2>
    <form method="post" action="tags.php">
      <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <div class="row">
        <label>Tag name<br><input name="name" required maxlength="50" placeholder="e.g. Production"></label>
        <label>Colour<br><input type="color" name="colour" value="#6c757d" class="mw-80"></label>
      </div>
      <p><button type="submit">Create Tag</button></p>
    </form>
  </div>

  <div class="card">
    <h2>Overview</h2>
    <div class="grid cols-2">
      <div class="metric">
        <div class="label">Tags</div>
        <div class="value"><?= e((string)count($tags)) ?></div>
      </div>
      <div class="metric">
        <div class="label">In use</div>
        <div class="value"><?= e((string)count(array_filter($tags, fn($t) => to_int($t['subnet_count']) + to_int($t['address_count']) > 0))) ?></div>
      </div>
    </div>
  </div>
</div>

<div class="card mt-16">
  <h2>Existing Tags</h2>

  <?php if (!$tags): ?>
    <div class="empty-state">No tags yet. <a class="action-pill" href="#add-tag">+ Add Tag</a></div>
  <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Tag</th>
          <th>Subnets</th>
          <th>Addresses</th>
          <th>Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tags as $tag): ?>
        <tr>
          <td>
            <span class="tag-badge" style="--tag-bg: <?= e(to_str($tag['colour'])) ?>"><?= e(to_str($tag['name'])) ?></span>
          </td>
          <td><?= to_int($tag['subnet_count']) ?></td>
          <td><?= to_int($tag['address_count']) ?></td>
          <td class="muted"><?= e(ipam_format_datetime(to_str($tag['created_at']))) ?></td>
          <td>
            <details>
              <summary>Edit/Delete</summary>
              <form method="post" action="tags.php" class="row mt-8">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id"     value="<?= to_int($tag['id']) ?>">
                <label>Name<br><input name="name" value="<?= e(to_str($tag['name'])) ?>" required maxlength="50"></label>
                <label>Colour<br><input type="color" name="colour" value="<?= e(to_str($tag['colour'])) ?>" class="mw-80"></label>
                <button type="submit">Save</button>
              </form>
              <form method="post" action="tags.php" class="mt-8"
                    data-confirm="Delete this tag? It will be removed from all subnets and addresses.">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= to_int($tag['id']) ?>">
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
