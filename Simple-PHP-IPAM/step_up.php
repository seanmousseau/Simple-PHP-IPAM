<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */

// v3.27.0 (#1116 follow-up, CR PR #1117 #3) — generic step-up landing page.
//
// Used by XHR endpoints (settings_reveal.php at the moment) that return
// {error: "step_up_required"} when no sudo grant is active. The JS handler
// navigates the user here with a `return_to` query string; we render the
// shared step-up prompt and, on a successful proof, redirect back to the
// originating page so they can retry.
//
// Without this page, an XHR-only sudo-gated endpoint can ONLY succeed when
// the user already happens to have a warm grant from another action — the
// regression CodeRabbit flagged in PR #1117.

require_login();
require_role('admin');

require_once __DIR__ . '/lib/auth_step_up.php';

/**
 * Validate `return_to` is a safe local path. Mirrors the rules in
 * ipam_post_login_redirect_stash() but accepts relative paths (the JS
 * handler builds these from window.location.pathname which may or may not
 * lead with a slash depending on install dir layout). Rejects schemes,
 * hosts, traversal, control characters, and oversize strings.
 */
function ipam_step_up_validate_return_to(string $raw, string $default): string
{
    $uri = trim($raw);
    if ($uri === '') return $default;
    if (strlen($uri) > 1024) return $default;
    if (preg_match('/[\r\n\t]/', $uri)) return $default;
    if (str_contains($uri, '\\')) return $default;
    if (str_contains($uri, '..')) return $default;
    // Reject any URL that has a scheme or authority component. parse_url()
    // returns false for malformed inputs; treat that as unsafe too.
    $parts = parse_url($uri);
    if (!is_array($parts)) return $default;
    if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return $default;
    }
    // Protocol-relative ("//evil.com/...") would parse without a scheme but
    // with a host on browsers; double-slash leading is the marker.
    if (str_starts_with($uri, '//')) return $default;
    return $uri;
}

$cur          = current_user();
$userId       = to_int($cur['id'] ?? 0);
$returnRaw    = to_str($_REQUEST['return_to'] ?? '');
$returnTarget = ipam_step_up_validate_return_to($returnRaw, 'settings.php');

// If a grant is already warm (or the POST carries a valid proof), bounce
// straight back to the originating page so the user can retry their action.
if (ipam_sudo_require($db, $userId)) {
    // $returnTarget was sanitised by ipam_step_up_validate_return_to()
    // above (rejects schemes, hosts, traversal, CRLF, backslashes, and
    // oversize strings, falling back to 'settings.php'). The function is
    // registered as a sanitizer for the ipam-open-redirect semgrep rule
    // in .semgrep/rules.yml.
    header('Location: ' . $returnTarget);
    exit;
}

// Otherwise render the prompt. Carry `return_to` through as a hidden field
// so the form's POST lands back here with the same target intact.
page_header('Confirm your identity');
$stepUpUserId       = $userId;
$stepUpFormAction   = 'step_up.php';
$stepUpHiddenFields = ['return_to' => $returnTarget];
$stepUpDescription  = 'Re-authenticate to continue. You will be returned to the page you were on.';
$stepUpReturnPath   = $returnTarget;
$stepUpError        = isset($_POST['_sudo_method']) ? 'Verification failed. Please try again.' : '';
include __DIR__ . '/views/_step_up_prompt.php';
page_footer();
