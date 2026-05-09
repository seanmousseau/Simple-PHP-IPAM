<?php
declare(strict_types=1);

/**
 * Restore wizard state machine (#807, v3.21.0).
 *
 * The wizard's phase is encoded in a server-signed token so the browser
 * cannot skip the dry-run step or replay an apply token after restore.
 * Phase transitions:
 *
 *   stage   --(POST step=stage,   issues phase=staged)-->        Step 2
 *   dryrun  --(POST step=dryrun + phase=staged token,
 *              issues phase=dryrun_passed on success)-->         Step 3
 *   apply   --(POST step=apply  + phase=dryrun_passed token)-->  done
 *
 * Tokens use a distinct HKDF info string from the legacy v3.17 stage
 * token so any in-flight wizard sessions at upgrade time are bumped
 * back to Step 1 — admin-only flow with rare use, acceptable.
 */

const RESTORE_WIZARD_PHASE_STAGED = 'staged';
const RESTORE_WIZARD_PHASE_DRYRUN_OK = 'dryrun_passed';

const RESTORE_WIZARD_RATE_LIMIT_WINDOW_SECONDS = 300;
const RESTORE_WIZARD_RATE_LIMIT_MAX_ATTEMPTS = 5;

/**
 * Restore-wizard pending-action TTL. After this many seconds the staged
 * slot expires and the operator must restart from Step 1. Mirrors the
 * legacy HMAC token's effective lifetime (the staged file in data/tmp/
 * gets purged by the housekeeping cron after a similar window).
 */
const RESTORE_WIZARD_PENDING_TTL_SECONDS = 600;

/**
 * Sentinel returned by ipam_restore_wizard_sign() in session-state mode.
 * Carried in the form's staged_sig hidden field for backwards compat with
 * existing views; the apply-side ipam_restore_wizard_verify() ignores it
 * entirely and reads from $_SESSION instead. Any non-empty value works
 * here — the value just needs to be truthy so existing form-rendering
 * code that tests `if ($stagedSig)` continues to render the wizard.
 */
const RESTORE_WIZARD_SESSION_SENTINEL = 'session-stashed';

/**
 * Stash a pending wizard action in the session. Replaces the legacy
 * HMAC-token mechanism (#1127, v3.27.3): the session is the trust
 * boundary for the single-user, single-tab wizard flow, so a
 * cryptographic token was unnecessary and forced a hard `app_secret`
 * dependency on every install — the entire restore-from-remote path was
 * blocked on installs that took the documented v3.26.0 vault-key
 * relocation (app_secret optional / blank).
 *
 * Three guarantees the legacy HMAC provided are preserved by this
 * mechanism:
 *
 *   - **Phase progression** (no apply-without-dryrun) is checked by
 *     ipam_restore_wizard_consume_pending() comparing the requested
 *     phase against $_SESSION['_pending_restore']['phase'].
 *   - **Path authenticity** is automatic — the path is server-side
 *     state, never client-supplied. The user's form carries action
 *     verbs only.
 *   - **No replay** — consume is single-use (clears the slot on
 *     successful return) and slots expire after
 *     RESTORE_WIZARD_PENDING_TTL_SECONDS.
 *
 * @param array{filename?:string,destination_id?:int,size?:int} $meta
 */
function ipam_restore_wizard_stage_pending(string $phase, string $stagedPath, array $meta): void
{
    if ($phase !== RESTORE_WIZARD_PHASE_STAGED && $phase !== RESTORE_WIZARD_PHASE_DRYRUN_OK) {
        throw new InvalidArgumentException("ipam_restore_wizard_stage_pending: unknown phase '$phase'");
    }
    $_SESSION['_pending_restore'] = [
        'path'    => $stagedPath,
        'meta'    => $meta,
        'phase'   => $phase,
        'expires' => time() + RESTORE_WIZARD_PENDING_TTL_SECONDS,
    ];
}

