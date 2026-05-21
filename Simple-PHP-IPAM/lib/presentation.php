<?php
declare(strict_types=1);

/**
 * @module presentation
 *
 * Page-chrome and reusable UI-helper functions extracted from lib.php in
 * v3.30.0 (ADR-004 Phase 5 Task 5.1). Finishes the partial decomposition
 * tracked by #910 (Finding A15). Functions stay in the global namespace
 * per ADR-004 Option E.
 *
 * Inclusion rule: functions whose primary job is emitting page chrome
 * (page_header / page_footer), rendering a generic reusable HTML fragment
 * (icon, badges, banners, sortable <th>, custom-field inputs), the
 * view-partial helpers (ipam_render / ipam_render_string), the pagination
 * math helper (paginate), or the session flash store (flash_set /
 * flash_get).
 *
 * Excluded on purpose: ipam_render_dhcpd_conf / ipam_render_kea_json
 * (DHCP config generation) and ipam_render_backup_run_detail (backup
 * domain) — they match `*render*` but belong to other domains and stay in
 * lib.php until their own modules are carved out.
 *
 * Dependencies: the plan calls this "Deps: utils" but the real set is
 * wider. lib/utils.php (to_str, to_int, e), lib/config.php (ipam_config,
 * ipam_config_nested — see ADR-003 note below). render_contact_badges /
 * render_tag_badges / render_install_key_banner take a PDO and query the
 * DB via get_contacts_for_entity() / get_tags_for_entity() / ipam_setting()
 * — those helpers, plus csrf_token(), recovery_mode_enabled(),
 * demo_mode_enabled(), ipam_asset_buster(), ipam_update_check() and
 * ipam_install_key_banner_handle_dismiss(), still live in lib.php (loaded
 * AFTER this module — resolved lazily at call time, never at include time).
 *
 * Path note: ipam_render() and page_header()'s inline SVG sprite, plus the
 * version.php require in page_header()/page_footer(), use
 * `dirname(__DIR__) . '/...'` so they resolve against the web root
 * (Simple-PHP-IPAM/) rather than this module's lib/ directory. The
 * original code in lib.php used a bare `__DIR__` which already pointed at
 * the web root.
 *
 * ADR-003 applied at extraction time: every former `global $config;` read
 * in page_header()/page_footer() is replaced with an ipam_config() /
 * ipam_config_nested() call. The `global $db;` reads are NOT config —
 * $db is the runtime PDO handle opened by init.php — so they stay.
 *
 * #910: this task does the behaviour-preserving function MOVE. The further
 * "extract page_header()'s nav/CSP/sidebar inline HTML into views/*.php
 * partials" remainder of #910 was evaluated and deferred — page_header()'s
 * inline echo blocks are interleaved with PHP logic (active-page detection,
 * role gating, per-block config checks) such that a views/ extraction is a
 * judgement call, not a mechanical move. Doing it here would be a risky
 * restructure outside the behaviour-preserving floor this task requires.
 */

/**
 * Render a PHP partial from Simple-PHP-IPAM/views/<view>.php.
 *
 * Props are extracted into the partial's local scope via extract() with
 * EXTR_SKIP so that no prop can shadow an existing variable. The partial
 * is a plain PHP file — no compiled syntax, no reserved directives, no
 * templating engine. This is the idiomatic code-reuse pattern for this
 * project (see CLAUDE.md "Runtime dependencies → UI and rendering").
 *
 * @param string               $view  Partial name without the .php extension
 *                                    (e.g. 'subnet_row' for views/subnet_row.php).
 * @param array<string, mixed> $props Variables to extract into the partial scope.
 * @throws \RuntimeException          If the partial file does not exist.
 */
function ipam_render(string $view, array $props = []): void
{
    if ($view !== basename($view) || $view === '') {
        throw new \InvalidArgumentException("Invalid view name: $view");
    }
    $path = dirname(__DIR__) . '/views/' . $view . '.php';
    if (!is_file($path)) {
        throw new \RuntimeException("View not found: $view");
    }
    extract($props, EXTR_SKIP);
    require $path;
}

/**
 * Render a PHP partial and return the output as a string.
 *
 * Buffers the output of ipam_render() and returns it. Useful when the
 * rendered fragment must be stored or passed to another function before
 * being sent to the client.
 *
 * @param string               $view  Partial name without the .php extension.
 * @param array<string, mixed> $props Variables to extract into the partial scope.
 * @return string                     The captured partial output.
 */
function ipam_render_string(string $view, array $props = []): string
{
    $level = ob_get_level();
    ob_start();
    try {
        ipam_render($view, $props);
        $out = ob_get_clean();
        return $out === false ? '' : $out;
    } catch (\Throwable $e) {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
        throw $e;
    }
}

