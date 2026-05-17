<?php
declare(strict_types=1);

/**
 * @module user_preferences
 *
 * Per-user preference read/write layer introduced in v3.30.0 (ADR-002 Q1–Q4,
 * Task 5.3 Chunk 3, sub of #907). Functions stay in the global namespace per
 * ADR-004 Option E.
 *
 * Responsibilities: atomic read (ipam_user_preference_get) and write
 * (ipam_user_preference_set) of rows in the `user_preferences` table.
 * The table schema — composite PK (user_id, key), value TEXT (nullable),
 * updated_at — is created by the `3.30.0-user-preferences` migration (Chunk 2).
 *
 * ADR-002: preferences are cosmetic (theme only in v3.30.0). No audit emission
 * is needed — the CSRF + session gate on the write endpoint is the sole
 * security boundary. The write endpoint lives in user_preference.php (a
 * dedicated session-authed, CSRF-required JSON file), NOT in api.php, which is
 * the stateless Bearer-only, CSRF-exempt surface (CLAUDE.md invariant #4).
 *
 * Dependencies: lib/db.php (ipam_dialect, ipam_key_col).
 * ipam_key_col() and ipam_dialect() resolve lazily at call time, never at
 * include time — this module has no side-effects on load.
 */

/**
 * Return the stored preference value for a (user_id, key) pair, or null if
 * no row exists.
 *
 * @param \PDO    $db     Active database connection.
 * @param int     $userId The user's primary key.
 * @param string  $key    Preference key (e.g. 'theme').
 * @return string|null    The stored value, or null when absent.
 */
function ipam_user_preference_get(PDO $db, int $userId, string $key): ?string
{
    $kc   = ipam_key_col();
    $stmt = $db->prepare(
        "SELECT value FROM user_preferences WHERE user_id = :uid AND {$kc} = :k"
    );
    $stmt->execute([':uid' => $userId, ':k' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    $v = $row['value'] ?? null;
    return is_string($v) ? $v : null;
}

/**
 * Atomically write a preference value for a (user_id, key) pair.
 *
 * Uses an explicit SELECT-then-UPDATE/INSERT pattern (same as ipam_setting_set)
 * because SQLite treats NULL as distinct from NULL in UNIQUE index lookups,
 * making ON CONFLICT unreliable for nullable PK columns. user_preferences has
 * no nullable PK columns, but the explicit branch is still the most portable
 * approach across all three supported engines (SQLite, MySQL, PostgreSQL) and
 * avoids dialect-specific UPSERT syntax entirely.
 *
 * Wraps in a transaction only when no outer transaction is already active
 * ($ownTx guard) — identical to the ipam_setting_set pattern.
 *
 * @param \PDO   $db     Active database connection.
 * @param int    $userId The user's primary key.
 * @param string $key    Preference key (e.g. 'theme').
 * @param string $value  Value to store.
 */
function ipam_user_preference_set(PDO $db, int $userId, string $key, string $value): void
{
    $kc = ipam_key_col();
    $d  = ipam_dialect();

    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    }
    try {
        $sel = $db->prepare(
            "SELECT value FROM user_preferences WHERE user_id = :uid AND {$kc} = :k"
        );
        $sel->execute([':uid' => $userId, ':k' => $key]);
        $existing = $sel->fetch(PDO::FETCH_ASSOC);

        if (is_array($existing)) {
            // Row exists — update in place.
            $db->prepare(
                "UPDATE user_preferences
                 SET value = :v, updated_at = {$d->now()}
                 WHERE user_id = :uid AND {$kc} = :k"
            )->execute([':v' => $value, ':uid' => $userId, ':k' => $key]);
        } else {
            // Row absent — insert.
            $db->prepare(
                "INSERT INTO user_preferences (user_id, {$kc}, value, updated_at)
                 VALUES (:uid, :k, :v, {$d->now()})"
            )->execute([':uid' => $userId, ':k' => $key, ':v' => $value]);
        }

        if ($ownTx) {
            $db->commit();
        }
    } catch (\Throwable $ex) {
        if ($ownTx) {
            try {
                $db->rollBack();
            } catch (\Throwable) {
                // Swallow rollback failure so the original exception propagates.
            }
        }
        throw $ex;
    }
}