/**
 * Consume the pending wizard slot iff its phase matches $expectedPhase
 * and it hasn't expired.
 *
 * Behaviour:
 *   - phase matches + fresh:    returns the slot AND clears it (single-use).
 *   - phase mismatch:           returns null AND leaves the slot intact
 *                               (so a correctly-phased subsequent call
 *                               can succeed; mismatch is a routing
 *                               error, not a security violation).
 *   - expired:                  returns null AND clears the slot (no
 *                               recovery; operator must restart).
 *   - no slot:                  returns null.
 *
 * The intentional asymmetry between "phase mismatch leaves intact" and
 * "expired clears" matters: phase-mismatch is the wizard's flow-control
 * primitive (e.g. apply step looking for `dryrun_passed` will see a
 * `staged` slot and refuse, but the slot stays valid for the dry-run
 * step that should actually run next). Expiry is terminal.
 *
 * @return ?array{path:string,meta:array<string,mixed>,phase:string}
 */
function ipam_restore_wizard_consume_pending(string $expectedPhase): ?array
{
    $pending = $_SESSION['_pending_restore'] ?? null;
    if (!is_array($pending)) {
        return null;
    }
    $expires = is_int($pending['expires'] ?? null) ? $pending['expires'] : 0;
    if ($expires < time()) {
        unset($_SESSION['_pending_restore']);
        return null;
    }
    $phase = is_string($pending['phase'] ?? null) ? $pending['phase'] : '';
    if ($phase !== $expectedPhase) {
        return null;  // phase mismatch — leave slot intact for the right caller
    }
    unset($_SESSION['_pending_restore']);
    return [
        'path'  => is_string($pending['path'] ?? null) ? $pending['path'] : '',
        'meta'  => is_array($pending['meta'] ?? null) ? $pending['meta'] : [],
        'phase' => $phase,
    ];
}

/**
 * Advance the pending slot from one phase to another (e.g. after dry-run
 * succeeds, advance from `staged` to `dryrun_passed`). Path and meta
 * survive untouched. The expiry clock is RESET so a long dry-run doesn't
 * eat into the apply-step window.
 *
 * Returns true on success, false when there's no slot or it's expired
 * (caller should refuse the next step).
 */
function ipam_restore_wizard_advance_phase(string $newPhase): bool
{
    if ($newPhase !== RESTORE_WIZARD_PHASE_STAGED && $newPhase !== RESTORE_WIZARD_PHASE_DRYRUN_OK) {
        throw new InvalidArgumentException("ipam_restore_wizard_advance_phase: unknown phase '$newPhase'");
    }
    $pending = $_SESSION['_pending_restore'] ?? null;
    if (!is_array($pending)) {
        return false;
    }
    $expires = is_int($pending['expires'] ?? null) ? $pending['expires'] : 0;
    if ($expires < time()) {
        unset($_SESSION['_pending_restore']);
        return false;
    }
    $pending['phase']   = $newPhase;
    $pending['expires'] = time() + RESTORE_WIZARD_PENDING_TTL_SECONDS;
    $_SESSION['_pending_restore'] = $pending;
    return true;
}

/**
 * Legacy sign/verify API — preserved for call-site compatibility but
 * routes through the session-state mechanism above. The $config /
 * $signature parameters are now ignored; the path/meta/phase live in
 * $_SESSION.
 *
 * Why this shape vs. deleting the legacy API entirely:
 *   - 6 call sites in lib/backup_admin_restore.php would need coordinated
 *     edits to the views/backup_admin_restore.php form template too
 *     (drop the staged_sig hidden field, drop the path round-trip).
 *   - The view changes are larger surface than the controller changes
 *     and harder to reason about in isolation.
 *   - This shim preserves the in-place call sites + view template while
 *     removing the app_secret dependency. Net code change is small.
 *   - The full call-site cleanup (delete shim, drop hidden fields from
 *     view) is queued for v3.27.5 alongside the broader restore-page
 *     redesign (#1136), where the views get reworked anyway.
 *
 * @param array<string,mixed>                                  $config IGNORED
 * @param array{filename?:string,destination_id?:int,size?:int} $meta
 */
function ipam_restore_wizard_sign(array $config, string $phase, string $stagedPath, array $meta): string
{
    unset($config); // explicit drop — reads $_SESSION instead
    ipam_restore_wizard_stage_pending($phase, $stagedPath, $meta);
    return RESTORE_WIZARD_SESSION_SENTINEL;
}

