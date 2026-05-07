<?php
declare(strict_types=1);

/**
 * Shared destination picker fields — backup format, encryption mode,
 * retention windows, and default-destination flag.
 *
 * Included by both the create form (views/backup_admin_destinations.php)
 * and the edit drawer form (views/_destination_edit_destination_form.php).
 *
 * Inputs (all optional with documented defaults):
 *   $picker['default_backup_type']     — 'logical' | 'database' (default 'logical')
 *   $picker['default_encryption_mode'] — 'stored' | 'unencrypted' (default 'stored';
 *                                       'transitory' is intentionally not exposed in
 *                                        this picker — it's only set by the manual
 *                                        upload-and-restore flow #837)
 *   $picker['retention_hourly']        — int >= 0 (default 0)
 *   $picker['retention_daily']         — int >= 0 (default 7)
 *   $picker['retention_weekly']        — int >= 0 (default 4)
 *   $picker['retention_monthly']       — int >= 0 (default 3)
 *   $picker['is_default']              — 0 | 1 (default 0)
 *   $picker['type']                    — 's3' | 'sftp' | 'local' — needed so the
 *                                        Unencrypted radio can be greyed out for
 *                                        non-local destinations (#851 server-side
 *                                        rule mirrored in the UI)
 *
 * @var array<string, mixed> $picker
 */
$pBackupType = is_string($picker['default_backup_type'] ?? null)
    ? (string) $picker['default_backup_type']
    : 'logical';
if ($pBackupType !== 'database' && $pBackupType !== 'logical') {
    $pBackupType = 'logical';
}
$pEncMode = is_string($picker['default_encryption_mode'] ?? null)
    ? (string) $picker['default_encryption_mode']
    : 'stored';
// Accept all three persisted enum values; only normalise truly unknown
// values back to 'stored'. Editing a 'transitory' destination must
// preserve its mode (CR #1096 major finding 2026-05-06).
if (!in_array($pEncMode, ['stored', 'transitory', 'unencrypted'], true)) {
    $pEncMode = 'stored';
}
$pType = is_string($picker['type'] ?? null) ? (string) $picker['type'] : '';
$pIsLocal = ($pType === 'local');
$pIsDefault = (isset($picker['is_default']) ? to_int($picker['is_default']) : 0) === 1;
$pRetH = isset($picker['retention_hourly'])  ? to_int($picker['retention_hourly'])  : 0;
$pRetD = isset($picker['retention_daily'])   ? to_int($picker['retention_daily'])   : 7;
$pRetW = isset($picker['retention_weekly'])  ? to_int($picker['retention_weekly'])  : 4;
$pRetM = isset($picker['retention_monthly']) ? to_int($picker['retention_monthly'])  : 3;
if (!isset($picker['retention_daily']))   $pRetD = 7;
if (!isset($picker['retention_weekly']))  $pRetW = 4;
if (!isset($picker['retention_monthly'])) $pRetM = 3;
?>
<fieldset class="picker-backup-format">
  <legend>Backup format</legend>
  <label class="radio">
    <input type="radio" name="default_backup_type" value="logical" <?= $pBackupType === 'logical' ? 'checked' : '' ?>>
    <strong>Logical</strong> — engine-agnostic IPAMBKL1 (recommended)
  </label>
  <label class="radio">
    <input type="radio" name="default_backup_type" value="database" <?= $pBackupType === 'database' ? 'checked' : '' ?>>
    <strong>Database</strong> — engine-native dump (escape hatch; needs CLI tools at restore time)
  </label>
  <p class="muted" style="font-size:.85rem">
    Logical produces backups that restore on any supported engine (sqlite/mysql/pgsql) and don't
    depend on the host having <code>mysqldump</code>/<code>pg_dump</code> available. Database
    backups are byte-for-byte engine-native and round-trip slightly faster on very large datasets,
    but only restore on the same engine they were taken from.
  </p>
</fieldset>

<fieldset class="picker-encryption-mode">
  <legend>Encryption</legend>
  <label class="radio">
    <input type="radio" name="default_encryption_mode" value="stored" <?= $pEncMode === 'stored' ? 'checked' : '' ?>>
    <strong>Encrypted (stored key)</strong> — IPAMBKP3 with the install's <code>backup_vault_key</code>
  </label>
  <?php if ($pEncMode === 'transitory'): ?>
    <label class="radio">
      <input type="radio" name="default_encryption_mode" value="transitory" checked>
      <strong>Per-passphrase (transitory)</strong>
      <span class="muted">— operator-typed passphrase, server never persists it</span>
      <small class="muted">(retained on edit; not selectable for new destinations — used by the manual upload-and-restore flow)</small>
    </label>
  <?php endif; ?>
  <label class="radio">
    <input type="radio" name="default_encryption_mode" value="unencrypted"
      <?= $pEncMode === 'unencrypted' ? 'checked' : '' ?>
      data-encryption-unencrypted-radio>
    <strong>Unencrypted</strong>
    <span class="muted">— plaintext payload (Local destinations only)</span>
    <?php if ($pIsLocal): ?>
      <small class="warning">⚠ plaintext storage; only enable on a trusted volume</small>
    <?php else: ?>
      <small class="muted">(server rejects unencrypted on remote destinations regardless of selection)</small>
    <?php endif; ?>
  </label>
</fieldset>

<fieldset class="picker-retention">
  <legend>Retention (per-destination, GFS)</legend>
  <p class="muted" style="font-size:.85rem">
    How many backups to keep at this destination. Each window is independent — a single
    backup can satisfy multiple windows (e.g. a Sunday at 02:00 anchors that week's weekly
    AND the month's monthly). Set a window to 0 to disable it.
  </p>
  <div class="grid-4">
    <label>Hourly <input type="number" name="retention_hourly" min="0" max="9999" value="<?= $pRetH ?>"></label>
    <label>Daily   <input type="number" name="retention_daily"   min="0" max="9999" value="<?= $pRetD ?>"></label>
    <label>Weekly  <input type="number" name="retention_weekly"  min="0" max="9999" value="<?= $pRetW ?>"></label>
    <label>Monthly <input type="number" name="retention_monthly" min="0" max="9999" value="<?= $pRetM ?>"></label>
  </div>
</fieldset>

<label class="checkbox">
  <input type="checkbox" name="is_default" value="1" <?= $pIsDefault ? 'checked' : '' ?>>
  Make this the default destination (pre-fills Run-now and new schedules)
</label>
