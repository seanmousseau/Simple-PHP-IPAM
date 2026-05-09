<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */

// #1131 (v3.27.3) — sudo-class action replay landing page.
//
// After OIDC re-auth completes, the original POST body that triggered
// the step-up gate is gone (browser left to Authentik via GET, came
// back via GET). For some sudo-class actions this manifests as a
// silent "nothing happened" UX (most painfully on vault_reveal —
// operator expects to see the key, doesn't, has to click again).
//
// This page consumes the pending-action slot stashed at OIDC-link-
// render time and auto-POSTs the original fields back to the
// originating page. The user briefly sees a "Continuing..." spinner
// (or no-JS fallback button) and the action runs as if they never
// left.
//
// Single-use: ipam_sudo_oidc_consume_pending() clears the slot.
// CSRF: the stashed CSRF token replaces $_SESSION['csrf'] for THIS
// request only (the form re-submits with it; init.php will validate).
// Expiry: 10-min TTL means stale slots are silently dropped — user
// gets bounced to dashboard with a friendly note rather than an
// unrecoverable error.

require_login();
require_role('admin');

require_once __DIR__ . '/lib/auth_step_up.php';

$pending = ipam_sudo_oidc_consume_pending();
if ($pending === null) {
    // No pending slot. Could be a direct visit, an expired slot, or a
    // stale double-submit. Fall back to dashboard with a flash.
    flash_set('No pending action to replay. If you were trying to perform a sensitive admin action, please try again.', 'warning');
    header('Location: dashboard.php');
    exit;
}

// Validate the target URL against the same rules step_up.php uses for
// return_to. Reject schemes/hosts/traversal/CRLF/oversize.
require_once __DIR__ . '/step_up.php';  // imports ipam_step_up_validate_return_to
$safeTarget = ipam_step_up_validate_return_to($pending['target'], 'dashboard.php');

// Render an auto-submitting form. The browser POSTs back to the target
// with the original fields + a fresh CSRF token. No-JS users see the
// button and click it manually.
page_header('Resuming…');
?>
<div class="card" data-sudo-replay>
    <h2>Resuming your action</h2>
    <p class="muted">Identity verified — completing the action you started before re-authentication.</p>

    <form method="post" action="<?= e($safeTarget) ?>" id="sudo-replay-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?php foreach ($pending['fields'] as $name => $value): ?>
            <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
        <?php endforeach; ?>
        <noscript>
            <p class="muted" style="margin-top: 1rem;">
                JavaScript is disabled — click below to continue:
            </p>
            <button type="submit" class="action-pill">Continue →</button>
        </noscript>
    </form>

    <script>
        // Auto-submit — the user shouldn't see this page for more than a
        // few hundred milliseconds. If JS is disabled the noscript form
        // above gives them a manual button.
        (function() {
            var f = document.getElementById('sudo-replay-form');
            if (f) f.submit();
        })();
    </script>
</div>
<?php
page_footer();
