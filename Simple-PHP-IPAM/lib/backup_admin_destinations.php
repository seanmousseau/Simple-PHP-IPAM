<?php
declare(strict_types=1);

/**
 * Destinations + schedules POST handlers and view-state loader.
 *
 * Extracted from destinations.php in v3.21.0 Wave 4 commit 2 so the same logic
 * can drive the legacy destinations.php page AND the unified Backup & Restore
 * admin surface (backup_admin.php?tab=destinations) without duplication.
 *
 * Both call sites pass their own $redirectBase so flash redirects land back on
 * the page the user was actually on.
 */

/**
 * Collect and validate type-specific destination config from $_POST.
 *
 * @param  string               $type  's3'|'sftp'|'local'
 * @param  array<string, mixed> $post  $_POST
 * @return array<string, mixed>|string Validated config array, or error string.
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

        return [
            'endpoint'   => $endpoint,
            'region'     => $region,
            'bucket'     => $bucket,
            'prefix'     => $prefix,
            'access_key' => $access_key,
            'secret_key' => $secret_key,
        ];
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

/**
 * Build a Location URL by appending optional ?flash=<code> to a redirect base
 * that may or may not already contain a query string.
 */
function ipam_destinations_redirect(string $base, string $flashCode = ''): never
{
    $url = $base;
    if ($flashCode !== '') {
        $sep  = str_contains($base, '?') ? '&' : '?';
        $url .= $sep . 'flash=' . rawurlencode($flashCode);
    }
    header('Location: ' . $url);
    exit;
}

/**
 * Run all destinations + schedules POST handlers. On success each handler
 * redirects to $redirectBase and never returns. On validation failure returns
 * an error message to display on the GET render.
 *
 * @return string Error message, or '' on no error.
 */
