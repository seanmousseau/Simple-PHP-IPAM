<?php
declare(strict_types=1);

/**
 * @module auth_rate_limit
 *
 * Login/IP rate-limiting and account-lockout helpers extracted from lib.php
 * in v3.30.0 (ADR-004 Phase 6 Task 6.3, sub of #907). Functions stay in the
 * global namespace per ADR-004 Option E.
 *
 * Responsibilities:
 *  - Generic per-action, per-IP sliding-window rate limiter over the
 *    login_attempts table (auth_rate_limited, record_auth_failure,
 *    clear_auth_failures) and its action='login' wrappers
 *    (login_rate_limited, record_login_failure, clear_login_failures).
 *  - Real lockout-expiry computation for a rolling window
 *    (auth_rate_limit_unlock_at).
 *  - Once-per-window 'auth.ip_rate_limited' audit emission with an atomic
 *    dampener claim (ipam_audit_ip_rate_limited) plus its housekeeping
 *    prune (prune_rate_limit_dampener).
 *  - Per-username account lockout over the same table (account_locked_out,
 *    clear_account_lockout) and login_attempts housekeeping
 *    (purge_old_login_attempts).
 *  - Per-API-key sliding-window rate limiting over rate_limit_buckets
 *    (ipam_api_key_rate_limit_check).
 *  - Clearing the persistent users.locked_until lockout
 *    (ipam_clear_persistent_lockout).
 *
 * Inclusion rule: functions whose primary job is counting/recording auth
 * attempts to throttle or lock out a caller, or clearing such throttle
 * state. Deliberately NOT moved here: ipam_is_persistently_locked and
 * ipam_record_2fa_failure (persistent-lockout reads / 2FA-failure recording
 * — tied to the MFA flow, stay in lib.php), recovery_mode_enabled (a config
 * check, not a rate-limit concern), and the reCAPTCHA helpers (Task 6.4,
 * lib/auth_recaptcha.php). Core session/CSRF/login (lib/auth.php) and
 * password policy/reset (lib/auth_password.php) stay in their own modules.
 *
 * ADR-003: this module performs no `global $config` / `$GLOBALS['config']`
 * reads — every helper takes its PDO handle and its limits as caller-passed
 * parameters, so no signatures change. The `global $db` handle is never
 * accessed implicitly here.
 *
 * Dependencies: lib/db.php (PDO via caller-passed handle), lib/utils.php
 * (to_int / to_str), lib/audit.php (audit() — called by
 * ipam_audit_ip_rate_limited). All cross-module helpers resolve lazily at
 * call time, never at include time — this module has no side-effects on
 * load. Note: ipam_api_key_rate_limit_check() uses driver-specific upsert
 * SQL branched on PDO::ATTR_DRIVER_NAME (no ipam_dialect() call).
 */

/* ---------------- Login rate limiting ---------------- */

/**
 * Generic per-action, per-IP rate limiter (#882). Counts rows in
 * login_attempts whose action matches and whose attempted_at is within the
 * sliding window. The legacy login_*-named helpers below remain as
 * action='login' wrappers so existing callers and tests are unaffected.
 */
function auth_rate_limited(PDO $db, string $action, string $ip, int $maxAttempts, int $windowSeconds): bool
{
    // login_attempts.attempted_at is UTC; build the cutoff in UTC (gmdate).
    $cutoff = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
    $st = $db->prepare(
        "SELECT COUNT(*) AS c FROM login_attempts
          WHERE action = :a AND ip = :ip AND attempted_at >= :cutoff"
    );
    $st->execute([':a' => $action, ':ip' => $ip, ':cutoff' => $cutoff]);
    /** @var array<string, mixed>|false $countRow */
    $countRow = $st->fetch();
    return (is_array($countRow) ? to_int($countRow['c']) : 0) >= $maxAttempts;
}

/**
 * Record one failed auth attempt for an (action, ip) pair. Side effect:
 * INSERTs a row into login_attempts (attempted_at defaults to now in the
 * schema). An empty $username is stored as NULL.
 *
 * @param PDO    $db       Live PDO handle.
 * @param string $action   Auth action, e.g. 'login', 'forgot_password'.
 * @param string $ip       Caller IP.
 * @param string $username Optional username associated with the attempt.
 */
