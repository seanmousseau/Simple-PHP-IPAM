<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var array<string, mixed> $config */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

require __DIR__ . '/lib/backup_admin_destinations.php';

$err   = ipam_destinations_handle_post($db, 'destinations.php');
$state = ipam_destinations_load_state($db);

page_header('Backup Destinations');
?>
<main class="container">
  <h1>Backup Destinations</h1>
  <?php
  ipam_render('backup_admin_destinations', [
      'err'              => $err,
      'flash'            => $state['flash'],
      'destinations'     => $state['destinations'],
      'schedules'        => $state['schedules'],
      'flashTestId'      => $state['flashTestId'],
      'flashTestOk'      => $state['flashTestOk'],
      'flashTestMsg'     => $state['flashTestMsg'],
      'flashTestLatency' => $state['flashTestLatency'],
      'vaultStatus'      => $state['vaultStatus'],
      'revealedKey'      => $state['revealedKey'],
  ]);
  ?>
</main>
<?php page_footer();
