<?php
declare(strict_types=1);
require __DIR__ . '/init.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
