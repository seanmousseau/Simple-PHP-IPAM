<?php
declare(strict_types=1);

/**
 * @module settings
 *
 * Runtime settings layer extracted from lib.php in v3.30.0 (ADR-004 Phase 5
 * Task 5.2b, sub of #907). Functions stay in the global namespace per ADR-004
 * Option E.
 *
 * Responsibilities: the setting registry (definitions + groups), the
 * encode/decode/infer codec, the read/write accessors (ipam_setting /
 * ipam_setting_set) with their per-request cache, the config.php back-compat
 * fallback, the deprecation detector, and — new in this task — the ADR-001
 * 11-value logical-type dispatch layer (storage-type mapping + validation).
 *
 * ADR-001 (settings type system, Option D — Amendment 2). The settings
 * registry stays in PHP; there is no DB table for it. ipam_setting_definitions()
 * computes the registry purely from the PHP definitions enriched with TWO type
 * keys: `logical_type` (the 11-value type) and `storage_type` (the 4-value
 * STORAGE type: string|int|bool|json). There is NO bare `type` key on the
 * returned array. The `settings.type` DB column and its
 * CHECK(type IN ('string','int','bool','json')) constraint were dropped in
 * v3.30.0 (Phase 5.4 / Task 3.3).
 *
 * ADR-003 ($config global). $GLOBALS['config'] reads are routed through the
 * ipam_config() accessor from lib/config.php. $GLOBALS['db'] / `global $db`
 * is deliberately retained — it is the runtime PDO handle, not config.
 *
 * #915 — the old 5-param, 4-mode ipam_setting_cache_storage() is replaced by
 * a focused trio: ipam_setting_cache_get / _set / _clear, sharing one static
 * backing store. ipam_setting_cache_bust() is the public name tests call and
 * delegates to the clear helper.
 *
 * Staged dispatch layer. The 11-type dispatch helpers
 * (ipam_setting_validate, ipam_setting_storage_type) and the `logical_type`
 * def-array key are introduced in this task but have no production caller
 * yet — they are wired into the settings.php page handler's render/validate
 * path in Task 5.2c. ipam_setting_set() deliberately does NOT call
 * ipam_setting_validate(); that coupling is intentionally deferred to 5.2c,
 * not an oversight.
 *
 * Load order: required by init.php after lib/presentation.php. lib.php also
 * requires it (dual-require pattern). Dependencies that still live in lib.php
 * (ipam_key_col, audit, current_user) resolve lazily at call time — always
 * after init.php has finished loading.
 */

/**
 * Sentinel returned by ipam_setting_cache_get() to distinguish a genuine
 * cache miss from a cached NULL value. Its string value is referenced by
 * tests and comments — do not change it.
 */
const IPAM_SETTING_CACHE_MISS = '__IPAM_SETTING_MISS__';

/**
 * The canonical PHP settings registry — the single source of truth for every
 * setting (ADR-001 Option D). ipam_setting_definitions() enriches each entry
 * here with `logical_type`/`storage_type`; this is where new settings are added.
 *
 * Fields per definition:
 *   - label       : human label for the admin UI
 *   - description : help text shown under the input
 *   - type        : string|int|bool|json (the 4-value STORAGE type)
 *   - group       : oidc|alert|branding|update_check|security|...
 *   - default     : value returned when the table and $config have no entry
 *   - sensitive   : mask in UI and audit details
 *   - config_key  : string (flat $config key) or array of keys (nested path)
 *                   for v2.6.0 $config back-compat fallback; null if none.
 *   - options     : enum domain (literal array or '@<resolver>' sentinel)
 *   - min / max   : numeric bounds for int settings
 *   - multiline   : true for textarea-rendered string settings
 *   - deprecated  : true for registry-only settings hidden from the UI
 *
 * @return array<string, array<string, mixed>>
 */
