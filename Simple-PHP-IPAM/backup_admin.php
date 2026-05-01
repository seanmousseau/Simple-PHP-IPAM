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
$destState = null;
if ($activeTab === 'destinations') {
    require __DIR__ . '/lib/backup_admin_destinations.php';
    $destErr   = ipam_destinations_handle_post($db, 'backup_admin.php?tab=destinations');
    $destState = ipam_destinations_load_state($db);
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
  <?php elseif ($activeTab === 'history'): ?>
    <section class="card">
      <p class="muted">
        Unified backup history has not yet ported into this surface. Use
        <a href="backup_history.php">the legacy backup history page</a> until commit 3 lands.
      </p>
    </section>
  <?php elseif ($activeTab === 'backup'): ?>
    <section class="card">
      <p class="muted">
        Manual run + schedule editing has not yet ported into this surface. Use
        <a href="backup_admin.php?tab=destinations">the Destinations tab</a> for run-now and
        schedule edits until commit 4 lands.
      </p>
    </section>
  <?php elseif ($activeTab === 'restore'): ?>
    <section class="card">
      <p class="muted">
        The restore wizard has not yet ported into this surface. Use
        <a href="restore_web.php">the legacy restore page</a> until commit 5 lands.
      </p>
    </section>
  <?php elseif ($activeTab === 'notifications'): ?>
    <section class="card">
      <p class="muted">
        Notification preferences have not yet ported into this surface. Backup-related
        settings currently live in <a href="settings.php?tab=backups">Settings → Backups</a>
        until commit 6 lands.
      </p>
    </section>
  <?php endif; ?>
</div>

<?php
page_footer();