function record_auth_failure(PDO $db, string $action, string $ip, string $username = ''): void
{
    $db->prepare(
        "INSERT INTO login_attempts (ip, username, action) VALUES (:ip, :username, :a)"
    )->execute([
        ':ip'       => $ip,
        ':username' => $username !== '' ? $username : null,
        ':a'        => $action,
    ]);
}

/**
 * Clear all recorded failures for an (action, ip) pair after a successful
 * auth. Side effect: DELETEs matching rows from login_attempts.
 *
 * @param PDO    $db     Live PDO handle.
 * @param string $action Auth action.
 * @param string $ip     Caller IP.
 */
function clear_auth_failures(PDO $db, string $action, string $ip): void
{
    $db->prepare("DELETE FROM login_attempts WHERE action = :a AND ip = :ip")
       ->execute([':a' => $action, ':ip' => $ip]);
}

/**
 * action='login' wrapper around auth_rate_limited(). True when the IP has
 * reached $maxAttempts failed logins within $windowSeconds.
 *
 * @param PDO    $db            Live PDO handle.
 * @param string $ip            Caller IP.
 * @param int    $maxAttempts   Failure threshold.
 * @param int    $windowSeconds Sliding-window size in seconds.
 */
function login_rate_limited(PDO $db, string $ip, int $maxAttempts, int $windowSeconds): bool
{
    return auth_rate_limited($db, 'login', $ip, $maxAttempts, $windowSeconds);
}

/**
 * Real lockout-expiry timestamp for a rolling-window IP rate limit.
 *
 * The window is "max N failures in the past $windowSeconds." The unlock
 * moment is when the OLDEST currently-counted failure ages out — at that
 * point the window has only N-1 failures and a fresh attempt is allowed.
 * Returns time() + $windowSeconds as a sane fallback when no attempts
 * are recorded (caller is asking for a future window without any
 * existing data — shouldn't happen for actively-rate-limited IPs).
 *
 * Introduced for CR PR #1141: pre-fix, login.php sent
 * `time() + $lockoutSeconds` to ipam_audit_ip_rate_limited(), which
 * overstated the wait under steady traffic and could keep the dampener
 * suppressing past the real unlock point.
 */
function auth_rate_limit_unlock_at(PDO $db, string $action, string $ip, int $maxAttempts, int $windowSeconds): int
{
    // login_attempts.attempted_at is UTC; build the cutoff in UTC (gmdate).
    $cutoff = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
    // CR PR #1141 round 2: locate the THRESHOLD-CROSSING failure (the
    // Nth-from-newest in the window). Pre-fix used MIN() of all
    // in-window failures, which is only correct when exactly N failures
    // exist. With more than N, expiring the absolute oldest still
    // leaves the IP blocked — `unlock_at` was reported too early and
    // the dampener could roll over before the real unlock point.
    $countSt = $db->prepare(
        "SELECT COUNT(*) FROM login_attempts
          WHERE action = :a AND ip = :ip AND attempted_at >= :cutoff"
    );
    $countSt->execute([':a' => $action, ':ip' => $ip, ':cutoff' => $cutoff]);
    $count = to_int($countSt->fetchColumn());
    if ($count === 0) {
        return time() + $windowSeconds;
    }
    // OFFSET = count - maxAttempts; e.g. with N=5 max and 7 in-window
    // failures, the threshold-crossing row is at OFFSET 2 (skipping the
    // 2 oldest that are about to age out before the lockout actually
    // lifts). With exactly N in-window failures, OFFSET = 0 == oldest.
    $offset = max(0, $count - $maxAttempts);
    $st = $db->prepare(
        "SELECT attempted_at FROM login_attempts
          WHERE action = :a AND ip = :ip AND attempted_at >= :cutoff
          ORDER BY attempted_at ASC
          LIMIT 1 OFFSET :offset"
    );
    $st->bindValue(':a',      $action,  PDO::PARAM_STR);
    $st->bindValue(':ip',     $ip,      PDO::PARAM_STR);
    $st->bindValue(':cutoff', $cutoff,  PDO::PARAM_STR);
    $st->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $st->execute();
    $oldest = to_str($st->fetchColumn());
    if ($oldest === '') {
        return time() + $windowSeconds;
    }
    $oldestTs = strtotime($oldest);
    if ($oldestTs === false) {
        return time() + $windowSeconds;
    }
    return $oldestTs + $windowSeconds;
}

