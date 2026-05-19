<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */
require_login();
require_role('admin');

/* -------------------------------------------------------------------------
 * Cache logic — 60 s TTL, bypassed by ?nocache=1
 * ---------------------------------------------------------------------- */
$cacheFile  = __DIR__ . '/data/tmp/health_cache.json';
$cacheTtl   = 60;
$noCache    = isset($_GET['nocache']);
$cachedAt   = null;
$data       = null;

if (!$noCache && is_file($cacheFile)) {
    $raw = @file_get_contents($cacheFile);
    if ($raw !== false) {
        $decoded = @json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['_ts']) && (time() - to_int($decoded['_ts'])) < $cacheTtl) {
            /** @var array<string, mixed> $decoded */
            $data     = $decoded;
            $cachedAt = to_int($decoded['_ts']);
        }
    }
}

if ($data === null) {
    /* -----------------------------------------------------------------
     * Gather all metrics — one pass, minimal queries
     * -------------------------------------------------------------- */
    $data = ['_ts' => time()];

    // --- Database ---
    $driver  = ipam_dialect()->driver_name();
    $dbSize  = 0;
    /** @var IpamConfig $gConf */
    $gConf = ipam_config();
    if ($driver === 'sqlite') {
        $dbPath = $gConf['db_path'] !== '' ? $gConf['db_path'] : (__DIR__ . '/data/ipam.sqlite');
        $dbSize = is_file($dbPath) ? (int)filesize($dbPath) : 0;
    }
    $dbVersion = '';
    try {
        $driver = ipam_dialect()->driver_name();
        if ($driver === 'sqlite') {
            $vRow = ipam_fetch_assoc($db->query("SELECT sqlite_version() AS v") ?: null);
            if ($vRow !== []) $dbVersion = 'SQLite ' . to_str($vRow['v'] ?? '');
        } elseif ($driver === 'mysql') {
            $vRow = ipam_fetch_assoc($db->query("SELECT VERSION() AS v") ?: null);
            if ($vRow !== []) {
                $vStr = to_str($vRow['v'] ?? '');
                $dbVersion = (stripos($vStr, 'MariaDB') !== false ? 'MariaDB ' : 'MySQL ') . $vStr;
            }
        } elseif ($driver === 'pgsql') {
            $vRow = ipam_fetch_assoc($db->query("SELECT version() AS v") ?: null);
            if ($vRow !== []) {
                $parts = explode(' ', to_str($vRow['v'] ?? ''));
                $dbVersion = 'PostgreSQL ' . ($parts[1] ?? '');
            }
        }
    } catch (Throwable) {}

    $counts = [];
    foreach (['subnets', 'addresses', 'audit_log', 'scan_results', 'users'] as $tbl) {
        try {
            $r = ipam_fetch_assoc($db->query("SELECT COUNT(*) AS c FROM {$tbl}") ?: null);
            $counts[$tbl] = $r !== [] ? to_int($r['c']) : 0;
        } catch (Throwable) { $counts[$tbl] = 0; }
    }
    $data['db'] = [
        'driver'   => $driver,
        'version'  => $dbVersion,
        'size'     => $dbSize,
        'subnets'  => $counts['subnets'],
        'addresses'=> $counts['addresses'],
        'audit_log'=> $counts['audit_log'],
        'scan_results' => $counts['scan_results'],
        'users'    => $counts['users'],
    ];

    // --- Backups ---
    $lastBackup   = null;
    $lastStatus   = '';
    $backupCount  = 0;
    $storageUsed  = 0;
    try {
        // v3.21.0 §A1 (#799): backup_history was collapsed into backup_runs.
        $r = ipam_fetch_assoc($db->query(
            "SELECT started_at, status FROM backup_runs ORDER BY started_at DESC LIMIT 1"
        ) ?: null);
        if ($r !== []) { $lastBackup = to_str($r['started_at'] ?? ''); $lastStatus = to_str($r['status'] ?? ''); }
        $r2 = ipam_fetch_assoc($db->query("SELECT COUNT(*) AS c, COALESCE(SUM(size_bytes),0) AS s FROM backup_runs WHERE status='success'") ?: null);
        if ($r2 !== []) { $backupCount = to_int($r2['c']); $storageUsed = to_int($r2['s']); }
    } catch (Throwable) {}
    // v3.26.0 (#1059): legacy single-directory disk_free + retention reads
    // are gone with the backup.* keys. The unified surface has per-destination
    // paths (backup_destinations.config), so we expose an active-destination
    // count instead and let backup_admin.php surface per-destination details.
    $destinationsActive = 0;
    try {
        $stmt = $db->query("SELECT COUNT(*) AS c FROM backup_destinations WHERE is_active = 1");
        if ($stmt !== false) {
            $row = ipam_fetch_assoc($stmt);
            if ($row !== []) { $destinationsActive = to_int($row['c'] ?? 0); }
        }
    } catch (Throwable) {}
    $backupToolMissing = false;
    if ($driver === 'mysql') {
        $backupToolMissing = (trim((string)shell_exec('which mysqldump 2>/dev/null')) === '');
    } elseif ($driver === 'pgsql') {
        $backupToolMissing = (trim((string)shell_exec('which pg_dump 2>/dev/null')) === '');
    }
    $data['backup'] = [
        'destinations_active' => $destinationsActive,
        'last_at'             => $lastBackup,
        'last_status'         => $lastStatus,
        'count'               => $backupCount,
        'storage_used'        => $storageUsed,
        'tool_missing'        => $backupToolMissing,
    ];

    // --- Scanning ---
    $schedules   = 0;
    $activeScans = 0;
    $overdueScans= 0;
    $lastScan    = null;
    $staleCount  = 0;
    $warnAlerts  = 0;
    $critAlerts  = 0;
    try {
        $r = ipam_fetch_assoc($db->query("SELECT COUNT(*) AS c FROM scan_schedules") ?: null);
        $schedules = $r !== [] ? to_int($r['c']) : 0;
        $r2 = ipam_fetch_assoc($db->query("SELECT COUNT(*) AS c FROM scan_schedules WHERE is_active=1") ?: null);
        $activeScans = $r2 !== [] ? to_int($r2['c']) : 0;
        // Overdue = active, last_run_at set, and now - last_run > 2× interval (PHP-side for portability)
        $st3 = $db->query(
            "SELECT interval_minutes, last_run_at FROM scan_schedules
             WHERE is_active=1 AND last_run_at IS NOT NULL"
        );
        if ($st3) {
            $now = time();
            foreach ($st3->fetchAll() as $srow) {
                $threshold = to_int($srow['interval_minutes']) * 120;
                $lastRun   = strtotime(to_str($srow['last_run_at']));
                if ($lastRun !== false && ($now - $lastRun) > $threshold) {
                    $overdueScans++;
                }
            }
        }
        $r4 = ipam_fetch_assoc($db->query("SELECT MAX(scanned_at) AS t FROM scan_results") ?: null);
        if ($r4 !== []) $lastScan = to_str($r4['t'] ?? '');
        $r5 = ipam_fetch_assoc($db->query("SELECT COUNT(*) AS c FROM addresses WHERE is_stale=1") ?: null);
        $staleCount = $r5 !== [] ? to_int($r5['c']) : 0;
        $st6 = $db->query("SELECT level, COUNT(*) AS c FROM alert_state GROUP BY level");
        if ($st6) {
            $r6 = $st6->fetchAll();
            if ($r6) {
                foreach ($r6 as $ar) {
                    if (to_str($ar['level']) === 'warn') $warnAlerts = to_int($ar['c']);
                    if (to_str($ar['level']) === 'crit') $critAlerts = to_int($ar['c']);
                }
            }
        }
    } catch (Throwable) {}
    $data['scan'] = [
        'schedules'    => $schedules,
        'active'       => $activeScans,
        'overdue'      => $overdueScans,
        'last_scan'    => $lastScan,
        'stale'        => $staleCount,
        'warn_alerts'  => $warnAlerts,
        'crit_alerts'  => $critAlerts,
    ];

    // --- Webhooks ---
    $whActive   = 0;
    $wh24hOk    = 0;
    $wh24hTotal = 0;
    $whPending  = 0;
    $whLastErr  = '';
    try {
        $r = ipam_fetch_assoc($db->query("SELECT COUNT(*) AS c FROM webhooks WHERE is_active=1") ?: null);
        $whActive = $r !== [] ? to_int($r['c']) : 0;
        $st2 = $db->prepare(
            "SELECT COUNT(*) AS total,
             SUM(CASE WHEN http_status >= 200 AND http_status < 300 THEN 1 ELSE 0 END) AS ok
             FROM webhook_deliveries WHERE delivered_at >= :t24h"
        );
        $st2->execute([':t24h' => gmdate('Y-m-d H:i:s', time() - 86400)]);
        $r2 = ipam_fetch_assoc($st2);
        if ($r2 !== []) { $wh24hOk = to_int($r2['ok']); $wh24hTotal = to_int($r2['total']); }
        $r3 = ipam_fetch_assoc($db->query(
            "SELECT COUNT(*) AS c FROM webhook_deliveries
             WHERE (http_status IS NULL OR http_status < 200 OR http_status >= 300)
               AND attempt < 3"
        ) ?: null);
        $whPending = $r3 !== [] ? to_int($r3['c']) : 0;
        $r4 = ipam_fetch_assoc($db->query(
            "SELECT error FROM webhook_deliveries
             WHERE error IS NOT NULL AND error != ''
             ORDER BY delivered_at DESC LIMIT 1"
        ) ?: null);
        if ($r4 !== []) $whLastErr = to_str($r4['error'] ?? '');
    } catch (Throwable) {}
    $data['webhook'] = [
        'active'      => $whActive,
        'h24_ok'      => $wh24hOk,
        'h24_total'   => $wh24hTotal,
        'pending_retry'=> $whPending,
        'last_error'  => $whLastErr,
    ];

    // --- Auth / Security ---
    $lockedAccounts = 0;
    $totpEnabled    = 0;
    $failedLogin1h  = 0;
    $failedLogin24h = 0;
    try {
        // Locked = recent IPs with many failures (failed attempt threshold).
        $st2 = $db->prepare("SELECT COUNT(DISTINCT ip) AS c FROM login_attempts WHERE attempted_at >= :t15m");
        $st2->execute([':t15m' => gmdate('Y-m-d H:i:s', time() - 900)]);
        $r2 = ipam_fetch_assoc($st2);
        $lockedAccounts = $r2 !== [] ? to_int($r2['c']) : 0;
        $r3 = ipam_fetch_assoc($db->query("SELECT COUNT(*) AS c FROM users WHERE totp_secret_enc IS NOT NULL AND totp_secret_enc != ''") ?: null);
        $totpEnabled = $r3 !== [] ? to_int($r3['c']) : 0;
        $st4 = $db->prepare("SELECT COUNT(*) AS c FROM login_attempts WHERE attempted_at >= :t1h");
        $st4->execute([':t1h' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        $r4 = ipam_fetch_assoc($st4);
        $failedLogin1h = $r4 !== [] ? to_int($r4['c']) : 0;
        $st5 = $db->prepare("SELECT COUNT(*) AS c FROM login_attempts WHERE attempted_at >= :t24h");
        $st5->execute([':t24h' => gmdate('Y-m-d H:i:s', time() - 86400)]);
        $r5 = ipam_fetch_assoc($st5);
        $failedLogin24h = $r5 !== [] ? to_int($r5['c']) : 0;
    } catch (Throwable) {}
    $totalUsers = to_int($data['db']['users']);
    $data['auth'] = [
        'locked_ips'    => $lockedAccounts,
        'totp_enabled'  => $totpEnabled,
        'total_users'   => $totalUsers,
        'failed_1h'     => $failedLogin1h,
        'failed_24h'    => $failedLogin24h,
    ];

    // --- System ---
    $tmpDir     = __DIR__ . '/data/tmp';
    $tmpSize    = 0;
    foreach (glob($tmpDir . '/*') ?: [] as $f) {
        $tmpSize += is_file($f) ? (int)filesize($f) : 0;
    }
    $diskFreeData = @disk_free_space(__DIR__ . '/data');
    $ipamVer  = IPAM_VERSION;
    $data['system'] = [
        'php_version'  => PHP_VERSION,
        'ipam_version' => $ipamVer,
        'disk_free'    => $diskFreeData !== false ? (int)$diskFreeData : -1,
        'tmp_size'     => $tmpSize,
    ];

    // Cache to disk
    @file_put_contents($cacheFile, json_encode($data));
    @chmod($cacheFile, 0600);
}

/* -------------------------------------------------------------------------
 * Helper: status dot
 * ---------------------------------------------------------------------- */
function health_dot(string $level): string
{
    $cls = match ($level) { 'ok' => 'ok', 'warn' => 'warn', 'crit' => 'crit', default => 'warn' };
    return '<span class="health-dot ' . $cls . '" aria-label="' . e($level) . '"></span>';
}

function health_row(string $label, string $value, string $level = ''): void
{
    $dot = $level !== '' ? ' ' . health_dot($level) : '';
    echo '<div class="health-row">';
    echo '<span class="health-label">' . e($label) . '</span>';
    echo '<span class="health-val">' . $value . $dot . '</span>';
    echo '</div>';
}

/* -------------------------------------------------------------------------
 * Derived status levels
 * ---------------------------------------------------------------------- */
/* Section sub-arrays — $data may be a cache-loaded array<string,mixed>;
 * narrow each top-level section to an array before offset access. */
$dbSec      = is_array($data['db'] ?? null)      ? $data['db']      : [];
$backupSec  = is_array($data['backup'] ?? null)  ? $data['backup']  : [];
$scanSec    = is_array($data['scan'] ?? null)    ? $data['scan']    : [];
$webhookSec = is_array($data['webhook'] ?? null) ? $data['webhook'] : [];
$authSec    = is_array($data['auth'] ?? null)    ? $data['auth']    : [];
$systemSec  = is_array($data['system'] ?? null)  ? $data['system']  : [];

$dbStatus     = 'ok';
$backupStatus = (bool)($backupSec['tool_missing'] ?? false) ? 'crit'
    : (to_int($backupSec['destinations_active'] ?? 0) > 0
        ? (($backupSec['last_status'] ?? '') === 'success' ? 'ok' : 'warn')
        : 'warn');
$scanStatus   = to_int($scanSec['overdue'] ?? 0) > 0 ? 'warn' : 'ok';
if (to_int($scanSec['crit_alerts'] ?? 0) > 0) $scanStatus = 'crit';
elseif (to_int($scanSec['warn_alerts'] ?? 0) > 0 && $scanStatus === 'ok') $scanStatus = 'warn';
$webhookStatus= to_int($webhookSec['pending_retry'] ?? 0) > 0 ? 'warn' : 'ok';
$authStatus   = to_int($authSec['failed_1h'] ?? 0) > 10 ? 'warn' : 'ok';
$authStatus   = to_int($authSec['failed_1h'] ?? 0) > 50 ? 'crit' : $authStatus;
$systemStatus = 'ok';

$cacheAgeStr = $cachedAt !== null
    ? (time() - $cachedAt) . 's ago'
    : 'just now';

page_header('Health Dashboard');
?>
<div class="breadcrumbs">
  <a href="dashboard.php"><?= icon('home') ?> Dashboard</a><span class="sep">›</span>
  <a href="#"><?= icon('cog') ?> Admin</a><span class="sep">›</span>
  <span><?= icon('health') ?> Health</span>
</div>

<div class="page-header">
  <div>
    <h1>Health Dashboard</h1>
    <p class="muted">Operational metrics — cached for <?= e((string)$cacheTtl) ?> seconds. Last updated: <?= e($cacheAgeStr) ?>.</p>
  </div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <a href="health.php?nocache=1" class="button-secondary">Refresh now</a>
  </div>
</div>

<div class="health-grid">

  <!-- Database -->
  <div class="card" style="padding:0;overflow:hidden">
    <div class="health-card-header">
      <?= health_dot($dbStatus) ?>
      Database
    </div>
    <?php
    health_row('Driver', e(to_str($dbSec['driver'] ?? '')));
    health_row('Version', e(to_str($dbSec['version'] ?? '')));
    if (to_int($dbSec['size'] ?? 0) > 0) {
        health_row('File size', e(format_bytes(to_int($dbSec['size']))));
    }
    health_row('Subnets', e(number_format(to_int($dbSec['subnets'] ?? 0))));
    health_row('Addresses', e(number_format(to_int($dbSec['addresses'] ?? 0))));
    health_row('Audit log rows', e(number_format(to_int($dbSec['audit_log'] ?? 0))));
    health_row('Scan results', e(number_format(to_int($dbSec['scan_results'] ?? 0))));
    health_row('Users', e(number_format(to_int($dbSec['users'] ?? 0))));
    ?>
  </div>

  <!-- Backups -->
  <div class="card" style="padding:0;overflow:hidden">
    <div class="health-card-header">
      <?= health_dot($backupStatus) ?>
      Backups
    </div>
    <?php
    // v3.26.0 (#1059) moved backups to a per-destination model — "enabled"
    // means at least one active backup destination is configured.
    $destActive = to_int($backupSec['destinations_active'] ?? 0);
    health_row('Status', $destActive > 0 ? '<span style="color:var(--success)">Enabled</span>' : '<span class="muted">Disabled</span>');
    health_row('Active destinations', e((string)$destActive), $destActive === 0 ? 'warn' : 'ok');
    if ((bool)($backupSec['tool_missing'] ?? false)) {
        health_row('Dump tool', '<span class="danger">Not found in $PATH — backups will fail</span>', 'crit');
    }
    $lastAt = to_str($backupSec['last_at'] ?? '');
    health_row('Last backup', $lastAt !== '' ? e(ipam_format_datetime($lastAt)) : '<span class="muted">Never</span>',
        $lastAt === '' ? 'warn' : (to_str($backupSec['last_status'] ?? '') === 'success' ? 'ok' : 'crit'));
    health_row('Last status', e(to_str($backupSec['last_status'] ?? '—')));
    health_row('Successful backups', e((string)to_int($backupSec['count'] ?? 0)));
    health_row('Storage used', e(format_bytes(to_int($backupSec['storage_used'] ?? 0))));
    ?>
  </div>

  <!-- Scanning -->
  <div class="card" style="padding:0;overflow:hidden">
    <div class="health-card-header">
      <?= health_dot($scanStatus) ?>
      Scanning
    </div>
    <?php
    health_row('Total schedules', e((string)to_int($scanSec['schedules'] ?? 0)));
    $active = to_int($scanSec['active'] ?? 0);
    health_row('Active schedules', e((string)$active), $active === 0 ? 'warn' : 'ok');
    $overdue = to_int($scanSec['overdue'] ?? 0);
    health_row('Overdue schedules', e((string)$overdue), $overdue > 0 ? 'warn' : 'ok');
    $ls = to_str($scanSec['last_scan'] ?? '');
    health_row('Last successful scan', $ls !== '' ? e(ipam_format_datetime($ls)) : '<span class="muted">Never</span>');
    $stale = to_int($scanSec['stale'] ?? 0);
    health_row('Stale addresses', e(number_format($stale)), $stale > 0 ? 'warn' : 'ok');
    $warnA = to_int($scanSec['warn_alerts'] ?? 0);
    health_row('Warn alerts', e((string)$warnA), $warnA > 0 ? 'warn' : 'ok');
    $critA = to_int($scanSec['crit_alerts'] ?? 0);
    health_row('Crit alerts', e((string)$critA), $critA > 0 ? 'crit' : 'ok');
    ?>
  </div>

  <!-- Webhooks -->
  <div class="card" style="padding:0;overflow:hidden">
    <div class="health-card-header">
      <?= health_dot($webhookStatus) ?>
      Webhooks
    </div>
    <?php
    health_row('Active webhooks', e((string)to_int($webhookSec['active'] ?? 0)));
    $h24t = to_int($webhookSec['h24_total'] ?? 0);
    $h24o = to_int($webhookSec['h24_ok'] ?? 0);
    $rate  = $h24t > 0 ? round($h24o / $h24t * 100) . '%' : 'N/A';
    health_row('24h delivery rate', e($rate), $h24t > 0 && $h24o < $h24t ? 'warn' : 'ok');
    health_row('24h deliveries', e((string)$h24t));
    $pending = to_int($webhookSec['pending_retry'] ?? 0);
    health_row('Pending retry', e((string)$pending), $pending > 0 ? 'warn' : 'ok');
    $lastErr = to_str($webhookSec['last_error'] ?? '');
    if ($lastErr !== '') {
        health_row('Last error',
            '<span title="' . e($lastErr) . '" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;vertical-align:top">'
            . e(mb_substr($lastErr, 0, 60)) . '</span>');
    } else {
        health_row('Last error', '<span class="muted">None</span>');
    }
    ?>
  </div>

  <!-- Auth / Security -->
  <div class="card" style="padding:0;overflow:hidden">
    <div class="health-card-header">
      <?= health_dot($authStatus) ?>
      Auth / Security
    </div>
    <?php
    $locked = to_int($authSec['locked_ips'] ?? 0);
    health_row('IPs w/ recent failures', e((string)$locked), $locked > 0 ? 'warn' : 'ok');
    $totp  = to_int($authSec['totp_enabled'] ?? 0);
    $total = to_int($authSec['total_users'] ?? 0);
    $totpPct = $total > 0 ? round($totp / $total * 100) . '%' : 'N/A';
    health_row('2FA enrolled', e("{$totp} / {$total} ({$totpPct})"), $totp < $total ? 'warn' : 'ok');
    $f1  = to_int($authSec['failed_1h'] ?? 0);
    $f24 = to_int($authSec['failed_24h'] ?? 0);
    health_row('Failed logins (1h)', e((string)$f1), $f1 > 10 ? ($f1 > 50 ? 'crit' : 'warn') : 'ok');
    health_row('Failed logins (24h)', e((string)$f24), $f24 > 50 ? 'warn' : 'ok');
    ?>
  </div>

  <!-- System -->
  <div class="card" style="padding:0;overflow:hidden">
    <div class="health-card-header">
      <?= health_dot($systemStatus) ?>
      System
    </div>
    <?php
    health_row('PHP version', e(to_str($systemSec['php_version'] ?? '')));
    health_row('IPAM version', e(to_str($systemSec['ipam_version'] ?? '')));
    $df2 = to_int($systemSec['disk_free'] ?? -1);
    health_row('Disk free (data/)', $df2 >= 0 ? e(format_bytes($df2)) : '<span class="muted">unknown</span>',
        $df2 >= 0 && $df2 < 100 * 1024 * 1024 ? 'warn' : 'ok');
    health_row('Tmp dir size', e(format_bytes(to_int($systemSec['tmp_size'] ?? 0))));
    ?>
  </div>

</div>

<?php
/* v3.25.0 #854: per-destination connectivity section. Operator-friendly
 * read-out of every active destination's last test result so a quietly
 * broken S3/SFTP doesn't sit unnoticed for weeks. The section is admin-only
 * (the rest of health.php already gates by role); the data comes from the
 * backup_state table (scope 'destination_health', one row per destination
 * id) that cron Task 6c (#§2.4 v3.22.0) maintains -- v3.28.0 #1159 moved it
 * out of the backup.destination_health JSON setting. */
try {
    $bdStmt = $db->query(
        "SELECT id, name, type, is_default, is_active
           FROM backup_destinations
          WHERE is_active = 1
          ORDER BY name"
    );
    $bdRows = $bdStmt !== false ? $bdStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (\Throwable) {
    $bdRows = [];
}
try {
    $bdHealth = function_exists('ipam_backup_state_get_all')
        ? ipam_backup_state_get_all($db, 'destination_health')
        : [];
} catch (\Throwable) {
    $bdHealth = [];
}

// v3.28.0 #1159: read the per-destination cooldown row maintained by cron
// Task 6c. Entry shape: status ('ok'|'failing'|'unknown'), last_ok_at,
// last_failed_at (ISO-8601 strings), last_alerted_at (int). (Pre-v3.28.0
// this section read a JSON setting with mismatched key names and so always
// showed "never tested" — fixed in passing as part of the migration.)
$bdHealthy = 0;
$bdUnhealthy = 0;
$bdRowsRender = [];
foreach ($bdRows as $r) {
    if (!is_array($r)) continue;
    $rid      = to_int($r['id']);
    $st       = is_array($bdHealth[(string) $rid] ?? null) ? $bdHealth[(string) $rid] : [];
    $status   = is_string($st['status'] ?? null) ? $st['status'] : '';
    $lastOk   = is_string($st['last_ok_at'] ?? null) ? $st['last_ok_at'] : '';
    $lastFail = is_string($st['last_failed_at'] ?? null) ? $st['last_failed_at'] : '';
    if ($status === 'ok') {
        $bdHealthy++;
        $level   = 'ok';
        $valHtml = '<span class="badge badge-success">OK</span> ' . e($lastOk);
    } elseif ($status === 'failing') {
        $bdUnhealthy++;
        $level   = 'warn';
        $valHtml = '<span class="badge badge-failed">Failed</span> ' . e($lastFail);
    } elseif ($status === 'unknown') {
        $level   = 'warn';
        $valHtml = '<span class="muted">last test errored</span>';
    } else {
        $level   = 'warn';
        $valHtml = '<span class="muted">never tested</span>';
    }
    $bdRowsRender[] = [
        'label' => to_str($r['name']) . ' (' . strtoupper(to_str($r['type'])) . ')',
        'html'  => $valHtml,
        'level' => $level,
    ];
}
?>

<?php if (count($bdRows) > 0): ?>
<h2 style="margin-top:1.5rem">Backup destinations</h2>
<p class="muted" style="font-size:.85em">
  <?= number_format($bdHealthy) ?> of <?= number_format(count($bdRows)) ?> destinations healthy
  <?php if ($bdUnhealthy > 0): ?>
    &mdash; <span class="warning"><?= number_format($bdUnhealthy) ?> failing</span>
  <?php endif; ?>
  <a href="backup_admin.php?tab=destinations" style="color:var(--link);margin-left:.5rem">Manage</a>
</p>
<div class="card" style="padding:0;overflow:hidden">
  <table class="data-table">
    <thead><tr><th>Destination</th><th>Last test</th></tr></thead>
    <tbody>
    <?php foreach ($bdRowsRender as $r): ?>
      <tr>
        <td><?= e($r['label']) ?></td>
        <td><?= $r['html'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<p class="muted" style="margin-top:1rem;font-size:.8em">
  Cache TTL: <?= e((string)$cacheTtl) ?>s &mdash;
  <a href="health.php?nocache=1" style="color:var(--link)">Force refresh</a>
</p>

<?php page_footer(); ?>
