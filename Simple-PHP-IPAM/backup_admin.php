<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var array<string, mixed> $config */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

/**
 * Backup & Restore unified admin surface (#797 — F1, v3.21.0 Wave 4).
 *
 * Replaces six legacy admin pages: destinations.php, backup_history.php,
 * remote_backups.php, restore_web.php, db_tools.php (backup half), and the
 * Backups section of settings.php. Tab content ports in over commits 2–6;
 * this shell is commit 1 (router + RBAC + nav + breadcrumb only).
 *
 * URL: backup_admin.php?tab=<slug>
 *
 * @var array<string, array{label:string, description:string}> $tabs
 */
$tabs = [
    'backup' => [
        'label'       => 'Backup',
        'description' => 'Run a backup now or manage scheduled backups per destination.',
    ],
    'restore' => [
        'label'       => 'Restore',
        'description' => 'Restore from a destination, an uploaded file, or a SQL dump.',
    ],
    'destinations' => [
        'label'       => 'Destinations',
        'description' => 'Local and remote backup targets — create, edit, test, delete.',
    ],
    'notifications' => [
        'label'       => 'Notifications',
        'description' => 'Email alerts for backup success, failure, and destination health.',
    ],
    'history' => [
        'label'       => 'History',
        'description' => 'Chronological log of every backup run with per-row detail.',
    ],
];

$activeTab = to_str($_GET['tab'] ?? 'backup');
if (!isset($tabs[$activeTab])) $activeTab = 'backup';