function ipam_setting_definitions_registry(): array
{
    return [
        // --- Branding ---
        'branding.site_name' => [
            'label'       => 'Application name',
            'description' => 'Shown in the browser tab, nav bar, and login page.',
            'type'        => 'string',
            'group'       => 'branding',
            'default'     => 'Simple PHP IPAM',
            'sensitive'   => false,
            'config_key'  => 'app_name',
        ],
        'branding.timezone' => [
            'label'       => 'Display timezone',
            'description' => 'PHP timezone identifier (e.g. America/Toronto). Timestamps are stored as UTC; this converts them for display only.',
            'type'        => 'string',
            'group'       => 'branding',
            'default'     => 'UTC',
            'sensitive'   => false,
            'config_key'  => 'timezone',
            // Dynamic option list from PHP's zoneinfo database (~420 entries).
            // Callable form keeps the registry lazy — the list is only built
            // when settings.php actually renders or validates this field.
            'options'     => '@timezone',
        ],

        // --- Security ---
        'security.session_idle_seconds' => [
            'label'       => 'Session idle timeout (seconds)',
            'description' => 'Users are logged out after this many seconds of inactivity. Minimum 60.',
            'type'        => 'int',
            'group'       => 'security',
            'default'     => 1800,
            'sensitive'   => false,
            'config_key'  => 'session_idle_seconds',
            'min'         => 60,
        ],
        'security.login_max_attempts' => [
            'label'       => 'Max failed login attempts',
            'description' => 'Lock out an IP after this many failed attempts within the window. Minimum 1.',
            'type'        => 'int',
            'group'       => 'security',
            'default'     => 5,
            'sensitive'   => false,
            'config_key'  => 'login_max_attempts',
            'min'         => 1,
        ],
        'security.login_lockout_seconds' => [
            'label'       => 'Login lockout window (seconds)',
            'description' => 'Time window during which failed attempts are counted toward lockout. Minimum 60.',
            'type'        => 'int',
            'group'       => 'security',
            'default'     => 900,
            'sensitive'   => false,
            'config_key'  => 'login_lockout_seconds',
            'min'         => 60,
        ],

        'security.account_lockout_max_attempts' => [
            'label'       => 'Account lockout attempts',
            'description' => 'Lock a username after this many failed login attempts within the window.',
            'type'        => 'int',
            'group'       => 'security',
            'default'     => 10,
            'sensitive'   => false,
            'config_key'  => 'account_lockout_max_attempts',
            'min'         => 1,
        ],
        'security.account_lockout_seconds' => [
            'label'       => 'Account lockout window (seconds)',
            'description' => 'Duration of per-username lockout after too many failed login attempts.',
            'type'        => 'int',
            'group'       => 'security',
            'default'     => 900,
            'sensitive'   => false,
            'config_key'  => 'account_lockout_seconds',
            'min'         => 1,
        ],
        'security.proxy_trust_cidrs' => [
            'label'       => 'Trusted reverse-proxy CIDRs',
            'description' => 'One CIDR per line. When the direct client (REMOTE_ADDR) matches any of these, X-Forwarded-For is walked right-to-left and the first untrusted hop is logged as the real client. Leave empty to ignore X-Forwarded-For entirely (the safe default for non-proxied installs). Replaces the legacy proxy_trust boolean — see docs/configuration.md.',
            'type'        => 'string',
            'group'       => 'security',
            'default'     => '',
            'sensitive'   => false,
            'config_key'  => null,
            'multiline'   => true,
        ],

        // --- Alerting ---
        // Deprecated in v2.8.0 by alert.recipient_user_ids. Kept in the
        // registry so the v2.8.0 migration can read its current value and
        // try to map it to a user. Hidden from the settings UI via the
        // 'deprecated' flag below; settings.php must skip rendering it.
        'alert.email' => [
            'label'       => 'Alert email recipient (deprecated)',
            'description' => 'Replaced in v2.8.0 by a multi-select picker tied to user records. This row is migrated automatically and hidden from the UI.',
            'type'        => 'string',
            'group'       => 'alert',
            'default'     => '',
            'sensitive'   => false,
            'deprecated'  => true,
            'config_key'  => 'alert_email',
        ],
        'alert.recipient_user_ids' => [
            'label'       => 'Alert recipients',
            'description' => 'Users who receive utilization alerts. Only users with a non-empty email address and an active account are eligible. Leave empty to disable email alerts.',
            'type'        => 'json',
            'group'       => 'alert',
            'default'     => [],
            'sensitive'   => false,
            'config_key'  => 'alert_recipient_user_ids',
        ],
        'alert.util_warn_pct' => [
            'label'       => 'Utilization warn threshold (%)',
            'description' => 'Trigger a warning alert when subnet utilization reaches this percent.',
            'type'        => 'int',
            'group'       => 'alert',
            'default'     => 80,
            'sensitive'   => false,
            'config_key'  => 'alert_util_warn_pct',
            'min'         => 0,
            'max'         => 100,
        ],
        'alert.util_crit_pct' => [
            'label'       => 'Utilization critical threshold (%)',
            'description' => 'Trigger a critical alert when subnet utilization reaches this percent.',
            'type'        => 'int',
            'group'       => 'alert',
            'default'     => 95,
            'sensitive'   => false,
            'config_key'  => 'alert_util_crit_pct',
            'min'         => 0,
            'max'         => 100,
        ],
        'alert.interval_seconds' => [
            'label'       => 'Alert check interval (seconds)',
            'description' => 'Minimum seconds between utilization alert evaluations. Minimum 60.',
            'type'        => 'int',
            'group'       => 'alert',
            'default'     => 3600,
            'sensitive'   => false,
            'config_key'  => 'alert_interval_seconds',
            'min'         => 60,
        ],

        // --- Update check ---
        'update_check.enabled' => [
            'label'       => 'Check for updates',
            'description' => 'Fetch release info from GitHub and show a banner when a newer version is available.',
            'type'        => 'bool',
            'group'       => 'update_check',
            'default'     => true,
            'sensitive'   => false,
            'config_key'  => ['update_check', 'enabled'],
        ],
        'update_check.ttl_seconds' => [
            'label'       => 'Update check cache TTL (seconds)',
            'description' => 'How long to cache the update check result before re-fetching from GitHub. Runtime enforces a floor of 3600 (one hour) to avoid hammering the GitHub API, so values below 3600 are silently clamped.',
            'type'        => 'int',
            'group'       => 'update_check',
            'default'     => 86400,
            'sensitive'   => false,
            'config_key'  => ['update_check', 'ttl_seconds'],
            'min'         => 3600,
        ],
        'update_check.notify_prerelease' => [
            'label'       => 'Notify on prereleases',
            'description' => 'Also alert for alpha, beta, and RC builds.',
            'type'        => 'bool',
            'group'       => 'update_check',
            'default'     => false,
            'sensitive'   => false,
            'config_key'  => ['update_check', 'notify_prerelease'],
        ],

        // --- Login protection (bot/abuse mitigation on the login form) ---
        'login_protection.method' => [
            'label'       => 'Login protection method',
            'description' => 'Bot/abuse mitigation on the login form. Pick the provider that matches the site/secret keys below.',
            'type'        => 'string',
            'group'       => 'login_protection',
            'default'     => '',
            'sensitive'   => false,
            'config_key'  => ['login_protection', 'method'],
            'options'     => [
                ''                 => 'Off',
                'honeypot'         => 'Honeypot',
                'time_check'       => 'Time check',
                'turnstile'        => 'Cloudflare Turnstile',
                'hcaptcha'         => 'hCaptcha',
                'recaptcha'        => 'Google reCAPTCHA',
                'friendly_captcha' => 'Friendly Captcha',
            ],
        ],
        'login_protection.site_key' => [
            'label'       => 'Login protection site key',
            'description' => 'Widget site key (Turnstile / hCaptcha / reCAPTCHA / Friendly Captcha).',
            'type'        => 'string',
            'group'       => 'login_protection',
            'default'     => '',
            'sensitive'   => false,
            'config_key'  => ['login_protection', 'site_key'],
        ],
        'login_protection.secret_key' => [
            'label'       => 'Login protection secret key',
            'description' => 'Widget secret key used for backend verification.',
            'type'        => 'string',
            'group'       => 'login_protection',
            'default'     => '',
            'sensitive'   => true,
            'config_key'  => ['login_protection', 'secret_key'],
        ],
        'login_protection.min_seconds' => [
            'label'       => 'Login time-check minimum (seconds)',
            'description' => "Minimum seconds between page load and submit when method is 'time_check'.",
            'type'        => 'int',
            'group'       => 'login_protection',
            'default'     => 3,
            'sensitive'   => false,
            'config_key'  => ['login_protection', 'min_seconds'],
            'min'         => 0,
        ],
        'login_protection.version' => [
            'label'       => 'reCAPTCHA version',
            'description' => "reCAPTCHA widget version: 2 (checkbox) or 3 (invisible). Only applies when method is 'recaptcha'.",
            'type'        => 'int',
            'group'       => 'login_protection',
            'default'     => 2,
            'sensitive'   => false,
            'config_key'  => ['login_protection', 'version'],
            'min'         => 2,
            'max'         => 3,
        ],

        // --- reCAPTCHA Enterprise backend verification ---
        'recaptcha_enterprise.enabled' => [
            'label'       => 'reCAPTCHA Enterprise enabled',
            'description' => "Use the reCAPTCHA Enterprise API for backend verification. Requires login_protection.method = 'recaptcha'.",
            'type'        => 'bool',
            'group'       => 'recaptcha_enterprise',
            'default'     => false,
            'sensitive'   => false,
            'config_key'  => ['recaptcha_enterprise', 'enabled'],
        ],
        'recaptcha_enterprise.project_id' => [
            'label'       => 'GCP project ID',
            'description' => 'Google Cloud project that owns the reCAPTCHA Enterprise key.',
            'type'        => 'string',
            'group'       => 'recaptcha_enterprise',
            'default'     => '',
            'sensitive'   => false,
            'config_key'  => ['recaptcha_enterprise', 'project_id'],
        ],
        'recaptcha_enterprise.api_key' => [
            'label'       => 'GCP API key',
            'description' => 'Server-side API key used to call the reCAPTCHA Enterprise API.',
            'type'        => 'string',
            'group'       => 'recaptcha_enterprise',
            'default'     => '',
            'sensitive'   => true,
            'config_key'  => ['recaptcha_enterprise', 'api_key'],
        ],
        'recaptcha_enterprise.expected_action' => [
            'label'       => 'Expected action',
            'description' => "Action name emitted by the widget, matched server-side during verification.",
            'type'        => 'string',
            'group'       => 'recaptcha_enterprise',
            'default'     => 'login',
            'sensitive'   => false,
            'config_key'  => ['recaptcha_enterprise', 'expected_action'],
        ],
        'recaptcha_enterprise.score_threshold' => [
            'label'       => 'Score threshold',
            'description' => "Minimum risk score to accept (0.0–1.0). Stored as a string so the 0.5 default round-trips cleanly.",
            'type'        => 'string',
            'group'       => 'recaptcha_enterprise',
            'default'     => '0.5',
            'sensitive'   => false,
            'config_key'  => ['recaptcha_enterprise', 'score_threshold'],
        ],

        // --- OIDC ---
        'oidc.enabled' => [
            'label'       => 'OIDC enabled',
            'description' => 'Turn Authorization Code + PKCE SSO on or off.',
            'type'        => 'bool',
            'group'       => 'oidc',
            'default'     => false,
            'sensitive'   => false,
            'config_key'  => ['oidc', 'enabled'],
        ],
        'oidc.display_name' => [
            'label'       => 'OIDC button label',
            'description' => 'Label shown on the login page SSO button (e.g. Okta, Azure AD).',
            'type'        => 'string',
            'group'       => 'oidc',
            'default'     => 'SSO',
            'sensitive'   => false,
            'config_key'  => ['oidc', 'display_name'],
        ],
        'oidc.client_id' => [
            'label'       => 'OIDC client ID',
            'description' => 'Client identifier issued by the identity provider.',
            'type'        => 'string',
            'group'       => 'oidc',
            'default'     => '',
            'sensitive'   => false,
            'config_key'  => ['oidc', 'client_id'],
        ],
        'oidc.client_secret' => [
            'label'       => 'OIDC client secret',
            'description' => 'Client secret issued by the identity provider. Stored in the database.',
            'type'        => 'string',
            'group'       => 'oidc',
            'default'     => '',
            'sensitive'   => true,
            'config_key'  => ['oidc', 'client_secret'],
        ],
        'oidc.discovery_url' => [
            'label'       => 'OIDC discovery URL',
            'description' => 'Base URL of the IdP (/.well-known/openid-configuration is appended automatically).',
            'type'        => 'string',
            'group'       => 'oidc',
            'default'     => '',
            'sensitive'   => false,
            'config_key'  => ['oidc', 'discovery_url'],
        ],
        'oidc.redirect_uri' => [
            'label'       => 'OIDC redirect URI',
            'description' => 'Must match exactly what is registered in the IdP application settings.',
            'type'        => 'string',
            'group'       => 'oidc',
            'default'     => '',
            'sensitive'   => false,
            'config_key'  => ['oidc', 'redirect_uri'],
        ],
        'oidc.scopes' => [
            'label'       => 'OIDC scopes',
            'description' => "Space-separated scopes. 'openid' is required; 'email' is needed for auto-provisioning.",
            'type'        => 'string',
            'group'       => 'oidc',
            'default'     => 'openid email profile',
            'sensitive'   => false,
            'config_key'  => ['oidc', 'scopes'],
        ],
        'oidc.auto_link' => [
            'label'       => 'OIDC auto-link existing accounts',
            'description' => 'On first OIDC login, link the incoming identity to a matching local account by username or email.',
            'type'        => 'bool',
            'group'       => 'oidc',
            'default'     => false,
            'sensitive'   => false,
            'config_key'  => ['oidc', 'auto_link'],
        ],
        'oidc.auto_provision' => [
            'label'       => 'OIDC auto-provision users',
            'description' => 'Create a new local account on first OIDC login when no existing user can be matched. Implies auto-link.',
            'type'        => 'bool',
            'group'       => 'oidc',
            'default'     => false,
            'sensitive'   => false,
            'config_key'  => ['oidc', 'auto_provision'],
        ],
        'oidc.default_role' => [
            'label'       => 'OIDC default role',
            'description' => "Role assigned to auto-provisioned users. 'Read-only' is recommended.",
            'type'        => 'string',
            'group'       => 'oidc',
            'default'     => 'readonly',
            'sensitive'   => false,
            'config_key'  => ['oidc', 'default_role'],
            // v2.11.0 #501: 'netops' added. The users.role column and the
            // demo seed already include a 'netops' role, but the OIDC
            // dropdown was missing it so IdP-provisioned users could not
            // be auto-assigned NetOps and had to be manually flipped.
            'options'     => [
                'readonly' => 'Read-only',
                'netops'   => 'Network operator',
                'admin'    => 'Administrator',
            ],
        ],
        'oidc.disable_local_login' => [
            'label'       => 'Disable local password login',
            'description' => 'When OIDC is active, hide the local username/password form entirely. Emergency bypass still works unless also disabled below.',
            'type'        => 'bool',
            'group'       => 'oidc',
            'default'     => false,
            'sensitive'   => false,
            'config_key'  => ['oidc', 'disable_local_login'],
        ],
        'oidc.disable_emergency_bypass' => [
            'label'       => 'Disable emergency local bypass',
            'description' => 'Disable the ?local=1 emergency access path that lets a local admin sign in even when local login is hidden. Leave off until you are confident SSO will keep working.',
            'type'        => 'bool',
            'group'       => 'oidc',
            'default'     => false,
            'sensitive'   => false,
            'config_key'  => ['oidc', 'disable_emergency_bypass'],
        ],
        'oidc.hide_emergency_link' => [
            'label'       => 'Hide emergency bypass link',
            'description' => 'Hide the "(emergency local access)" link even if the ?local=1 bypass itself is still reachable.',
            'type'        => 'bool',
            'group'       => 'oidc',
            'default'     => false,
            'sensitive'   => false,
            'config_key'  => ['oidc', 'hide_emergency_link'],
        ],

        // --- Housekeeping ---
        'housekeeping.enabled' => [
            'label'       => 'Lazy housekeeping enabled',
            'description' => 'Run temp cleanup, log pruning, and alert checks on normal page loads at the configured interval.',
            'type'        => 'bool',
            'group'       => 'housekeeping',
            'default'     => true,
            'sensitive'   => false,
            'config_key'  => ['housekeeping', 'enabled'],
        ],
        'housekeeping.interval_seconds' => [
            'label'       => 'Housekeeping interval (seconds)',
            'description' => 'Minimum seconds between lazy housekeeping runs. Default: 86400 (once per day).',
            'type'        => 'int',
            'group'       => 'housekeeping',
            'default'     => 86400,
            'sensitive'   => false,
            'config_key'  => ['housekeeping', 'interval_seconds'],
            'min'         => 60,
        ],
        'housekeeping.tmp_cleanup_ttl_seconds' => [
            'label'       => 'Temp file TTL (seconds)',
            'description' => 'Files in data/tmp/ older than this are deleted during housekeeping.',
            'type'        => 'int',
            'group'       => 'housekeeping',
            'default'     => 86400,
            'sensitive'   => false,
            'config_key'  => 'tmp_cleanup_ttl_seconds',
            'min'         => 60,
        ],
        'housekeeping.audit_log_retention_days' => [
            'label'       => 'Audit log retention (days)',
            'description' => 'Entries older than this are pruned during housekeeping. 0 = keep forever.',
            'type'        => 'int',
            'group'       => 'housekeeping',
            'default'     => 0,
            'sensitive'   => false,
            'config_key'  => 'audit_log_retention_days',
            'min'         => 0,
        ],
        'housekeeping.address_history_retention_days' => [
            'label'       => 'Address history retention (days)',
            'description' => 'History entries older than this are pruned during housekeeping. 0 = keep forever.',
            'type'        => 'int',
            'group'       => 'housekeeping',
            'default'     => 0,
            'sensitive'   => false,
            'config_key'  => 'address_history_retention_days',
            'min'         => 0,
        ],
        'housekeeping.snapshot_retention_days' => [
            'label'       => 'Utilization snapshot retention (days)',
            'description' => 'Utilization snapshot rows older than this are pruned during housekeeping. 0 = keep forever.',
            'type'        => 'int',
            'group'       => 'housekeeping',
            'default'     => 365,
            'sensitive'   => false,
            'config_key'  => 'snapshot_retention_days',
            'min'         => 0,
        ],

        // --- Backup ---
        // v3.26.0 (#1059): the 4 legacy v3.7 keys (backup.enabled,
        // backup.frequency, backup.retention, backup.dir) were retired with
        // the run_db_backup_if_due() runner. Backups are now driven by the
        // unified backup_destinations + backup_schedules surface.
        // v3.24.0 — manual upload-restore (#837). Effective cap is the
        // smallest of: this setting, php upload_max_filesize, post_max_size.
        // Increasing this above PHP's runtime limits has no effect; the
        // operator-facing description nudges them to bump both.
        'backup_max_upload_size_mb' => [
            'label'       => 'Manual restore upload limit (MiB)',
            'description' => 'Maximum size of a manually-uploaded backup archive on the Restore tab. Effective cap is min(this, php.ini upload_max_filesize, post_max_size). Increase all three together for files larger than the PHP defaults.',
            'type'        => 'int',
            'group'       => 'backup',
            'default'     => 2048,
            'min'         => 1,
            'sensitive'   => false,
        ],
        // v3.26.0 (#1098) — backup_vault_key wrapped envelope. The runtime
        // read path is added in v3.26.0 D2-B; this registry entry ships
        // alongside the data migration in D2-A so the column is present
        // in fresh installs from the day v3.26.0 is released. The value
        // is an "IPAMWK1." envelope produced by ipam_vault_wrap() — the
        // raw 32-byte vault key never lives in this row.
        'backup_vault_key' => [
            'label'       => 'Backup vault key (wrapped, internal)',
            'description' => 'Internal — the IPAMBKP3 vault key, wrapped under the bootstrap_key from config.php. Never displayed; managed via the Destinations admin panel.',
            'type'        => 'string',
            'group'       => 'backup',
            'default'     => '',
            'sensitive'   => true,
            'hidden'      => true,
        ],
        // --- Install-key announcement flags (v3.28.2 #1178) ---
        // Internal one-shot flags consumed by render_install_key_banner().
        // Set to '1' by ipam_install_key_announce_record() when ipam_app_secret()
        // or ipam_bootstrap_key() auto-generates a new value; cleared to '0' when
        // the admin dismisses the banner. Hidden from the Settings UI — the
        // banner is the user-facing surface.
        'install_keys_announce.app_secret' => [
            'label'       => 'Install-key announce: app_secret (internal)',
            'description' => 'Internal one-shot flag for the install-key auto-gen admin banner.',
            'type'        => 'bool',
            'group'       => 'security',
            'default'     => false,
            'sensitive'   => false,
            'hidden'      => true,
        ],
        'install_keys_announce.bootstrap_key' => [
            'label'       => 'Install-key announce: bootstrap_key (internal)',
            'description' => 'Internal one-shot flag for the install-key auto-gen admin banner.',
            'type'        => 'bool',
            'group'       => 'security',
            'default'     => false,
            'sensitive'   => false,
            'hidden'      => true,
        ],
        // --- Notifications (v3.22.0 §2.4) ---
        // Per-event toggles split scheduled-vs-manual on the success/failure
        // axis (§2.4 lists the two as independent — operators commonly want
        // "tell me when scheduled fail" + "stay silent on manual" or vice
        // versa). Per-schedule overrides are parking-lot work.
        // Legacy keys backup.notify_on_failure / backup.notify_on_success
        // were retired here; ipam_backup_notify() reads the new keys directly.
        'backup.notify_success_scheduled' => [
            'label'       => 'Email on scheduled-backup success',
            'description' => 'Send a notification when a scheduled backup completes successfully. Off by default — successful schedules can be very noisy.',
            'type'        => 'bool',
            'group'       => 'backup',
            'default'     => false,
            'sensitive'   => false,
        ],
        'backup.notify_success_manual' => [
            'label'       => 'Email on manual-backup success',
            'description' => 'Send a notification when an operator-triggered (Run-now) backup completes successfully.',
            'type'        => 'bool',
            'group'       => 'backup',
            'default'     => false,
            'sensitive'   => false,
        ],
        'backup.notify_failure_scheduled' => [
            'label'       => 'Email on scheduled-backup failure',
            'description' => 'Send a notification when a scheduled backup fails.',
            'type'        => 'bool',
            'group'       => 'backup',
            'default'     => true,
            'sensitive'   => false,
        ],
        'backup.notify_failure_manual' => [
            'label'       => 'Email on manual-backup failure',
            'description' => 'Send a notification when an operator-triggered (Run-now) backup fails.',
            'type'        => 'bool',
            'group'       => 'backup',
            'default'     => true,
            'sensitive'   => false,
        ],
        'backup.notify_destination_conn_failure' => [
            'label'       => 'Email on destination connection-test failure',
            'description' => "Periodically re-tests every active backup destination from the cron tick. When a previously-healthy destination starts failing, send one email (with a cooldown). No email is sent on recovery.",
            'type'        => 'bool',
            'group'       => 'backup',
            'default'     => true,
            'sensitive'   => false,
        ],
        'backup.notify_schedule_overdue' => [
            'label'       => 'Email when a backup schedule is overdue',
            'description' => 'Send a notification when a schedule should have fired but has not (cron stuck, host crashed, etc.).',
            'type'        => 'bool',
            'group'       => 'backup',
            'default'     => true,
            'sensitive'   => false,
        ],
        'backup.notify_overdue_grace_minutes' => [
            'label'       => 'Schedule-overdue grace period (minutes)',
            'description' => 'How many minutes past the expected next_run_at before a schedule is considered overdue and emailed.',
            'type'        => 'int',
            'group'       => 'backup',
            'default'     => 60,
            'sensitive'   => false,
            'min'         => 5,
            'max'         => 1440,
        ],
        'backup.notify_retention_prune' => [
            'label'       => 'Email retention-prune summaries',
            'description' => 'Send a notification each time retention deletes blobs from a destination. Verbose — off by default.',
            'type'        => 'bool',
            'group'       => 'backup',
            'default'     => false,
            'sensitive'   => false,
        ],
        'backup.dump_ssl_verify' => [
            'label'       => 'Verify MySQL/MariaDB TLS server certificate during backup/restore',
            'description' => 'When ON, mysqldump / mysql / mysql-restore verify the server TLS certificate chain against the system trust store. When OFF (default), they still use TLS encryption if the server offers it but skip cert verification — matches PHP PDO_MYSQL\'s default behaviour and lets the app connect to internal servers with self-signed certificates. Operators with a properly-chained CA cert should enable this.',
            'type'        => 'bool',
            'group'       => 'backup',
            'default'     => false,
            'sensitive'   => false,
            'config_key'  => ['backup', 'dump_ssl_verify'],
        ],
        'backup.notify_encryption_change' => [
            'label'       => 'Email on destination encryption-mode change',
            'description' => 'Send a notification when an admin toggles a destination between encrypted and plaintext.',
            'type'        => 'bool',
            'group'       => 'backup',
            'default'     => true,
            'sensitive'   => false,
        ],
        // v3.25.0 #1078: per-tab override knobs so backup notifications
        // can target a different audience than the global alert
        // infrastructure. Empty / 'inherit' values fall back to the
        // global alert.recipient_user_ids + mail.transport settings.
        'backup.notify_recipient_user_ids' => [
            'label'       => 'Backup-specific recipient user IDs',
            'description' => 'Override list of user IDs (JSON array) to receive backup notifications. Empty = inherit alert.recipient_user_ids.',
            'type'        => 'json',
            'group'       => 'backup',
            'default'     => [],
            'sensitive'   => false,
        ],
        'backup.notify_recipient_email_extra' => [
            'label'       => 'Extra backup notification recipients',
            'description' => 'Additional comma-separated email addresses (no user account required). Combined with the recipient list.',
            'type'        => 'string',
            'group'       => 'backup',
            'default'     => '',
            'sensitive'   => false,
        ],
        'backup.notify_delivery_method' => [
            'label'       => 'Backup notification delivery method',
            'description' => "'inherit' uses the global mail transport (current behaviour). 'smtp' explicitly forces SMTP delivery for backup notifications regardless of the global default.",
            'type'        => 'string',
            'group'       => 'backup',
            'default'     => 'inherit',
            'sensitive'   => false,
        ],
        // Deprecated v3.28.0 #1159 — the cron Task 6c destination health map
        // now lives in the backup_state table (scope 'destination_health'),
        // one atomic row per destination id. This registry entry is retained
        // for one release as a vestigial fallback (the migration backfills
        // backup_state from it) but is no longer read or written; remove in a
        // future release. Hidden from the settings UI.
        'backup.destination_health' => [
            'label'       => 'Destination health (internal, deprecated)',
            'description' => 'Deprecated — superseded by the backup_state table in v3.28.0. Not user-editable.',
            'type'        => 'string',
            'group'       => 'backup',
            'default'     => '{}',
            'sensitive'   => false,
            'hidden'      => true,
        ],
        // Deprecated v3.28.0 #1159 — the cron Task 6d schedule-overdue
        // cooldown map now lives in the backup_state table (scope
        // 'schedule_overdue'). Retained for one release as a vestigial
        // backfill source; no longer read or written. Hidden from the UI.
        'backup.schedule_overdue_state' => [
            'label'       => 'Schedule overdue state (internal, deprecated)',
            'description' => 'Deprecated — superseded by the backup_state table in v3.28.0. Not user-editable.',
            'type'        => 'string',
            'group'       => 'backup',
            'default'     => '{}',
            'sensitive'   => false,
            'hidden'      => true,
        ],
        // Internal — sentinel stamped on every v3.23.0–v3.25.x page load by
        // the ipam_legacy_backup_migrate_if_due() helper (now retired in
        // v3.26.0 #1059). The sentinel is kept in the registry so the
        // 3.26.0-retire-legacy-backup migration can verify operators passed
        // through that conversion path before dropping the 4 legacy keys.
        'backup.legacy_migrated_v3_23_0' => [
            'label'       => 'Legacy backup migration sentinel (internal)',
            'description' => 'Internal — set to true on any v3.23.0–v3.25.x page load (the conversion helper is retired in v3.26.0 #1059). The 3.26.0-retire-legacy-backup migration verifies this sentinel before dropping legacy backup.* keys. Not user-editable.',
            'type'        => 'bool',
            'group'       => 'backup',
            'default'     => false,
            'sensitive'   => false,
            'hidden'      => true,
        ],
        'backup_runs.retention_days' => [
            'label'       => 'Backup history retention (days)',
            'description' => 'Keep backup_runs rows this many days. 0 disables auto-purge. Protected runs (is_protected=1) and in-flight rows are never auto-purged.',
            'type'        => 'int',
            'group'       => 'backup',
            'default'     => 90,
            'sensitive'   => false,
            'config_key'  => null,
            'min'         => 0,
        ],
        'backup_runs.prune_batch_size' => [
            'label'       => 'Backup history prune batch size',
            'description' => 'Max rows to delete per cron tick. Prevents long lock holds on large purges.',
            'type'        => 'int',
            'group'       => 'backup',
            'default'     => 500,
            'sensitive'   => false,
            'config_key'  => null,
            'min'         => 1,
            'max'         => 10000,
        ],

        // --- Limits ---
        'limits.import_csv_max_mb' => [
            'label'       => 'CSV import max file size (MB)',
            'description' => 'Maximum upload size for CSV imports.',
            'type'        => 'int',
            'group'       => 'limits',
            'default'     => 5,
            'sensitive'   => false,
            'config_key'  => 'import_csv_max_mb',
            'min'         => 1,
            'max'         => 50,
        ],
        'limits.import_sql_max_mb' => [
            'label'       => 'SQL import max file size (MB)',
            'description' => 'Maximum upload size for SQL database imports. Also update upload_max_filesize in php.ini.',
            'type'        => 'int',
            'group'       => 'limits',
            'default'     => 200,
            'sensitive'   => false,
            'config_key'  => 'import_sql_max_mb',
            'min'         => 1,
        ],

        // --- API ---
        'api.max_attempts' => [
            'label'       => 'Max failed API key attempts',
            'description' => 'Lock out an IP after this many failed API key attempts.',
            'type'        => 'int',
            'group'       => 'api',
            'default'     => 20,
            'sensitive'   => false,
            'config_key'  => 'api_max_attempts',
            'min'         => 1,
        ],
        'api.lockout_seconds' => [
            'label'       => 'API lockout window (seconds)',
            'description' => 'Duration of API key lockout after too many failed attempts.',
            'type'        => 'int',
            'group'       => 'api',
            'default'     => 300,
            'sensitive'   => false,
            'config_key'  => 'api_lockout_seconds',
            'min'         => 1,
        ],
        'api.bulk_limit' => [
            'label'       => 'Bulk API write limit',
            'description' => 'Maximum records per bulk API write request.',
            'type'        => 'int',
            'group'       => 'api',
            'default'     => 500,
            'sensitive'   => false,
            'config_key'  => 'api_bulk_limit',
            'min'         => 1,
        ],

        // --- Multi-Factor Authentication ---
        'mfa.totp_enabled' => [
            'label'       => 'Enable TOTP (authenticator app)',
            'type'        => 'bool',
            'default'     => true,
            'group'       => 'mfa',
            'description' => 'Allow users to enroll a Time-based One-Time Password (RFC 6238) as a second authentication factor using an authenticator app (1Password, Google Authenticator, Authy, etc.). Requires app_secret to be set in config.php.',
            'sensitive'   => false,
            'config_key'  => null,
        ],
        'mfa.email_otp_enabled' => [
            'label'       => 'Enable Email OTP',
            'type'        => 'bool',
            'default'     => false,
            'group'       => 'mfa',
            'description' => 'Allow users to enroll Email OTP as a second authentication factor. Requires SMTP to be configured.',
            'sensitive'   => false,
            'config_key'  => null,
        ],
        'mfa.require' => [
            'label'       => 'Require 2FA for all users',
            'type'        => 'bool',
            'default'     => false,
            'group'       => 'mfa',
            'description' => 'Users without any 2FA method enrolled will be redirected to the Account page to enroll before accessing the application.',
            'sensitive'   => false,
            'config_key'  => null,
        ],
        'mfa.passkeys_enabled' => [
            'label'       => 'Enable Passkeys (WebAuthn)',
            'type'        => 'bool',
            'default'     => false,
            'group'       => 'mfa',
            'description' => 'Allow users to register hardware security keys or device biometrics (Face ID, Touch ID, Windows Hello) as a second authentication factor.',
            'sensitive'   => false,
            'config_key'  => null,
        ],

        // --- Step-up authentication (sudo-mode for sensitive admin actions) ---
        // v3.27.0 #1108: install-wide policy controlling which credential
        // proofs satisfy ipam_sudo_verify(). Decoupled from login provider
        // so OIDC/LDAP/SAML users can manage sensitive resources (vault key,
        // sensitive settings, DB import, API key creation, MFA disable)
        // without depending on a local password. See
        // docs/superpowers/plans/2026-05-07-v3.27.0.md.
        'auth.step_up.allow_totp' => [
            'label'       => 'Accept TOTP for step-up',
            'type'        => 'bool',
            'default'     => true,
            'group'       => 'step_up',
            'description' => 'Allow a fresh TOTP code to satisfy the step-up gate for sensitive admin actions. Has no effect for users who have not enrolled TOTP.',
            'sensitive'   => false,
            'config_key'  => null,
        ],
        'auth.step_up.allow_email_otp' => [
            'label'       => 'Accept Email OTP for step-up',
            'type'        => 'bool',
            'default'     => true,
            'group'       => 'step_up',
            'description' => 'Allow a fresh Email OTP code to satisfy the step-up gate. Slightly weaker than TOTP/passkey because compromise of the email account leaks it; disable if your threat model includes inbox compromise.',
            'sensitive'   => false,
            'config_key'  => null,
        ],
        'auth.step_up.allow_webauthn' => [
            'label'       => 'Accept WebAuthn passkey for step-up',
            'type'        => 'bool',
            'default'     => true,
            'group'       => 'step_up',
            'description' => 'Allow a fresh WebAuthn (passkey) assertion to satisfy the step-up gate. Strongest method; requires the user to have a registered passkey.',
            'sensitive'   => false,
            'config_key'  => null,
        ],
        'auth.step_up.allow_provider_reauth' => [
            'label'       => 'Accept provider re-authentication for step-up',
            'type'        => 'bool',
            'default'     => true,
            'group'       => 'step_up',
            'description' => 'Fall back to the user\'s primary login credential (local password, OIDC prompt=login, or other provider re-auth) when no MFA method is available. Disable to force MFA enrollment for any sensitive action.',
            'sensitive'   => false,
            'config_key'  => null,
        ],
        'auth.step_up.ttl_seconds' => [
            'label'       => 'Step-up cache duration',
            // Stored as a string of seconds so the UI can render a discrete
            // dropdown via the registry options mechanism (only string-typed
            // fields render <select> in views/settings_group_form.php).
            // ipam_sudo_policy() coerces with to_int() before use.
            'type'        => 'string',
            'default'     => '300',
            'group'       => 'step_up',
            'description' => 'How long a successful step-up grant remains valid before the user is re-prompted on the next sensitive action.',
            'sensitive'   => false,
            'config_key'  => null,
            'options'     => [
                '0'    => 'Re-prompt every action',
                '60'   => '1 minute',
                '300'  => '5 minutes',
                '900'  => '15 minutes',
                '1800' => '30 minutes',
                '3600' => '1 hour',
            ],
        ],

        // --- Password policy ---
        'password_policy.min_length' => [
            'label'       => 'Minimum password length',
            'description' => 'Minimum number of characters required.',
            'type'        => 'int',
            'group'       => 'password_policy',
            'default'     => 12,
            'sensitive'   => false,
            'config_key'  => ['password_policy', 'min_length'],
            'min'         => 8,
        ],
        'password_policy.require_uppercase' => [
            'label'       => 'Require uppercase letter',
            'description' => 'Password must contain at least one uppercase letter.',
            'type'        => 'bool',
            'group'       => 'password_policy',
            'default'     => false,
            'sensitive'   => false,
            'config_key'  => ['password_policy', 'require_uppercase'],
        ],
        'password_policy.require_lowercase' => [
            'label'       => 'Require lowercase letter',
            'description' => 'Password must contain at least one lowercase letter.',
            'type'        => 'bool',
            'group'       => 'password_policy',
            'default'     => false,
            'sensitive'   => false,
            'config_key'  => ['password_policy', 'require_lowercase'],
        ],
        'password_policy.require_number' => [
            'label'       => 'Require digit',
            'description' => 'Password must contain at least one digit.',
            'type'        => 'bool',
            'group'       => 'password_policy',
            'default'     => false,
            'sensitive'   => false,
            'config_key'  => ['password_policy', 'require_number'],
        ],
        'password_policy.require_symbol' => [
            'label'       => 'Require special character',
            'description' => 'Password must contain at least one symbol.',
            'type'        => 'bool',
            'group'       => 'password_policy',
            'default'     => false,
            'sensitive'   => false,
            'config_key'  => ['password_policy', 'require_symbol'],
        ],
        'password_policy.max_password_age_days' => [
            'label'       => 'Password expiry (days)',
            'description' => 'Force a password change after this many days. 0 = never expires.',
            'type'        => 'int',
            'group'       => 'password_policy',
            'default'     => 0,
            'sensitive'   => false,
            'config_key'  => ['password_policy', 'max_password_age_days'],
            'min'         => 0,
        ],

        // --- Display ---
        'display.utilization_warn' => [
            'label'       => 'Utilization warning threshold (%)',
            'description' => 'Subnet utilization bars turn yellow at this percentage.',
            'type'        => 'int',
            'group'       => 'display',
            'default'     => 80,
            'sensitive'   => false,
            'config_key'  => 'utilization_warn',
            'min'         => 0,
            'max'         => 100,
        ],
        'display.utilization_critical' => [
            'label'       => 'Utilization critical threshold (%)',
            'description' => 'Subnet utilization bars turn red at this percentage.',
            'type'        => 'int',
            'group'       => 'display',
            'default'     => 95,
            'sensitive'   => false,
            'config_key'  => 'utilization_critical',
            'min'         => 0,
            'max'         => 100,
        ],
        'display.auto_reserve_network_broadcast' => [
            'label'       => 'Auto-reserve network/broadcast/gateway',
            'description' => 'Pre-check the auto-reserve checkbox on the Add Subnet form.',
            'type'        => 'bool',
            'group'       => 'display',
            'default'     => true,
            'sensitive'   => false,
            'config_key'  => 'auto_reserve_network_broadcast',
        ],
        'display.status_hide_version' => [
            'label'       => 'Hide version in status endpoint',
            'description' => 'Remove the version field from the /status.php health check response.',
            'type'        => 'bool',
            'group'       => 'display',
            'default'     => false,
            'sensitive'   => false,
            'config_key'  => 'status_hide_version',
        ],

        // --- SMTP / Email Delivery ---
        'smtp.enabled' => [
            'label'       => 'SMTP enabled',
            'description' => 'Send mail via direct SMTP instead of the server\'s native mail() function.',
            'type'        => 'bool',
            'group'       => 'smtp',
            'default'     => false,
            'sensitive'   => false,
            'config_key'  => null,
        ],
        'smtp.host' => [
            'label'       => 'SMTP host',
            'description' => 'Hostname or IP of the SMTP server (e.g. smtp.gmail.com).',
            'type'        => 'string',
            'group'       => 'smtp',
            'default'     => '',
            'sensitive'   => false,
            'config_key'  => null,
        ],
        'smtp.port' => [
            'label'       => 'SMTP port',
            'description' => 'TCP port — 587 (STARTTLS), 465 (SSL), or 25 (unencrypted).',
            'type'        => 'int',
            'group'       => 'smtp',
            'default'     => 587,
            'sensitive'   => false,
            'config_key'  => null,
            'min'         => 1,
            'max'         => 65535,
        ],
        'smtp.encryption' => [
            'label'       => 'Encryption',
            'description' => 'Transport-layer encryption type.',
            'type'        => 'string',
            'group'       => 'smtp',
            'default'     => 'starttls',
            'sensitive'   => false,
            'config_key'  => null,
            'options'     => [
                'starttls' => 'STARTTLS (recommended)',
                'ssl'      => 'SSL/TLS',
                'none'     => 'None (unencrypted)',
            ],
        ],
        'smtp.auth_user' => [
            'label'       => 'SMTP username',
            'description' => 'Login username for SMTP authentication. Leave blank for anonymous relay.',
            'type'        => 'string',
            'group'       => 'smtp',
            'default'     => '',
            'sensitive'   => false,
            'config_key'  => null,
        ],
        'smtp.auth_pass' => [
            'label'       => 'SMTP password',
            'description' => 'Login password for SMTP authentication.',
            'type'        => 'string',
            'group'       => 'smtp',
            'default'     => '',
            'sensitive'   => true,
            'config_key'  => null,
        ],
        'smtp.from_address' => [
            'label'       => 'From address',
            'description' => 'Envelope From address for outbound mail (e.g. ipam@example.com).',
            'type'        => 'string',
            'group'       => 'smtp',
            'default'     => '',
            'sensitive'   => false,
            'config_key'  => null,
        ],
        'smtp.from_name' => [
            'label'       => 'From name',
            'description' => 'Display name shown in the From header (e.g. IPAM Alerts).',
            'type'        => 'string',
            'group'       => 'smtp',
            'default'     => '',
            'sensitive'   => false,
            'config_key'  => null,
        ],
        'smtp.verify_peer' => [
            'label'       => 'Verify TLS certificate',
            'description' => 'Reject connections with invalid or self-signed certificates. Disable only in dev/test environments.',
            'type'        => 'bool',
            'group'       => 'smtp',
            'default'     => true,
            'sensitive'   => false,
            'config_key'  => null,
        ],
        'smtp.timeout_seconds' => [
            'label'       => 'Connection timeout (seconds)',
            'description' => 'Maximum seconds to wait for the SMTP server to respond.',
            'type'        => 'int',
            'group'       => 'smtp',
            'default'     => 10,
            'sensitive'   => false,
            'config_key'  => null,
            'min'         => 1,
            'max'         => 120,
        ],

        // --- Webhooks ---
        'webhook.retention_days' => [
            'label'       => 'Delivery log retention (days)',
            'description' => 'Webhook delivery log rows older than this are pruned during housekeeping. 0 = keep forever.',
            'type'        => 'int',
            'group'       => 'webhooks',
            'default'     => 30,
            'sensitive'   => false,
            'config_key'  => null,
            'min'         => 0,
        ],
        'webhook.allow_private_ips' => [
            'label'       => 'Allow private IP webhook targets',
            'description' => 'When enabled, outbound webhook deliveries may target RFC-1918 / loopback addresses. Enable only in isolated dev/test environments.',
            'type'        => 'bool',
            'group'       => 'webhooks',
            'default'     => false,
            'sensitive'   => false,
            'config_key'  => null,
        ],
    ];
}

