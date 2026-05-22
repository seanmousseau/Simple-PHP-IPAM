<?php
declare(strict_types=1);

/**
 * @module bootstrap_dialect
 *
 * DB dialect bootstrap extracted from init.php in v3.35.0 (#1293).
 * Responsibility: validate db_driver from config, load the concrete Dialect
 * class, and stash an instance in $GLOBALS['ipam_dialect'] so that
 * ipam_dialect() returns the correct driver before ipam_db() is called.
 *
 * Must be called AFTER config.php is loaded ($config['db_driver'] must exist)
 * and AFTER the Dialect.php + DialectValidator.php base files are required.
 * Must be called BEFORE session setup or any helper that calls ipam_dialect().
 *
 * Exits with code 2 on an unknown db_driver value (hard configuration error).
 *
 * ADR-003: no `global $config;` — caller passes $config explicitly.
 * No sibling lib/*.php requires — dialect classes live under dialects/.
 *
 * @param IpamConfig $config
 */
function ipam_bootstrap_dialect(array $config): void
{
    // Supported drivers: 'sqlite' (default), 'mysql', and 'pgsql' (all stable
    // as of v3.0.0). Unknown values are rejected here before any DB code runs.
    // Error reporting routes through error_log() / echo rather than
    // fwrite(STDERR, ...) because STDIN/STDOUT/STDERR are only defined under
    // CLI and phpdbg SAPIs — referencing them under Apache or PHP-FPM would
    // throw a fatal error.
    $_ipam_db_driver = (string)($config['db_driver'] ?? 'sqlite');
    $_ipam_driver_error = match ($_ipam_db_driver) {
        'sqlite', 'mysql', 'pgsql' => null,
        default  => "Unknown db_driver: {$_ipam_db_driver}",
    };
    if ($_ipam_driver_error !== null) {
        error_log('Simple-PHP-IPAM: ' . $_ipam_driver_error);
        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
            http_response_code(500);
            echo 'Internal configuration error. See server log for details.';
        } else {
            echo $_ipam_driver_error . "\n";
        }
        exit(2);
    }

    // Load the concrete dialect class and stash an instance under
    // $GLOBALS['ipam_dialect'] so any code that calls ipam_dialect() before
    // ipam_db($config) runs (HTTPS redirect, session setup, early helpers) sees
    // the right driver rather than the SqliteDialect fallback.
    $appDir = dirname(__DIR__);
    require_once $appDir . '/dialects/SqliteDialect.php';
    if ($_ipam_db_driver === 'mysql') {
        require_once $appDir . '/dialects/MysqlDialect.php';
        $GLOBALS['ipam_dialect'] = new MysqlDialect();
    } elseif ($_ipam_db_driver === 'pgsql') {
        require_once $appDir . '/dialects/PgsqlDialect.php';
        $GLOBALS['ipam_dialect'] = new PgsqlDialect();
    } else {
        $GLOBALS['ipam_dialect'] = new SqliteDialect();
    }
}
