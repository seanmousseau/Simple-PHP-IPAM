<?php
declare(strict_types=1);

// Load Composer autoloader so tests can exercise functions that depend on
// vendored libraries (e.g. robthree/twofactorauth for TOTP helpers).
// Vendor lives at the repo root (one level up from tests/).
$_autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($_autoload)) {
    require_once $_autoload;
}
unset($_autoload);

require __DIR__ . '/../Simple-PHP-IPAM/version.php';
require __DIR__ . '/../Simple-PHP-IPAM/lib.php';
