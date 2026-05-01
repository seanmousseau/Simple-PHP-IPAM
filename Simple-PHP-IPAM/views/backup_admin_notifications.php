<?php
declare(strict_types=1);

/**
 * Notifications tab — backup-event email preferences (read-only summary).
 *
 * Per backup_overhaul.md §2.4, the v3.21.0 Notifications tab is "global
 * default only" and lives at the top level of the unified surface. Per-event
 * granularity, schedule-overrides, and richer recipients are parked for
 * v3.22.0. For this release the tab surfaces the existing backup-related
 * settings that already drive ipam_backup_notify() in lib.php, with
 * deep-links into Settings → Backups so admins still have one editor.
 *
 * @var bool   $notifyOnFailure
 * @var bool   $notifyOnSuccess
 * @var string $alertEmail
 * @var bool   $smtpEnabled
 */

$badgeOn  = '<span class="badge badge-success">On</span>';
$badgeOff = '<span class="badge badge-failed">Off</span>';
?>
<section class="card">
  <h3 style="margin-top:0;">Backup notification preferences</h3>
  <p class="muted">
    Backup notifications use the global <strong>Alert email</strong> recipient and the existing SMTP delivery pipeline.
    Per-event granularity and per-schedule overrides are planned for v3.22.0
    (see <code>docs/internal/backup_overhaul.md</code> §2.4).
  </p>

  <table class="data-table">
    <tbody>
      <tr>
        <th scope="row" style="text-align:left;">Notify on backup failure</th>
        <td><?= $notifyOnFailure ? $badgeOn : $badgeOff ?></td>
        <td><a href="settings.php?tab=data#group-backup">Edit in Settings</a></td>
      </tr>
      <tr>
        <th scope="row" style="text-align:left;">Notify on backup success</th>
        <td><?= $notifyOnSuccess ? $badgeOn : $badgeOff ?></td>
        <td><a href="settings.php?tab=data#group-backup">Edit in Settings</a></td>
      </tr>
      <tr>
        <th scope="row" style="text-align:left;">Recipient (alert_email)</th>
        <td><?= $alertEmail !== '' ? e($alertEmail) : '<span class="muted">— not set —</span>' ?></td>
        <td><a href="settings.php?tab=general#group-alerts">Edit in Settings</a></td>
      </tr>
      <tr>
        <th scope="row" style="text-align:left;">SMTP delivery</th>
        <td><?= $smtpEnabled ? $badgeOn : $badgeOff ?></td>
        <td><a href="settings.php?tab=integrations#group-smtp">Edit in Settings</a></td>
      </tr>
    </tbody>
  </table>

  <?php if (!$smtpEnabled): ?>
    <p class="muted">
      SMTP is currently disabled. Enabling SMTP under Settings → Integrations is required
      before any of the notifications above will deliver.
    </p>
  <?php elseif ($alertEmail === ''): ?>
    <p class="muted">
      No <code>alert_email</code> recipient is configured. Notifications will silently
      no-op until an address is set under Settings → Alerts.
    </p>
  <?php endif; ?>
</section>