/**
 * Emit a once-per-window 'auth.ip_rate_limited' audit row (#1134, v3.27.3;
 * made atomic in v3.28.0 #1143).
 *
 * Pre-fix (#1134): login.php audited 'auth.login_blocked' on every refused
 * attempt within the rate-limit window — flooding the log with up to one
 * row per attempted login over the lockout window. Operators filtering
 * audit_log by username also missed these rows entirely (entity_id NULL,
 * username column NULL).
 *
 * #1134 contract: emit a single row per (action, ip) lockout window with
 * details = "action=$action attempts=$attempts unlock_at=$ISO8601 ip=$ip"
 * so the operator can grep it on either the audit_log.ip column or the
 * details substring; subsequent attempts inside the same window do not
 * re-emit.
 *
 * #1143: the original dampener enforced "once per window" by SELECTing the
 * most recent prior 'auth.ip_rate_limited' row out of audit_log and only
 * INSERTing if no active window was found — a read-then-insert TOCTOU that
 * let two concurrent brute-force requests both miss the prior row and both
 * emit. The window is now tracked in the `rate_limit_dampener` table
 * (PRIMARY KEY (action, ip), unlock_at = Unix epoch). The caller claims
 * the window with an UPDATE that only fires on an expired row, falling
 * back to an INSERT when no row exists at all; exactly one concurrent
 * caller wins the INSERT (PK collision), so at most one audit row is
 * emitted per window regardless of concurrency.
 */
function ipam_audit_ip_rate_limited(PDO $db, string $action, string $ip, int $attempts, int $unlockAt): void
{
    $now = time();

    // Claim this (action, ip) lockout window atomically. A row is "active"
    // while unlock_at > now; we may claim the window only when no active
    // row exists. Step 1: UPDATE an expired row to the new window (fires
    // exactly when a row exists AND it has expired). Step 2 (only if the
    // UPDATE matched nothing): INSERT a fresh row — succeeds when no row
    // exists, raises a UNIQUE violation when an active row already holds
    // the window (or a concurrent caller just inserted one), which we
    // treat as "already dampened".
    $upd = $db->prepare(
        "UPDATE rate_limit_dampener SET unlock_at = :u
          WHERE action = :a AND ip = :ip AND unlock_at <= :now"
    );
    $upd->execute([':u' => $unlockAt, ':a' => $action, ':ip' => $ip, ':now' => $now]);
    $claimed = $upd->rowCount() > 0;

    if (!$claimed) {
        try {
            $ins = $db->prepare(
                "INSERT INTO rate_limit_dampener (action, ip, unlock_at) VALUES (:a, :ip, :u)"
            );
            $ins->execute([':a' => $action, ':ip' => $ip, ':u' => $unlockAt]);
            $claimed = true;
        } catch (\PDOException $e) {
            $sqlstate = (string) ($e->errorInfo[0] ?? '');
            $msg = $e->getMessage();
            $isUniqueViolation = $sqlstate === '23000' || $sqlstate === '23505'
                || stripos($msg, 'unique') !== false
                || stripos($msg, 'duplicate') !== false;
            if (!$isUniqueViolation) {
                throw $e;
            }
            // Active row already holds the window — dampened.
        }
    }

    if (!$claimed) {
        return;
    }

    $unlockIso = gmdate('Y-m-d\TH:i:s\Z', $unlockAt);
    $emitted = audit($db, 'auth.ip_rate_limited', 'auth', null,
          "action=$action attempts=$attempts unlock_at=$unlockIso ip=$ip");
    if (!$emitted) {
        // audit() returns false (not an exception) on write failure. We just
        // claimed this (action, ip) window via the UPDATE/INSERT above, so the
        // claim is the only thing standing between subsequent attempts and a
        // (still missing) audit row. Roll the claim back so a later attempt in
        // the same window can re-attempt the emit.
        $db->prepare("DELETE FROM rate_limit_dampener WHERE action = :a AND ip = :ip")
           ->execute([':a' => $action, ':ip' => $ip]);
    }
}

