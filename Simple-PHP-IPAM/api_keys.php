<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$u = current_user();
$newKey = null; // raw key shown once after creation

// ---- Actions ----

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

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
            audit($db, 'api_key.create', 'api_key', (int)$db->lastInsertId(), 'name=' . $name);
            $newKey = $rawKey; // shown once only
        }
    }

    if ($action === 'deactivate') {
        $kid = (int)($_POST['key_id'] ?? 0);
        $db->prepare("UPDATE api_keys SET is_active = 0 WHERE id = :id")
           ->execute([':id' => $kid]);
        audit($db, 'api_key.deactivate', 'api_key', $kid, '');
        header('Location: api_keys.php');
        exit;
    }

    if ($action === 'activate') {
        $kid = (int)($_POST['key_id'] ?? 0);
        $db->prepare("UPDATE api_keys SET is_active = 1 WHERE id = :id")
           ->execute([':id' => $kid]);
        audit($db, 'api_key.activate', 'api_key', $kid, '');
        header('Location: api_keys.php');
        exit;
    }

    if ($action === 'delete') {
        $kid = (int)($_POST['key_id'] ?? 0);
        $db->prepare("DELETE FROM api_keys WHERE id = :id")
           ->execute([':id' => $kid]);
        audit($db, 'api_key.delete', 'api_key', $kid, '');
        header('Location: api_keys.php');
        exit;
    }
}

// ---- List ----

$keys = $db->query("SELECT id, name, created_at, last_used_at, is_active, created_by
                    FROM api_keys ORDER BY created_at DESC")
           ->fetchAll();

page_header('API Keys');
?>
<h1>API Keys</h1>
<p class="muted">API keys grant read-only access to the <a href="api.php">REST API</a>.
  Each key is shown <strong>once</strong> at creation — copy it before navigating away.</p>

<?php if (!empty($newKey)): ?>
<div class="card" style="border-color:var(--success)">
  <strong>New API key created — copy it now, it will not be shown again:</strong><br>
  <code style="word-break:break-all;font-size:1.05rem"><?= e($newKey) ?></code>
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
        <input name="name" required placeholder="e.g. Monitoring script" style="min-width:260px">
      </label>
      <div style="align-self:flex-end">
        <button type="submit">Generate key</button>
      </div>
    </div>
  </form>
</div>

<?php if ($keys): ?>
<div class="card" style="margin-top:16px">
  <h2>Existing keys</h2>
  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>Created</th>
        <th>Created by</th>
        <th>Last used</th>
        <th>Status</th>
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
              <form method="post" action="api_keys.php" style="display:inline">
                <input type="hidden" name="csrf"     value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action"   value="deactivate">
                <input type="hidden" name="key_id"   value="<?= (int)$k['id'] ?>">
                <button type="submit" class="button-secondary">Deactivate</button>
              </form>
            <?php else: ?>
              <form method="post" action="api_keys.php" style="display:inline">
                <input type="hidden" name="csrf"     value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action"   value="activate">
                <input type="hidden" name="key_id"   value="<?= (int)$k['id'] ?>">
                <button type="submit" class="button-secondary">Activate</button>
              </form>
            <?php endif; ?>
            <form method="post" action="api_keys.php" style="display:inline"
                  onsubmit="return confirm('Permanently delete this key?')">
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
<?php else: ?>
  <p class="muted" style="margin-top:16px">No API keys yet.</p>
<?php endif; ?>

<?php page_footer(); ?>
