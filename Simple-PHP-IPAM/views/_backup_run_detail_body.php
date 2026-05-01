<?php
/**
 * Drawer body partial for backup_runs row detail (#803, F11).
 *
 * @var array<string,mixed>  $row       backup_runs row joined to dest name/type
 * @var array<string,bool>   $disabled  per-action disabled flags
 * @var array<string,string> $tooltip   per-action title attribute (when disabled)
 */
declare(strict_types=1);

$runId = to_int($row['id']);
$status = to_str($row['status']);
?>
<div class="drawer-meta">
  <h3 class="drawer-title-line">Run #<?= $runId ?> &mdash; <?= e($status) ?></h3>
  <dl class="kv-grid">
    <dt>Started</dt>
    <dd><?= e(ipam_format_datetime(to_str($row['started_at']))) ?></dd>
    <dt>Finished</dt>
    <dd>
      <?php if (!empty($row['completed_at'])): ?>
        <?= e(ipam_format_datetime(to_str($row['completed_at']))) ?>
      <?php else: ?>
        <em class="muted">in progress</em>
      <?php endif; ?>
    </dd>
    <dt>Trigger</dt>
    <dd><?= e(to_str($row['triggered_by'])) ?></dd>
    <dt>Type</dt>
    <dd><?= e(ucfirst(to_str($row['backup_type']))) ?> &middot; <?= e(ucfirst(to_str($row['encryption_mode']))) ?></dd>
    <dt>Destination</dt>
    <dd>
      <?php if (!empty($row['dest_name'])): ?>
        <?= e(to_str($row['dest_name'])) ?> (<?= e(to_str($row['dest_type'])) ?>)
      <?php else: ?>
        <em class="muted">deleted</em>
      <?php endif; ?>
    </dd>
  </dl>
</div>

<?php if (!empty($row['filename'])): ?>
<div class="drawer-section">
  <h4>Artifact</h4>
  <p><code><?= e(to_str($row['filename'])) ?></code></p>
  <p class="muted">
    <?= number_format(to_int($row['size_bytes'] ?? 0)) ?> bytes
    <?php if (!empty($row['checksum'])): ?>
      &middot; sha256:<?= e(substr(to_str($row['checksum']), 0, 12)) ?>&hellip;
    <?php endif; ?>
  </p>
</div>
<?php endif; ?>

<?php if (!empty($row['error_message'])): ?>
<div class="drawer-section">
  <h4>Error</h4>
  <pre class="drawer-error-text"><?= e(to_str($row['error_message'])) ?></pre>
</div>
<?php endif; ?>

<?php
// Download is a normal browser navigation through the existing
// download_remote_backup.php endpoint; render as <a> when enabled,
// <button disabled> when not (so disabled-state matrix is uniform).
$downloadHref = 'download_remote_backup.php?run_id=' . $runId;
?>
<form id="backup-run-actions" data-run-id="<?= $runId ?>">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <div class="drawer-actions">
    <button type="button" class="action-pill" data-action="verify"
            <?= $disabled['verify'] ? 'disabled' : '' ?>
            <?= ($disabled['verify'] && $tooltip['verify'] !== '') ? 'title="' . e($tooltip['verify']) . '"' : '' ?>>Verify</button>

    <?php if ($disabled['download']): ?>
      <button type="button" class="action-pill" data-action="download" disabled
              <?= $tooltip['download'] !== '' ? 'title="' . e($tooltip['download']) . '"' : '' ?>>Download</button>
    <?php else: ?>
      <a class="action-pill" data-action="download" href="<?= e($downloadHref) ?>">Download</a>
    <?php endif; ?>

    <button type="button" class="action-pill button-danger" data-action="delete"
            <?= $disabled['delete'] ? 'disabled' : '' ?>
            <?= ($disabled['delete'] && $tooltip['delete'] !== '') ? 'title="' . e($tooltip['delete']) . '"' : '' ?>>Delete</button>
  </div>
  <div class="drawer-action-result" id="drawer-action-result" hidden></div>
</form>
