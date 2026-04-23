<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();
require_role('admin');
header('HTTP/1.1 301 Moved Permanently');
header('Location: db_tools.php');
exit;