/**
 * Derive the 11-value logical type for a registry entry. Used by
 * ipam_setting_definitions() to enrich every entry with its `logical_type`.
 * Without this derivation an enum/secret/url/email/cidr/timezone setting would
 * collapse to its raw storage type ('string'), causing enum validation to
 * silently accept out-of-set values.
 *
 * Pure function of ($key, $def) — no external dependencies.
 *
 * @param string               $key the setting key (e.g. 'login_protection.method')
 * @param array<string, mixed> $def one entry from ipam_setting_definitions_registry()
 */
function ipam_setting_definitions_logical_type(string $key, array $def): string
{
    $base = is_string($def['type'] ?? null) ? $def['type'] : 'string';
    if (!empty($def['sensitive'])) {
        return 'secret';
    }
    if (array_key_exists('options', $def)) {
        return ($def['options'] ?? null) === '@timezone' ? 'timezone' : 'enum';
    }
    // Reclassify to url/email/cidr only where the key name makes the
    // semantic type unambiguous. Datetime: no registry entry today.
    if ($base !== 'string') {
        return $base;
    }
    // Allow-list reclassifies the string-typed registry entries whose key name
    // makes their semantic type unambiguous.
    return match ($key) {
        'oidc.discovery_url', 'oidc.redirect_uri' => 'url',
        'smtp.from_address', 'alert.email'        => 'email',
        'security.proxy_trust_cidrs'              => 'cidr',
        default                                   => $base,
    };
}

