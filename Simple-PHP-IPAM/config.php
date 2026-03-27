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

    // Lazy housekeeping: runs on normal site access at most once per interval.
    'housekeeping' => [
        'enabled' => true,
        'interval_seconds' => 86400, // once per day
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
        // Emergency local access is always available at login.php?local=1
        'disable_local_login' => false,
    ],
];