/**
 * Render an SVG icon from the sprite sheet.
 *
 * Returns an inline <svg><use> element referencing the icon sprite.
 * Decorative — always aria-hidden and focusable=false.
 * Use the $cls parameter to add sizing classes (e.g. 'icon-lg').
 *
 * @param string $name  Icon name (without 'icon-' prefix), e.g. 'home', 'cog'
 * @param string $cls   Additional CSS classes to append (space-separated)
 * @return string       HTML string safe to echo directly
 */
function icon(string $name, string $cls = ''): string
{
    $c = trim('icon ' . $cls);
    return '<svg class="' . e($c) . '" aria-hidden="true" focusable="false">'
         . '<use href="#icon-' . e($name) . '"></use>'
         . '</svg>';
}

/**
 * Store a one-shot flash message in the session. Displayed and cleared by
 * the next flash_get() call (page_header() does this). Side effect: writes
 * $_SESSION['ipam_flash'].
 *
 * @param string $message Message text (rendered HTML-escaped by the consumer).
 * @param string $type    Severity: 'success' | 'warning' | 'error' | 'danger'.
 */
function flash_set(string $message, string $type = 'success'): void
{
    $_SESSION['ipam_flash'] = ['msg' => $message, 'type' => $type];
}

/** @return array{msg: string, type: string}|null */
function flash_get(): ?array
{
    if (empty($_SESSION['ipam_flash'])) return null;
    $flash = $_SESSION['ipam_flash'];
    unset($_SESSION['ipam_flash']);
    if (!is_array($flash)) return null;
    $msg  = is_string($flash['msg']  ?? null) ? $flash['msg']  : '';
    $type = is_string($flash['type'] ?? null) ? $flash['type'] : 'info';
    return ['msg' => $msg, 'type' => $type];
}

/**
 * Render a sortable <th> element linking to the current page with sort params applied.
 * $baseQs should be the query string prefix for the page (e.g. '?subnet_id=3&page_size=50').
 */
function sort_th(string $col, string $label, string $currentCol, string $currentDir, string $baseQs, string $dataCol = ''): string
{
    $isActive  = $col === $currentCol;
    $nextDir   = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
    $arrow     = $isActive ? ($currentDir === 'asc' ? ' ▲' : ' ▼') : '';
    $sep       = str_contains($baseQs, '?') ? '&' : '?';
    $qs        = $baseQs . $sep . 'sort=' . urlencode($col) . '&dir=' . $nextDir;
    $cls       = $isActive ? ' class="sort-active"' : '';
    $dataAttr  = $dataCol !== '' ? ' data-col="' . e($dataCol) . '"' : '';
    return "<th{$cls}{$dataAttr}><a href='" . e($qs) . "'>" . e($label) . $arrow . '</a></th>';
}

/** Render contact badges for a site or subnet (HTML-safe). */
function render_contact_badges(PDO $db, string $type, int $id): string
{
    $contacts = get_contacts_for_entity($db, $type, $id);
    if (!$contacts) return '';
    $out = '<span class="contact-badges">';
    foreach ($contacts as $c) {
        $label = e($c['name']);
        if ($c['role'] !== '') $label .= ' <span class="muted">(' . e($c['role']) . ')</span>';
        $out .= '<a href="#" class="badge contact-badge contact-card-trigger" data-contact-id="' . $c['id'] . '">' . $label . '</a> ';
    }
    return $out . '</span>';
}

/** Render coloured tag badges for a list of tags (HTML-safe). */
function render_tag_badges(PDO $db, string $type, int $id): string
{
    $tags = get_tags_for_entity($db, $type, $id);
    if (!$tags) return '';
    $out = '';
    foreach ($tags as $tag) {
        // #869 + CR #1100: defence in depth against tag.colour CSS
        // injection. e() only HTML-escapes — it does not enforce CSS
        // syntax — so we ALSO validate the value as a strict #RRGGBB
        // hex literal at render time. Anything else falls back to the
        // muted default colour. The custom-property indirection
        // (--tag-bg) is a second layer: even a payload that leaks past
        // this validation cannot append a second CSS declaration
        // because CSS variables refuse ';' and '}' in their values.
        $colourRaw = to_str($tag['colour']);
        $colour = preg_match('/^#[0-9A-Fa-f]{6}$/', $colourRaw) === 1
            ? $colourRaw
            : '#6c757d';
        $bg   = e($colour);
        $name = e($tag['name']);
        $out .= "<span class='tag-badge' style='--tag-bg:$bg'>$name</span>";
    }
    return $out;
}

/** @return array<string, int> */
function paginate(int $total, int $page, int $pageSize): array
{
    $page = max(1, $page);
    $pageSize = max(1, min(500, $pageSize));
    $pages = (int)max(1, (int)ceil($total / $pageSize));
    if ($page > $pages) $page = $pages;

    return [
        'page' => $page,
        'page_size' => $pageSize,
        'pages' => $pages,
        'offset' => ($page - 1) * $pageSize,
        'limit' => $pageSize,
    ];
}

