<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var array<string, mixed> $config */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err   = '';
$flash = '';

// ── Helper: collect and validate type-specific config from $_POST ─────────────
/**
 * @param  string               $type  's3'|'sftp'|'local'
 * @param  array<string, mixed> $post  $_POST
 * @return array<string, mixed>|string  validated config array, or error string
 */
function ipam_destinations_collect_config(string $type, array $post): array|string
{
    if ($type === 's3') {
        $endpoint   = trim(to_str($post['s3_endpoint']   ?? ''));
        $region     = trim(to_str($post['s3_region']     ?? ''));
        $bucket     = trim(to_str($post['s3_bucket']     ?? ''));
        $prefix     = trim(to_str($post['s3_prefix']     ?? 'ipam/'));
        $access_key = trim(to_str($post['s3_access_key'] ?? ''));
        $secret_key = to_str($post['s3_secret_key'] ?? '');

        if ($endpoint === '') return 'S3 endpoint URL is required.';
        if ($region   === '') return 'S3 region is required.';
        if ($bucket   === '') return 'S3 bucket is required.';
        if ($access_key === '') return 'S3 access key ID is required.';
        if ($secret_key === '') return 'S3 secret access key is required.';

        $cfg = [
            'endpoint'   => $endpoint,
            'region'     => $region,
            'bucket'     => $bucket,
            'prefix'     => $prefix,
            'access_key' => $access_key,
            'secret_key' => $secret_key,
        ];
        return $cfg;
    }

    if ($type === 'sftp') {
        $host        = trim(to_str($post['sftp_host']        ?? ''));
        $port        = max(1, min(65535, to_int($post['sftp_port'] ?? 22)));
        $username    = trim(to_str($post['sftp_username']    ?? ''));
        $password    = to_str($post['sftp_password']    ?? '');
        $private_key = trim(to_str($post['sftp_private_key'] ?? ''));
        $remote_path = trim(to_str($post['sftp_remote_path'] ?? ''));
        $fingerprint = trim(to_str($post['sftp_fingerprint'] ?? ''));

        if ($host === '')        return 'SFTP host is required.';
        if ($username === '')    return 'SFTP username is required.';
        if ($remote_path === '') return 'SFTP remote path is required.';
        if ($password === '' && $private_key === '') {
            return 'SFTP requires a password or private key.';
        }

        $cfg = [
            'host'        => $host,
            'port'        => $port,
            'username'    => $username,
            'remote_path' => $remote_path,
        ];
        if ($password !== '')    $cfg['password']    = $password;
        if ($private_key !== '') $cfg['private_key'] = $private_key;
        if ($fingerprint !== '') $cfg['fingerprint'] = $fingerprint;
        return $cfg;
    }

    if ($type === 'local') {
        $path = trim(to_str($post['local_path'] ?? ''));
        if ($path === '') return 'Local path is required.';
        return ['path' => $path];
    }

    return 'Unknown destination type.';
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = to_str($_POST['action'] ?? '');

    if (demo_mode_enabled()) {
        $err    = 'This action is disabled in demo mode.';
        $action = '';
    }

    // ── create_destination ────────────────────────────────────────────────────
    if ($action === 'create_destination') {
        $name    = trim(to_str($_POST['name'] ?? ''));
        $type    = to_str($_POST['type'] ?? '');
        $encrypt = isset($_POST['encrypt']) ? 1 : 0;

        if ($name === '') {
            $err = 'Name is required.';
        } elseif (!in_array($type, ['s3', 'sftp', 'local'], true)) {
            $err = 'Invalid destination type.';
        } else {
            $cfg = ipam_destinations_collect_config($type, $_POST);
            if (is_string($cfg)) {
                $err = $cfg;
            } else {
                $now  = ipam_dialect()->now();
                $stmt = $db->prepare(
                    "INSERT INTO backup_destinations (name, type, config, encrypt, is_active, created_at, updated_at)
                     VALUES (:n, :t, :c, :e, 1, $now, $now)"
                );
                $stmt->execute([
                    ':n' => $name,
                    ':t' => $type,
                    ':c' => json_encode($cfg, JSON_UNESCAPED_SLASHES),
                    ':e' => $encrypt,
                ]);
                $newId = (int) $db->lastInsertId();
                audit($db, 'destination.create', 'destination', $newId, "name=$name type=$type");
                header('Location: destinations.php?flash=created');
                exit;
            }
        }
    }

    // ── update_destination ────────────────────────────────────────────────────
    if ($action === 'update_destination') {
        $id      = to_int($_POST['id'] ?? '0');
        $name    = trim(to_str($_POST['name'] ?? ''));
        $type    = to_str($_POST['type'] ?? '');
        $encrypt = isset($_POST['encrypt']) ? 1 : 0;

        if ($id <= 0) {
            $err = 'Invalid destination ID.';
        } elseif ($name === '') {
            $err = 'Name is required.';
        } elseif (!in_array($type, ['s3', 'sftp', 'local'], true)) {
            $err = 'Invalid destination type.';
        } else {
            // Load existing config to carry over secrets not re-submitted
            $existing = $db->prepare("SELECT type, config FROM backup_destinations WHERE id=:id");
            $existing->execute([':id' => $id]);
            $existingRow = $existing->fetch();
            /** @var array<string, mixed> $existingCfg */
            $existingCfg = [];
            $existingType = '';
            if (is_array($existingRow)) {
                $existingType = to_str($existingRow['type']);
                $decoded = json_decode(to_str($existingRow['config']), true);
                if (is_array($decoded)) {
                    $existingCfg = $decoded;
                }
            }

            // Hard guard: type cannot change on update — schemas are incompatible (#778).
            if ($existingType !== '' && $type !== $existingType) {
                http_response_code(400);
                $err = 'Destination type cannot be changed. Delete and recreate to switch types.';
            } else {
                // Merge existing secrets so omitted/blank fields don't wipe them (#793).
                $_POST = ipam_destination_merge_secrets($_POST, $existingCfg, $type);

                $cfg = ipam_destinations_collect_config($type, $_POST);
                if (is_string($cfg)) {
                    $err = $cfg;
                } else {
                    $now  = ipam_dialect()->now();
                    $stmt = $db->prepare(
                        "UPDATE backup_destinations SET name=:n, type=:t, config=:c, encrypt=:e, updated_at=$now WHERE id=:id"
                    );
                    $stmt->execute([
                        ':n'  => $name,
                        ':t'  => $type,
                        ':c'  => json_encode($cfg, JSON_UNESCAPED_SLASHES),
                        ':e'  => $encrypt,
                        ':id' => $id,
                    ]);
                    audit($db, 'destination.update', 'destination', $id, "name=$name type=$type");
                    header('Location: destinations.php?flash=updated');
                    exit;
                }
            }
        }
    }

    // ── delete_destination ────────────────────────────────────────────────────
    if ($action === 'delete_destination') {
        $id = to_int($_POST['id'] ?? '0');
        if ($id > 0) {
            $db->prepare("DELETE FROM backup_destinations WHERE id=:id")->execute([':id' => $id]);
            audit($db, 'destination.delete', 'destination', $id, "id=$id");
            header('Location: destinations.php?flash=deleted');
            exit;
        }
        $err = 'Invalid destination ID.';
    }

    // ── toggle_active_destination ─────────────────────────────────────────────
    if ($action === 'toggle_active_destination') {
        $id = to_int($_POST['id'] ?? '0');
        if ($id > 0) {
            $now  = ipam_dialect()->now();
            $stmt = $db->prepare(
                "UPDATE backup_destinations SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END, updated_at=$now WHERE id=:id"
            );
            $stmt->execute([':id' => $id]);
            $row = $db->prepare("SELECT is_active FROM backup_destinations WHERE id=:id");
            $row->execute([':id' => $id]);
            $fetched = $row->fetch();
            $state   = is_array($fetched) ? (to_int($fetched['is_active']) === 1 ? 'enabled' : 'disabled') : 'toggled';
            audit($db, 'destination.toggle_active', 'destination', $id, "state=$state");
            header('Location: destinations.php');
            exit;
        }
        $err = 'Invalid destination ID.';
    }

    // ── create_schedule ───────────────────────────────────────────────────────
    if ($action === 'create_schedule') {
        $destId      = to_int($_POST['destination_id'] ?? '0');
        $frequency   = to_str($_POST['frequency'] ?? 'daily');
        $timeOfDay   = to_str($_POST['time_of_day'] ?? '02:00');
        $dayOfWeek   = to_int($_POST['day_of_week'] ?? '1');
        $dayOfMonth  = to_int($_POST['day_of_month'] ?? '1');
        $retHourly   = max(0, to_int($_POST['retention_hourly']  ?? '24'));
        $retDaily    = max(0, to_int($_POST['retention_daily']   ?? '7'));
        $retWeekly   = max(0, to_int($_POST['retention_weekly']  ?? '4'));
        $retMonthly  = max(0, to_int($_POST['retention_monthly'] ?? '12'));

        if ($destId <= 0) {
            $err = 'Destination is required.';
        } elseif (!in_array($frequency, ['hourly', 'daily', 'weekly', 'monthly'], true)) {
            $err = 'Invalid frequency.';
        } elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeOfDay)) {
            $err = 'Time of day must be in HH:MM format (e.g. 02:00).';
        } elseif ($dayOfWeek < 0 || $dayOfWeek > 6) {
            $err = 'Day of week must be 0–6.';
        } elseif ($dayOfMonth < 1 || $dayOfMonth > 28) {
            $err = 'Day of month must be 1–28.';
        } else {
            $nextRunAt = gmdate('Y-m-d H:i:s', ipam_backup_next_run_at([
                'frequency'    => $frequency,
                'time_of_day'  => $timeOfDay,
                'day_of_week'  => $dayOfWeek,
                'day_of_month' => $dayOfMonth,
            ]));
            $now  = ipam_dialect()->now();
            $stmt = $db->prepare(
                "INSERT INTO backup_schedules
                    (destination_id, frequency, time_of_day, day_of_week, day_of_month,
                     retention_hourly, retention_daily, retention_weekly, retention_monthly,
                     next_run_at, is_active, created_at)
                 VALUES (:did, :freq, :tod, :dow, :dom, :rh, :rd, :rw, :rm, :nra, 1, $now)"
            );
            $stmt->execute([
                ':did'  => $destId,
                ':freq' => $frequency,
                ':tod'  => $timeOfDay,
                ':dow'  => $dayOfWeek,
                ':dom'  => $dayOfMonth,
                ':rh'   => $retHourly,
                ':rd'   => $retDaily,
                ':rw'   => $retWeekly,
                ':rm'   => $retMonthly,
                ':nra'  => $nextRunAt,
            ]);
            $newSchedId = (int) $db->lastInsertId();
            audit($db, 'schedule.create', 'schedule', $newSchedId, "destination_id=$destId frequency=$frequency");
            header('Location: destinations.php?flash=sched_created');
            exit;
        }
    }

    // ── update_schedule ───────────────────────────────────────────────────────
    if ($action === 'update_schedule') {
        $id         = to_int($_POST['id'] ?? '0');
        $frequency  = to_str($_POST['frequency'] ?? 'daily');
        $timeOfDay  = to_str($_POST['time_of_day'] ?? '02:00');
        $dayOfWeek  = to_int($_POST['day_of_week'] ?? '1');
        $dayOfMonth = to_int($_POST['day_of_month'] ?? '1');
        $retHourly  = max(0, to_int($_POST['retention_hourly']  ?? '24'));
        $retDaily   = max(0, to_int($_POST['retention_daily']   ?? '7'));
        $retWeekly  = max(0, to_int($_POST['retention_weekly']  ?? '4'));
        $retMonthly = max(0, to_int($_POST['retention_monthly'] ?? '12'));

        if ($id <= 0) {
            $err = 'Invalid schedule ID.';
        } elseif (!in_array($frequency, ['hourly', 'daily', 'weekly', 'monthly'], true)) {
            $err = 'Invalid frequency.';
        } elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeOfDay)) {
            $err = 'Time of day must be in HH:MM format (e.g. 02:00).';
        } elseif ($dayOfWeek < 0 || $dayOfWeek > 6) {
            $err = 'Day of week must be 0–6.';
        } elseif ($dayOfMonth < 1 || $dayOfMonth > 28) {
            $err = 'Day of month must be 1–28.';
        } else {
            // Recompute next_run_at since the schedule timing fields may have changed.
            $nextRunAt = gmdate('Y-m-d H:i:s', ipam_backup_next_run_at([
                'frequency'    => $frequency,
                'time_of_day'  => $timeOfDay,
                'day_of_week'  => $dayOfWeek,
                'day_of_month' => $dayOfMonth,
            ]));
            $stmt = $db->prepare(
                "UPDATE backup_schedules SET
                    frequency=:freq, time_of_day=:tod, day_of_week=:dow, day_of_month=:dom,
                    retention_hourly=:rh, retention_daily=:rd, retention_weekly=:rw, retention_monthly=:rm,
                    next_run_at=:nra
                 WHERE id=:id"
            );
            $stmt->execute([
                ':freq' => $frequency,
                ':tod'  => $timeOfDay,
                ':dow'  => $dayOfWeek,
                ':dom'  => $dayOfMonth,
                ':rh'   => $retHourly,
                ':rd'   => $retDaily,
                ':rw'   => $retWeekly,
                ':rm'   => $retMonthly,
                ':nra'  => $nextRunAt,
                ':id'   => $id,
            ]);
            audit($db, 'schedule.update', 'schedule', $id, "frequency=$frequency");
            header('Location: destinations.php?flash=sched_updated');
            exit;
        }
    }

    // ── delete_schedule ───────────────────────────────────────────────────────
    if ($action === 'delete_schedule') {
        $id = to_int($_POST['id'] ?? '0');
        if ($id > 0) {
            $db->prepare("DELETE FROM backup_schedules WHERE id=:id")->execute([':id' => $id]);
            audit($db, 'schedule.delete', 'schedule', $id, "id=$id");
            header('Location: destinations.php?flash=sched_deleted');
            exit;
        }
        $err = 'Invalid schedule ID.';
    }

    // ── toggle_active_schedule ────────────────────────────────────────────────
    if ($action === 'toggle_active_schedule') {
        $id = to_int($_POST['id'] ?? '0');
        if ($id > 0) {
            $stmt = $db->prepare(
                "UPDATE backup_schedules SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=:id"
            );
            $stmt->execute([':id' => $id]);
            $row = $db->prepare("SELECT is_active FROM backup_schedules WHERE id=:id");
            $row->execute([':id' => $id]);
            $fetched = $row->fetch();
            $state   = is_array($fetched) ? (to_int($fetched['is_active']) === 1 ? 'enabled' : 'disabled') : 'toggled';
            audit($db, 'schedule.toggle_active', 'schedule', $id, "state=$state");
            header('Location: destinations.php');
            exit;
        }
        $err = 'Invalid schedule ID.';
    }
}

