<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');

require __DIR__ . '/lib/backup_admin_history.php';

$state = ipam_backup_history_load_state($db);

page_header('Backup History');
?>
<main class="container">
  <h1>Backup History</h1>
  <p class="muted">Read-only log of all backup runs. <a href="destinations.php">Manage destinations →</a></p>
  <?php
  ipam_render('backup_admin_history', array_merge($state, [
      'self'       => 'backup_history.php',
      'extraQuery' => '',
  ]));
  ?>
</main>
<?php page_footer();
