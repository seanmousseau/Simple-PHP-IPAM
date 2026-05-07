<?php
/**
 * Destination edit form rendered into the global drawer.
 *
 * @var array<string,mixed> $dest    Row from backup_destinations.
 * @var array<string,mixed> $config  Decoded config JSON.
 */
$destId   = to_int($dest['id']);
$destType = to_str($dest['type']);
?>
<form action="backup_admin.php?tab=destinations" method="post" class="destination-form destination-edit-form drawer-form">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="update_destination">
  <input type="hidden" name="id" value="<?= $destId ?>">
  <input type="hidden" name="type" value="<?= e($destType) ?>">
  <label>Name <input type="text" name="name" value="<?= e(to_str($dest['name'])) ?>" required maxlength="100"></label>
  <label>Type
    <input type="text" value="<?= e(strtoupper($destType)) ?>" disabled readonly>
    <small class="muted">Type is locked. Delete and recreate to change.</small>
  </label>
  <?php $cfg = $config; ?>
  <fieldset>
    <legend><?= e(strtoupper($destType)) ?> connection</legend>
    <?php
    // CR feedback PR #1054: fail-closed allowlist before dynamic require.
    // `$destType` should already be constrained by backup_destinations.type CHECK,
    // but defense in depth — never let a hostile DB row LFI this template.
    $allowedTypes = ['s3', 'sftp', 'local'];
    if (!in_array($destType, $allowedTypes, true)) {
        throw new RuntimeException('Unsupported destination type: ' . $destType);
    }
    $partial = __DIR__ . '/destination_form_' . $destType . '.php';
    if (!is_file($partial)) {
        throw new RuntimeException('Missing destination form partial: ' . $destType);
    }
    require $partial;
    ?>
  </fieldset>
  <?php
    // v3.25.0 #1076 #851 #846 #848: shared picker fields prefilled from the
    // destination row. The legacy `encrypt` checkbox is replaced by the
    // encryption-mode radio group (#851).
    $picker = [
        'type'                    => $destType,
        'default_backup_type'     => $dest['default_backup_type']     ?? null,
        'default_encryption_mode' => $dest['default_encryption_mode'] ?? null,
        'retention_hourly'        => $dest['retention_hourly']        ?? null,
        'retention_daily'         => $dest['retention_daily']         ?? null,
        'retention_weekly'        => $dest['retention_weekly']        ?? null,
        'retention_monthly'       => $dest['retention_monthly']       ?? null,
        'is_default'              => $dest['is_default']              ?? 0,
    ];
    require __DIR__ . '/_destination_picker_fields.php';
  ?>
  <div class="drawer-actions">
    <button type="submit" class="action-pill">Save changes</button>
  </div>
</form>
