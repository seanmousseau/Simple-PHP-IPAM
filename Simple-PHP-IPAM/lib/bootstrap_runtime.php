<?php
declare(strict_types=1);

/**
 * @module bootstrap_runtime
 *
 * Runtime-gates bootstrap extracted from init.php in v3.35.0 (#1293).
 * Responsibility: apply the admin-configured timezone from the DB, surface
 * config validation warnings and stale-config-key warnings to admins,
 * and dispatch best-effort housekeeping + utilization-alert runs.
 *
 * Must be called AFTER ipam_db_init() (settings are available once the DB
 * is initialised) and AFTER session_start() (warnings land in $GLOBALS for
 * page_header() to render). Must be called BEFORE demo-mode checks and any
 * page-level logic that reads formatted dates or ipam_setting() values.
 *
 * ADR-003: no `global $config;` — caller passes $config and $db explicitly.
 * No sibling lib/*.php requires — all helpers resolve at call time.
 *
 * @param IpamConfig $config
 * @param PDO        $db
 */
function ipam_bootstrap_runtime_gates(array $config, PDO $db): void
{
    // Now that settings are available, apply the admin-configured timezone. All DB
    // timestamps are stored as UTC; ipam_format_datetime() in lib.php converts them
    // for UI output using the effective timezone set here.
    $tz = to_str(ipam_setting('branding.timezone'));
    // best-effort: invalid or empty timezone string falls back to UTC
    if ($tz === '' || !@date_default_timezone_set($tz)) {
        date_default_timezone_set('UTC');
    }
    unset($tz);

    // Validate config values on every boot; surface warnings to admins in the UI
    $_configWarnings = ipam_validate_config($config);
    if ($_configWarnings) {
        $GLOBALS['config_warnings'] = $_configWarnings;
    }
    unset($_configWarnings);

    // v3.0.0: detect non-bootstrap keys left in config.php after migration
    $_staleConfigKeys = ipam_config_stale_keys($config);
    if ($_staleConfigKeys) {
        $GLOBALS['config_stale_keys'] = $_staleConfigKeys;
    }
    unset($_staleConfigKeys);

    // Run best-effort housekeeping at most once/day (configurable)
    run_housekeeping_if_due($db);

    // Utilization alerts — independent interval (default 1 h); no-op if alert_email is empty
    alerts_check_if_due($config, $db);
}
