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
 * @param array<string,mixed>                                  $config
 * @param array{filename?:string,destination_id?:int,size?:int} $meta
 */
function ipam_restore_wizard_sign(array $config, string $phase, string $stagedPath, array $meta): string
{
    if ($phase !== RESTORE_WIZARD_PHASE_STAGED && $phase !== RESTORE_WIZARD_PHASE_DRYRUN_OK) {
        throw new InvalidArgumentException("ipam_restore_wizard_sign: unknown phase '$phase'");
    }
    $appSecret = is_string($config['app_secret'] ?? null) ? $config['app_secret'] : '';
    if ($appSecret === '') {
        throw new RuntimeException('ipam_restore_wizard: cannot sign without app_secret');
    }
    $key = ipam_hkdf_sha256($appSecret, 'ipam-v4:restore-wizard', 32);
    $message = "phase=" . $phase
        . "\0path=" . $stagedPath
        . "\0filename=" . (isset($meta['filename']) ? (string) $meta['filename'] : '')
        . "\0destination_id=" . (isset($meta['destination_id']) ? (string) (int) $meta['destination_id'] : '')
        . "\0size=" . (isset($meta['size']) ? (string) (int) $meta['size'] : '');
    return hash_hmac('sha256', $message, $key);
}

/**
 * Verify a wizard token. Returns the canonicalised staged path on success
 * or null on any of: wrong app_secret, tampered fields, mismatched phase
 * (i.e. caller expected dryrun_passed but token only authorises staged
 * — step-skip blocked), or staged path now resolving outside data/tmp/.
 *
 * @param array<string,mixed>                                  $config
 * @param array{filename?:string,destination_id?:int,size?:int} $meta
 */
function ipam_restore_wizard_verify(
    array $config,
    string $expectedPhase,
    string $stagedPath,
    string $signature,
    array $meta
): ?string {
    try {
        $expected = ipam_restore_wizard_sign($config, $expectedPhase, $stagedPath, $meta);
    } catch (Throwable) {
        return null;
    }
    if (!hash_equals($expected, $signature)) {
        return null;
    }
    return ipam_restore_canonicalize_staged($stagedPath);
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
