<?php
declare(strict_types=1);

// Simple PHP IPAM — Playwright test configuration.
//
// Copied to Simple-PHP-IPAM/config.php by testing/playwright/bootstrap-app.sh.
// Mirrors the committed config.php default set so no warnings fire when
// running under the containerized harness. The only meaningful overrides
// versus the default config are:
//   - app_name:   tagged "(test)" to make accidental prod use obvious
//   - login_max_attempts / login_lockout_seconds: relaxed so back-to-back
//                 test logins do not trip the rate limiter
//   - demo_mode.enabled: flipped to true briefly during seeding, then
//                 flipped back to false by bootstrap-app.sh before the
//                 long-running container starts
//   - housekeeping.enabled: false (no need for lazy housekeeping during tests)
//   - update_check.enabled: false (no outbound GitHub calls from CI)

return [
    'db_path'              => __DIR__ . '/data/ipam.sqlite',
    'session_name'         => 'IPAMSESSID',
    'proxy_trust'          => false,
    'app_name'             => 'Simple PHP IPAM (test)',
    'timezone'             => 'UTC',
    'bootstrap_admin'      => ['username' => 'admin', 'password' => 'ChangeMeNow!12345'],
    'session_idle_seconds' => 1800,
    'login_max_attempts'   => 9999,
    'login_lockout_seconds'=> 1,
    'import_csv_max_mb'    => 5,
    'import_sql_max_mb'    => 200,
    'tmp_cleanup_ttl_seconds' => 86400,
    'audit_log_retention_days' => 0,
    'address_history_retention_days' => 0,
    'housekeeping'         => ['enabled' => false, 'interval_seconds' => 86400],
    'utilization_warn'     => 80,
    'utilization_critical' => 95,
    'auto_reserve_network_broadcast' => true,
    'alert_email'            => '',
    'alert_util_warn_pct'    => 80,
    'alert_util_crit_pct'    => 95,
    'alert_interval_seconds' => 3600,
    'update_check'         => [
        'enabled'           => false,
        'ttl_seconds'       => 86400,
        'notify_prerelease' => false,
    ],
    'backup'               => [
        'enabled'   => false,
        'frequency' => 'daily',
        'retention' => 7,
        'dir'       => '',
    ],
    'password_policy'      => [
        'min_length'            => 12,
        'require_uppercase'     => false,
        'require_lowercase'     => false,
        'require_number'        => false,
        'require_symbol'        => false,
        'max_password_age_days' => 0,
    ],
    'login_protection'     => [
        'method'      => null,
        'site_key'    => '',
        'secret_key'  => '',
        'min_seconds' => 3,
        'version'     => 2,
    ],
    'demo_mode'            => [
        'enabled'     => false,
        'allow_force' => true,   // permits DEMO_SEED_FORCE=1 in the test harness
        'gate'        => null,
        'site_key'    => '',
        'secret_key'  => '',
    ],
    'app_secret'           => 'playwright-test-app-secret-for-totp',
    'oidc'                 => [
        'enabled'        => false,
        'display_name'   => 'SSO',
        'client_id'      => '',
        'client_secret'  => '',
        'discovery_url'  => '',
        'redirect_uri'   => '',
        'scopes'         => 'openid email profile',
        'auto_link'      => false,
        'auto_provision' => false,
        'default_role'   => 'readonly',
        'disable_local_login' => false,
        'hide_emergency_link' => false,
        'disable_emergency_bypass' => false,
    ],
];
