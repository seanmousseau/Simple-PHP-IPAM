<?php
declare(strict_types=1);

return [
    'db_path' => __DIR__ . '/data/ipam.sqlite',
    'session_name' => 'IPAMSESSID',
    'proxy_trust' => false,

    'bootstrap_admin' => [
        'username' => 'admin',
        'password' => 'ChangeMeNow!12345',
    ],

    // Session idle timeout (seconds). Users are logged out after this much inactivity.
    'session_idle_seconds' => 1800,

    // Login rate limiting: lock out an IP after this many failed attempts within the window.
    'login_max_attempts'    => 5,
    'login_lockout_seconds' => 900,

    // CSV import max upload size (MB). Allowed range: 5..50
    'import_csv_max_mb' => 5,

    // Temp upload cleanup (seconds). Files older than this are eligible for cleanup.
    'tmp_cleanup_ttl_seconds' => 86400,

    // Audit log retention (days). Entries older than this are pruned during housekeeping.
    // Set to 0 to keep the audit log forever (default).
    'audit_log_retention_days' => 0,

    // Lazy housekeeping: runs on normal site access at most once per interval.
    'housekeeping' => [
        'enabled' => true,
        'interval_seconds' => 86400, // once per day
    ],

    // Subnet utilization thresholds. Utilization bars in the subnet list turn
    // yellow at 'warn' percent and red at 'critical' percent.
    'utilization_warn'     => 80,
    'utilization_critical' => 95,

    // Update check: fetches releases from GitHub and shows a banner when a newer
    // version is available. notify_prerelease: also alert for alpha/beta/RC builds.
    'update_check' => [
        'enabled'           => true,
        'ttl_seconds'       => 86400, // cache result for 24 hours
        'notify_prerelease' => false,
    ],

    // Automatic database backups. Backups are written to 'dir' (default:
    // data/backups/) on page load when the interval has elapsed.
    // frequency: 'daily' | 'weekly'
    // retention: number of most-recent backups to keep (older ones are deleted).
    'backup' => [
        'enabled'   => false,
        'frequency' => 'daily',  // 'daily' | 'weekly'
        'retention' => 7,
        'dir'       => '',       // empty = data/backups/ relative to this file
    ],

    // -----------------------------------------------------------------------
    // Password policy — complexity requirements and rotation.
    // min_length: minimum number of characters (default 12).
    // require_*: enforce character classes (uppercase, lowercase, number, symbol).
    // max_password_age_days: force a password change after N days. 0 = never expires.
    // -----------------------------------------------------------------------
    'password_policy' => [
        'min_length'            => 12,
        'require_uppercase'     => false,
        'require_lowercase'     => false,
        'require_number'        => false,
        'require_symbol'        => false,
        'max_password_age_days' => 0,
    ],

    // -----------------------------------------------------------------------
    // OIDC — Authorization Code + PKCE single sign-on (optional)
    // Set 'enabled' => true and fill in the IdP details to activate.
    // The redirect_uri must be registered with your IdP exactly as written.
    // -----------------------------------------------------------------------
    'oidc' => [
        'enabled'        => false,

        // Label shown on the login page button, e.g. 'Okta', 'Azure AD', 'Google'
        'display_name'   => 'SSO',

        'client_id'      => '',
        'client_secret'  => '',

        // Base URL of the IdP (/.well-known/openid-configuration is appended automatically),
        // or the full discovery document URL if the path differs.
        // Examples:
        //   'https://accounts.google.com'
        //   'https://login.microsoftonline.com/{tenant}/v2.0'
        //   'https://your-org.okta.com/oauth2/default'
        'discovery_url'  => '',

        // Must match exactly what is registered in the IdP application settings.
        'redirect_uri'   => '',   // e.g. 'https://ipam.example.com/oidc_callback.php'

        // Space-separated scopes. 'openid' is required; 'email' is needed for
        // auto-provisioning. 'profile' is optional.
        'scopes'         => 'openid email profile',

        // auto_provision: if true, a local user is created on first OIDC login
        // when no existing user is linked to the IdP subject (sub) claim.
        // Username is derived from the preferred_username claim (or email local-part).
        // Name and email are populated from the corresponding ID token claims.
        'auto_provision' => false,

        // Role assigned to auto-provisioned users. 'readonly' is recommended.
        'default_role'   => 'readonly',

        // disable_local_login: if true, the username/password form is hidden
        // when OIDC is enabled. Users must authenticate via SSO.
        'disable_local_login' => false,

        // hide_emergency_link: hides the "(emergency local access)" link text on
        // the login page. The URL login.php?local=1 still works unless
        // disable_emergency_bypass is also true.
        'hide_emergency_link' => false,

        // disable_emergency_bypass: when true, login.php?local=1 has no effect
        // and local login is entirely unavailable when disable_local_login is set.
        // WARNING: if your IdP becomes unavailable you will be locked out.
        // Only set this after verifying your IdP is reliable.
        'disable_emergency_bypass' => false,
    ],
];