function ipam_destinations_handle_post(\PDO $db, string $redirectBase): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return '';

    $action = to_str($_POST['action'] ?? '');

    if (demo_mode_enabled()) {
        return 'This action is disabled in demo mode.';
    }

    if ($action === 'create_destination') {
        $name    = trim(to_str($_POST['name'] ?? ''));
        $type    = to_str($_POST['type'] ?? '');
        $encrypt = isset($_POST['encrypt']) ? 1 : 0;

        if ($name === '') return 'Name is required.';
        if (!in_array($type, ['s3', 'sftp', 'local'], true)) return 'Invalid destination type.';

        $cfg = ipam_destinations_collect_config($type, $_POST);
        if (is_string($cfg)) return $cfg;

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
        $_SESSION['flash_test'] = [
            'destination_id' => $newId,
            'result'         => ipam_destination_test_now($db, $newId, 'auto-on-save'),
        ];
        ipam_destinations_redirect($redirectBase, 'created');
    }

    if ($action === 'update_destination') {
        $id      = to_int($_POST['id'] ?? '0');
        $name    = trim(to_str($_POST['name'] ?? ''));
        $type    = to_str($_POST['type'] ?? '');
        $encrypt = isset($_POST['encrypt']) ? 1 : 0;

        if ($id <= 0) return 'Invalid destination ID.';
        if ($name === '') return 'Name is required.';
        if (!in_array($type, ['s3', 'sftp', 'local'], true)) return 'Invalid destination type.';

        $existing = $db->prepare("SELECT type, config FROM backup_destinations WHERE id=:id");
        $existing->execute([':id' => $id]);
        $existingRow = $existing->fetch();
        /** @var array<string, mixed> $existingCfg */
        $existingCfg  = [];
        $existingType = '';
        if (is_array($existingRow)) {
            $existingType = to_str($existingRow['type']);
            $decoded = json_decode(to_str($existingRow['config']), true);
            if (is_array($decoded)) $existingCfg = $decoded;
        }

        if (!is_array($existingRow)) {
            http_response_code(404);
            return 'Destination not found.';
        }
        if ($existingType !== '' && $type !== $existingType) {
            http_response_code(400);
            return 'Destination type cannot be changed. Delete and recreate to switch types.';
        }

        $_POST = ipam_destination_merge_secrets($_POST, $existingCfg, $type);

        $cfg = ipam_destinations_collect_config($type, $_POST);
        if (is_string($cfg)) return $cfg;

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
        $_SESSION['flash_test'] = [
            'destination_id' => $id,
            'result'         => ipam_destination_test_now($db, $id, 'auto-on-save'),
        ];
        ipam_destinations_redirect($redirectBase, 'updated');
    }

    if ($action === 'delete_destination') {
        $id = to_int($_POST['id'] ?? '0');
        if ($id <= 0) return 'Invalid destination ID.';
        $db->prepare("DELETE FROM backup_destinations WHERE id=:id")->execute([':id' => $id]);
        audit($db, 'destination.delete', 'destination', $id, "id=$id");
        ipam_destinations_redirect($redirectBase, 'deleted');
    }

    if ($action === 'toggle_active_destination') {
        $id = to_int($_POST['id'] ?? '0');
        if ($id <= 0) return 'Invalid destination ID.';
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
        ipam_destinations_redirect($redirectBase);
    }

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

        $dowParam = ($frequency === 'weekly')  ? $dayOfWeek  : null;
        $domParam = ($frequency === 'monthly') ? $dayOfMonth : null;

        if ($destId <= 0) return 'Destination is required.';
        if (!in_array($frequency, ['hourly', 'daily', 'weekly', 'monthly'], true)) {
            return 'Invalid frequency.';
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeOfDay)) {
            return 'Time of day must be in HH:MM format (e.g. 02:00).';
        }
        if ($dowParam !== null && ($dowParam < 0 || $dowParam > 6)) {
            return 'Day of week must be 0–6.';
        }
        if ($domParam !== null && ($domParam < 1 || $domParam > 28)) {
            return 'Day of month must be 1–28.';
        }

        $nextRunAt = gmdate('Y-m-d H:i:s', ipam_backup_next_run_at([
            'frequency'    => $frequency,
            'time_of_day'  => $timeOfDay,
            'day_of_week'  => $dowParam ?? 0,
            'day_of_month' => $domParam ?? 1,
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
            ':dow'  => $dowParam,
            ':dom'  => $domParam,
            ':rh'   => $retHourly,
            ':rd'   => $retDaily,
            ':rw'   => $retWeekly,
            ':rm'   => $retMonthly,
            ':nra'  => $nextRunAt,
        ]);
        $newSchedId = (int) $db->lastInsertId();
        audit($db, 'schedule.create', 'schedule', $newSchedId, "destination_id=$destId frequency=$frequency");
        ipam_destinations_redirect($redirectBase, 'sched_created');
    }

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

        $dowParam = ($frequency === 'weekly')  ? $dayOfWeek  : null;
        $domParam = ($frequency === 'monthly') ? $dayOfMonth : null;

        if ($id <= 0) return 'Invalid schedule ID.';
        if (!in_array($frequency, ['hourly', 'daily', 'weekly', 'monthly'], true)) {
            return 'Invalid frequency.';
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeOfDay)) {
            return 'Time of day must be in HH:MM format (e.g. 02:00).';
        }
        if ($dowParam !== null && ($dowParam < 0 || $dowParam > 6)) {
            return 'Day of week must be 0–6.';
        }
        if ($domParam !== null && ($domParam < 1 || $domParam > 28)) {
            return 'Day of month must be 1–28.';
        }

        $nextRunAt = gmdate('Y-m-d H:i:s', ipam_backup_next_run_at([
            'frequency'    => $frequency,
            'time_of_day'  => $timeOfDay,
            'day_of_week'  => $dowParam ?? 0,
            'day_of_month' => $domParam ?? 1,
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
            ':dow'  => $dowParam,
            ':dom'  => $domParam,
            ':rh'   => $retHourly,
            ':rd'   => $retDaily,
            ':rw'   => $retWeekly,
            ':rm'   => $retMonthly,
            ':nra'  => $nextRunAt,
            ':id'   => $id,
        ]);
        audit($db, 'schedule.update', 'schedule', $id, "frequency=$frequency");
        ipam_destinations_redirect($redirectBase, 'sched_updated');
    }

    if ($action === 'delete_schedule') {
        $id = to_int($_POST['id'] ?? '0');
        if ($id <= 0) return 'Invalid schedule ID.';
        $db->prepare("DELETE FROM backup_schedules WHERE id=:id")->execute([':id' => $id]);
        audit($db, 'schedule.delete', 'schedule', $id, "id=$id");
        ipam_destinations_redirect($redirectBase, 'sched_deleted');
    }

    if ($action === 'toggle_active_schedule') {
        $id = to_int($_POST['id'] ?? '0');
        if ($id <= 0) return 'Invalid schedule ID.';
        $stmt = $db->prepare(
            "UPDATE backup_schedules SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=:id"
        );
        $stmt->execute([':id' => $id]);
        $row = $db->prepare("SELECT is_active FROM backup_schedules WHERE id=:id");
        $row->execute([':id' => $id]);
        $fetched = $row->fetch();
        $state   = is_array($fetched) ? (to_int($fetched['is_active']) === 1 ? 'enabled' : 'disabled') : 'toggled';
        audit($db, 'schedule.toggle_active', 'schedule', $id, "state=$state");
        ipam_destinations_redirect($redirectBase);
    }

    return '';
}

