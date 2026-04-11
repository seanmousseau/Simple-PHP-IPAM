<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */

if (is_logged_in()) {
    $u = current_user();
    audit($db, 'auth.logout', 'user', to_int($u['id']), 'logout');
}
logout_user();
header('Location: login.php');
exit;
