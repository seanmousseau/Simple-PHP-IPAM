<?php
/**
 * Schedule edit form rendered into the global drawer.
 *
 * @var array<string,mixed> $dest   Row from backup_destinations.
 * @var array<string,mixed> $sched  Row from backup_schedules.
 */
$schedId = to_int($sched['id']);
$freq    = to_str($sched['frequency']);
?>
<form action="backup_admin.php?tab=destinations" method="post" class="schedule-form schedule-edit-form drawer-form">
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
  <label data-freq-field="time_of_day">Time of day (UTC, HH:MM) <input type="text" name="time_of_day" value="<?= e(to_str($sched['time_of_day'])) ?>" pattern="(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]"></label>
  <label data-freq-field="day_of_week">Day of week (Sun=0..Sat=6) <input type="number" name="day_of_week" min="0" max="6" value="<?= to_int($sched['day_of_week']) ?>"></label>
  <label data-freq-field="day_of_month">Day of month (1-28) <input type="number" name="day_of_month" min="1" max="28" value="<?= to_int($sched['day_of_month']) ?>"></label>
  <label>Retain hourly  <input type="number" name="retention_hourly"  min="0" value="<?= to_int($sched['retention_hourly'])  ?>"></label>
  <label>Retain daily   <input type="number" name="retention_daily"   min="0" value="<?= to_int($sched['retention_daily'])   ?>"></label>
  <label>Retain weekly  <input type="number" name="retention_weekly"  min="0" value="<?= to_int($sched['retention_weekly'])  ?>"></label>
  <label>Retain monthly <input type="number" name="retention_monthly" min="0" value="<?= to_int($sched['retention_monthly']) ?>"></label>
  <div class="drawer-actions">
    <button type="submit" class="action-pill">Save changes</button>
  </div>
</form>
