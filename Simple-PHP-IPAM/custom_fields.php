<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';

/** @var list<string> $validTypes */
$validTypes = ['text', 'number', 'date', 'boolean', 'select'];
/** @var list<string> $validEntityTypes */
$validEntityTypes = ['subnet', 'address'];

/** @var array<string, string> $typeLabels */
$typeLabels = [
    'text'    => 'Text',
    'number'  => 'Number',
    'date'    => 'Date',
    'boolean' => 'Yes / No',
    'select'  => 'Select',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = to_str($_POST['action'] ?? '');

    if (demo_mode_enabled() && in_array($action, ['create', 'update', 'delete'], true)) {
        $err = 'This action is disabled in demo mode.';
        $action = '';
    }

    // v3.28.0 (#1158) — sudo-gate custom field definition create/update/delete.
    // A field definition mutates the shape of every subnet/address edit form
    // and (for delete) drops a column's worth of metadata. Gate BEFORE input
    // validation; round-trip the raw POST so the action resumes after
    // verification.
    if (in_array($action, ['create', 'update', 'delete'], true)) {
        $sudoUid = to_int((current_user()['id']) ?? 0);
        if (!ipam_sudo_require($db, $sudoUid)) {
            page_header('Confirm your identity');
            $stepUpUserId       = $sudoUid;
            $stepUpFormAction   = 'custom_fields.php';
            $stepUpHiddenFields = ['action' => $action];
            foreach ($_POST as $pk => $pv) {
                $pkS = (string) $pk;
                if ($pkS === 'csrf' || $pkS === 'action' || str_starts_with($pkS, '_sudo_')) continue;
                if (is_scalar($pv)) {
                    $stepUpHiddenFields[$pkS] = (string) $pv;
                } elseif (is_array($pv)) {
                    foreach ($pv as $i => $v) {
                        if (is_scalar($v)) $stepUpHiddenFields[$pkS . '[' . (string) $i . ']'] = (string) $v;
                    }
                }
            }
            $stepUpDescription  = $action === 'create'
                ? 'Re-authenticate to create a custom field definition. It changes the shape of every subnet or address edit form.'
                : ($action === 'update'
                    ? 'Re-authenticate to update a custom field definition.'
                    : 'Re-authenticate to delete a custom field definition.');
            $stepUpReturnPath   = 'custom_fields.php';
            $stepUpError        = isset($_POST['_sudo_method']) ? 'Verification failed. The custom field definition was not changed.' : '';
            include __DIR__ . '/views/_step_up_prompt.php';
            page_footer();
            exit;
        }
        ipam_sudo_consume_once();
    }

    if ($action === 'create') {
        $entityType = to_str($_POST['entity_type'] ?? '');
        $key        = trim(to_str($_POST['key']         ?? ''));
        $label      = trim(to_str($_POST['label']       ?? ''));
        $type       = to_str($_POST['type']             ?? 'text');
        $sortOrder  = to_int($_POST['sort_order']       ?? 0);
        $isRequired = isset($_POST['is_required']) ? 1 : 0;
        $rawOptions = trim(to_str($_POST['options']     ?? ''));

        if (!in_array($entityType, $validEntityTypes, true)) {
            $err = 'Invalid entity type.';
        } elseif (!preg_match('/^[a-z][a-z0-9_]{0,62}$/', $key)) {
            $err = 'Key must start with a lowercase letter and contain only lowercase letters, digits, or underscores (max 63 characters).';
        } elseif ($label === '') {
            $err = 'Label is required.';
        } elseif (!in_array($type, $validTypes, true)) {
            $err = 'Invalid field type.';
        } else {
            $options = null;
            if ($type === 'select') {
                $opts = array_values(array_filter(array_map('trim', explode("\n", $rawOptions))));
                if (!$opts) {
                    $err = 'Select fields require at least one option (one per line).';
                } else {
                    $options = json_encode($opts, JSON_UNESCAPED_SLASHES);
                    if ($options === false) {
                        $err = 'Could not encode options.';
                    }
                }
            }
            if ($err === '') {
                try {
                    $kc = ipam_key_col();
                    $st = $db->prepare(
                        "INSERT INTO custom_field_defs
                             (entity_type, $kc, label, type, options, sort_order, is_required)
                         VALUES (:et, :k, :lbl, :t, :opts, :so, :req)"
                    );
                    $st->execute([
                        ':et'   => $entityType,
                        ':k'    => $key,
                        ':lbl'  => $label,
                        ':t'    => $type,
                        ':opts' => $options,
                        ':so'   => $sortOrder,
                        ':req'  => $isRequired,
                    ]);
                    $newId = ipam_last_insert_id($db, 'custom_field_defs');
                    audit($db, 'custom_field.create', 'custom_field_def', $newId,
                          "entity_type=$entityType key=$key type=$type");
                    flash_set("Custom field \"{$label}\" created.");
                    header('Location: custom_fields.php');
                    exit;
                } catch (PDOException $e) {
                    $err = 'Could not create field — a field with this key already exists for this entity type.';
                }
            }
        }
    } elseif ($action === 'update') {
        $id         = to_int($_POST['id']         ?? 0);
        $label      = trim(to_str($_POST['label'] ?? ''));
        $sortOrder  = to_int($_POST['sort_order'] ?? 0);
        $isRequired = isset($_POST['is_required']) ? 1 : 0;
        $rawOptions = trim(to_str($_POST['options'] ?? ''));

        if ($id <= 0 || $label === '') {
            $err = 'Valid id and label are required.';
        } else {
            $defSt = $db->prepare("SELECT * FROM custom_field_defs WHERE id = :id AND is_deleted = 0");
            $defSt->execute([':id' => $id]);
            /** @var array<string,mixed>|false $def */
            $def = $defSt->fetch();
            if (!$def) {
                $err = 'Field definition not found.';
            } else {
                $options = to_str($def['options'] ?? '');
                if ($options === '') $options = 'null';
                if (to_str($def['type']) === 'select') {
                    $opts = array_values(array_filter(array_map('trim', explode("\n", $rawOptions))));
                    if (!$opts) {
                        $err = 'Select fields require at least one option (one per line).';
                    } else {
                        $encoded = json_encode($opts, JSON_UNESCAPED_SLASHES);
                        $options = $encoded !== false ? $encoded : '[]';
                    }
                }
                if ($err === '') {
                    $now = ipam_dialect()->now();
                    $st  = $db->prepare(
                        "UPDATE custom_field_defs
                         SET label = :lbl, options = :opts, sort_order = :so,
                             is_required = :req, updated_at = {$now}
                         WHERE id = :id"
                    );
                    $st->execute([
                        ':lbl'  => $label,
                        ':opts' => $options === 'null' ? null : $options,
                        ':so'   => $sortOrder,
                        ':req'  => $isRequired,
                        ':id'   => $id,
                    ]);
                    audit($db, 'custom_field.update', 'custom_field_def', $id, "label=$label");
                    flash_set("Custom field \"{$label}\" updated.");
                    header('Location: custom_fields.php');
                    exit;
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = to_int($_POST['id'] ?? 0);
        if ($id > 0) {
            $defSt = $db->prepare("SELECT * FROM custom_field_defs WHERE id = :id AND is_deleted = 0");
            $defSt->execute([':id' => $id]);
            /** @var array<string,mixed>|false $def */
            $def = $defSt->fetch();
            if ($def && custom_field_in_use($db, to_str($def['key']), to_str($def['entity_type']))) {
                $tblLabel = to_str($def['entity_type']) === 'subnet' ? 'subnets' : 'addresses';
                $err = 'Cannot delete "' . to_str($def['label']) . '" — one or more '
                     . $tblLabel . ' have values set for this field. Clear all values first, then delete.';
            } elseif ($def) {
                $db->prepare("DELETE FROM custom_field_defs WHERE id = :id")->execute([':id' => $id]);
                audit($db, 'custom_field.delete', 'custom_field_def', $id,
                      'entity_type=' . to_str($def['entity_type']) . ' key=' . to_str($def['key']));
                flash_set('Custom field deleted.');
                header('Location: custom_fields.php');
                exit;
            }
        }
    }
}

// ── GET ───────────────────────────────────────────────────────────────────
$filterType = in_array(to_str($_GET['type'] ?? ''), $validEntityTypes, true)
    ? to_str($_GET['type'])
    : '';

$defs = custom_field_def_list($db, $filterType !== '' ? $filterType : null);

$countSubnet  = (int)(($db->query(
    "SELECT count(*) FROM custom_field_defs WHERE is_deleted = 0 AND entity_type = 'subnet'"
) ?: throw new \RuntimeException('Query failed'))->fetchColumn());
$countAddress = (int)(($db->query(
    "SELECT count(*) FROM custom_field_defs WHERE is_deleted = 0 AND entity_type = 'address'"
) ?: throw new \RuntimeException('Query failed'))->fetchColumn());

$flash = flash_get();
page_header('Custom Fields');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="#">Admin</a><span class="sep">›</span>
  <span>Custom Fields</span>
</div>

<div class="toolbar">
  <div>
    <h1>Custom Fields</h1>
    <div class="muted">Define extra metadata fields that appear on subnet and address edit forms.</div>
  </div>
</div>

<?php if ($flash): ?>
<p class="<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['msg']) ?></p>
<?php endif; ?>
<?php if ($err): ?>
<p class="danger" role="alert"><?= e($err) ?></p>
<?php endif; ?>

<!-- Entity-type filter tabs -->
<div class="row mt-8 mb-8" style="gap:var(--space-2)">
  <a class="action-pill subnet-view-btn<?= $filterType === '' ? ' active' : '' ?>"
     href="custom_fields.php">All</a>
  <a class="action-pill subnet-view-btn<?= $filterType === 'subnet' ? ' active' : '' ?>"
     href="custom_fields.php?type=subnet">Subnet</a>
  <a class="action-pill subnet-view-btn<?= $filterType === 'address' ? ' active' : '' ?>"
     href="custom_fields.php?type=address">Address</a>
</div>

<div class="grid cols-2">
  <!-- ── Add form ──────────────────────────────────────────────────────── -->
  <div class="card" id="add-field">
    <h2>Add Custom Field</h2>
    <form method="post" action="custom_fields.php" id="cf-add-form">
      <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">

      <div class="row">
        <label>Entity type<br>
          <select name="entity_type" required>
            <option value="subnet">Subnet</option>
            <option value="address">Address</option>
          </select>
        </label>
        <label class="flex-1">Key (slug)<br>
          <input name="key" required maxlength="63"
                 pattern="[a-z][a-z0-9_]{0,62}"
                 placeholder="e.g. cost_centre"
                 title="Lowercase letters, digits, underscores; starts with a letter">
        </label>
      </div>

      <div class="row">
        <label class="flex-1">Label (display name)<br>
          <input name="label" required placeholder="e.g. Cost Centre" class="w-full">
        </label>
        <label>Type<br>
          <select name="type" id="cf-type-select">
            <option value="text">Text</option>
            <option value="number">Number</option>
            <option value="date">Date</option>
            <option value="boolean">Yes / No</option>
            <option value="select">Select</option>
          </select>
        </label>
      </div>

      <div class="row">
        <label class="mw-80">Sort order<br>
          <input type="number" name="sort_order" value="0" min="0" max="9999">
        </label>
        <label style="align-self:flex-end;padding-bottom:4px">
          <input type="checkbox" name="is_required"> Required
        </label>
      </div>

      <!-- Options editor — shown only for select type -->
      <div id="cf-options-row" hidden>
        <label class="flex-1">Options (one per line)<br>
          <textarea name="options" id="cf-options" rows="4"
                    placeholder="Option A&#10;Option B&#10;Option C"
                    class="w-full" style="resize:vertical"></textarea>
        </label>
      </div>

      <!-- Live type preview -->
      <div class="mt-8" id="cf-preview-wrap">
        <div class="muted" style="font-size:.8rem;margin-bottom:4px">Preview (what users will see):</div>
        <div id="cf-preview-text"><input type="text" placeholder="Free text value" disabled style="width:100%;max-width:280px"></div>
        <div id="cf-preview-number" hidden><input type="number" step="any" placeholder="0" disabled style="width:120px"></div>
        <div id="cf-preview-date"   hidden><input type="date" disabled></div>
        <div id="cf-preview-boolean" hidden><label style="display:inline-flex;align-items:center;gap:6px"><input type="checkbox" disabled> Yes / No</label></div>
        <div id="cf-preview-select" hidden>
          <select disabled>
            <option>— select an option —</option>
          </select>
        </div>
      </div>

      <p><button type="submit">Create Field</button></p>
    </form>
  </div>

  <!-- ── Overview metrics ─────────────────────────────────────────────── -->
  <div class="card">
    <h2>Overview</h2>
    <div class="grid cols-2">
      <div class="metric">
        <div class="label">Subnet fields</div>
        <div class="value"><?= $countSubnet ?></div>
      </div>
      <div class="metric">
        <div class="label">Address fields</div>
        <div class="value"><?= $countAddress ?></div>
      </div>
    </div>
    <p class="muted mt-8">
      Custom fields appear on the subnet and address edit forms once defined. Fields marked
      <strong>Required</strong> must be filled before saving.
      Deleting a field that has values set on existing records is blocked — clear the values
      first via bulk update, then delete the definition.
    </p>
  </div>
</div>

<!-- ── Definitions table ──────────────────────────────────────────────── -->
<div class="card mt-16">
  <h2>Field Definitions<?php if ($filterType !== ''): ?> <span class="badge badge-muted"><?= e(ucfirst($filterType)) ?></span><?php endif; ?></h2>

  <?php if (!$defs): ?>
    <div class="empty-state">
      No custom fields<?= $filterType !== '' ? ' for ' . e($filterType) . 's' : '' ?> yet.
      <a class="action-pill" href="#add-field">+ Add Field</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Entity</th>
          <th>Key</th>
          <th>Label</th>
          <th>Type</th>
          <th>Sort</th>
          <th>Required</th>
          <th>Options</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($defs as $def): ?>
        <?php
        $defId         = to_int($def['id']);
        $defEntity     = to_str($def['entity_type']);
        $defKey        = to_str($def['key']);
        $defLabel      = to_str($def['label']);
        $defType       = to_str($def['type']);
        $defSort       = to_int($def['sort_order']);
        $defRequired   = to_int($def['is_required']);
        $defOptionsRaw = to_str($def['options'] ?? '');
        /** @var list<string>|null $defOptsArr */
        $defOptsArr    = $defOptionsRaw !== '' ? json_decode($defOptionsRaw, true) : null;
        $defOptsText   = is_array($defOptsArr) ? implode("\n", $defOptsArr) : '';
        $entityBadge   = $defEntity === 'subnet'
            ? "<span class='badge badge-success'>Subnet</span>"
            : "<span class='badge badge-muted'>Address</span>";
        ?>
        <tr>
          <td><?= $entityBadge ?></td>
          <td><code class="monospace"><?= e($defKey) ?></code></td>
          <td><?= e($defLabel) ?></td>
          <td><?= e($typeLabels[$defType] ?? $defType) ?></td>
          <td class="muted"><?= $defSort ?></td>
          <td><?= $defRequired ? '✓' : '<span class="muted">—</span>' ?></td>
          <td class="muted">
            <?php if ($defType === 'select' && is_array($defOptsArr)): ?>
              <?= count($defOptsArr) ?> option<?= count($defOptsArr) !== 1 ? 's' : '' ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <details>
              <summary>Edit / Delete</summary>

              <!-- Edit form (label, sort_order, is_required, options) -->
              <!-- key, entity_type, type are immutable after creation -->
              <form method="post" action="custom_fields.php" class="mt-8">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id"     value="<?= $defId ?>">
                <div class="row">
                  <label>Label<br><input name="label" value="<?= e($defLabel) ?>" required></label>
                  <label class="mw-80">Sort order<br>
                    <input type="number" name="sort_order" value="<?= $defSort ?>" min="0" max="9999">
                  </label>
                  <label style="align-self:flex-end;padding-bottom:4px">
                    <input type="checkbox" name="is_required"<?= $defRequired ? ' checked' : '' ?>> Required
                  </label>
                </div>
                <?php if ($defType === 'select'): ?>
                <div class="row">
                  <label class="flex-1">Options (one per line)<br>
                    <textarea name="options" rows="4" class="w-full"
                              style="resize:vertical"><?= e($defOptsText) ?></textarea>
                  </label>
                </div>
                <?php endif; ?>
                <button type="submit">Save</button>
              </form>

              <!-- Delete form -->
              <form method="post" action="custom_fields.php" class="mt-8"
                    data-confirm="Delete custom field &ldquo;<?= e($defLabel) ?>&rdquo;? This cannot be undone.">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= $defId ?>">
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