/**
 * The setting registry (ADR-001 Option D). Computed purely from the PHP
 * registry (ipam_setting_definitions_registry()) — there is no DB table. Each
 * entry is enriched with two type keys and memoised in a per-request static.
 *
 * Each entry carries BOTH:
 *   - `storage_type` — the 4-value STORAGE type (string|int|bool|json),
 *                      computed from the logical type via
 *                      ipam_setting_storage_type(). Drives encode/decode and
 *                      the per-row value serialization.
 *   - `logical_type` — the 11-value logical type (string|int|bool|json|enum|
 *                      secret|url|email|timezone|cidr|datetime), derived via
 *                      ipam_setting_definitions_logical_type().
 * A bare `type` key is NEVER present on the returned arrays.
 *
 * Each returned entry is an `array<string, mixed>` with required keys
 * `label`, `storage_type` (4-value), `logical_type` (11-value) and `group`,
 * plus optional keys. A contract test in the settings test suite enforces the
 * absence of a bare `type` key.
 *
 * @return array<string, array<string, mixed>>
 */
function ipam_setting_definitions(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $out = [];
    foreach (ipam_setting_definitions_registry() as $key => $def) {
        $logicalType = ipam_setting_definitions_logical_type((string) $key, $def);
        $storageType = ipam_setting_storage_type($logicalType);
        unset($def['type']);
        $def['storage_type'] = $storageType;
        $def['logical_type'] = $logicalType;
        $out[(string) $key] = $def;
    }
    $memo = $out;
    return $out;
}