$activeLabel       = htmlspecialchars($tabs[$activeTab]['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$activeDescription = htmlspecialchars($tabs[$activeTab]['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Per-tab pre-render dispatch. Each branch may emit a header() redirect on
// POST and exit; load state for the GET render otherwise. Must run before
// page_header() so handlers can redirect cleanly.
$destState    = null;
$histState    = null;
$backupDests  = null;
$restoreState = null;
$notifyState  = null;
if ($activeTab === 'destinations') {
    require __DIR__ . '/lib/backup_admin_destinations.php';
    $destErr   = ipam_destinations_handle_post($db, 'backup_admin.php?tab=destinations');
    $destState = ipam_destinations_load_state($db);
} elseif ($activeTab === 'history') {
    require __DIR__ . '/lib/backup_admin_history.php';
    require_once __DIR__ . '/lib/backup.php';  // ipam_backup_dest_client() for verify/delete
    ipam_backup_history_handle_post($db);
    $histState = ipam_backup_history_load_state($db);
} elseif ($activeTab === 'backup') {
    $stmt = $db->query("SELECT id, name FROM backup_destinations WHERE is_active = 1 ORDER BY name");
    /** @var list<array<string, mixed>> $backupDests */
    $backupDests = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} elseif ($activeTab === 'restore') {
    require __DIR__ . '/lib/backup_admin_restore.php';
    $restoreState = ipam_backup_admin_restore_handle($db, $config);
} elseif ($activeTab === 'notifications') {
    /** @var string $notifyFlash */
    $notifyFlash = '';
    /** @var string $notifyFlashKind */
    $notifyFlashKind = 'success';

    $notifyEventKeys = [
        'success_scheduled', 'success_manual',
        'failure_scheduled', 'failure_manual',
        'destination_conn_failure', 'schedule_overdue',
        'retention_prune', 'encryption_change',
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && to_str($_POST['action'] ?? '') === 'save_notifications'
    ) {
        $uid = to_int($_SESSION['uid'] ?? 0);
        try {
            $db->beginTransaction();
            foreach ($notifyEventKeys as $evKey) {
                $val = isset($_POST['event_' . $evKey]);
                ipam_setting_set($db, 'backup.notify_' . $evKey, $val, $uid > 0 ? $uid : null);
            }
            $grace = to_int($_POST['overdue_grace_minutes'] ?? 60);
            if ($grace < 5) $grace = 5;
            if ($grace > 1440) $grace = 1440;
            ipam_setting_set($db, 'backup.notify_overdue_grace_minutes', $grace, $uid > 0 ? $uid : null);
            $db->commit();
            $notifyFlash = 'Notification preferences saved.';
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $notifyFlash = 'Failed to save preferences: ' . $e->getMessage();
            $notifyFlashKind = 'danger';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && to_str($_POST['action'] ?? '') === 'save_schedule_notify_overrides'
    ) {
        // Per-schedule overrides: $_POST['sched'][$id] = [
        //   'override'   => '1' | absent,
        //   'failure'    => 'inherit' | 'on' | 'off',
        //   'success'    => 'inherit' | 'on' | 'off',
        //   'recipients' => string (empty = inherit),
        // ]
        $schedRaw = $_POST['sched'] ?? [];
        $schedInput = is_array($schedRaw) ? $schedRaw : [];
        $missingSchedules = [];
        try {
            $db->beginTransaction();
            $upd = $db->prepare(
                "UPDATE backup_schedules
                    SET notify_override   = :ov,
                        notify_on_failure = :nf,
                        notify_on_success = :ns,
                        notify_recipients = :nr
                  WHERE id = :id"
            );
            foreach ($schedInput as $rawId => $rawFields) {
                $sid = is_numeric($rawId) ? (int) $rawId : 0;
                if ($sid <= 0 || !is_array($rawFields)) continue;
                $override = isset($rawFields['override']) ? 1 : 0;
                $nf = ipam_admin_notify_tristate_to_db($rawFields['failure']    ?? 'inherit');
                $ns = ipam_admin_notify_tristate_to_db($rawFields['success']    ?? 'inherit');
                $nrRaw = is_string($rawFields['recipients'] ?? null) ? trim((string) $rawFields['recipients']) : '';
                $nr = $nrRaw === '' ? null : $nrRaw;
                $upd->bindValue(':ov', $override, PDO::PARAM_INT);
                $upd->bindValue(':nf', $nf, $nf === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $upd->bindValue(':ns', $ns, $ns === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $upd->bindValue(':nr', $nr, $nr === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $upd->bindValue(':id', $sid, PDO::PARAM_INT);
                $upd->execute();
                if ($upd->rowCount() === 0) {
                    // Schedule deleted between page render and form submit.
                    // Surface a partial-success notice instead of silently
                    // claiming everything saved (CR feedback PR #1090).
                    $missingSchedules[] = $sid;
                }
            }
            $db->commit();
            if (!empty($missingSchedules)) {
                $notifyFlash    = 'Saved overrides for ' . (count($schedInput) - count($missingSchedules))
                                  . ' schedule(s); ' . count($missingSchedules)
                                  . ' schedule(s) no longer exist (id=' . implode(', ', $missingSchedules) . ').';
                $notifyFlashKind = 'warning';
            } else {
                $notifyFlash = 'Per-schedule notification overrides saved.';
            }
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $notifyFlash = 'Failed to save per-schedule overrides: ' . $e->getMessage();
            $notifyFlashKind = 'danger';
        }
    }

    $events = [];
    foreach ($notifyEventKeys as $evKey) {
        $events[$evKey] = (bool) ipam_setting('backup.notify_' . $evKey);
    }

    // Resolve the multi-user alert recipients for display.
    $alertUserIdsRaw = ipam_setting('alert.recipient_user_ids');
    $alertUserIds = [];
    if (is_array($alertUserIdsRaw)) {
        foreach ($alertUserIdsRaw as $rid) {
            if (is_numeric($rid)) $alertUserIds[] = (int) $rid;
        }
    }
    $alertUsers = [];
    if ($alertUserIds !== []) {
        $placeholders = implode(',', array_fill(0, count($alertUserIds), '?'));
        $stmt = $db->prepare(
            "SELECT id, username, email FROM users WHERE id IN ($placeholders) AND email <> '' ORDER BY username"
        );
        $stmt->execute($alertUserIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $alertUsers[] = [
                'id'       => to_int($u['id'] ?? 0),
                'username' => to_str($u['username'] ?? ''),
                'email'    => to_str($u['email'] ?? ''),
            ];
        }
    }

    // Schedule list with current per-schedule override state (#825 / E3b).
    // Joined with destinations for the human-readable label.
    $schedRows = $db->query(
        "SELECT s.id, s.frequency, s.time_of_day, s.is_active,
                s.notify_override, s.notify_on_failure, s.notify_on_success,
                s.notify_recipients,
                d.name AS destination_name
           FROM backup_schedules s
           JOIN backup_destinations d ON d.id = s.destination_id
          ORDER BY d.name, s.id"
    );
    $scheduleOverrides = [];
    if ($schedRows !== false) {
        foreach ($schedRows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $scheduleOverrides[] = [
                'id'                => to_int($r['id'] ?? 0),
                'destination_name'  => to_str($r['destination_name'] ?? ''),
                'frequency'         => to_str($r['frequency'] ?? ''),
                'time_of_day'       => to_str($r['time_of_day'] ?? ''),
                'is_active'         => (int) ($r['is_active'] ?? 0) === 1,
                'override'          => (int) ($r['notify_override'] ?? 0) === 1,
                'failure_state'     => ipam_admin_notify_tristate_from_db($r['notify_on_failure'] ?? null),
                'success_state'     => ipam_admin_notify_tristate_from_db($r['notify_on_success'] ?? null),
                'recipients'        => to_str($r['notify_recipients'] ?? ''),
            ];
        }
    }

    $notifyState = [
        'events'              => $events,
        'overdueGraceMinutes' => to_int(ipam_setting('backup.notify_overdue_grace_minutes')),
        'alertEmail'          => to_str(ipam_setting('alert.email') ?? ''),
        'smtpEnabled'         => (bool) ipam_setting('smtp.enabled'),
        'alertUsers'          => $alertUsers,
        'scheduleOverrides'   => $scheduleOverrides,
        'flash'               => $notifyFlash,
        'flashKind'           => $notifyFlashKind,
    ];
}

page_header('Backup & Restore');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="#">Admin</a><span class="sep">›</span>
  <span>Backup &amp; Restore</span>
</div>

<div class="toolbar">
  <div>
    <h1>Backup &amp; Restore</h1>
    <div class="muted">
      Manage backup destinations, schedules, restores, and notifications from a single surface.
    </div>
  </div>
</div>

<nav class="tab-bar" aria-label="Backup &amp; Restore sections">
  <ul class="tab-bar__list">
    <?php foreach ($tabs as $slug => $meta):
        $isActive = $slug === $activeTab; ?>
      <li class="tab-bar__item">
        <a class="tab-bar__link<?= $isActive ? ' is-active' : '' ?>"
           href="backup_admin.php?tab=<?= e($slug) ?>"
           <?= $isActive ? 'aria-current="page"' : '' ?>>
          <?= e($meta['label']) ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</nav>

<div class="backup-admin-tab" aria-labelledby="backup-admin-tab-title">
  <h2 id="backup-admin-tab-title" style="margin-top:0;"><?= $activeLabel ?></h2>
  <p class="muted"><?= $activeDescription ?></p>

  <?php if ($activeTab === 'destinations' && $destState !== null):
      ipam_render('backup_admin_destinations', [
          'err'              => $destErr,
          'flash'            => $destState['flash'],
          'destinations'     => $destState['destinations'],
          'schedules'        => $destState['schedules'],
          'flashTestId'      => $destState['flashTestId'],
          'flashTestOk'      => $destState['flashTestOk'],
          'flashTestMsg'     => $destState['flashTestMsg'],
          'flashTestLatency' => $destState['flashTestLatency'],
      ]);
  ?>
  <?php elseif ($activeTab === 'history' && $histState !== null):
      ipam_render('backup_admin_history', array_merge($histState, [
          'self'       => 'backup_admin.php',
          'extraQuery' => 'tab=history',
      ]));
  ?>
  <?php elseif ($activeTab === 'backup' && $backupDests !== null):
      ipam_render('backup_admin_backup', ['destinations' => $backupDests]);
  ?>
  <?php elseif ($activeTab === 'restore' && $restoreState !== null):
      ipam_render('backup_admin_restore', $restoreState);
  ?>
  <?php elseif ($activeTab === 'notifications' && $notifyState !== null):
      ipam_render('backup_admin_notifications', $notifyState);
  ?>
  <?php endif; ?>
</div>

<?php
page_footer();
