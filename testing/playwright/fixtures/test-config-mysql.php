<?php
declare(strict_types=1);

// Simple PHP IPAM — Playwright test configuration (MySQL driver, v2.10.0 #433).
//
// Copied to Simple-PHP-IPAM/config.php by testing/playwright/bootstrap-app.sh
// when the driver parameter is 'mysql'. Mirrors fixtures/test-config.php
// with the minimum changes needed to opt into MysqlDialect:
//
//   - db_driver      => 'mysql'
//   - db_dsn         => points at the MySQL service container by DNS name
//                       (ipam-pw-mysql). Both the throwaway seed container
//                       and the long-running Apache container run on the
//                       same docker network so the hostname resolves.
//   - db_user/db_pass => root/testpw (CI-only, not production)
//   - db_path        => unset (not used on MySQL, removed for clarity)
//
// Everything else matches test-config.php: demo_mode off by default,
// housekeeping off, update_check off, login rate limit relaxed.

return [
    'db_driver'            => 'mysql',
    'db_dsn'               => 'mysql:host=ipam-pw-mysql;port=3306;dbname=ipam_pw;charset=utf8mb4',
    'db_user'              => 'root',
    'db_pass'              => 'testpw',
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
        'enabled'    => false,
        'gate'       => null,
        'site_key'   => '',
        'secret_key' => '',
    ],
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
