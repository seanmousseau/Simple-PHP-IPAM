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
        if (is_array($decoded) && isset($decoded['_ts']) && (time() - (int)$decoded['_ts']) < $cacheTtl) {
            $data     = $decoded;
            $cachedAt = (int)$decoded['_ts'];
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
    $gConf = $GLOBALS['config'];
    if ($driver === 'sqlite') {
        $dbPath = $gConf['db_path'] !== '' ? $gConf['db_path'] : (__DIR__ . '/data/ipam.sqlite');
        $dbSize = is_file($dbPath) ? (int)filesize($dbPath) : 0;
    }
    $dbVersion = '';
    try {
        $vRow = $db->query("SELECT sqlite_version() AS v")?->fetch();
        if ($vRow) $dbVersion = 'SQLite ' . to_str($vRow['v'] ?? '');
    } catch (Throwable) {}

    $counts = [];
    foreach (['subnets', 'addresses', 'audit_log', 'scan_results', 'users'] as $tbl) {
        try {
            $r = $db->query("SELECT COUNT(*) AS c FROM {$tbl}")?->fetch();
            $counts[$tbl] = $r ? to_int($r['c']) : 0;
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
        $r = $db->query(
            "SELECT started_at, status FROM backup_history ORDER BY started_at DESC LIMIT 1"
        )?->fetch();
        if ($r) { $lastBackup = to_str($r['started_at'] ?? ''); $lastStatus = to_str($r['status'] ?? ''); }
        $r2 = $db->query("SELECT COUNT(*) AS c, COALESCE(SUM(size_bytes),0) AS s FROM backup_history WHERE status='success'")?->fetch();
        if ($r2) { $backupCount = to_int($r2['c']); $storageUsed = to_int($r2['s']); }
    } catch (Throwable) {}
    $backupDir   = backup_dir($config);
    $diskFree    = @disk_free_space($backupDir);
    $data['backup'] = [
        'enabled'      => (bool)ipam_setting('backup.enabled'),
        'last_at'      => $lastBackup,
        'last_status'  => $lastStatus,
        'count'        => $backupCount,
        'storage_used' => $storageUsed,
        'disk_free'    => $diskFree !== false ? (int)$diskFree : -1,
        'retention'    => max(1, to_int(ipam_setting('backup.retention'))),
    ];

    // --- Scanning ---
    $schedules   = 0;
    $activeScans = 0;
    $overdueScans= 0;
    $lastScan    = null;
    $staleCount  = 0;
    try {
        $r = $db->query("SELECT COUNT(*) AS c FROM scan_schedules")?->fetch();
        $schedules = $r ? to_int($r['c']) : 0;
        $r2 = $db->query("SELECT COUNT(*) AS c FROM scan_schedules WHERE is_active=1")?->fetch();
        $activeScans = $r2 ? to_int($r2['c']) : 0;
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
        $r4 = $db->query("SELECT MAX(scanned_at) AS t FROM scan_results")?->fetch();
        if ($r4) $lastScan = to_str($r4['t'] ?? '');
        $r5 = $db->query("SELECT COUNT(*) AS c FROM addresses WHERE is_stale=1")?->fetch();
        $staleCount = $r5 ? to_int($r5['c']) : 0;
    } catch (Throwable) {}
    $data['scan'] = [
        'schedules'    => $schedules,
        'active'       => $activeScans,
        'overdue'      => $overdueScans,
        'last_scan'    => $lastScan,
        'stale'        => $staleCount,
    ];

    // --- Webhooks ---
    $whActive   = 0;
    $wh24hOk    = 0;
    $wh24hTotal = 0;
    $whPending  = 0;
    $whLastErr  = '';
    try {
        $r = $db->query("SELECT COUNT(*) AS c FROM webhooks WHERE is_active=1")?->fetch();
        $whActive = $r ? to_int($r['c']) : 0;
        $st2 = $db->prepare(
            "SELECT COUNT(*) AS total,
             SUM(CASE WHEN http_status >= 200 AND http_status < 300 THEN 1 ELSE 0 END) AS ok
             FROM webhook_deliveries WHERE delivered_at >= :t24h"
        );
        $st2->execute([':t24h' => gmdate('Y-m-d H:i:s', time() - 86400)]);
        $r2 = $st2->fetch();
        if ($r2) { $wh24hOk = to_int($r2['ok']); $wh24hTotal = to_int($r2['total']); }
        $r3 = $db->query(
            "SELECT COUNT(*) AS c FROM webhook_deliveries
             WHERE (http_status IS NULL OR http_status < 200 OR http_status >= 300)
               AND attempt < 3"
        )?->fetch();
        $whPending = $r3 ? to_int($r3['c']) : 0;
        $r4 = $db->query(
            "SELECT error FROM webhook_deliveries
             WHERE error IS NOT NULL AND error != ''
             ORDER BY delivered_at DESC LIMIT 1"
        )?->fetch();
        if ($r4) $whLastErr = to_str($r4['error'] ?? '');
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
        $r2 = $st2->fetch();
        $lockedAccounts = $r2 ? to_int($r2['c']) : 0;
        $r3 = $db->query("SELECT COUNT(*) AS c FROM users WHERE totp_secret_enc IS NOT NULL AND totp_secret_enc != ''")?->fetch();
        $totpEnabled = $r3 ? to_int($r3['c']) : 0;
        $st4 = $db->prepare("SELECT COUNT(*) AS c FROM login_attempts WHERE attempted_at >= :t1h");
        $st4->execute([':t1h' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        $r4 = $st4->fetch();
        $failedLogin1h = $r4 ? to_int($r4['c']) : 0;
        $st5 = $db->prepare("SELECT COUNT(*) AS c FROM login_attempts WHERE attempted_at >= :t24h");
        $st5->execute([':t24h' => gmdate('Y-m-d H:i:s', time() - 86400)]);
        $r5 = $st5->fetch();
        $failedLogin24h = $r5 ? to_int($r5['c']) : 0;
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
    $ipamVer  = defined('IPAM_VERSION') ? IPAM_VERSION : '?';
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
$dbStatus     = 'ok';
$backupStatus = (bool)($data['backup']['enabled'] ?? false)
    ? (($data['backup']['last_status'] ?? '') === 'success' ? 'ok' : 'warn')
    : 'warn';
$scanStatus   = to_int($data['scan']['overdue'] ?? 0) > 0 ? 'warn' : 'ok';
$webhookStatus= to_int($data['webhook']['pending_retry'] ?? 0) > 0 ? 'warn' : 'ok';
$authStatus   = to_int($data['auth']['failed_1h'] ?? 0) > 10 ? 'warn' : 'ok';
$authStatus   = to_int($data['auth']['failed_1h'] ?? 0) > 50 ? 'crit' : $authStatus;
$systemStatus = 'ok';

$cacheAgeStr = $cachedAt !== null
    ? (time() - $cachedAt) . 's ago'
    : 'just now';

page_header('Health Dashboard');
?>

<div class="page-header">
  <div>
    <h1>Health Dashboard</h1>
    <p class="muted">Operational metrics — cached for <?= e((string)$cacheTtl) ?> seconds. Last updated: <?= e($cacheAgeStr) ?>.</p>
  </div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <a href="health.php?nocache=1" class="button-secondary" style="text-decoration:none">Refresh now</a>
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
    health_row('Driver', e(to_str($data['db']['driver'] ?? '')));
    health_row('Version', e(to_str($data['db']['version'] ?? '')));
    if (to_int($data['db']['size'] ?? 0) > 0) {
        health_row('File size', e(format_bytes(to_int($data['db']['size']))));
    }
    health_row('Subnets', e(number_format(to_int($data['db']['subnets'] ?? 0))));
    health_row('Addresses', e(number_format(to_int($data['db']['addresses'] ?? 0))));
    health_row('Audit log rows', e(number_format(to_int($data['db']['audit_log'] ?? 0))));
    health_row('Scan results', e(number_format(to_int($data['db']['scan_results'] ?? 0))));
    health_row('Users', e(number_format(to_int($data['db']['users'] ?? 0))));
    ?>
  </div>

  <!-- Backups -->
  <div class="card" style="padding:0;overflow:hidden">
    <div class="health-card-header">
      <?= health_dot($backupStatus) ?>
      Backups
    </div>
    <?php
    $bEnabled = (bool)($data['backup']['enabled'] ?? false);
    health_row('Status', $bEnabled ? '<span style="color:var(--success)">Enabled</span>' : '<span class="muted">Disabled</span>');
    $lastAt = to_str($data['backup']['last_at'] ?? '');
    health_row('Last backup', $lastAt !== '' ? e($lastAt) : '<span class="muted">Never</span>',
        $lastAt === '' ? 'warn' : (to_str($data['backup']['last_status'] ?? '') === 'success' ? 'ok' : 'crit'));
    health_row('Last status', e(to_str($data['backup']['last_status'] ?? '—')));
    health_row('Successful backups', e((string)to_int($data['backup']['count'] ?? 0)));
    health_row('Storage used', e(format_bytes(to_int($data['backup']['storage_used'] ?? 0))));
    $df = to_int($data['backup']['disk_free'] ?? -1);
    health_row('Disk free', $df >= 0 ? e(format_bytes($df)) : '<span class="muted">unknown</span>');
    health_row('Retention', e((string)to_int($data['backup']['retention'] ?? 7)) . ' backups');
    ?>
  </div>

  <!-- Scanning -->
  <div class="card" style="padding:0;overflow:hidden">
    <div class="health-card-header">
      <?= health_dot($scanStatus) ?>
      Scanning
    </div>
    <?php
    health_row('Total schedules', e((string)to_int($data['scan']['schedules'] ?? 0)));
    $active = to_int($data['scan']['active'] ?? 0);
    health_row('Active schedules', e((string)$active), $active === 0 ? 'warn' : 'ok');
    $overdue = to_int($data['scan']['overdue'] ?? 0);
    health_row('Overdue schedules', e((string)$overdue), $overdue > 0 ? 'warn' : 'ok');
    $ls = to_str($data['scan']['last_scan'] ?? '');
    health_row('Last successful scan', $ls !== '' ? e($ls) : '<span class="muted">Never</span>');
    $stale = to_int($data['scan']['stale'] ?? 0);
    health_row('Stale addresses', e(number_format($stale)), $stale > 0 ? 'warn' : 'ok');
    ?>
  </div>

  <!-- Webhooks -->
  <div class="card" style="padding:0;overflow:hidden">
    <div class="health-card-header">
      <?= health_dot($webhookStatus) ?>
      Webhooks
    </div>
    <?php
    health_row('Active webhooks', e((string)to_int($data['webhook']['active'] ?? 0)));
    $h24t = to_int($data['webhook']['h24_total'] ?? 0);
    $h24o = to_int($data['webhook']['h24_ok'] ?? 0);
    $rate  = $h24t > 0 ? round($h24o / $h24t * 100) . '%' : 'N/A';
    health_row('24h delivery rate', e($rate), $h24t > 0 && $h24o < $h24t ? 'warn' : 'ok');
    health_row('24h deliveries', e((string)$h24t));
    $pending = to_int($data['webhook']['pending_retry'] ?? 0);
    health_row('Pending retry', e((string)$pending), $pending > 0 ? 'warn' : 'ok');
    $lastErr = to_str($data['webhook']['last_error'] ?? '');
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
    $locked = to_int($data['auth']['locked_ips'] ?? 0);
    health_row('IPs w/ recent failures', e((string)$locked), $locked > 0 ? 'warn' : 'ok');
    $totp  = to_int($data['auth']['totp_enabled'] ?? 0);
    $total = to_int($data['auth']['total_users'] ?? 0);
    $totpPct = $total > 0 ? round($totp / $total * 100) . '%' : 'N/A';
    health_row('2FA enrolled', e("{$totp} / {$total} ({$totpPct})"), $totp < $total ? 'warn' : 'ok');
    $f1  = to_int($data['auth']['failed_1h'] ?? 0);
    $f24 = to_int($data['auth']['failed_24h'] ?? 0);
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
    health_row('PHP version', e(to_str($data['system']['php_version'] ?? '')));
    health_row('IPAM version', e(to_str($data['system']['ipam_version'] ?? '')));
    $df2 = to_int($data['system']['disk_free'] ?? -1);
    health_row('Disk free (data/)', $df2 >= 0 ? e(format_bytes($df2)) : '<span class="muted">unknown</span>',
        $df2 >= 0 && $df2 < 100 * 1024 * 1024 ? 'warn' : 'ok');
    health_row('Tmp dir size', e(format_bytes(to_int($data['system']['tmp_size'] ?? 0))));
    ?>
  </div>

</div>

<p class="muted" style="margin-top:1rem;font-size:.8em">
  Cache TTL: <?= e((string)$cacheTtl) ?>s &mdash;
  <a href="health.php?nocache=1" style="color:var(--link)">Force refresh</a>
</p>

<?php page_footer(); ?>
