<?php
declare(strict_types=1);

/**
 * @module audit
 *
 * Audit-log helpers extracted from lib.php in v3.30.0 (ADR-004 Phase 4
 * Task 4.2). Audit-action filter validation, the audit_log row writer
 * (audit / audit_export), and the retention pruner (prune_audit_log).
 * Functions stay in the global namespace per ADR-004 Option E.
 *
 * Inclusion rule: functions whose primary job is reading, writing, or
 * filtering the audit_log table.
 *
 * Dependencies: lib/db.php (ipam_dialect), lib/utils.php (to_str),
 * plus current_user() / client_ip() which still live in lib/auth.php (loaded
 * after this module — resolved lazily at call time, never at include time).
 * The audit_log table/trigger self-heal helpers ensure_audit_log_table() /
 * ensure_audit_log_triggers() live in lib/db.php; this module never calls
 * them directly any more (see #912 below).
 *
 * ADR-003: none of the moved functions read `global $config;`, so no
 * ipam_config() conversion was needed at extraction time.
 *
 * #912: prune_audit_log() previously carried a 3-arm switch on the driver
 * name, each arm implementing its own append-only-trigger bypass strategy
 * (SQLite drop/recreate inside BEGIN IMMEDIATE, MySQL session variable,
 * Postgres SET LOCAL GUC) plus an error-path restoration arm. That logic
 * moved into Dialect::with_append_only_bypass(); prune_audit_log() now just
 * builds the cutoff and hands a DELETE closure to the dialect. Observable
 * behaviour is byte-identical: $retentionDays <= 0 -> 0; failure -> error_log
 * + return 0.
 */

/**
 * Allowed audit-action prefixes (categories).
 * Shared between audit.php (UI filter) and api.php (/audit endpoint).
 */
const AUDIT_FILTER_PREFIXES = [
    'address', 'aggregate', 'alert', 'apikey', 'audit', 'auth', 'backup',
    'backup_run', 'config', 'contact', 'custom_field', 'db', 'destination',
    'device', 'device_interface', 'dhcp_pool', 'export', 'import', 'mail',
    'mfa', 'pd_pool', 'remote_backup', 'restore', 'scan', 'setting',
    'settings', 'site', 'subnet', 'tag', 'user', 'vault', 'vlan', 'vrf',
    'webhook',
];

/**
 * Validate an audit-prefix filter string. Returns the prefix if it matches the
 * allowlist, or '' if not. Use for ?prefix=foo style filters.
 */
function audit_filter_validate_prefix(string $raw): string
{
    $p = trim($raw);
    return ($p !== '' && in_array($p, AUDIT_FILTER_PREFIXES, true)) ? $p : '';
}

/**
 * Validate an exact audit-action filter string. Returns the action if it
 * matches the <prefix>.<verb> regex, or '' if not. Use for ?action=auth.login
 * style filters; no SQL-injection surface beyond bind.
 */
function audit_filter_validate_action(string $raw): string
{
    $a = trim($raw);
    // CR #1100: allow multi-segment actions like 'mfa.otp.fail' or
    // 'backup.vault_key.revealed'. The previous single-dot regex
    // rejected every audit row this codebase emits with two-dot
    // hierarchies, so ?action=<that> filters could never match.
    return ($a !== '' && preg_match('/^[a-z_]+(?:\.[a-z_]+)+$/', $a)) ? $a : '';
}

/**
 * Append a row to audit_log. Returns true on success, false on PDO failure
 * (with the failure logged via error_log). Callers that need to surface
 * audit failures (e.g. cron jobs) should check the return value; user-facing
 * pages can continue to ignore it. Pre-v3.26.0 this function was `void` and
 * would let exceptions propagate up the page, sometimes bubbling past the
 * page layout and rendering a blank screen.
 *
 * v3.26.0 (#1100 CR review): when invoked inside an active transaction,
 * a PDO failure RETHROWS rather than silently returning false. Callers
 * like ipam_setting_set() and auto_reserve_subnet_ips() rely on the
 * audit row landing atomically with the persisted state change; if the
 * audit insert fails, the surrounding transaction must roll back so
 * we don't commit state without its corresponding audit entry. Outside
 * an active transaction (most user-facing pages and cron tasks) the
 * legacy false-return behaviour is preserved.
 */
function audit(PDO $db, string $action, string $entityType, ?int $entityId, string $details = ''): bool
{
    $u = current_user();
    try {
        $st = $db->prepare("INSERT INTO audit_log (user_id, username, action, entity_type, entity_id, ip, user_agent, details)
                            VALUES (:uid,:un,:ac,:et,:eid,:ip,:ua,:dt)");
        $st->execute([
            ':uid' => $u['id'] ?: null,
            ':un'  => $u['username'] ?: null,
            ':ac'  => $action,
            ':et'  => $entityType,
            ':eid' => $entityId,
            ':ip'  => client_ip() ?: null,
            ':ua'  => to_str($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ':dt'  => $details,
        ]);
        return true;
    } catch (\PDOException $e) {
        error_log("audit failed: action={$action} entity={$entityType} id="
            . ($entityId === null ? 'NULL' : (string)$entityId)
            . ' err=' . $e->getMessage());
        if ($db->inTransaction()) {
            throw $e;
        }
        return false;
    }
}

function audit_export(PDO $db, string $what, string $details = ''): void
{
    audit($db, "export.$what", 'system', null, $details);
}

/**
 * Delete audit_log rows older than $retentionDays, returning the number of
 * rows removed (0 when retention is disabled or on any failure).
 *
 * The retention routine must DELETE rows without ever leaving the
 * append-only guarantee observable-violated for other connections. The
 * per-engine bypass strategy (SQLite reserved-lock trigger drop/recreate,
 * MySQL session variable, Postgres SET LOCAL GUC) and its error-path
 * restoration now live in Dialect::with_append_only_bypass() — see #502
 * (the original race fix) and #912 (the Dialect-helper refactor). This
 * function only builds the cutoff and supplies the DELETE closure.
 */
function prune_audit_log(PDO $db, int $retentionDays): int
{
    if ($retentionDays <= 0) return 0;
    $cutoff = date('Y-m-d H:i:s', (int)strtotime("-{$retentionDays} days"));

    try {
        return (int) ipam_dialect()->with_append_only_bypass($db, function () use ($db, $cutoff) {
            $st = $db->prepare("DELETE FROM audit_log WHERE created_at < :cutoff");
            $st->execute([':cutoff' => $cutoff]);
            return $st->rowCount();
        });
    } catch (Throwable $e) {
        error_log('audit_log prune failed: ' . $e->getMessage());
        return 0;
    }
}