/**
 * Map one of the 11 logical setting types to the 4-value STORAGE type used by
 * the settings.value column and its CHECK constraint. int/bool/json map to
 * themselves; every other logical type (string, enum, secret, url, email,
 * timezone, cidr, datetime) stores as 'string'.
 *
 * ADR-001 § Implications.
 */
function ipam_setting_storage_type(string $logicalType): string
{
    return match ($logicalType) {
        'int'   => 'int',
        'bool'  => 'bool',
        'json'  => 'json',
        default => 'string',
    };
}

/**
 * Validate a value against a logical setting type. Returns true when valid, or
 * a human-readable error string when not. Most optional settings default to
 * '' so the empty string is accepted as "unset" for the string-like types.
 *
 * ADR-001 § Implications — the type-driven validation path.
 *
 * @param array<string, mixed> $def the setting definition (for min/max/options/multiline)
 * @return true|string
 */
function ipam_setting_validate(string $logicalType, mixed $value, array $def): true|string
{
    switch ($logicalType) {
        case 'int':
            // Reject non-integer input at the root (Finding 4): is_numeric()
            // alone would let '1.5' through and then (int)-truncate it. Accept
            // a native int or an integer-valued string ('42'); reject floats,
            // fractional strings ('1.5'), and non-numeric strings ('abc').
            if (is_int($value)) {
                $n = $value;
            } elseif (is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value)) {
                $n = (int) trim($value);
            } else {
                return 'Must be an integer.';
            }
            if (isset($def['min']) && is_numeric($def['min']) && $n < (int) $def['min']) {
                return 'Must be at least ' . (int) $def['min'] . '.';
            }
            if (isset($def['max']) && is_numeric($def['max']) && $n > (int) $def['max']) {
                return 'Must be at most ' . (int) $def['max'] . '.';
            }
            return true;

        case 'bool':
            // Always valid — ipam_setting_encode() coerces any value.
            return true;

        case 'json':
            if (is_array($value)) {
                return true;
            }
            if (is_string($value)) {
                json_decode($value);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return true;
                }
            }
            return 'Must be valid JSON.';

        case 'enum':
            $options = ipam_setting_options($def);
            if ($options === null) {
                // No resolvable domain — cannot constrain, accept.
                return true;
            }
            $candidate = is_scalar($value) ? (string) $value : '';
            if (array_key_exists($candidate, $options)) {
                return true;
            }
            return 'Must be one of: ' . implode(', ', array_keys($options)) . '.';

        case 'secret':
        case 'string':
            return is_scalar($value) || $value === null ? true : 'Must be a string.';

        case 'url':
            $s = is_scalar($value) ? (string) $value : '';
            if ($s === '') {
                return true;
            }
            return filter_var($s, FILTER_VALIDATE_URL) !== false ? true : 'Must be a valid URL.';

        case 'email':
            $s = is_scalar($value) ? (string) $value : '';
            if ($s === '') {
                return true;
            }
            return filter_var($s, FILTER_VALIDATE_EMAIL) !== false ? true : 'Must be a valid email address.';

        case 'timezone':
            $s = is_scalar($value) ? (string) $value : '';
            if ($s === '') {
                return true;
            }
            return in_array($s, DateTimeZone::listIdentifiers(), true)
                ? true
                : 'Must be a valid PHP timezone identifier.';

        case 'cidr':
            $s = is_scalar($value) ? (string) $value : '';
            if ($s === '') {
                return true;
            }
            $lines = !empty($def['multiline'])
                ? preg_split('/\r\n|\r|\n/', $s)
                : [$s];
            if ($lines === false) {
                $lines = [$s];
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (parse_cidr($line) === null) {
                    return 'Invalid CIDR: ' . $line;
                }
            }
            return true;

        case 'datetime':
            $s = is_scalar($value) ? (string) $value : '';
            if ($s === '') {
                return true;
            }
            return strtotime($s) !== false ? true : 'Must be a parseable date/time.';

        default:
            // Unknown logical type — be permissive rather than block a save.
            return true;
    }
}

/**
 * Ordered list of setting groups for the admin UI.
 *
 * @return array<string, array<string, mixed>>
 */
