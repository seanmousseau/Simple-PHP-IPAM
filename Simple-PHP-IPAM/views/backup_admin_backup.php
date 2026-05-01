<?php
declare(strict_types=1);

/**
 * Backup tab — manual run trigger. Schedule management lives in the
 * Destinations tab (per backup_overhaul.md §2.1 / §2.3 — the schedule editor
 * is colocated with the destination row that owns it).
 *
 * The Backup tab shows a destination selector + "Run backup now" button. The
 * button reuses the existing data-run-now JS handler from app.js, which POSTs
 * to run_backup_now.php and renders the result inline (no redirect — #801).
 *
 * @var list<array<string, mixed>> $destinations  Active destinations only
 */
?>
<section class="card">
  <h3 style="margin-top:0;">Run a backup now</h3>
  <p class="muted">
    Trigger an immediate backup to one of your active destinations. Progress is
    reported inline; you do not need to leave this page.
  </p>

  <?php if (count($destinations) === 0): ?>
    <p class="muted">
      No active destinations configured. Add one under the
      <a href="backup_admin.php?tab=destinations">Destinations tab</a> first.
    </p>
  <?php else: ?>
    <div class="run-now-bar">
      <label for="run-now-destination">Destination
        <select id="run-now-destination">
          <?php foreach ($destinations as $d): ?>
            <?php $dId = to_int($d['id']); ?>
            <option value="<?= $dId ?>"><?= e(to_str($d['name'])) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button id="run-now-button" type="button" class="action-pill"
              data-run-now-target="run-now-destination"
              data-run-now-result="run-now-result">Run backup now</button>
      <span id="run-now-result" class="muted" aria-live="polite"></span>
    </div>
  <?php endif; ?>
</section>

<section class="card">
  <h3 style="margin-top:0;">Schedules</h3>
  <p class="muted">
    Scheduled backups are configured per destination. Manage them on the
    <a href="backup_admin.php?tab=destinations">Destinations tab</a>.
  </p>
</section>
