<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$u = current_user();
$newKey = null; // raw key shown once after creation
$formError = '';

// ---- Actions ----

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // Demo mode: block destructive mutations
    if (demo_mode_enabled() && in_array($action, ['create', 'deactivate', 'delete'], true)) {
        $formError = 'This action is disabled in demo mode.';
        $action = '';
    }

    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $formError = 'Key name is required.';
        } else {
            // Generate a 32-byte random key, encode as hex (64 chars)
            $rawKey  = bin2hex(random_bytes(32));
            $keyHash = hash('sha256', $rawKey);
            $st = $db->prepare("INSERT INTO api_keys (name, key_hash, created_by) VALUES (:n,:h,:by)");
            $st->execute([':n' => $name, ':h' => $keyHash, ':by' => $u['username']]);
            audit($db, 'apikey.create', 'apikey', (int)$db->lastInsertId(), 'name=' . $name);
            $newKey = $rawKey; // shown once only
        }
    }

    if ($action === 'deactivate') {
        $kid = (int)($_POST['key_id'] ?? 0);
        $db->prepare("UPDATE api_keys SET is_active = 0 WHERE id = :id")
           ->execute([':id' => $kid]);
        audit($db, 'apikey.deactivate', 'apikey', $kid, '');
        flash_set('API key deactivated.');
        header('Location: api_keys.php');
        exit;
    }

    if ($action === 'activate') {
        $kid = (int)($_POST['key_id'] ?? 0);
        $db->prepare("UPDATE api_keys SET is_active = 1 WHERE id = :id")
           ->execute([':id' => $kid]);
        audit($db, 'apikey.activate', 'apikey', $kid, '');
        flash_set('API key activated.');
        header('Location: api_keys.php');
        exit;
    }

    if ($action === 'delete') {
        $kid = (int)($_POST['key_id'] ?? 0);
        $db->prepare("DELETE FROM api_keys WHERE id = :id")
           ->execute([':id' => $kid]);
        audit($db, 'apikey.delete', 'apikey', $kid, '');
        flash_set('API key deleted.');
        header('Location: api_keys.php');
        exit;
    }
}

// ---- List ----

$keySortCols = ['name' => 'name', 'status' => 'is_active', 'created' => 'created_at'];
$keySort = parse_sort($keySortCols, 'created', 'desc');

$keys = $db->query("SELECT id, name, created_at, last_used_at, is_active, created_by
                    FROM api_keys ORDER BY {$keySort['sql']}")
           ->fetchAll();

page_header('API Keys');
?>
<h1>API Keys</h1>
<p class="muted">API keys grant read-only access to the <a href="api.php">REST API</a>.
  Each key is shown <strong>once</strong> at creation — copy it before navigating away.</p>

<?php if (!empty($newKey)): ?>
<div class="card card--success">
  <strong>New API key created — copy it now, it will not be shown again:</strong><br>
  <code class="key-display"><?= e($newKey) ?></code>
</div>
<?php endif; ?>

<?php if (!empty($formError)): ?>
  <p class="danger"><?= e($formError) ?></p>
<?php endif; ?>

<div class="card">
  <h2>Create new key</h2>
  <form method="post" action="api_keys.php">
    <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="row">
      <label>Key name / description
        <input name="name" required placeholder="e.g. Monitoring script" class="mw-260">
      </label>
      <div class="flex-self-end">
        <button type="submit">Generate key</button>
      </div>
    </div>
  </form>
</div>

<?php if ($keys): ?>
<div class="card mt-16">
  <h2>Existing keys</h2>
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <?php $keyQs = '?';
              echo sort_th('name',    'Name',    $keySort['col'], $keySort['dir'], $keyQs);
              echo sort_th('created', 'Created', $keySort['col'], $keySort['dir'], $keyQs);
        ?>
        <th>Created by</th>
        <th>Last used</th>
        <?php echo sort_th('status', 'Status', $keySort['col'], $keySort['dir'], $keyQs); ?>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($keys as $k): ?>
      <tr>
        <td><?= e((string)$k['name']) ?></td>
        <td><?= e((string)$k['created_at']) ?></td>
        <td><?= e((string)$k['created_by']) ?></td>
        <td><?= $k['last_used_at'] ? e((string)$k['last_used_at']) : '<span class="muted">Never</span>' ?></td>
        <td>
          <?php if ((int)$k['is_active']): ?>
            <span class="success">Active</span>
          <?php else: ?>
            <span class="muted">Inactive</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="actions-inline">
            <?php if ((int)$k['is_active']): ?>
              <form method="post" action="api_keys.php" class="d-inline">
                <input type="hidden" name="csrf"     value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action"   value="deactivate">
                <input type="hidden" name="key_id"   value="<?= (int)$k['id'] ?>">
                <button type="submit" class="button-secondary">Deactivate</button>
              </form>
            <?php else: ?>
              <form method="post" action="api_keys.php" class="d-inline">
                <input type="hidden" name="csrf"     value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action"   value="activate">
                <input type="hidden" name="key_id"   value="<?= (int)$k['id'] ?>">
                <button type="submit" class="button-secondary">Activate</button>
              </form>
            <?php endif; ?>
            <form method="post" action="api_keys.php" class="d-inline"
                  data-confirm="Permanently delete this key?">
              <input type="hidden" name="csrf"     value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action"   value="delete">
              <input type="hidden" name="key_id"   value="<?= (int)$k['id'] ?>">
              <button type="submit" class="button-danger">Delete</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php else: ?>
  <p class="muted mt-16">No API keys yet.</p>
<?php endif; ?>

<?php page_footer(); ?>