/**
 * Housekeeping: drop rate_limit_dampener rows whose lockout window expired
 * more than $graceSeconds ago. The table is bounded by the number of
 * distinct (action, ip) pairs that have ever been rate-limited; a sustained
 * brute-force from many sources can grow it, so prune it during the lazy
 * housekeeping sweep alongside purge_old_login_attempts().
 */
function prune_rate_limit_dampener(PDO $db, int $graceSeconds = 86400): void
{
    $cutoff = time() - max(0, $graceSeconds);
    $db->prepare("DELETE FROM rate_limit_dampener WHERE unlock_at < :cutoff")
       ->execute([':cutoff' => $cutoff]);
}

/**
 * action='login' wrapper around record_auth_failure(). Side effect:
 * INSERTs a login failure row into login_attempts.
 *
 * @param PDO    $db       Live PDO handle.
 * @param string $ip       Caller IP.
 * @param string $username Optional username associated with the attempt.
 */
function record_login_failure(PDO $db, string $ip, string $username = ''): void
{
    record_auth_failure($db, 'login', $ip, $username);
}

/**
 * action='login' wrapper around clear_auth_failures(). Side effect:
 * DELETEs the IP's login failure rows from login_attempts.
 *
 * @param PDO    $db Live PDO handle.
 * @param string $ip Caller IP.
 */
function clear_login_failures(PDO $db, string $ip): void
{
    clear_auth_failures($db, 'login', $ip);
}

/**
 * True when a username has reached $maxAttempts failed action='login'
 * attempts within $windowSeconds. Scoped to action='login' so non-login
 * failures (forgot_password, email_otp, vault_key_reveal) do not
 * contaminate login lockout.
 *
 * @param PDO    $db            Live PDO handle.
 * @param string $username      Username to check.
 * @param int    $maxAttempts   Failure threshold.
 * @param int    $windowSeconds Sliding-window size in seconds.
 */
function account_locked_out(PDO $db, string $username, int $maxAttempts, int $windowSeconds): bool
{
    // login_attempts.attempted_at is UTC; build the cutoff in UTC (gmdate).
    $cutoff = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
    // Scope to action='login' so forgot_password / email_otp / vault_key_reveal
    // failures (also written to login_attempts with a username) don't
    // contaminate login lockout.
    $st = $db->prepare(
        "SELECT COUNT(*) AS c FROM login_attempts WHERE action = 'login' AND username = :u AND attempted_at >= :cutoff"
    );
    $st->execute([':u' => $username, ':cutoff' => $cutoff]);
    /** @var array<string, mixed>|false $row */
    $row = $st->fetch();
    return (is_array($row) ? to_int($row['c']) : 0) >= $maxAttempts;
}

/**
 * Clear a username's action='login' lockout after a successful login.
 * Side effect: DELETEs the username's action='login' rows from
 * login_attempts; scoped to action='login' so forgot_password / email_otp
 * / vault_key_reveal attempt rows survive.
 *
 * @param PDO    $db       Live PDO handle.
 * @param string $username Username whose lockout to clear.
 */
function clear_account_lockout(PDO $db, string $username): void
{
    // Scope to action='login' so clearing a login lockout does not wipe
    // forgot_password / email_otp / vault_key_reveal attempt rows.
    $db->prepare("DELETE FROM login_attempts WHERE action = 'login' AND username = :u")
       ->execute([':u' => $username]);
}

/**
 * Housekeeping: drop login_attempts rows older than $windowSeconds.
 * Side effect: DELETEs aged-out rows. Called during the lazy housekeeping
 * sweep to bound the table size.
 *
 * @param PDO $db            Live PDO handle.
 * @param int $windowSeconds Age cutoff in seconds; rows older than this are removed.
 */
function purge_old_login_attempts(PDO $db, int $windowSeconds): void
{
    // login_attempts.attempted_at is UTC; build the cutoff in UTC (gmdate).
    $cutoff = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
    $db->prepare("DELETE FROM login_attempts WHERE attempted_at < :cutoff")
       ->execute([':cutoff' => $cutoff]);
}

/* ---------------- API per-key rate limiting (v3.6.0, #419) ---------------- */

/**
 * Returns 0 when the request is allowed, or the number of seconds until the
 * sliding window unblocks when it is denied.
 */