/**
 * Render a dismissible per-session security warning banner.
 *
 * Call this immediately after page_header() on sensitive admin pages.
 * The banner is hidden for the rest of the session once the user dismisses it.
 *
 * @param string $context  Short identifier for this banner, e.g. 'db_tools', 'import_csv'
 * @param string $message  Warning text to display (HTML-escaped before output)
 */
function render_security_banner(string $context, string $message): void
{
    // Handle dismiss: clicking the link adds ?dismiss_warning=<context> to the URL.
    // We process it here (before any HTML output from this function) and store in session.
    if (isset($_GET['dismiss_warning']) && $_GET['dismiss_warning'] === $context) {
        $dw = is_array($_SESSION['dismissed_warnings'] ?? null) ? $_SESSION['dismissed_warnings'] : [];
        $dw[$context] = true;
        $_SESSION['dismissed_warnings'] = $dw;
    }

    $dw = is_array($_SESSION['dismissed_warnings'] ?? null) ? $_SESSION['dismissed_warnings'] : [];
    if (!empty($dw[$context])) {
        return;
    }

    // Build dismiss URL: current URL with dismiss_warning param added, page reset removed
    $params = array_merge($_GET, ['dismiss_warning' => $context]);
    $dismissUrl = '?' . http_build_query($params);

    echo '<div class="security-banner">'
       . '<span>⚠ <strong>Security notice:</strong> ' . e($message) . '</span>' // nosemgrep: php.lang.security.tainted-user-input-in-php-script.tainted-user-input-in-php-script
       . '<a class="dismiss-link" href="' . e($dismissUrl) . '">Dismiss</a>'
       . '</div>';
}

/**
 * v3.28.2 #1178 — render the admin-only "install key auto-generated" banner.
 *
 * Companion to `ipam_install_key_announce_record()` which sets the
 * `install_keys_announce.<key>` flag in the `settings` table when an
 * install-root secret is lazily generated on first use. This helper renders a
 * dismissible alert until the operator acknowledges it; dismissal clears the
 * flag (engine-portable via `ipam_setting_set()`) so it persists across
 * sessions rather than the per-session `render_security_banner()` pattern.
 *
 * Dismissal is via a POST to whichever admin page page_header() runs on.
 * `$_POST['action'] === 'dismiss_install_key_banner'` with `$_POST['key']`
 * allowlisted to the two known secret names. CSRF-protected through
 * `csrf_require()`.
 *
 * Safe to call when `$role !== 'admin'` (early return). Safe to call before
 * the settings table is reachable (silently no-ops via try/catch).
 *
 * The companion dismiss-POST handler ipam_install_key_banner_handle_dismiss()
 * stays in lib.php (it is called from page_header() here and resolved
 * lazily at call time).
 */
function render_install_key_banner(PDO $db, string $role): void
{
    if ($role !== 'admin') {
        return;
    }

    $messages = [
        'app_secret' => [
            'headline' => 'A new <code>app_secret</code> was generated',
            'body'     => "It's the key for your 2FA secrets and restore-staging tokens. "
                        . 'Back up <code>config.php</code> now — if it ever changes, all 2FA enrollments break.',
        ],
        'bootstrap_key' => [
            'headline' => 'A new <code>bootstrap_key</code> was generated',
            'body'     => 'It wraps the <code>backup_vault_key</code> in the database. '
                        . "Back up <code>config.php</code> now — without it, encrypted backups can't be decrypted.",
        ],
    ];

    foreach ($messages as $key => $text) {
        try {
            $flag = ipam_setting('install_keys_announce.' . $key, '0');
        } catch (\Throwable) {
            continue;
        }
        $shouldShow = ($flag === '1' || $flag === 1 || $flag === true);
        if (!$shouldShow) {
            continue;
        }

        $csrf = e(csrf_token());
        $keyAttr = e($key);
        echo "<div class='admin-notice admin-notice--warning' role='alert'>"
           . '&#9888; <strong>' . $text['headline'] . '.</strong> ' // nosemgrep: php.lang.security.tainted-user-input-in-php-script.tainted-user-input-in-php-script
           . $text['body']
           . ' See <a href="https://github.com/seanmousseau/Simple-PHP-IPAM/blob/main/docs/backups.md#disaster-recovery--back-up-your-keys-not-just-your-data">'
           . 'backups.md &rarr; Disaster recovery</a>'
           . ' or the <a href="settings.php#install-keys">Install keys panel</a>.'
           . ' <form method="post" action="" style="display:inline;margin-left:0.5rem;">'
           . "<input type='hidden' name='csrf' value='{$csrf}'>"
           . "<input type='hidden' name='action' value='dismiss_install_key_banner'>"
           . "<input type='hidden' name='key' value='{$keyAttr}'>"
           . '<button type="submit" class="button-secondary btn-sm">Dismiss</button>'
           . '</form>'
           . '</div>';
    }
}

