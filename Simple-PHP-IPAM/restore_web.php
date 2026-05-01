<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var array<string, mixed> $config */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

require __DIR__ . '/lib/backup_admin_restore.php';

$state = ipam_backup_admin_restore_handle($db, $config);

page_header('Restore Database');
?>
<main class="container">
  <h1>Restore Database</h1>
  <p class="muted">Restore the database from a remote backup file. Includes dry-run preview before live apply.
     <a href="remote_backups.php">Browse remote files &rarr;</a></p>
  <?php
  ipam_render('backup_admin_restore', $state);
  ?>
</main>
<?php page_footer();