/**
 * Load destinations, schedules, GET flash, and pop the auto-test session flash.
 *
 * @return array{
 *   destinations: list<array<string, mixed>>,
 *   schedules: list<array<string, mixed>>,
 *   flash: string,
 *   flashTestId: int,
 *   flashTestOk: bool,
 *   flashTestMsg: string,
 *   flashTestLatency: int|null
 * }
 */
function ipam_destinations_load_state(\PDO $db): array
{
    $flash = '';
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $f     = to_str($_GET['flash'] ?? '');
        $flash = match ($f) {
            'created'       => 'Destination created.',
            'updated'       => 'Destination updated.',
            'deleted'       => 'Destination deleted.',
            'sched_created' => 'Schedule created.',
            'sched_updated' => 'Schedule updated.',
            'sched_deleted' => 'Schedule deleted.',
            default         => '',
        };
    }

    $flashTestId      = 0;
    $flashTestOk      = false;
    $flashTestMsg     = '';
    $flashTestLatency = null;
    if (isset($_SESSION['flash_test']) && is_array($_SESSION['flash_test'])) {
        $ft               = $_SESSION['flash_test'];
        $flashTestId      = is_int($ft['destination_id'] ?? null) ? $ft['destination_id'] : 0;
        $resultRaw        = is_array($ft['result'] ?? null) ? $ft['result'] : [];
        $flashTestOk      = (bool) ($resultRaw['ok'] ?? false);
        $flashTestMsg     = is_string($resultRaw['message'] ?? null) ? $resultRaw['message'] : '';
        $rawLatency       = $resultRaw['latency_ms'] ?? null;
        $flashTestLatency = is_int($rawLatency)
            ? $rawLatency
            : (is_numeric($rawLatency) ? (int) $rawLatency : null);
        unset($_SESSION['flash_test']);
    }

    $destStmt = $db->query("SELECT * FROM backup_destinations ORDER BY name");
    /** @var list<array<string, mixed>> $destinations */
    $destinations = $destStmt !== false ? $destStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $schedStmt = $db->query("SELECT * FROM backup_schedules ORDER BY destination_id, id");
    /** @var list<array<string, mixed>> $schedules */
    $schedules = $schedStmt !== false ? $schedStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    return [
        'destinations'     => $destinations,
        'schedules'        => $schedules,
        'flash'            => $flash,
        'flashTestId'      => $flashTestId,
        'flashTestOk'      => $flashTestOk,
        'flashTestMsg'     => $flashTestMsg,
        'flashTestLatency' => $flashTestLatency,
    ];
}