/**
 * Emit the full top-of-page chrome: security headers, <head>, the SVG
 * sprite, sidebar/topbar nav, and any active admin/flash/update banners.
 *
 * Side effects: sends Content-Security-Policy / X-Frame-Options /
 * X-Content-Type-Options / Referrer-Policy headers (so call before any
 * output), echoes HTML, consumes the session flash via flash_get(), sets
 * $GLOBALS['__ipam_no_sidebar'], and may handle the install-key dismiss
 * POST (which can redirect + exit). Reads the global $db PDO handle and
 * the session. Must be paired with page_footer().
 *
 * @param string               $title Page title (rendered after the app name).
 * @param array<string, mixed> $opts  Optional keys: 'no_sidebar' (bool),
 *                                     'page' (string, data-page attr),
 *                                     'extra_script_src' / 'extra_style_src' /
 *                                     'extra_frame_src' (string, CSP additions).
 */
function page_header(string $title, array $opts = []): void
{
    // $db is the runtime PDO handle opened by init.php — not config — so it
    // stays a `global`. ADR-003: the former `global $config;` read for the
    // bootstrap_admin default-password check and ipam_update_check() below
    // is now an ipam_config()/ipam_config_nested() call.
    global $db;
    require_once dirname(__DIR__) . '/version.php';
    $u = to_str($_SESSION['username'] ?? '');
    $role = to_str($_SESSION['role'] ?? '');
    // v3.28.2 #1178 — handle the install-key banner dismiss POST before
    // any HTML or header() output so csrf_require() can still send 403
    // on a bad/missing token. A successful dismiss redirects to the
    // same URL via GET and exits, so the rest of this function is
    // skipped on the dismiss request.
    if ($db instanceof PDO) {
        ipam_install_key_banner_handle_dismiss($db, $role);
    }
    $appName = trim(to_str(ipam_setting('branding.site_name'))) ?: 'Simple PHP IPAM';

    $noSidebar      = !empty($opts['no_sidebar']);
    $extraScriptSrc = isset($opts['extra_script_src']) && $opts['extra_script_src'] !== '' ? ' ' . to_str($opts['extra_script_src']) : '';
    $extraStyleSrc  = isset($opts['extra_style_src'])  && $opts['extra_style_src']  !== '' ? ' ' . to_str($opts['extra_style_src'])  : '';
    $frameSrc       = isset($opts['extra_frame_src'])  && $opts['extra_frame_src']  !== '' ? " frame-src 'self' " . to_str($opts['extra_frame_src']) . ';' : '';
    header("Content-Security-Policy: default-src 'self'; script-src 'self'{$extraScriptSrc}; style-src 'self'{$extraStyleSrc}; style-src-attr 'unsafe-inline'; img-src 'self' data:;{$frameSrc} frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    echo "<!doctype html><html lang='en'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
    echo "<title>" . e($appName) . " \u{2014} " . e($title) . "</title>";
    // v3.29.0 #897: cache-buster values centralised in ipam_asset_buster().
    $av   = e(ipam_asset_buster());
    $cssV = e(ipam_asset_buster('assets/app.css'));
    echo "<link rel='icon' type='image/webp' sizes='32x32' href='assets/favicon-32.webp?v={$av}'>";
    echo "<link rel='icon' type='image/png' sizes='32x32' href='assets/favicon-32.png?v={$av}'>";
    echo "<link rel='apple-touch-icon' type='image/webp' sizes='180x180' href='assets/apple-touch-icon.webp?v={$av}'>";
    echo "<link rel='apple-touch-icon' sizes='180x180' href='assets/apple-touch-icon.png?v={$av}'>";
    echo "<link rel='stylesheet' href='assets/vendor/open-props.min.css?v={$av}'>";
    echo "<link rel='stylesheet' href='assets/app.css?v={$cssV}'>";
    // Expose server-side theme via meta tag so app.js can seed localStorage (CSP-safe)
    $userTheme = to_str($_SESSION['user_theme'] ?? 'auto');
    echo "<meta name='ipam-server-theme' content='" . e($userTheme) . "'>";
    // Expose CSRF token for fetch-based POSTs from app.js (e.g. user_preference.php).
    echo "<meta name='ipam-csrf' content='" . e(csrf_token()) . "'>";
    // v3.34.0 #939 Phase 0: assets/app.js was moved to assets/modules/_monolith.js
    // as a transitional intermediate. Future phases extract per-concern modules
    // alongside _monolith.js (which shrinks as concerns move out) and finally
    // delete the monolith. The emit loop here is the contract that each
    // subsequent extraction commit appends one entry to — load order is
    // alphabetic; numeric-prefixed filenames (00-…, 20-…) pin it.
    // See docs/internal/app-js-modularization-plan.md.
    $jsModules = [
        // Phase 2b in progress: per-concern modules sorted by load order
        // (numeric prefix). Each name maps to assets/modules/<name>.js.
        // `_monolith` shrinks with each move and gets deleted in Phase 4.
        '00-bootstrap',                  // C01 — synchronous theme apply pre-paint
        '10-theme-banner',               // C02 — theme toggle + banner dismiss
        '15-site-group-collapse',        // C03 — sidebar group toggle
        '20-drawer',                     // C19 — IpamDrawer (Phase 1 anchor)
        '25-ping-shortcuts',             // C04 — live-ping + alert-recipients clear
        '_monolith',                     // remaining concerns; deleted in Phase 4
    ];
    foreach ($jsModules as $mod) {
        $v = e(ipam_asset_buster("assets/modules/{$mod}.js"));
        echo "<script defer src='assets/modules/{$mod}.js?v={$v}'></script>";
    }
    $pageAttr = isset($opts['page']) && $opts['page'] !== ''
        ? " data-page='" . e(to_str($opts['page'])) . "'"
        : '';
    echo "</head><body{$pageAttr}>";
    // Inline SVG sprite once per page (display:none in the SVG itself)
    static $__spritePrinted = false;
    if (!$__spritePrinted) {
        $__spritePrinted = true;
        $__spritePath = dirname(__DIR__) . '/assets/icons.svg';
        if (is_file($__spritePath)) {
            readfile($__spritePath);
        }
    }
    echo "<a class='skip-link' href='#main-content'>Skip to main content</a>";

    $gConf = ipam_config();
    if (recovery_mode_enabled($gConf)) {
        echo "<div class='recovery-banner'>RECOVERY MODE ACTIVE &mdash; disable <code>recovery_mode</code> in config.php after use</div>";
    }

    // Active page detection for sidebar highlighting
    $activePage = basename(to_str($_SERVER['SCRIPT_NAME'] ?? ''), '.php');

    $GLOBALS['__ipam_no_sidebar'] = $noSidebar;

    if ($noSidebar) {
        echo "<div class='layout-root layout-root--no-sidebar'>";
        echo "<main id='main-content' class='layout-main'>";
    } else {
        echo "<div class='layout-root'>";

        // Sidebar — desktop: always visible; mobile: off-canvas
        echo "<aside id='sidebar' class='sidebar' aria-label='Main navigation'>";
        echo "<div class='sidebar-logo'>";
        echo "<a href='dashboard.php' class='sidebar-logo-link'>" . icon('server-stack', 'icon-xl') . " " . e($appName) . "</a>";
        echo "<button id='sidebar-close' class='sidebar-close' aria-label='Close menu'>" . icon('x') . "</button>";
        echo "</div>";
        echo "<nav class='sidebar-nav' aria-label='Primary'>";

        if ($u) {
            // Primary group
            echo "<div class='sidebar-group'>";
            echo "<a class='sidebar-link" . ($activePage === 'dashboard' ? ' is-active' : '') . "' href='dashboard.php'>" . icon('home') . " Dashboard</a>";
            echo "<a class='sidebar-link" . ($activePage === 'subnets' ? ' is-active' : '') . "' href='subnets.php'>" . icon('server-stack') . " Subnets</a>";
            echo "<a class='sidebar-link" . ($activePage === 'addresses' ? ' is-active' : '') . "' href='addresses.php'>" . icon('map-pin') . " Addresses</a>";
            echo "<a class='sidebar-link nav-search-link" . ($activePage === 'search' ? ' is-active' : '') . "' href='search.php'>" . icon('magnifying-glass') . " Search</a>";
            echo "<a class='sidebar-link" . ($activePage === 'audit' ? ' is-active' : '') . "' href='audit.php'>" . icon('audit') . " Audit</a>";
            echo "<a class='sidebar-link" . ($activePage === 'unassigned' ? ' is-active' : '') . "' href='unassigned.php'>" . icon('unassigned') . " Unassigned</a>";
            echo "</div>";

            if ($role === 'admin') {
                echo "<div class='sidebar-group'>";
                echo "<span class='sidebar-group-label'>Admin</span>";
                echo "<a class='sidebar-link" . ($activePage === 'dhcp_pool' ? ' is-active' : '') . "' href='dhcp_pool.php'>" . icon('dhcp') . " DHCP Pools</a>";
                echo "<a class='sidebar-link" . ($activePage === 'sites' ? ' is-active' : '') . "' href='sites.php'>" . icon('building') . " Sites</a>";
                echo "<a class='sidebar-link" . ($activePage === 'vrfs' ? ' is-active' : '') . "' href='vrfs.php'>" . icon('globe') . " VRFs</a>";
                echo "<a class='sidebar-link" . ($activePage === 'vlans' ? ' is-active' : '') . "' href='vlans.php'>" . icon('link') . " VLANs</a>";
                echo "<a class='sidebar-link" . ($activePage === 'aggregates' ? ' is-active' : '') . "' href='aggregates.php'>" . icon('aggregates') . " Aggregates</a>";
                echo "<a class='sidebar-link" . ($activePage === 'pd_pools' ? ' is-active' : '') . "' href='pd_pools.php'>" . icon('pd-pools') . " PD Pools</a>";
                echo "<a class='sidebar-link" . ($activePage === 'tags' ? ' is-active' : '') . "' href='tags.php'>" . icon('tag') . " Tags</a>";
                echo "<a class='sidebar-link" . ($activePage === 'devices' ? ' is-active' : '') . "' href='devices.php'>" . icon('server') . " Devices</a>";
                echo "<a class='sidebar-link" . ($activePage === 'contacts' ? ' is-active' : '') . "' href='contacts.php'>" . icon('phone') . " Contacts</a>";
                echo "<a class='sidebar-link" . ($activePage === 'custom_fields' ? ' is-active' : '') . "' href='custom_fields.php'>" . icon('custom-fields') . " Custom Fields</a>";
                echo "<a class='sidebar-link" . ($activePage === 'users' ? ' is-active' : '') . "' href='users.php'>" . icon('users') . " Users</a>";
                echo "<a class='sidebar-link" . ($activePage === 'api_keys' ? ' is-active' : '') . "' href='api_keys.php'>" . icon('key') . " API Keys</a>";
                echo "<a class='sidebar-link" . ($activePage === 'webhooks' ? ' is-active' : '') . "' href='webhooks.php'>" . icon('webhook') . " Webhooks</a>";
                // v3.21.0 Wave 4 #797: unified Backup & Restore admin surface replaces
                // the four prior entries (destinations, backup_history, remote_backups,
                // restore_web). The legacy pages remain reachable as thin wrappers so
                // existing bookmarks and Playwright specs keep working.
                $isBackupAdmin = in_array($activePage, ['backup_admin', 'destinations', 'backup_history', 'remote_backups', 'restore_web'], true);
                echo "<a class='sidebar-link" . ($isBackupAdmin ? ' is-active' : '') . "' href='backup_admin.php'>" . icon('server-stack') . " Backup &amp; Restore</a>";
                echo "<a class='sidebar-link" . ($activePage === 'import_csv' ? ' is-active' : '') . "' href='import_csv.php'>" . icon('upload') . " Import CSV</a>";
                echo "<a class='sidebar-link" . ($activePage === 'import_arp' ? ' is-active' : '') . "' href='import_arp.php'>" . icon('arp') . " ARP Import</a>";
                echo "<a class='sidebar-link" . ($activePage === 'reports' ? ' is-active' : '') . "' href='reports.php'>" . icon('reports') . " Reports</a>";
                // v3.21.0 Wave 4 #798: db_tools.php Database admin entry retires. The
                // file stays functional for direct access (data-export flows live there
                // until they relocate); the sidebar link is gone.
                echo "<a class='sidebar-link" . ($activePage === 'health' ? ' is-active' : '') . "' href='health.php'>" . icon('health') . " Health</a>";
                echo "<a class='sidebar-link" . ($activePage === 'settings' ? ' is-active' : '') . "' href='settings.php'>" . icon('settings') . " Settings</a>";
                echo "</div>";
            }

            // Account group
            echo "<div class='sidebar-group sidebar-group--account'>";
            echo "<span class='sidebar-group-label'>Account</span>";
            echo "<button type='button' class='sidebar-link' id='theme-toggle'>" . icon('theme') . " <span class='theme-label'>Theme</span></button>";
            echo "<a class='sidebar-link" . ($activePage === 'change_password' ? ' is-active' : '') . "' href='change_password.php'>" . icon('account') . " Account <span class='badge badge-role-" . e($role) . "'>" . e($u) . "</span></a>";
            echo "<a class='sidebar-link' href='logout.php'>" . icon('logout') . " Logout</a>";
            echo "</div>";
        } else {
            echo "<div class='sidebar-group'>";
            echo "<a class='sidebar-link" . ($activePage === 'login' ? ' is-active' : '') . "' href='login.php'>" . icon('login') . " Login</a>";
            echo "</div>";
        }

        echo "</nav>";
        echo "</aside>";

        // Main content area
        echo "<div class='layout-main'>";

        // Mobile topbar — hamburger + app name
        echo "<header class='topbar topbar--mobile'>";
        echo "<button id='sidebar-open' class='hamburger' aria-label='Open menu' aria-expanded='false'>" . icon('bars') . "</button>";
        echo "<span class='topbar-title'>" . e($appName) . "</span>";
        echo "</header>";

        // ⌘K / Ctrl+K command palette (#516)
        echo "<div id='cmd-palette-bg' class='cmd-palette-bg' role='dialog' aria-modal='true' aria-label='Command palette'>";
        echo "  <div class='cmd-palette'>";
        echo "    <div class='cmd-input-wrap'>";
        echo "      <input id='cmd-input' class='cmd-input' type='search' placeholder='Search pages, actions, addresses\xe2\x80\xa6' autocomplete='off' spellcheck='false' aria-label='Command palette search'>";
        echo "    </div>";
        echo "    <div id='cmd-results' class='cmd-results'></div>";
        echo "  </div>";
        echo "</div>";

        echo "<main id='main-content' class='page-content'>";
    }

    // Demo mode banner (non-dismissible)
    if (demo_mode_enabled()) {
        echo "<div class='admin-notice admin-notice--info text-center' role='alert'>"
           . "🧪 <strong>Demo mode</strong> — Explore freely. Destructive actions are disabled. Data resets nightly at midnight."
           . "</div>";
    }

    // Default bootstrap admin password warning (admin only)
    if ($role === 'admin') {
        if (ipam_config_nested('bootstrap_admin', 'password') === 'ChangeMeNow!12345') {
            echo "<div class='admin-notice admin-notice--danger' role='alert'>"
               . "⚠ <strong>Security warning:</strong> The default bootstrap admin password is still set in <code>config.php</code>. "
               . "<a href='change_password.php'>Change your password</a> and update <code>config.php</code> before this site receives any traffic."
               . "</div>";
        }
    }

    // v3.28.2 #1178 — admin-only install-key auto-gen banner. Helper
    // gates on $role itself; $db (declared global at the top of this
    // function) is opened by init.php and is the same handle audit() /
    // ipam_setting_set() expect.
    if ($role === 'admin') {
        if (isset($db) && $db instanceof PDO) {
            render_install_key_banner($db, $role);
        }
    }

    // Config auto-population notice (shown once per session, admin only)
    if (!empty($_SESSION['config_notice']) && $role === 'admin') {
        $notice = e(to_str($_SESSION['config_notice']));
        echo "<div class='admin-notice admin-notice--info' role='alert'>"
           . "⚙ Config updated: {$notice} Review and adjust values in config.php."
           . "</div>";
        unset($_SESSION['config_notice']);
    }

    // Config write failure notice — shown when config.php is not writable (#119)
    if (!empty($_SESSION['config_unwritable']) && $role === 'admin') {
        echo "<div class='admin-notice admin-notice--danger' role='alert'>"
           . "&#9888; config.php is not writable — new configuration keys could not be saved. "
           . "Check file permissions."
           . "</div>";
        unset($_SESSION['config_unwritable']);
    }

    // Config validation warnings — shown to admins when config.php has invalid values (#236)
    if ($role === 'admin' && !empty($GLOBALS['config_warnings']) && is_array($GLOBALS['config_warnings'])) {
        foreach ($GLOBALS['config_warnings'] as $cfgWarn) {
            echo "<div class='admin-notice admin-notice--danger' role='alert'>"
               . "&#9888; <strong>Config warning:</strong> " . e(to_str($cfgWarn))
               . " Review <code>config.php</code>."
               . "</div>";
        }
    }

    // General flash messages (success, warning, danger)
    $flash = flash_get();
    if ($flash) {
        $flashClass = match ($flash['type']) {
            'success' => 'success',
            'warning' => 'warning',
            'error', 'danger' => 'danger',
            default => 'success',
        };
        echo "<p class='{$flashClass}'>" . e($flash['msg']) . "</p>";
    }

    // Update-available dismissible banner (admin only, client-side dismiss via localStorage)
    if ($role === 'admin') {
        $update = ipam_update_check($gConf);
        if ($update) {
            $uv  = e(to_str($update['version']));
            $url = e(to_str($update['url']));
            echo "<div class='admin-notice admin-notice--update' id='ipam-update-banner' data-version='{$uv}' role='alert'>"
               . "🚀 Simple PHP IPAM v{$uv} is available. "
               . "<a href='{$url}' target='_blank' rel='noopener'>View release</a>"
               . " &nbsp;<button type='button' class='button-secondary btn-sm' "
               . "data-dismiss-update='{$uv}'>Dismiss</button>"
               . "</div>";
        }
    }
}

/**
 * Emit the bottom-of-page chrome: closing layout tags, the footer with
 * version/docs links and update badge, and the form-drawer container.
 *
 * Side effect: echoes HTML. Reads $GLOBALS['__ipam_no_sidebar'] (set by
 * page_header()) to balance the layout-wrapper tags. Must follow a
 * matching page_header() call.
 */
function page_footer(): void
{
    // ADR-003: the former `global $config;` read used only by
    // ipam_update_check() is now an ipam_config() call.
    require_once dirname(__DIR__) . '/version.php';

    $noSidebar = !empty($GLOBALS['__ipam_no_sidebar']);

    echo "</main><footer role='contentinfo'><hr><div class='muted footer-meta'>";
    echo "<a href='https://simplephpipam.com' target='_blank' rel='noopener' class='nav-brand footer-brand link-plain'>"
       . "Simple<span class='nav-brand-php'>PHP</span>IPAM"
       . "</a> v" . e(IPAM_VERSION)
       . " &middot; <a href='https://simplephpipam.com/docs/' target='_blank' rel='noopener'>Docs</a>";

    $update = ipam_update_check(ipam_config());
    if ($update) {
        $uv  = e(to_str($update['version']));
        $url = e(to_str($update['url']));
        echo " <a href='{$url}' target='_blank' rel='noopener' class='badge badge-update'>"
           . "Update available v{$uv}</a>";
    }

    if ($noSidebar) {
        echo "</div></footer></div>"; // close footer-meta div + footer + layout-root (main used as layout-main)
    } else {
        echo "</div></footer></div></div>"; // close footer-meta div + footer + layout-main (div) + layout-root
    }

    // Note: the slide-in form-drawer (#247) was retired in v3.34.0 (#1243).
    // All drawer rendering now goes through IpamDrawer (`#global-drawer`,
    // built lazily on first use by `assets/app.js`). No container is
    // emitted server-side.

    echo "</body></html>";
}

/**
 * Render HTML form inputs for a set of custom field definitions.
 * The name of each input is "{$namePrefix}{$key}".
 * Returns '' when $defs is empty (no heading rendered).
 *
 * @param list<array<string,mixed>> $defs       Output of custom_field_def_list()
 * @param array<string,mixed>       $values     Current stored values (from parse_custom_fields_row)
 * @param string                    $namePrefix Form input name prefix (default 'cf_')
 */
function render_custom_field_inputs(array $defs, array $values, string $namePrefix = 'cf_'): string
{
    if (empty($defs)) return '';

    $html  = '<div class="custom-field-group">';
    $html .= '<h4 class="custom-field-heading">Custom fields</h4>';

    foreach ($defs as $def) {
        $key      = to_str($def['key']);
        $label    = e(to_str($def['label']));
        $type     = to_str($def['type']);
        $required = (bool)$def['is_required'];
        $name     = e($namePrefix . $key);
        $inputId  = 'cf-inp-' . e($key);
        $val      = $values[$key] ?? null;
        $reqAttr  = $required ? ' required' : '';
        $reqMark  = $required ? '<span class="cf-required" aria-hidden="true">*</span>' : '';
        $cfKey    = ' data-cf-key="' . e($key) . '"';

        $html .= '<div class="custom-field-row">';

        if ($type === 'boolean') {
            $checked = ($val === true || $val === 1 || $val === '1') ? ' checked' : '';
            $html .= '<label class="form-check" for="' . $inputId . '">';
            $html .= '<input id="' . $inputId . '" type="checkbox" name="' . $name . '" value="1"' . $cfKey . $checked . '>';
            $html .= ' ' . $label . $reqMark;
            $html .= '</label>';
        } else {
            $html .= '<label for="' . $inputId . '">' . $label . ' ' . $reqMark . '<br>';
            switch ($type) {
                case 'text':
                    $html .= '<input id="' . $inputId . '" type="text" name="' . $name . '" value="' . e(to_str($val)) . '"' . $cfKey . $reqAttr . '>';
                    break;
                case 'number':
                    $html .= '<input id="' . $inputId . '" type="number" step="any" name="' . $name . '" value="' . e(to_str($val)) . '"' . $cfKey . $reqAttr . '>';
                    break;
                case 'date':
                    $html .= '<input id="' . $inputId . '" type="date" name="' . $name . '" value="' . e(to_str($val)) . '"' . $cfKey . $reqAttr . '>';
                    break;
                case 'select':
                    $options = json_decode(to_str($def['options'] ?? '[]'), true);
                    $options = is_array($options) ? $options : [];
                    $html .= '<select id="' . $inputId . '" name="' . $name . '"' . $cfKey . $reqAttr . '>';
                    $html .= '<option value="">(none)</option>';
                    foreach ($options as $opt) {
                        $optE = e(to_str($opt));
                        $sel  = to_str($val) === to_str($opt) ? ' selected' : '';
                        $html .= '<option value="' . $optE . '"' . $sel . '>' . $optE . '</option>';
                    }
                    $html .= '</select>';
                    break;
            }
            $html .= '</label>';
        }

        $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
}
