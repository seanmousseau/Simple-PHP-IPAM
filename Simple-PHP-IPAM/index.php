<?php
declare(strict_types=1);
require __DIR__ . '/init.php';

header('Location: ' . (is_logged_in() ? 'dashboard.php' : 'login.php'));
exit;