function ipam_setting_groups(): array
{
    return [
        'branding'             => ['label' => 'Branding',             'description' => 'Display name and timezone shown across the UI.'],
        'security'             => ['label' => 'Security',             'description' => 'Session lifetime and login lockout policy.'],
        'step_up'              => ['label' => 'Step-up authentication', 'description' => 'Re-authentication policy for sensitive admin actions (vault key, sensitive setting reveal, DB import, API key creation, MFA disable, webhook create/edit/delete, SMTP and backup-notification-recipient settings, custom field definition changes). Decoupled from the login provider so OIDC users can manage these without a local password.'],
        'mfa'                  => ['label' => 'Multi-Factor Authentication', 'description' => 'Available 2FA methods and enforcement policy.'],
        'password_policy'      => ['label' => 'Password policy',      'description' => 'Complexity requirements and rotation for local passwords.'],
        'alert'                => ['label' => 'Alerting',             'description' => 'Subnet utilization email alerts.'],
        'update_check'         => ['label' => 'Update checker',       'description' => 'GitHub release checker for the in-app upgrade banner.'],
        'login_protection'     => ['label' => 'Login protection',     'description' => 'Bot and abuse mitigation on the login form.'],
        'recaptcha_enterprise' => ['label' => 'reCAPTCHA Enterprise', 'description' => "Backend verification via Google's reCAPTCHA Enterprise API."],
        'oidc'                 => ['label' => 'OIDC / SSO',           'description' => 'OpenID Connect single sign-on.'],
        'housekeeping'         => ['label' => 'Housekeeping',         'description' => 'Temp cleanup, log pruning, and alert check intervals.'],
        'backup'               => ['label' => 'Database backup',      'description' => 'Automatic database backup schedule and retention.'],
        'limits'               => ['label' => 'Upload limits',        'description' => 'Maximum file sizes for CSV and SQL imports.'],
        'api'                  => ['label' => 'API',                  'description' => 'Rate limiting and bulk write limits for the REST API.'],
        'display'              => ['label' => 'Display',              'description' => 'Utilization thresholds, auto-reserve defaults, and UI toggles.'],
        'smtp'                 => ['label' => 'SMTP / Email Delivery', 'description' => 'Direct SMTP delivery for utilization alerts. Falls back to native mail() when disabled.'],
        'webhooks'             => ['label' => 'Webhooks',             'description' => 'Outbound HMAC-signed HTTP callbacks on address and subnet changes.'],
    ];
}

/**
 * Look up a value in the config.php array given either a flat key or a nested
 * path. Used by the v2.6.0 back-compat fallback in ipam_setting() and by the
 * settings table seeder. Returns null when the key is not present.
 *
 * @param array<array-key, mixed> $config
 * @param string|array<array-key, mixed>|null $configKey
 */
function ipam_setting_config_fallback(array $config, string|array|null $configKey): mixed
{
    if ($configKey === null) return null;
    if (is_string($configKey)) {
        return array_key_exists($configKey, $config) ? $config[$configKey] : null;
    }
    $cursor = $config;
    foreach ($configKey as $segment) {
        if (!is_string($segment) && !is_int($segment)) return null;
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) return null;
        $cursor = $cursor[$segment];
    }
    return $cursor;
}

/**
 * Encode a PHP value for storage in the settings.value TEXT column, given a type.
 */
function ipam_setting_encode(mixed $value, string $type): string
{
    switch ($type) {
        case 'bool':
            return ($value === true || $value === 1 || $value === '1' || (is_string($value) && strtolower($value) === 'true')) ? '1' : '0';
        case 'int':
            return (string)(int)(is_numeric($value) ? $value : 0);
        case 'json':
            $encoded = json_encode($value);
            return is_string($encoded) ? $encoded : 'null';
        default:
            return is_scalar($value) ? (string)$value : '';
    }
}

/**
 * Decode a value read from the settings.value column back to its PHP type. On
 * JSON decode failure returns $default and logs a warning.
 */
function ipam_setting_decode(?string $stored, string $type, mixed $default): mixed
{
    if ($stored === null) return $default;
    switch ($type) {
        case 'bool':
            return ($stored === '1' || strtolower($stored) === 'true');
        case 'int':
            return (int)$stored;
        case 'json':
            $decoded = json_decode($stored, true);
            if ($decoded === null && strtolower(trim($stored)) !== 'null') {
                error_log("ipam_setting_decode: invalid JSON in settings row, returning default");
                return $default;
            }
            return $decoded;
        default:
            return $stored;
    }
}

/**
 * Infer a type string from a PHP value. Used when callers write without an
 * explicit type hint.
 */
function ipam_setting_infer_type(mixed $value): string
{
    if (is_bool($value)) return 'bool';
    if (is_int($value))  return 'int';
    if (is_array($value)) return 'json';
    return 'string';
}

/**
 * Read a setting. Fallback chain:
 *   1. Tenant-scoped DB row (only when $tenantId is non-null)
 *   2. Global DB row (tenant_id IS NULL)
 *   3. config.php via the registry's config_key path (v2.6 back-compat for
 *      installs that have not yet migrated config.php values to the DB)
 *   4. Registry default -> $default argument
 *
 * Never throws. Results are memoised via ipam_setting_cache_get/_set keyed by
 * "{tenantId}:{key}" so repeated reads in a single request don't re-query the
 * DB; ipam_setting_set() / ipam_setting_cache_bust() invalidate the cache.
 *
 * In v3.x all settings rows have tenant_id = NULL, so callers that do not pass
 * $tenantId continue to work identically to before. The parameter is
 * groundwork for the v4.0.0 multi-tenancy cascade.
 */
/**
 * Decrypt a DB-sourced settings value if it is a managed secret envelope.
 * Returns the plaintext (or the unchanged value if not an envelope).
 *
 * @throws IpamSecretDecryptException if a managed envelope cannot be decrypted.
 */
function ipam_setting_decrypt_db_value(string $key, ?string $value): ?string
{
    if (is_string($value) && ipam_secret_is_managed_key($key) && ipam_secret_is_envelope($value)) {
        $decrypted = ipam_secret_decrypt($value);
        if ($decrypted === null) {
            throw new IpamSecretDecryptException(
                "Failed to decrypt settings secret '$key'. " .
                'app_secret in config.php may have changed or the row is corrupt.'
            );
        }
        return $decrypted;
    }
    return $value;
}

function ipam_setting(string $key, mixed $default = null, ?int $tenantId = null): mixed
{
    $cached = ipam_setting_cache_get($key, $tenantId);
    if ($cached !== IPAM_SETTING_CACHE_MISS) return $cached;

    $definitions = ipam_setting_definitions();
    $def         = $definitions[$key] ?? null;
    $storageType = is_array($def) && is_string($def['storage_type'] ?? null) ? $def['storage_type'] : 'string';
    $fallback = ($def !== null && array_key_exists('default', $def))
        ? $def['default']
        : $default;

    try {
        $db = $GLOBALS['db'] ?? null;
        if ($db instanceof PDO) {
            $kc = ipam_key_col();

            // Step 1: tenant-scoped row (only when a tenantId is provided).
            if ($tenantId !== null) {
                $st = $db->prepare("SELECT value FROM settings WHERE tenant_id = :t AND {$kc} = :k");
                $st->execute([':t' => $tenantId, ':k' => $key]);
                $row = $st->fetch();
                if (is_array($row)) {
                    // settings.type was dropped in v3.30.0 (ADR-001): the stored
                    // type is always the definition's storage type ($storageType).
                    $value      = is_string($row['value'] ?? null) ? $row['value'] : null;
                    // v3.31.0 (#1233): decrypt managed settings secrets read
                    // from the DB. config.php fallback (Step 3) is plaintext
                    // and must NOT pass through here.
                    $value      = ipam_setting_decrypt_db_value($key, $value);
                    $decoded    = ipam_setting_decode($value, $storageType, $fallback);
                    ipam_setting_cache_set($key, $decoded, $tenantId);
                    return $decoded;
                }
            }

            // Step 2: global row (tenant_id IS NULL) — always checked.
            $st = $db->prepare("SELECT value FROM settings WHERE tenant_id IS NULL AND {$kc} = :k");
            $st->execute([':k' => $key]);
            $row = $st->fetch();
            if (is_array($row)) {
                // settings.type was dropped in v3.30.0 (ADR-001): the stored
                // type is always the definition's storage type ($storageType).
                $value      = is_string($row['value'] ?? null) ? $row['value'] : null;
                // v3.31.0 (#1233): decrypt managed settings secrets read from
                // the DB. config.php fallback (Step 3) is plaintext and must
                // NOT pass through here.
                $value      = ipam_setting_decrypt_db_value($key, $value);
                $decoded    = ipam_setting_decode($value, $storageType, $fallback);
                ipam_setting_cache_set($key, $decoded, $tenantId);
                return $decoded;
            }
        }
    } catch (\PDOException $e) {
        // Differentiate "schema not migrated yet" (silent fallback to config)
        // from a real DB error (log + rethrow so the caller sees the failure
        // instead of getting a stale fallback value). PDO surfaces "missing
        // table/column" with SQLSTATE 42S02 / 42703, plus a per-engine
        // human-readable message that we also pattern-match for resilience
        // against driver quirks where SQLSTATE may not be set.
        $sqlstate = $e->getCode();
        $msg      = $e->getMessage();
        $isMissingSchema =
            $sqlstate === '42S02' || $sqlstate === '42703' ||
            // MySQL/MariaDB report 'Unknown column' under SQLSTATE 42S22
            // (CR #1100 review). Pre-migration installs missing tenant_id
            // would otherwise rethrow during bootstrap instead of taking
            // the documented config/default fallback.
            $sqlstate === '42S22' ||
            stripos($msg, 'no such table') !== false ||
            stripos($msg, 'no such column') !== false ||
            stripos($msg, 'undefined table') !== false ||
            stripos($msg, 'undefined column') !== false ||
            stripos($msg, 'unknown column') !== false;
        if (!$isMissingSchema) {
            error_log("ipam_setting: read failed for key {$key}: {$msg}");
            throw $e;
        }
        // Pre-migration fallback path — silently fall through.
    } catch (IpamSecretDecryptException $e) {
        // v3.31.0 (#1233): a decrypt failure for a managed settings secret
        // (envelope present but undecryptable — app_secret changed or row
        // corrupt) must fail loud, not silently fall back to a stale config
        // or registry default. Re-throw so the caller sees it. Every other
        // RuntimeException falls through to the catch-all below and is
        // swallowed exactly as before this task.
        error_log("ipam_setting: read failed for key {$key}: " . $e->getMessage());
        throw $e;
    } catch (\Throwable $e) {
        // Non-PDO failure (e.g. cache helper bug). Log and fall through to the
        // config back-compat path; do not rethrow because callers that read
        // settings during bootstrap cannot meaningfully recover.
        error_log("ipam_setting: read failed for key {$key}: " . $e->getMessage());
    }

    // Step 3: config.php back-compat — installs that have not yet migrated
    // their config.php values to the settings table still get the correct
    // value here rather than falling through to the registry default.
    // ADR-003: config is read via ipam_config(), not $GLOBALS['config'].
    $rawConfigKey = is_array($def) ? ($def['config_key'] ?? null) : null;
    $configKey    = (is_string($rawConfigKey) || is_array($rawConfigKey)) ? $rawConfigKey : null;
    // ipam_config() always returns the config array (possibly empty);
    // ipam_setting_config_fallback() handles an empty array safely.
    $cfg          = ipam_config();
    if ($configKey !== null) {
        $cfgVal = ipam_setting_config_fallback($cfg, $configKey);
        if ($cfgVal !== null) {
            ipam_setting_cache_set($key, $cfgVal, $tenantId);
            return $cfgVal;
        }
    }

    ipam_setting_cache_set($key, $fallback, $tenantId);
    return $fallback;
}