// ── GET flash messages ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $f     = to_str($_GET['flash'] ?? '');
    $flash = match ($f) {
        'created'      => 'Destination created.',
        'updated'      => 'Destination updated.',
        'deleted'      => 'Destination deleted.',
        'sched_created' => 'Schedule created.',
        'sched_updated' => 'Schedule updated.',
        'sched_deleted' => 'Schedule deleted.',
        default        => '',
    };
}

// ── Data fetch ────────────────────────────────────────────────────────────────
$destStmt = $db->query("SELECT * FROM backup_destinations ORDER BY name");
/** @var list<array<string, mixed>> $destinations */
$destinations = $destStmt !== false ? $destStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$schedStmt = $db->query("SELECT * FROM backup_schedules ORDER BY destination_id, id");
/** @var list<array<string, mixed>> $schedules */
$schedules = $schedStmt !== false ? $schedStmt->fetchAll(PDO::FETCH_ASSOC) : [];

page_header('Backup Destinations');
?>
<main class="container">
  <h1>Backup Destinations</h1>

  <?php if ($err !== ''): ?>
    <div class="card danger"><?= e($err) ?></div>
  <?php endif; ?>
  <?php if ($flash !== ''): ?>
    <div class="card success"><?= e($flash) ?></div>
  <?php endif; ?>

  <p class="muted">Configure where backups are sent. Each destination can have a schedule for automatic runs.</p>

  <!-- ── Destinations list ─────────────────────────────────────────────────── -->
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
                <button class="action-pill" type="button" data-edit-destination="<?= $destId ?>" aria-controls="edit-destination-<?= $destId ?>" aria-expanded="false">Edit</button>
                <button class="action-pill" data-test-destination="<?= $destId ?>">Test</button>
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
                    <?php require __DIR__ . '/views/destination_form_' . $destType . '.php'; ?>
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
        <?php $cfg = []; require __DIR__ . '/views/destination_form_s3.php'; ?>
      </fieldset>
      <fieldset class="destination-fields" data-type="sftp" hidden>
        <legend>SFTP connection</legend>
        <?php $cfg = []; require __DIR__ . '/views/destination_form_sftp.php'; ?>
      </fieldset>
      <fieldset class="destination-fields" data-type="local" hidden>
        <legend>Local filesystem</legend>
        <?php $cfg = []; require __DIR__ . '/views/destination_form_local.php'; ?>
      </fieldset>

      <button type="submit" class="action-pill">Create destination</button>
    </form>
  </section>

  <!-- ── Schedules list ────────────────────────────────────────────────────── -->
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
              <td><?= e(to_str($s['last_run_at'] ?? '—')) ?></td>
              <td><?= e(to_str($s['next_run_at'] ?? '—')) ?></td>
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
                  <label>Time of day (UTC, HH:MM) <input type="text" name="time_of_day" value="<?= e(to_str($s['time_of_day'])) ?>" pattern="[0-2][0-9]:[0-5][0-9]"></label>
                  <label>Day of week (Sun=0..Sat=6) <input type="number" name="day_of_week" min="0" max="6" value="<?= to_int($s['day_of_week']) ?>"></label>
                  <label>Day of month (1-28) <input type="number" name="day_of_month" min="1" max="28" value="<?= to_int($s['day_of_month']) ?>"></label>
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
    <?php require __DIR__ . '/views/schedule_form.php'; ?>
  </section>
</main>
<?php page_footer();
