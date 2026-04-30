<?php /** @var list<array<string, mixed>> $destinations */ ?>
<form method="post" class="schedule-form">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="create_schedule">
  <label>Destination
    <select name="destination_id" required>
      <?php foreach ($destinations as $d): ?>
        <option value="<?= to_int($d['id']) ?>"><?= e(to_str($d['name'])) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Frequency
    <select name="frequency" required>
      <option value="hourly">Hourly</option>
      <option value="daily" selected>Daily</option>
      <option value="weekly">Weekly</option>
      <option value="monthly">Monthly</option>
    </select>
  </label>
  <label data-freq-field="time_of_day">Time of day (UTC, HH:MM) <input type="text" name="time_of_day" value="02:00" pattern="(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]"></label>
  <label data-freq-field="day_of_week">Day of week (Sun=0..Sat=6, weekly only) <input type="number" name="day_of_week" min="0" max="6" value="1"></label>
  <label data-freq-field="day_of_month">Day of month (1-28, monthly only) <input type="number" name="day_of_month" min="1" max="28" value="1"></label>
  <label>Retain hourly <input type="number" name="retention_hourly" value="24" min="0"></label>
  <label>Retain daily <input type="number" name="retention_daily" value="7" min="0"></label>
  <label>Retain weekly <input type="number" name="retention_weekly" value="4" min="0"></label>
  <label>Retain monthly <input type="number" name="retention_monthly" value="12" min="0"></label>
  <button type="submit" class="action-pill">Create schedule</button>
</form>