/**
 * Legacy verify shim — consumes from $_SESSION instead of HMAC-checking
 * a token. The $stagedPath and $signature parameters are read from the
 * client form but the trusted source is the session slot stored at
 * sign() time. Returns the session-stored path (canonicalised) on a
 * successful phase match, null on phase-mismatch / expiry / no-slot.
 *
 * Why we ignore $stagedPath from the caller: in the legacy mechanism
 * the path was carried client-side and HMAC-protected. With session
 * state the path is server-side; trusting the form's staged_path would
 * re-introduce the path-confusion threat the HMAC was originally
 * defending against.
 *
 * @param array<string,mixed>                                  $config IGNORED
 * @param array{filename?:string,destination_id?:int,size?:int} $meta IGNORED
 */
function ipam_restore_wizard_verify(
    array $config,
    string $expectedPhase,
    string $stagedPath,
    string $signature,
    array $meta
): ?string {
    unset($config, $stagedPath, $signature, $meta); // explicit drop — reads $_SESSION
    $consumed = ipam_restore_wizard_consume_pending($expectedPhase);
    if ($consumed === null) {
        return null;
    }
    return ipam_restore_canonicalize_staged($consumed['path']);
}

/**
 * Throttle restore wizard attempts per user. Counts every db.restore_*
 * audit row in the rolling window — staging, dry-run, apply — so a user
 * burning through tokens cannot indefinitely retry. The check runs
 * before audit-logging the current attempt, so the threshold itself is
 * the cap (caller's row will land on top of N existing rows).
 *
 * Returns the number of attempts in the window. Caller compares against
 * the threshold and decides whether to deny.
 */
function ipam_restore_wizard_recent_attempts(PDO $db, ?int $userId, int $windowSeconds = RESTORE_WIZARD_RATE_LIMIT_WINDOW_SECONDS): int
{
    if ($userId === null || $userId <= 0) {
        return 0;
    }
    // Compute cutoff in PHP (UTC) and bind as a plain string — keeps the
    // SQL portable across SQLite/MySQL/Postgres without engine-specific
    // interval arithmetic.
    $cutoff = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
    // CR feedback PR #1054: don't count denial entries in the limiter — the
    // `db.restore_rate_limited` row written when this function blocks an
    // attempt would otherwise re-extend the lockout window with every
    // bounced retry. Count only intent-to-act actions (stage/dryrun/apply).
    $st = $db->prepare(
        "SELECT COUNT(*) FROM audit_log
         WHERE user_id = :uid
           AND action LIKE 'db.restore_%'
           AND action <> 'db.restore_rate_limited'
           AND created_at >= :cutoff"
    );
    $st->execute([':uid' => $userId, ':cutoff' => $cutoff]);
    $n = $st->fetchColumn();
    return is_numeric($n) ? (int) $n : 0;
}

/**
 * Convenience: returns true when the user is over threshold and the
 * caller should deny. Caller is responsible for surfacing a friendly
 * message and audit-logging the denial.
 */
function ipam_restore_wizard_is_rate_limited(
    PDO $db,
    ?int $userId,
    int $maxAttempts = RESTORE_WIZARD_RATE_LIMIT_MAX_ATTEMPTS,
    int $windowSeconds = RESTORE_WIZARD_RATE_LIMIT_WINDOW_SECONDS
): bool {
    return ipam_restore_wizard_recent_attempts($db, $userId, $windowSeconds) >= $maxAttempts;
}

/**
 * Tear down the current PHP session after a successful restore.
 *
 * The restored DB may have different user IDs / password hashes /
 * MFA enrolments from the running session, so the session_id must
 * not survive the apply. Caller is expected to redirect to login
 * immediately after this returns.
 *
 * No-op when there is no active session (e.g. CLI tests). The
 * session_write_close() is necessary because session_destroy()
 * alone leaves PHP holding the file lock.
 */
function ipam_restore_wizard_invalidate_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION = [];
    $cookieName = session_name();
    if (ini_get('session.use_cookies') && is_string($cookieName)) {
        $params = session_get_cookie_params();
        setcookie(
            $cookieName,
            '',
            [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]
        );
    }
    session_destroy();
    session_write_close();
}
