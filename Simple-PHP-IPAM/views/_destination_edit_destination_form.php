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
  <label class="checkbox"><input type="checkbox" name="encrypt" <?= to_int($dest['encrypt']) === 1 ? 'checked' : '' ?>> Encrypt with AES-256-GCM (recommended)</label>
  <?php $cfg = $config; ?>
  <fieldset>
    <legend><?= e(strtoupper($destType)) ?> connection</legend>
    <?php require __DIR__ . '/destination_form_' . $destType . '.php'; ?>
  </fieldset>
  <div class="drawer-actions">
    <button type="submit" class="action-pill">Save changes</button>
  </div>
</form>
