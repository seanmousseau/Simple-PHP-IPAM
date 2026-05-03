<?php
declare(strict_types=1);

/**
 * Notifications tab — backup-event email preferences (editable).
 *
 * Two stacked sections:
 *   1. Global event toggles (8 booleans + overdue-grace minutes). Each maps
 *      to a `backup.notify_*` setting key; ipam_backup_notify_dispatch()
 *      reads them before sending.
 *   2. Per-schedule overrides (v3.23.0 #825): each schedule may opt to
 *      override the global failure/success defaults and pin its own
 *      recipient CSV. Tri-state per field (Inherit / On / Off) so an admin
 *      can override a subset.
 *
 * @var array<string, bool>           $events
 * @var int                           $overdueGraceMinutes
 * @var string                        $alertEmail
 * @var bool                          $smtpEnabled
 * @var list<array{id:int,email:string,username:string}> $alertUsers
 * @var list<array{
 *        id:int, destination_name:string, frequency:string, time_of_day:string,
 *        is_active:bool, override:bool,
 *        failure_state:string, success_state:string, recipients:string
 *      }> $scheduleOverrides
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
    These global toggles apply to every destination and schedule by default.
    Per-schedule overrides (below) can promote or suppress notifications for individual schedules without
    affecting the rest. Notifications use the global <strong>Alert email recipients</strong> list and the
    existing SMTP delivery pipeline unless a schedule pins its own recipient CSV.
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

<section class="card" style="margin-top:1.5rem;">
  <h3 style="margin-top:0;">Per-schedule overrides</h3>
  <p class="muted">
    Each schedule can override the <em>Scheduled-backup failure</em> and <em>Scheduled-backup success</em>
    defaults and pin its own recipient CSV. <strong>Inherit</strong> uses the global toggle above; choose
    <strong>On</strong> or <strong>Off</strong> to override that one field. Recipients left blank inherit
    the global alert-user list. Manual-run, retention, overdue and connection-test events stay global.
  </p>

  <?php if ($scheduleOverrides === []): ?>
    <p class="muted">No backup schedules defined yet — create one on the
       <a href="backup_admin.php?tab=backup">Backup tab</a> and it will appear here.</p>
  <?php else: ?>
    <form method="post" action="backup_admin.php?tab=notifications">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_schedule_notify_overrides">
      <table class="data-table" style="margin-bottom:1rem;">
        <thead>
          <tr>
            <th scope="col">Schedule</th>
            <th scope="col">Override</th>
            <th scope="col">On failure</th>
            <th scope="col">On success</th>
            <th scope="col">Recipients (CSV)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($scheduleOverrides as $s):
              $sid     = (int) $s['id'];
              $label   = $s['destination_name'] . ' &mdash; ' . $s['frequency'] . ' @ ' . $s['time_of_day'];
              $ovChk   = $s['override'] ? 'checked' : '';
              $fState  = $s['failure_state'];
              $sState  = $s['success_state'];
              $recip   = $s['recipients'];
              $tristateOpts = [
                  'inherit' => 'Inherit',
                  'on'      => 'On',
                  'off'     => 'Off',
              ];
          ?>
            <tr>
              <th scope="row" style="text-align:left;">
                <?= e((string) $s['destination_name']) ?>
                <span class="muted">&mdash; <?= e($s['frequency']) ?> @ <?= e($s['time_of_day']) ?></span>
                <?php if (!$s['is_active']): ?>
                  <span class="badge" title="Schedule is inactive">inactive</span>
                <?php endif; ?>
              </th>
              <td>
                <label style="display:inline-flex;align-items:center;gap:.4rem;">
                  <input type="checkbox"
                         name="sched[<?= $sid ?>][override]"
                         value="1"
                         <?= $ovChk ?>>
                  <span class="muted" aria-hidden="true">override</span>
                </label>
              </td>
              <?php foreach ([['failure', $fState], ['success', $sState]] as $pair):
                  [$field, $current] = $pair; ?>
                <td>
                  <select name="sched[<?= $sid ?>][<?= e($field) ?>]" aria-label="<?= e($label) ?> <?= e($field) ?>">
                    <?php foreach ($tristateOpts as $val => $disp): ?>
                      <option value="<?= e($val) ?>" <?= $current === $val ? 'selected' : '' ?>>
                        <?= e($disp) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
              <?php endforeach; ?>
              <td>
                <input type="text"
                       name="sched[<?= $sid ?>][recipients]"
                       value="<?= e($recip) ?>"
                       placeholder="ops@example.com, oncall@example.com"
                       style="width:100%;min-width:14rem;">
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div style="display:flex;gap:.5rem;align-items:center;">
        <button type="submit" class="button-primary">Save per-schedule overrides</button>
        <span class="muted">Override applies on the next run of each affected schedule.</span>
      </div>
    </form>
  <?php endif; ?>
</section>
