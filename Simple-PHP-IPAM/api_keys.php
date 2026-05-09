<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$u = current_user();
$newKey = null; // raw key shown once after creation
$formError = '';

// ---- Actions ----

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = to_str($_POST['action'] ?? '');

    // Demo mode: block destructive mutations
    if (demo_mode_enabled() && in_array($action, ['create', 'deactivate', 'delete', 'set_readonly', 'set_readwrite'], true)) {
        $formError = 'This action is disabled in demo mode.';
        $action = '';
    }

    if ($action === 'create') {
        $name = trim(to_str($_POST['name'] ?? ''));
        if ($name === '') {
            $formError = 'Key name is required.';
        } else {
            // v3.27.0 (#1113) — gate API-key creation behind ipam_sudo_require().
            // Same one-shot raw-token reveal shape as vault_set: the response
            // body contains the raw secret. Render the prompt with the original
            // form fields stashed as hidden inputs so the create resumes after
            // verification.
            $desc       = trim(to_str($_POST['description'] ?? ''));
            $isReadonly = isset($_POST['is_readonly']) ? 1 : 0;
            $userId     = to_int($u['id'] ?? 0);
            if (!ipam_sudo_require($db, $userId)) {
                page_header('Confirm your identity');
                $stepUpUserId       = $userId;
                $stepUpFormAction   = 'api_keys.php';
                $stepUpHiddenFields = ['action' => 'create', 'name' => $name, 'description' => $desc];
                if ($isReadonly === 1) $stepUpHiddenFields['is_readonly'] = '1';
                $stepUpDescription  = 'Re-authenticate to create an API key. The raw token is shown exactly once after creation.';
                $stepUpReturnPath   = 'api_keys.php';
                $stepUpError        = isset($_POST['_sudo_method']) ? 'Verification failed. API key was not created.' : '';
                include __DIR__ . '/views/_step_up_prompt.php';
                page_footer();
                exit;
            }
            ipam_sudo_consume_once();  // Bug X (Pass A 2026-05-08, v3.27.1): consume sudo_once for TTL=0 policy.

            // Generate a 32-byte random key, encode as hex (64 chars)
            $rawKey  = bin2hex(random_bytes(32));
            $keyHash = hash('sha256', $rawKey);
            $st = $db->prepare("INSERT INTO api_keys (name, description, is_readonly, key_hash, created_by) VALUES (:n,:d,:ro,:h,:by)");
            $st->execute([':n' => $name, ':d' => $desc, ':ro' => $isReadonly, ':h' => $keyHash, ':by' => $u['username']]);
            audit($db, 'apikey.create', 'apikey', ipam_last_insert_id($db, 'api_keys'), 'name=' . $name);
            $newKey = $rawKey; // shown once only
        }
    }

    if ($action === 'deactivate') {
        $kid = to_int($_POST['key_id'] ?? 0);
        $db->prepare("UPDATE api_keys SET is_active = 0 WHERE id = :id")
           ->execute([':id' => $kid]);
        audit($db, 'apikey.deactivate', 'apikey', $kid, '');
        flash_set('API key deactivated.');
        header('Location: api_keys.php');
        exit;
    }

    if ($action === 'activate') {
        $kid = to_int($_POST['key_id'] ?? 0);
        $db->prepare("UPDATE api_keys SET is_active = 1 WHERE id = :id")
           ->execute([':id' => $kid]);
        audit($db, 'apikey.activate', 'apikey', $kid, '');
        flash_set('API key activated.');
        header('Location: api_keys.php');
        exit;
    }

    if ($action === 'set_readonly') {
        $kid = to_int($_POST['key_id'] ?? 0);
        $db->prepare("UPDATE api_keys SET is_readonly = 1 WHERE id = :id")
           ->execute([':id' => $kid]);
        audit($db, 'apikey.set_readonly', 'apikey', $kid, '');
        flash_set('API key set to read-only.');
        header('Location: api_keys.php');
        exit;
    }

    if ($action === 'set_readwrite') {
        $kid = to_int($_POST['key_id'] ?? 0);
        $db->prepare("UPDATE api_keys SET is_readonly = 0 WHERE id = :id")
           ->execute([':id' => $kid]);
        audit($db, 'apikey.set_readwrite', 'apikey', $kid, '');
        flash_set('API key set to read-write.');
        header('Location: api_keys.php');
        exit;
    }

    if ($action === 'delete') {
        $kid = to_int($_POST['key_id'] ?? 0);
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

$keys = ($db->query("SELECT id, name, description, is_readonly, created_at, last_used_at, is_active, created_by
                    FROM api_keys ORDER BY {$keySort['sql']}") ?: throw new \RuntimeException('Query failed'))
           ->fetchAll();

page_header('API Keys');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="#">Admin</a><span class="sep">›</span>
  <span>API Keys</span>
</div>

<h1>API Keys</h1>
<p class="muted">API keys grant access to the <a href="api.php">REST API</a>.
  Read-only keys can only perform GET requests. Each key is shown <strong>once</strong> at creation — copy it before navigating away.</p>

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
      <label>Key name
        <input name="name" required placeholder="e.g. Monitoring script" class="mw-260">
      </label>
      <label>Description
        <input name="description" placeholder="Optional note (purpose, owner…)" class="mw-260">
      </label>
      <label class="flex-self-end">
        <input type="checkbox" name="is_readonly"> Read-only (GET only)
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
        <th>Description</th>
        <th>Access</th>
        <?php echo sort_th('status', 'Status', $keySort['col'], $keySort['dir'], $keyQs); ?>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($keys as $k): ?>
      <tr>
        <td><?= e(to_str($k['name'])) ?></td>
        <td><?= e(ipam_format_datetime(to_str($k['created_at']))) ?></td>
        <td><?= e(to_str($k['created_by'])) ?></td>
        <td><?= $k['last_used_at'] ? e(ipam_format_datetime(to_str($k['last_used_at']))) : '<span class="muted">Never</span>' ?></td>
        <td><?= $k['description'] !== '' ? e(to_str($k['description'])) : '<span class="muted">—</span>' ?></td>
        <td>
          <?php if (to_int($k['is_readonly'])): ?>
            <span class="badge">Read-only</span>
          <?php else: ?>
            <span class="muted">Read-write</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if (to_int($k['is_active'])): ?>
            <span class="success">Active</span>
          <?php else: ?>
            <span class="muted">Inactive</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="actions-inline">
            <?php if (to_int($k['is_active'])): ?>
              <form method="post" action="api_keys.php" class="d-inline">
                <input type="hidden" name="csrf"     value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action"   value="deactivate">
                <input type="hidden" name="key_id"   value="<?= to_int($k['id']) ?>">
                <button type="submit" class="button-secondary">Deactivate</button>
              </form>
            <?php else: ?>
              <form method="post" action="api_keys.php" class="d-inline">
                <input type="hidden" name="csrf"     value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action"   value="activate">
                <input type="hidden" name="key_id"   value="<?= to_int($k['id']) ?>">
                <button type="submit" class="button-secondary">Activate</button>
              </form>
            <?php endif; ?>
            <?php if (to_int($k['is_readonly'])): ?>
              <form method="post" action="api_keys.php" class="d-inline">
                <input type="hidden" name="csrf"     value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action"   value="set_readwrite">
                <input type="hidden" name="key_id"   value="<?= to_int($k['id']) ?>">
                <button type="submit" class="button-secondary">Make read-write</button>
              </form>
            <?php else: ?>
              <form method="post" action="api_keys.php" class="d-inline">
                <input type="hidden" name="csrf"     value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action"   value="set_readonly">
                <input type="hidden" name="key_id"   value="<?= to_int($k['id']) ?>">
                <button type="submit" class="button-secondary">Make read-only</button>
              </form>
            <?php endif; ?>
            <form method="post" action="api_keys.php" class="d-inline"
                  data-confirm="Permanently delete this key?">
              <input type="hidden" name="csrf"     value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action"   value="delete">
              <input type="hidden" name="key_id"   value="<?= to_int($k['id']) ?>">
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
  <div class="empty-state">
    <?= icon('key') ?>
    <p>No API keys configured.</p>
    <p class="muted">Create an API key to enable programmatic read access to your IPAM data via the REST API.</p>
    <a href="api_keys.php" class="action-pill"><?= icon('plus') ?> Create API key</a>
  </div>
<?php endif; ?>

<?php page_footer(); ?>