/**
 * Write a setting. Infers type from $value unless the registry defines one.
 * Produces a `setting.update` audit entry with old/new values (masked for
 * sensitive keys). Invalidates the per-request cache for the key.
 *
 * When $tenantId is null the row is written to the global layer (tenant_id IS
 * NULL). When non-null it is written to the tenant-scoped layer. In v3.x all
 * callers pass null (the default), so existing behaviour is unchanged.
 *
 * Data-layer validation (architecture review Finding 1): when the key has a
 * registry definition the value is validated against its logical type via
 * ipam_setting_validate() *before* encoding/persisting. An invalid value
 * raises \InvalidArgumentException carrying the validator's human-readable
 * message. A key with no definition has no logical type and is written
 * through unvalidated (the historic behaviour for unknown/synthetic keys).
 *
 * The $validate opt-out is a narrow, greppable escape hatch for the legacy
 * config-import migration (3.0.0-config-import) which replays values from an
 * arbitrarily old config.php — a value that pre-dates the current validator
 * must still be importable, or the upgrade would hard-fail. Only that one
 * call site passes false; every other caller gets the gate.
 */
function ipam_setting_set(
    PDO $db,
    string $key,
    mixed $value,
    ?int $userId = null,
    ?int $tenantId = null,
    bool $validate = true
): void {
    $definitions = ipam_setting_definitions();
    $def         = $definitions[$key] ?? null;

    // Phase 1 — validate the value against its registry logical type (no-op
    // for keys with no definition; throws \InvalidArgumentException otherwise).
    ipam_setting_set_validate($key, $value, $def, $validate);

    // Phase 2 — resolve the storage type and produce the encoded (and, for
    // managed secrets, encrypted) string that will be persisted.
    $storageType = ipam_setting_set_storage_type($def, $value);
    $sensitive   = is_array($def) && !empty($def['sensitive']);
    $encoded     = ipam_setting_set_encode_for_storage($key, $value, $storageType, $sensitive);

    // Phase 3 — acquire the MySQL advisory lock (no-op on SQLite/PostgreSQL).
    $mysqlLockName = ipam_setting_set_lock_acquire($db, $key, $tenantId);

    // Phase 4 — run the SELECT-then-UPDATE/INSERT upsert and the audit write
    // inside a single transaction (own transaction only when none is active).
    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    }
    try {
        $oldRaw = ipam_setting_set_upsert($db, $key, $tenantId, $encoded, $userId);
        ipam_setting_set_audit($db, $key, $oldRaw, $encoded, $storageType, $sensitive);

        if ($ownTx) {
            $db->commit();
        }
    } catch (\Throwable $ex) {
        // Best-effort rollback. PHPStan 2.1.54 narrows
        // PDO::inTransaction() to always-false in this catch path
        // (incorrectly — a throw in the SELECT/UPDATE/INSERT block
        // before commit() leaves the transaction open). Wrap rollBack()
        // in its own try/catch so a "no active transaction" PDOException
        // does not mask the original exception we're propagating.
        if ($ownTx) {
            try { $db->rollBack(); } catch (\Throwable) {}
        }
        throw $ex;
    } finally {
        ipam_setting_set_lock_release($db, $mysqlLockName);
    }

    // Phase 5 — bust the per-request cache by forcing a re-read on next call.
    ipam_setting_cache_bust($key);
}

/**
 * Data-layer type gate for ipam_setting_set(). Validates $value against the
 * key's registry logical type. A key with no registry definition has no
 * logical type, so validation is a no-op pass — never a throw — for unknown
 * keys. Likewise a no-op when $validate is false (the legacy config-import
 * escape hatch).
 *
 * @param string             $key      Setting key being written.
 * @param mixed              $value    Caller-supplied value.
 * @param array<mixed>|null  $def      Registry definition for $key, or null.
 * @param bool               $validate When false, skip the gate entirely.
 * @return void
 * @throws \InvalidArgumentException When the value fails its logical-type validator.
 * @internal
 */
function ipam_setting_set_validate(string $key, mixed $value, ?array $def, bool $validate): void
{
    if ($validate && is_array($def)) {
        $logicalType = is_string($def['logical_type'] ?? null) && $def['logical_type'] !== ''
            ? $def['logical_type']
            : (is_string($def['storage_type'] ?? null) ? $def['storage_type'] : 'string');
        $verdict = ipam_setting_validate($logicalType, $value, $def);
        if ($verdict !== true) {
            throw new \InvalidArgumentException(
                "Invalid value for setting '{$key}': {$verdict}"
            );
        }
    }
}

/**
 * Resolve the storage type for a write: the registry's declared storage_type
 * when present, otherwise the type inferred from the value itself.
 *
 * @param array<mixed>|null $def   Registry definition for the key, or null.
 * @param mixed             $value Caller-supplied value (used for inference).
 * @return string The storage type to encode against.
 * @internal
 */
function ipam_setting_set_storage_type(?array $def, mixed $value): string
{
    return (is_array($def) && is_string($def['storage_type'] ?? null) && $def['storage_type'] !== '')
        ? $def['storage_type']
        : ipam_setting_infer_type($value);
}

/**
 * Encode a value for persistence, applying encrypt-at-rest for sensitive
 * managed-secret keys.
 *
 * v3.31.0 (#1233): encrypt-at-rest for managed settings secrets.
 * ipam_secret_is_managed_key() excludes backup_vault_key (own envelope).
 * ipam_setting_encode() always returns a string, so no is_string() guard is
 * needed here (PHPStan: it would be an always-true narrowed type).
 *
 * @param string $key         Setting key being written.
 * @param mixed  $value        Caller-supplied value.
 * @param string $storageType  Storage type resolved by ipam_setting_set_storage_type().
 * @param bool   $sensitive    Whether the registry flags this key as sensitive.
 * @return string The string to store in settings.value.
 * @internal
 */
function ipam_setting_set_encode_for_storage(string $key, mixed $value, string $storageType, bool $sensitive): string
{
    $encoded = ipam_setting_encode($value, $storageType);
    if ($sensitive && ipam_secret_is_managed_key($key)) {
        $encoded = ipam_secret_encrypt($encoded);
    }
    return $encoded;
}

/**
 * Acquire the MySQL advisory lock that serialises the SELECT->INSERT for a
 * key/scope. No-op on SQLite and PostgreSQL, which enforce uniqueness via
 * partial indexes (uq_settings_global, uq_settings_tenant).
 *
 * MySQL's composite UNIQUE(tenant_id, key) allows multiple NULL tenant_id
 * values per SQL standard, so two concurrent writers could both see "row does
 * not exist" and both INSERT, creating duplicate global rows. GET_LOCK blocks
 * until the lock is free (or the 5 s timeout elapses).
 *
 * Cross-references — read together if you change any of the three:
 *   - migrations.php :: 3.13.0-settings-cascade (cross-engine UQ shape)
 *   - tests/SchemaParityTest.php (whitelist of the divergence)
 *   - this lock (the runtime fix MySQL needs to match the partial-index
 *     semantics SQLite/PG get for free)
 * E1 (#884) cross-reference complete.
 *
 * @param PDO      $db       Database handle.
 * @param string   $key      Setting key being written.
 * @param int|null $tenantId Tenant scope, or null for the global layer.
 * @return string|null The lock name to release later, or null when no lock was taken.
 * @internal
 */
function ipam_setting_set_lock_acquire(PDO $db, string $key, ?int $tenantId): ?string
{
    if (ipam_dialect()->driver_name() !== 'mysql') {
        return null;
    }
    // MySQL GET_LOCK names are capped at 64 bytes and silently truncate
    // beyond that — two long keys sharing a 50+ char prefix would both hash
    // to the same lock and deadlock-ish. Hash the composed name to a fixed
    // 32-char digest so length is bounded regardless of key.
    $mysqlLockName = 'ipam_setting:' . md5($key . ':' . ($tenantId === null ? '__GLOBAL__' : (string)$tenantId));
    $db->prepare("SELECT GET_LOCK(:n, 5)")->execute([':n' => $mysqlLockName]);
    return $mysqlLockName;
}

/**
 * Release a MySQL advisory lock taken by ipam_setting_set_lock_acquire().
 * Best-effort: a failed release is swallowed because the connection close
 * frees the lock regardless. No-op when $mysqlLockName is null.
 *
 * @param PDO         $db            Database handle.
 * @param string|null $mysqlLockName Lock name from ipam_setting_set_lock_acquire().
 * @return void
 * @internal
 */
function ipam_setting_set_lock_release(PDO $db, ?string $mysqlLockName): void
{
    if ($mysqlLockName === null) {
        return;
    }
    try {
        $db->prepare("SELECT RELEASE_LOCK(:n)")->execute([':n' => $mysqlLockName]);
    } catch (\Throwable $_) {
        // Best-effort: the connection close will free it regardless.
    }
}

/**
 * Persist the encoded value: SELECT the existing row for the scope, then
 * UPDATE in place or INSERT. Must run inside the caller's transaction.
 *
 * We use explicit UPDATE-then-INSERT rather than a dialect upsert because
 * SQLite treats NULL as distinct from NULL in UNIQUE index lookups, so
 * ON CONFLICT(tenant_id, key) never fires for global (tenant_id IS NULL)
 * rows. The tenant WHERE clause is built as a literal condition rather than
 * a parameterized NULL so PostgreSQL can infer the data type (it raises
 * "indeterminate datatype" on bare NULL parameters in prepared statements).
 *
 * @param PDO      $db       Database handle (inside an active transaction).
 * @param string   $key      Setting key being written.
 * @param int|null $tenantId Tenant scope, or null for the global layer.
 * @param string   $encoded  The encoded value to persist.
 * @param int|null $userId   The acting user id, recorded as updated_by.
 * @return string|null The previous raw stored value, or null when no row existed.
 * @internal
 */
