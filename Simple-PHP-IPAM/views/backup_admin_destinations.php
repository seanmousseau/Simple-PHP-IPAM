<?php
declare(strict_types=1);

/**
 * Destinations + schedules render block. Shared by destinations.php (legacy)
 * and backup_admin.php?tab=destinations (unified surface, v3.21.0 Wave 4).
 *
 * @var string                            $err
 * @var string                            $flash
 * @var list<array<string, mixed>>        $destinations
 * @var list<array<string, mixed>>        $schedules
 * @var int                               $flashTestId
 * @var bool                              $flashTestOk
 * @var string                            $flashTestMsg
 * @var int|null                          $flashTestLatency
 */
?>
  <?php if ($err !== ''): ?>
    <div class="card danger"><?= e($err) ?></div>
  <?php endif; ?>
  <?php if ($flash !== ''): ?>
    <div class="card success"><?= e($flash) ?></div>
  <?php endif; ?>

  <p class="muted">Configure where backups are sent. Each destination can have a schedule for automatic runs.</p>

  <!-- Destinations list -->
  <section class="card">
    <h2>Destinations</h2>

    <?php if (count($destinations) === 0): ?>
      <p class="muted">No destinations configured yet.</p>
    <?php else: ?>
      <table class="data-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Type</th>
            <th>Encrypt</th>
            <th>Active</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($destinations as $d):
              $destId   = to_int($d['id']);
              $destType = to_str($d['type']);
              $decoded  = json_decode(to_str($d['config']), true);
              /** @var array<string, mixed> $destCfg */
              $destCfg  = is_array($decoded) ? $decoded : [];
          ?>
            <tr>
              <td><?= e(to_str($d['name'])) ?></td>
              <td><span class="badge badge-type-<?= e($destType) ?>"><?= e(strtoupper($destType)) ?></span></td>
              <td><?= to_int($d['encrypt']) === 1 ? 'Yes' : 'No' ?></td>
              <td><?= to_int($d['is_active']) === 1 ? 'Active' : 'Disabled' ?></td>
              <td class="actions">
                <?php
                if ($flashTestId === $destId && $flashTestMsg !== '') {
                    $latencySuffix = ($flashTestLatency !== null && $flashTestLatency >= 0)
                        ? ' (' . (int) $flashTestLatency . ' ms)'
                        : '';
                    $badgeText  = ($flashTestOk ? '✓ ' : '✗ ') . $flashTestMsg . $latencySuffix;
                    $badgeClass = $flashTestOk ? 'badge-success' : 'badge-failed';
                ?>
                  <span class="badge <?= e($badgeClass) ?>" data-auto-test-result>
                    <?= e($badgeText) ?>
                  </span>
                <?php } ?>
                <button class="action-pill" type="button" data-edit-destination="<?= $destId ?>" aria-controls="edit-destination-<?= $destId ?>" aria-expanded="false">Edit</button>
                <button class="action-pill" data-test-destination="<?= $destId ?>">Test</button>
                <button class="action-pill" data-run-now="<?= $destId ?>">Run now</button>
                <form method="post" style="display:inline" data-confirm-delete="this destination (schedules will be removed)">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete_destination">
                  <input type="hidden" name="id" value="<?= $destId ?>">
                  <button class="action-pill button-danger" type="submit">Delete</button>
                </form>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="toggle_active_destination">
                  <input type="hidden" name="id" value="<?= $destId ?>">
                  <button class="action-pill button-secondary" type="submit">
                    <?= to_int($d['is_active']) === 1 ? 'Disable' : 'Enable' ?>
                  </button>
                </form>
              </td>
            </tr>
            <tr id="edit-destination-<?= $destId ?>" class="edit-destination-row" hidden>
              <td colspan="5">
                <form method="post" class="destination-form destination-edit-form">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="update_destination">
                  <input type="hidden" name="id" value="<?= $destId ?>">
                  <input type="hidden" name="type" value="<?= e($destType) ?>">
                  <label>Name <input type="text" name="name" value="<?= e(to_str($d['name'])) ?>" required maxlength="100"></label>
                  <label>Type
                    <input type="text" value="<?= e(strtoupper($destType)) ?>" disabled readonly>
                    <small class="muted">Type is locked. Delete and recreate to change.</small>
                  </label>
                  <label class="checkbox"><input type="checkbox" name="encrypt" <?= to_int($d['encrypt']) === 1 ? 'checked' : '' ?>> Encrypt with AES-256-GCM (recommended)</label>
                  <?php $cfg = $destCfg; ?>
                  <fieldset>
                    <legend><?= e(strtoupper($destType)) ?> connection</legend>
                    <?php require __DIR__ . '/destination_form_' . $destType . '.php'; ?>
                  </fieldset>
                  <button type="submit" class="action-pill">Save changes</button>
                  <button type="button" class="action-pill button-secondary" data-edit-destination-cancel="<?= $destId ?>">Cancel</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h3 style="margin-top:1.5rem">Add a destination</h3>
    <form method="post" class="destination-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create_destination">
      <label>Name <input type="text" name="name" required maxlength="100"></label>
      <label>Type
        <select name="type" required data-destination-type-selector>
          <option value="s3">S3-compatible</option>
          <option value="sftp">SFTP</option>
          <option value="local">Local filesystem</option>
        </select>
      </label>
      <label class="checkbox"><input type="checkbox" name="encrypt" checked> Encrypt with AES-256-GCM (recommended)</label>

      <fieldset class="destination-fields" data-type="s3">
        <legend>S3 connection</legend>
        <?php $cfg = []; require __DIR__ . '/destination_form_s3.php'; ?>
      </fieldset>
      <fieldset class="destination-fields" data-type="sftp" hidden>
        <legend>SFTP connection</legend>
        <?php $cfg = []; require __DIR__ . '/destination_form_sftp.php'; ?>
      </fieldset>
      <fieldset class="destination-fields" data-type="local" hidden>
        <legend>Local filesystem</legend>
        <?php $cfg = []; require __DIR__ . '/destination_form_local.php'; ?>
      </fieldset>

      <button type="submit" class="action-pill">Create destination</button>
    </form>
  </section>

  <!-- Schedules list -->
  <section class="card">
    <h2>Schedules</h2>

    <?php if (count($schedules) === 0): ?>
      <p class="muted">No schedules configured.</p>
    <?php else: ?>
      <?php
        $destById = [];
        foreach ($destinations as $d) {
            $destById[to_int($d['id'])] = to_str($d['name']);
        }
      ?>
      <table class="data-table">
        <thead>
          <tr>
            <th>Destination</th>
            <th>Frequency</th>
            <th>Time/Day</th>
            <th>Retention (h/d/w/m)</th>
            <th>Last run</th>
            <th>Next run</th>
            <th>Active</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($schedules as $s):
            $destName = $destById[to_int($s['destination_id'])] ?? 'unknown';
            $freq     = to_str($s['frequency']);
            if ($freq === 'weekly') {
                $when = 'DOW ' . to_int($s['day_of_week']) . ' @ ' . to_str($s['time_of_day']);
            } elseif ($freq === 'monthly') {
                $when = 'Day ' . to_int($s['day_of_month']) . ' @ ' . to_str($s['time_of_day']);
            } elseif ($freq === 'daily') {
                $when = '@ ' . to_str($s['time_of_day']);
            } else {
                $when = '—';
            }
          ?>
            <?php $schedId = to_int($s['id']); ?>
            <tr>
              <td><?= e($destName) ?></td>
              <td><?= e($freq) ?></td>
              <td><?= e($when) ?></td>
              <td><?= to_int($s['retention_hourly']) ?>/<?= to_int($s['retention_daily']) ?>/<?= to_int($s['retention_weekly']) ?>/<?= to_int($s['retention_monthly']) ?></td>
              <td><?= e(ipam_format_datetime(to_str($s['last_run_at'] ?? '')) ?: '—') ?></td>
              <td><?= e(ipam_format_datetime(to_str($s['next_run_at'] ?? '')) ?: '—') ?></td>
              <td><?= to_int($s['is_active']) === 1 ? 'Active' : 'Disabled' ?></td>
              <td class="actions">
                <button class="action-pill" type="button" data-edit-schedule="<?= $schedId ?>" aria-controls="edit-schedule-<?= $schedId ?>" aria-expanded="false">Edit</button>
                <button class="action-pill" data-run-now="<?= to_int($s['destination_id']) ?>">Run now</button>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="toggle_active_schedule">
                  <input type="hidden" name="id" value="<?= $schedId ?>">
                  <button class="action-pill button-secondary" type="submit">
                    <?= to_int($s['is_active']) === 1 ? 'Disable' : 'Enable' ?>
                  </button>
                </form>
                <form method="post" style="display:inline" data-confirm-delete="this schedule">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete_schedule">
                  <input type="hidden" name="id" value="<?= $schedId ?>">
                  <button class="action-pill button-danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
            <tr id="edit-schedule-<?= $schedId ?>" class="edit-schedule-row" hidden>
              <td colspan="8">
                <form method="post" class="schedule-form schedule-edit-form">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="update_schedule">
                  <input type="hidden" name="id" value="<?= $schedId ?>">
                  <label>Frequency
                    <select name="frequency" required>
                      <option value="hourly"  <?= $freq === 'hourly'  ? 'selected' : '' ?>>Hourly</option>
                      <option value="daily"   <?= $freq === 'daily'   ? 'selected' : '' ?>>Daily</option>
                      <option value="weekly"  <?= $freq === 'weekly'  ? 'selected' : '' ?>>Weekly</option>
                      <option value="monthly" <?= $freq === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                    </select>
                  </label>
                  <label data-freq-field="time_of_day">Time of day (UTC, HH:MM) <input type="text" name="time_of_day" value="<?= e(to_str($s['time_of_day'])) ?>" pattern="(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]"></label>
                  <label data-freq-field="day_of_week">Day of week (Sun=0..Sat=6) <input type="number" name="day_of_week" min="0" max="6" value="<?= to_int($s['day_of_week']) ?>"></label>
                  <label data-freq-field="day_of_month">Day of month (1-28) <input type="number" name="day_of_month" min="1" max="28" value="<?= to_int($s['day_of_month']) ?>"></label>
                  <label>Retain hourly  <input type="number" name="retention_hourly"  min="0" value="<?= to_int($s['retention_hourly'])  ?>"></label>
                  <label>Retain daily   <input type="number" name="retention_daily"   min="0" value="<?= to_int($s['retention_daily'])   ?>"></label>
                  <label>Retain weekly  <input type="number" name="retention_weekly"  min="0" value="<?= to_int($s['retention_weekly'])  ?>"></label>
                  <label>Retain monthly <input type="number" name="retention_monthly" min="0" value="<?= to_int($s['retention_monthly']) ?>"></label>
                  <button type="submit" class="action-pill">Save changes</button>
                  <button type="button" class="action-pill button-secondary" data-edit-schedule-cancel="<?= $schedId ?>">Cancel</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h3 style="margin-top:1.5rem">Add a schedule</h3>
    <?php require __DIR__ . '/schedule_form.php'; ?>
  </section>
