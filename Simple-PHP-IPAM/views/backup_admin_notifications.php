<?php
declare(strict_types=1);

/**
 * Notifications tab — backup-event email preferences (editable).
 *
 * v3.22.0 §2.4: GLOBAL-only granularity per event. Per-schedule overrides
 * remain parking-lot work (they need a schedule-edit drawer surface that
 * does not exist yet) — see docs/internal/backup_overhaul.md §2.4.
 *
 * Eight booleans + one integer (overdue grace). Each toggle maps 1:1 to a
 * setting key in ipam_setting_definitions(); ipam_backup_notify_dispatch()
 * reads the setting before sending and returns early if disabled.
 *
 * @var array<string, bool>           $events
 * @var int                           $overdueGraceMinutes
 * @var string                        $alertEmail
 * @var bool                          $smtpEnabled
 * @var list<array{id:int,email:string,username:string}> $alertUsers
 * @var string                        $flash
 * @var string                        $flashKind
 */

// All vars are extract()ed from the controller's $notifyState array; defaults
// applied there. PHPDoc above asserts the shape.

$rows = [
    [
        'key'    => 'success_scheduled',
        'label'  => 'Scheduled-backup success',
        'help'   => 'Email when a cron-fired backup completes successfully. Off by default — typically noisy.',
    ],
    [
        'key'    => 'success_manual',
        'label'  => 'Manual-backup success',
        'help'   => 'Email when an operator-triggered (Run-now) backup completes successfully.',
    ],
    [
        'key'    => 'failure_scheduled',
        'label'  => 'Scheduled-backup failure',
        'help'   => 'Email when a cron-fired backup fails.',
    ],
    [
        'key'    => 'failure_manual',
        'label'  => 'Manual-backup failure',
        'help'   => 'Email when an operator-triggered backup fails.',
    ],
    [
        'key'    => 'destination_conn_failure',
        'label'  => 'Destination connection-test failure',
        'help'   => 'Cron periodically re-tests every active destination. Alert on the first transition from healthy to failing; no follow-up alerts until recovery and re-failure.',
    ],
    [
        'key'    => 'schedule_overdue',
        'label'  => 'Schedule overdue',
        'help'   => 'Alert when a schedule has not fired by its expected next_run_at plus the grace period below.',
    ],
    [
        'key'    => 'retention_prune',
        'label'  => 'Retention-prune summary',
        'help'   => 'Email after retention deletes blobs from a destination. Verbose — off by default.',
    ],
    [
        'key'    => 'encryption_change',
        'label'  => 'Destination encryption-mode change',
        'help'   => 'Email when an admin toggles a destination between encrypted and plaintext.',
    ],
];
?>
<?php if ($flash !== ''): ?>
<div class="alert alert-<?= e($flashKind) ?>" role="status" style="margin-bottom:1rem;">
  <?= e($flash) ?>
</div>
<?php endif; ?>

<section class="card">
  <h3 style="margin-top:0;">Backup notification preferences</h3>
  <p class="muted">
    Notifications use the global <strong>Alert email recipients</strong> list and the existing SMTP delivery pipeline.
    Settings on this tab apply to <strong>all</strong> destinations and schedules; per-schedule overrides are
    future work (see <code>docs/internal/backup_overhaul.md</code> §2.4 parking lot).
  </p>

  <form method="post" action="backup_admin.php?tab=notifications">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_notifications">

    <table class="data-table" style="margin-bottom:1rem;">
      <thead>
        <tr>
          <th scope="col" style="width:30%;">Event</th>
          <th scope="col" style="width:10%;">Notify</th>
          <th scope="col">Description</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row):
            $checked = !empty($events[$row['key']]); ?>
          <tr>
            <th scope="row"
                id="notify-label-<?= e($row['key']) ?>"
                style="text-align:left;"><?= e($row['label']) ?></th>
            <td>
              <label class="toggle-switch" style="display:inline-flex;align-items:center;gap:.4rem;">
                <input type="checkbox"
                       id="event_<?= e($row['key']) ?>"
                       name="event_<?= e($row['key']) ?>"
                       value="1"
                       aria-labelledby="notify-label-<?= e($row['key']) ?>"
                       <?= $checked ? 'checked' : '' ?>>
                <span class="muted" aria-hidden="true"><?= $checked ? 'on' : 'off' ?></span>
              </label>
            </td>
            <td class="muted"><?= e($row['help']) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr>
          <th scope="row" style="text-align:left;">
            <label for="overdue_grace_minutes">Schedule-overdue grace period</label>
          </th>
          <td>
            <input type="number"
                   id="overdue_grace_minutes"
                   name="overdue_grace_minutes"
                   value="<?= e((string) $overdueGraceMinutes) ?>"
                   min="5" max="1440" step="1"
                   style="width:6rem;">
            <span class="muted">min</span>
          </td>
          <td class="muted">
            How many minutes past the expected next_run_at before a schedule is flagged overdue. Min 5, max 1440 (24h).
          </td>
        </tr>
      </tbody>
    </table>

    <div style="display:flex;gap:.5rem;align-items:center;">
      <button type="submit" class="button-primary">Save preferences</button>
      <span class="muted">Changes apply on the next cron tick.</span>
    </div>
  </form>
</section>

<section class="card" style="margin-top:1.5rem;">
  <h3 style="margin-top:0;">Recipients</h3>
  <p class="muted">
    Recipients are managed in <a href="settings.php?tab=general#group-alerts">Settings &rsaquo; Alerts</a> alongside
    the other alert channels (utilization, scanner, etc.). Backup notifications use the same multi-user picker.
  </p>
  <table class="data-table">
    <tbody>
      <tr>
        <th scope="row" style="text-align:left;">SMTP delivery</th>
        <td>
          <?php if ($smtpEnabled): ?>
            <span class="badge badge-success">On</span>
          <?php else: ?>
            <span class="badge badge-failed">Off</span>
            &mdash; <a href="settings.php?tab=integrations#group-smtp">enable SMTP</a> before notifications can deliver.
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th scope="row" style="text-align:left;">Selected alert users</th>
        <td>
          <?php if ($alertUsers === []): ?>
            <span class="muted">No users selected.</span>
            <?php if ($alertEmail !== ''): ?>
              Falling back to legacy <code>alert_email</code>: <?= e($alertEmail) ?>
            <?php endif; ?>
          <?php else: ?>
            <ul style="margin:0;padding-left:1.2rem;">
              <?php foreach ($alertUsers as $u): ?>
                <li><?= e($u['username']) ?> &lt;<?= e($u['email']) ?>&gt;</li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </td>
      </tr>
    </tbody>
  </table>
</section>