function ipam_setting_set_upsert(PDO $db, string $key, ?int $tenantId, string $encoded, ?int $userId): ?string
{
    $kc          = ipam_key_col();
    $d           = ipam_dialect();
    $tenantWhere = $tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = :tb';

    // Fetch the existing row for the same scope to produce a meaningful audit diff.
    $st = $db->prepare(
        "SELECT value FROM settings
         WHERE {$tenantWhere} AND {$kc} = :k"
    );
    $stParams = [':k' => $key];
    if ($tenantId !== null) { $stParams[':tb'] = $tenantId; }
    $st->execute($stParams);
    $prev = $st->fetch();

    $oldRaw = null;
    if (is_array($prev)) {
        $oldRaw = is_string($prev['value'] ?? null) ? $prev['value'] : null;

        // Row exists — update in place.
        $up = $db->prepare(
            "UPDATE settings
             SET value = :v, updated_at = {$d->now()}, updated_by = :u
             WHERE {$tenantWhere} AND {$kc} = :k"
        );
        $upParams = [':v' => $encoded, ':u' => $userId, ':k' => $key];
        if ($tenantId !== null) { $upParams[':tb'] = $tenantId; }
        $up->execute($upParams);
    } else {
        // Row does not exist — insert.
        $up = $db->prepare(
            "INSERT INTO settings (tenant_id, {$kc}, value, updated_at, updated_by)
             VALUES (:t, :k, :v, {$d->now()}, :u)"
        );
        $up->execute([
            ':t'  => $tenantId,
            ':k'  => $key,
            ':v'  => $encoded,
            ':u'  => $userId,
        ]);
    }

    return $oldRaw;
}

/**
 * Write the `setting.update` audit row for a settings change. Must run inside
 * the same transaction as the upsert so a failed audit INSERT rolls the
 * settings write back — the change and its audit trail commit atomically.
 * Old/new values are masked for sensitive keys.
 *
 * @param PDO         $db          Database handle (inside an active transaction).
 * @param string      $key         Setting key that was written.
 * @param string|null $oldRaw      Previous raw stored value, or null.
 * @param string      $encoded     The newly persisted encoded value.
 * @param string      $storageType Storage type used to decode old/new for the diff.
 * @param bool        $sensitive   Whether old/new values must be masked.
 * @return void
 * @internal
 */
function ipam_setting_set_audit(
    PDO $db,
    string $key,
    ?string $oldRaw,
    string $encoded,
    string $storageType,
    bool $sensitive
): void {
    $details = [
        'key' => $key,
        'old' => $sensitive ? '***' : ipam_setting_decode($oldRaw, $storageType, null),
        'new' => $sensitive ? '***' : ipam_setting_decode($encoded, $storageType, null),
    ];
    $encodedDetails = json_encode($details);
    audit($db, 'setting.update', 'setting', null, is_string($encodedDetails) ? $encodedDetails : $key);
}

/**
 * Bust the per-request cache for ipam_setting(). Always clears the entire
 * cache, regardless of whether a specific key is passed. Passing a key is
 * accepted for call-site clarity but has no narrowing effect: we cannot
 * enumerate all active tenant IDs to selectively evict only that key's
 * tenant-scoped entries, so a full wipe is the only safe option. The cache
 * is a single-request optimisation and rebuilds cheaply on next access.
 * Also exposed so tests can reset state between assertions. Public name
 * retained for callers; delegates to ipam_setting_cache_clear() (#915).
 */
function ipam_setting_cache_bust(?string $key = null): void
{
    ipam_setting_cache_clear();
}

/**
 * Shared per-request backing store for the ipam_setting() cache (#915). Holds
 * the static array so the get/set/clear trio can all reach it. Not for direct
 * application use.
 *
 * @internal
 * @return array<string, mixed>
 */
function &ipam_setting_cache_store(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Compose the cache key for a setting. 'g' namespaces the global (null) tenant;
 * tenant-scoped rows use the integer string so they never collide with global.
 *
 * @internal
 */
function ipam_setting_cache_key(string $key, ?int $tenantId): string
{
    return ($tenantId === null ? 'g' : (string)$tenantId) . ':' . $key;
}

/**
 * Read a value from the ipam_setting() per-request cache. Returns the cached
 * value, or the IPAM_SETTING_CACHE_MISS sentinel when the key is not cached
 * (#915 — replaces the read mode of the old ipam_setting_cache_storage()).
 *
 * @internal
 */
function ipam_setting_cache_get(string $key, ?int $tenantId): mixed
{
    $cache    = &ipam_setting_cache_store();
    $cacheKey = ipam_setting_cache_key($key, $tenantId);
    return array_key_exists($cacheKey, $cache) ? $cache[$cacheKey] : IPAM_SETTING_CACHE_MISS;
}

/**
 * Store a value in the ipam_setting() per-request cache (#915 — replaces the
 * write mode of the old ipam_setting_cache_storage()).
 *
 * @internal
 */
function ipam_setting_cache_set(string $key, mixed $value, ?int $tenantId): void
{
    $cache = &ipam_setting_cache_store();
    $cache[ipam_setting_cache_key($key, $tenantId)] = $value;
}

/**
 * Wipe the entire ipam_setting() per-request cache, including all
 * tenant-scoped entries (#915 — replaces the __CLEAR__ mode of the old
 * ipam_setting_cache_storage()).
 *
 * @internal
 */
function ipam_setting_cache_clear(): void
{
    $cache = &ipam_setting_cache_store();
    $cache = [];
}

/**
 * @return array<string, mixed> All known setting keys mapped to their effective
 *                              values via ipam_setting(). Used by the admin UI.
 */
function ipam_setting_all(): array
{
    $out = [];
    foreach (array_keys(ipam_setting_definitions()) as $key) {
        $out[$key] = ipam_setting($key);
    }
    return $out;
}

/**
 * Resolve where a setting's current value is coming from: 'db' or 'default'.
 * Used to render the source badge on settings.php. Queries the database
 * directly rather than through the cached helper so the badge reflects ground
 * truth.
 *
 * The legacy 'config' source was removed in v3.0.0 along with the config.php
 * fallback this helper used to inspect — there are only two outcomes now: a
 * row exists in the `settings` table ('db'), or it does not ('default').
 *
 * @return 'db'|'default'
 */
function ipam_setting_source(PDO $db, string $key): string
{
    try {
        $st = $db->prepare("SELECT 1 FROM settings WHERE tenant_id IS NULL AND ".ipam_key_col()." = :k");
        $st->execute([':k' => $key]);
        if ($st->fetchColumn() !== false) return 'db';
    } catch (\Throwable) {
    }
    return 'default';
}

/**
 * @deprecated v3.0.0 — config.php fallback removed. Kept only for the
 * 3.0.0-config-stub migration closure which needs to read old config values.
 * Return the list of registered settings that are still being served from
 * $config (config.php) instead of the database. Drives the v2.7.0 deprecation
 * banner in settings.php, the init.php boot-time log warning, and the
 * dashboard admin card that nudges admins to migrate before v3.0.0 removes
 * the fallback.
 *
 * A key is considered deprecated iff **all** of these hold:
 *   - its registry definition exists (bootstrap-only keys like db_driver
 *     are not in the registry so they are never flagged);
 *   - no row exists in the `settings` table for that key;
 *   - the key's `config_key` path resolves to a non-null value in config.php;
 *   - that value differs from the registry `default`.
 *
 * The last condition keeps a pristine install with a seeded default
 * config.php from lighting up the banner for every single key — we only
 * surface what the admin has actually customised.
 *
 * On any DB error the helper returns [] (fail-quiet) so the boot path and
 * the dashboard render never break because of this advisory feature.
 *
 * ADR-003: config is read via ipam_config(), not $GLOBALS['config'].
 *
 * @return list<array{key: string, config_path: string, current: mixed}>
 */
function ipam_setting_deprecated_keys(): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!($db instanceof PDO)) return [];

    try {
        // `key` must be backtick-quoted — it's a MySQL reserved word. SQLite
        // also accepts backticks as identifier delimiters so the same SQL
        // runs on both engines. Without the quotes, MySQL would throw a
        // syntax error that the catch below would silently swallow,
        // returning [] and hiding every deprecation from the UI banner.
        $st   = $db->query("SELECT ".ipam_key_col()." FROM settings WHERE tenant_id IS NULL");
        $rows = $st !== false ? $st->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (\Throwable) {
        return [];
    }
    $inDb = [];
    foreach ($rows as $k) {
        if (is_string($k)) $inDb[$k] = true;
    }

    // ipam_config() always returns the config array (possibly empty).
    $config = ipam_config();

    $out  = [];
    $defs = ipam_setting_definitions();
    foreach ($defs as $key => $def) {
        if (isset($inDb[$key])) continue;
        // CodeRabbit second sweep on PR #450: skip keys that are flagged
        // deprecated in the registry. Otherwise the deprecation banner would
        // offer an "Import to database" button that silently writes a
        // deprecated value (e.g. alert.email) into the settings table, where
        // the save and render loops would then ignore it forever.
        if (!empty($def['deprecated'])) continue;
        $configKey = $def['config_key'] ?? null;
        if ($configKey === null || (!is_string($configKey) && !is_array($configKey))) continue;

        $current = ipam_setting_config_fallback($config, $configKey);
        if ($current === null) continue;

        // Skip values that match the registry default — a config that still
        // holds the shipped default is not "customised". Loose compares keep
        // '0' vs 0 vs false and '0.5' vs 0.5 from producing spurious banner
        // entries. recaptcha_enterprise.score_threshold is the concrete
        // reason the string branch normalises through (string) casts: the
        // registry default is '0.5' while config_defaults keeps 0.5 (float).
        $default = $def['default'] ?? null;
        $storageType = is_string($def['storage_type'] ?? null) ? $def['storage_type'] : 'string';
        if ($storageType === 'bool' && (bool)$current === (bool)$default) continue;
        if ($storageType === 'int'  && is_numeric($current) && is_numeric($default) && (int)$current === (int)$default) continue;
        if ($storageType === 'string' && is_scalar($current) && is_scalar($default) && (string)$current === (string)$default) continue;
        if ($storageType === 'json'   && $current === $default) continue;

        if (is_array($configKey)) {
            $segments   = [];
            foreach ($configKey as $seg) $segments[] = to_str($seg);
            $configPath = implode('.', $segments);
        } else {
            $configPath = $configKey;
        }

        $out[] = [
            'key'         => $key,
            'config_path' => $configPath,
            'current'     => $current,
        ];
    }
    return $out;
}

/**
 * Resolve a setting definition's `options` entry to a `[value => label]` map,
 * or null when the setting is free-form. Supports three registry shapes:
 *
 *   - associative array: returned as-is
 *   - callable: invoked, result must be an associative array
 *   - sentinel string '@timezone': PHP timezone identifiers
 *
 * Unknown sentinels return null so the caller falls back to free-text
 * rendering rather than silently accepting any value.
 *
 * @param array<string, mixed> $def
 * @return array<string, string>|null
 */
function ipam_setting_options(array $def): ?array
{
    if (!array_key_exists('options', $def)) return null;
    $raw = $def['options'];

    if (is_callable($raw)) {
        $resolved = $raw();
    } elseif ($raw === '@timezone') {
        $ids = DateTimeZone::listIdentifiers();
        $resolved = array_combine($ids, $ids);
    } elseif (is_array($raw)) {
        $resolved = $raw;
    } else {
        return null;
    }

    if (!is_array($resolved)) return null;

    // Normalise to string => string for consistent rendering and strict
    // validation with array_key_exists() on the persisted value.
    $out = [];
    foreach ($resolved as $value => $label) {
        $out[(string)$value] = is_scalar($label) ? (string)$label : (string)$value;
    }
    return $out;
}