function ipam_api_key_rate_limit_check(PDO $db, string $bucketKey, int $windowSec, int $max): int {
    if ($windowSec < 1) {
        throw new \InvalidArgumentException('windowSec must be >= 1');
    }
    if ($max < 1) {
        throw new \InvalidArgumentException('max must be >= 1');
    }
    $now              = time();
    $windowStartEpoch = (int)($now / $windowSec) * $windowSec;
    $prevWindowEpoch  = $windowStartEpoch - $windowSec;
    // rate_limit_buckets.window_start is UTC and these values are also used
    // as bucket keys; build them all in UTC (gmdate) for consistency.
    $windowStart      = gmdate('Y-m-d H:i:s', $windowStartEpoch);
    $prevWindowStart  = gmdate('Y-m-d H:i:s', $prevWindowEpoch);
    $cutoff           = gmdate('Y-m-d H:i:s', $prevWindowEpoch - $windowSec);

    // Prune buckets older than 2 windows ago
    $db->prepare("DELETE FROM rate_limit_buckets WHERE window_start < :cutoff")
       ->execute([':cutoff' => $cutoff]);

    // Increment current window
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $db->prepare(
            "INSERT INTO rate_limit_buckets (bucket_key, window_start, count) VALUES (:k, :w, 1)
             ON DUPLICATE KEY UPDATE count = count + 1"
        )->execute([':k' => $bucketKey, ':w' => $windowStart]);
    } else {
        $db->prepare(
            "INSERT INTO rate_limit_buckets (bucket_key, window_start, count) VALUES (:k, :w, 1)
             ON CONFLICT(bucket_key, window_start) DO UPDATE SET count = rate_limit_buckets.count + 1"
        )->execute([':k' => $bucketKey, ':w' => $windowStart]);
    }

    // Sliding-window approximation: weight previous bucket by fraction remaining in current window
    $stmt = $db->prepare(
        "SELECT count FROM rate_limit_buckets WHERE bucket_key = :k AND window_start = :w"
    );
    $stmt->execute([':k' => $bucketKey, ':w' => $windowStart]);
    $currentCount = (int)($stmt->fetchColumn() ?: 0);

    $stmt->execute([':k' => $bucketKey, ':w' => $prevWindowStart]);
    $prevCount = (int)($stmt->fetchColumn() ?: 0);

    $elapsed  = $now - $windowStartEpoch;
    $weight   = ($windowSec - $elapsed) / $windowSec;
    $weighted = $currentCount + ($prevCount * $weight);

    if ($weighted <= $max) {
        return 0; // allowed
    }

    // Calculate when the weighted count will drop to <= max (assuming no new requests).
    //
    // Case A: currentCount < max — overflow is driven by prevCount weight.
    //   Solve: currentCount + prevCount*(windowSec - elapsed - t)/windowSec = max
    //   => t = (windowSec - elapsed) - (max - currentCount)*windowSec/prevCount
    if ($currentCount < $max && $prevCount > 0) {
        $tWithin = ($windowSec - $elapsed) - ((float)($max - $currentCount) * $windowSec / $prevCount);
        if ($tWithin > 0) {
            return max(1, (int)ceil($tWithin));
        }
    }

    // Case B: currentCount >= max — need to survive into the next window.
    //   In next window: weighted ≈ currentCount*(windowSec - t')/windowSec
    //   Solve for t' when weighted = max, then add remaining time in current window.
    $remainInWindow = $windowStartEpoch + $windowSec - $now;
    if ($currentCount > 0) {
        $intoNext = (int)ceil($windowSec * (1.0 - (float)$max / $currentCount));
        return max(1, $remainInWindow + max(0, $intoNext));
    }
    return max(1, $remainInWindow + 1);
}

/* ---------------- Persistent account lockout (v3.6.0, #421) ---------------- */

/**
 * Clear a user's persistent account lockout. Side effect: UPDATEs the
 * users row, resetting failed_auth_count to 0 and nulling locked_until /
 * lock_reason.
 *
 * @param PDO $db  Live PDO handle.
 * @param int $uid User id whose lockout to clear.
 */
function ipam_clear_persistent_lockout(PDO $db, int $uid): void {
    $db->prepare(
        "UPDATE users SET failed_auth_count = 0, locked_until = NULL, lock_reason = NULL WHERE id = :id"
    )->execute([':id' => $uid]);
}
