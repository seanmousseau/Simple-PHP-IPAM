<?php
declare(strict_types=1);

/**
 * Backup history state loader + pagination helper.
 *
 * Extracted from backup_history.php in v3.21.0 Wave 4 commit 3 so the same
 * data can drive the legacy backup_history.php page AND the unified Backup &
 * Restore admin surface (backup_admin.php?tab=history).
 */

/**
 * Build a Backup-History query string for pagination/reset links. The caller
 * passes $self (e.g. 'backup_history.php' or 'backup_admin.php') so links
 * land on the same page the user is already on; tab=history is appended for
 * the unified surface.
 */
function ipam_backup_history_qs(
    int $dest,
    string $status,
    string $from,
    string $to,
    int $page,
    string $type,
    string $self,
    string $extraQuery = '',
    string $backupType = '',
    string $since = ''
): string {
    $parts = [];
    if ($extraQuery !== '') $parts[] = $extraQuery;
    if ($dest > 0)          $parts[] = 'destination_id=' . $dest;
    if ($status !== '')     $parts[] = 'status=' . urlencode($status);
    if ($from !== '')       $parts[] = 'from=' . urlencode($from);
    if ($to !== '')         $parts[] = 'to=' . urlencode($to);
    if ($type !== '')       $parts[] = 'type=' . urlencode($type);
    if ($backupType !== '') $parts[] = 'backup_type=' . urlencode($backupType);
    if ($since !== '')      $parts[] = 'since=' . urlencode($since);
    if ($page > 1)          $parts[] = 'page=' . $page;
    return $parts ? ($self . '?' . implode('&', $parts)) : $self;
}

/**
 * Build a chip-link URL: copy current filter state, override one dimension.
 * Pass `$override = ['key' => 'value']`; an empty value clears that filter.
 *
 * @param array<string, int|string> $current All current filter values.
 * @param array<string, int|string> $override Single-key map of the dimension to change.
 */
function ipam_backup_history_chip_url(string $self, string $extraQuery, array $current, array $override): string
{
    $merged = array_merge($current, $override);
    return ipam_backup_history_qs(
        to_int($merged['destination_id'] ?? 0),
        to_str($merged['status']         ?? ''),
        to_str($merged['from']           ?? ''),
        to_str($merged['to']             ?? ''),
        1, // any chip click resets pagination
        to_str($merged['type']           ?? ''),
        $self,
        $extraQuery,
        to_str($merged['backup_type']    ?? ''),
        to_str($merged['since']          ?? ''),
    );
}

/**
 * Load backup history rows + per-destination stats + filter values.
 *
 * @return array{
 *   rows: list<array<string, mixed>>,
 *   total: int,
 *   pages: positive-int,
 *   page: positive-int,
 *   perPage: int,
 *   stats: list<array<string, mixed>>,
 *   destinations: list<array<string, mixed>>,
 *   filterDest: int,
 *   filterStatus: string,
 *   filterFrom: string,
 *   filterTo: string,
 *   filterType: string,
 *   filterBackupType: string,
 *   filterSince: string,
 *   safeFrom: string,
 *   safeTo: string
 * }
 */
function ipam_backup_history_load_state(\PDO $db): array
{
    $page    = max(1, to_int($_GET['page'] ?? 1));
    $perPage = 50;

    $filterDest       = to_int($_GET['destination_id'] ?? 0);
    $filterStatus     = to_str($_GET['status'] ?? '');
    $rawFrom          = to_str($_GET['from'] ?? '');
    $rawTo            = to_str($_GET['to']   ?? '');
    $filterFrom       = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFrom) ? $rawFrom : '';
    $filterTo         = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawTo)   ? $rawTo   : '';
    $safeFrom         = htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8');
    $safeTo           = htmlspecialchars($filterTo,   ENT_QUOTES, 'UTF-8');
    $filterType       = to_str($_GET['type'] ?? '');
    $rawBackupType    = to_str($_GET['backup_type'] ?? '');
    $filterBackupType = in_array($rawBackupType, ['database', 'logical'], true) ? $rawBackupType : '';
    $rawSince         = to_str($_GET['since'] ?? '');
    $filterSince      = in_array($rawSince, ['24h', '7d', '30d'], true) ? $rawSince : '';

    $where  = [];
    $params = [];
    if ($filterDest > 0) {
        $where[]      = 'l.destination_id = :d';
        $params[':d'] = $filterDest;
    }
    if (in_array($filterStatus, ['running', 'success', 'failed', 'retention_pruned'], true)) {
        $where[]      = 'l.status = :s';
        $params[':s'] = $filterStatus;
    }
    if ($filterFrom !== '') {
        $where[]         = 'l.started_at >= :from';
        $params[':from'] = $filterFrom;
    }
    if ($filterTo !== '') {
        $where[]       = 'l.started_at <= :to';
        $params[':to'] = $filterTo . ' 23:59:59';
    }
    if ($filterSince !== '') {
        // Preset time chips: translate 24h / 7d / 30d into a started_at floor.
        // Computed in PHP (UTC) to keep the SQL portable across SQLite/MySQL/PG.
        $intervals = ['24h' => 86400, '7d' => 604800, '30d' => 2592000];
        $cutoff    = gmdate('Y-m-d H:i:s', time() - $intervals[$filterSince]);
        $where[]              = 'l.started_at >= :since';
        $params[':since']     = $cutoff;
    }
    if ($filterBackupType !== '') {
        $where[]                = 'l.backup_type = :bt';
        $params[':bt']          = $filterBackupType;
    }
    // v3.21.0 §A1 (#799/#808): backup_runs only tracks backup runs. The
    // restore-side `type` filter no longer matches anything; keep the URL
    // parameter for back-compat — `type=restore` yields zero rows;
    // `type=backup` is a no-op. Use `backup_type=database|logical` instead
    // (#804).
    if ($filterType === 'restore') {
        $where[] = '1 = 0';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM backup_runs l $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $pages  = max(1, (int) ceil($total / $perPage));
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $perPage;

    $rowSql = "SELECT l.*, d.name AS dest_name
               FROM backup_runs l
               LEFT JOIN backup_destinations d ON d.id = l.destination_id
               $whereSql
               ORDER BY l.started_at DESC
               LIMIT :lim OFFSET :off";
    $rowsStmt = $db->prepare($rowSql);
    foreach ($params as $k => $v) $rowsStmt->bindValue($k, $v);
    $rowsStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $rowsStmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $rowsStmt->execute();
    /** @var list<array<string, mixed>> $rows */
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    $statsStmt = $db->query(
        "SELECT d.id AS dest_id, d.name AS dest_name,
                (SELECT MAX(started_at) FROM backup_runs
                  WHERE destination_id = d.id AND status = 'success') AS last_success,
                (SELECT COALESCE(SUM(size_bytes), 0) FROM backup_runs
                  WHERE destination_id = d.id AND status = 'success') AS total_bytes,
                (SELECT MIN(next_run_at) FROM backup_schedules
                  WHERE destination_id = d.id AND is_active = 1) AS next_run
         FROM backup_destinations d
         WHERE d.is_active = 1
         ORDER BY d.name"
    );
    /** @var list<array<string, mixed>> $stats */
    $stats = ($statsStmt !== false) ? $statsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $destStmt = $db->query("SELECT id, name FROM backup_destinations ORDER BY name");
    /** @var list<array<string, mixed>> $destinations */
    $destinations = ($destStmt !== false) ? $destStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    return [
        'rows'         => $rows,
        'total'        => $total,
        'pages'        => $pages,
        'page'         => $page,
        'perPage'      => $perPage,
        'stats'        => $stats,
        'destinations' => $destinations,
        'filterDest'       => $filterDest,
        'filterStatus'     => $filterStatus,
        'filterFrom'       => $filterFrom,
        'filterTo'         => $filterTo,
        'filterType'       => $filterType,
        'filterBackupType' => $filterBackupType,
        'filterSince'      => $filterSince,
        'safeFrom'         => $safeFrom,
        'safeTo'           => $safeTo,
    ];
}

/**
 * Drawer-action helpers for the History tab (#803, F11).
 *
 * Verify and Delete are exposed as actions on the per-row drawer; both
 * are POST handlers wired in backup_admin.php's tab=history dispatch.
 * Each helper returns a JSON-serialisable array. The wiring layer maps
 * the structured `error` keys to HTTP status codes.
 */

/**
 * Resolve a destination row by id and return its BackupClientInterface.
 * Thin wrapper around ipam_backup_dest_client(array) to keep callers
 * id-based; returns null when the destination is missing or invalid.
 */
function ipam_backup_client_for_destination(\PDO $db, int $destId): ?BackupClientInterface
{
    $st = $db->prepare("SELECT * FROM backup_destinations WHERE id = :id");
    $st->execute([':id' => $destId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    /** @var array<string,mixed> $row */
    try {
        return ipam_backup_dest_client($row);
    } catch (\Throwable) {
        return null;
    }
}

/**
 * Re-fetches a backup_runs row's artifact from its destination and
 * recomputes SHA-256 to compare against the recorded checksum.
 *
 * Strategy: download() into a tmp file, hash_file('sha256'), unlink.
 * Works uniformly across Local/S3/SFTP without modifying
 * BackupClientInterface (no per-driver streaming hash needed).
 *
 * Result keys:
 *   ['ok' => true,  'expected' => '<hex>', 'actual' => '<hex>']
 *   ['ok' => false, 'expected' => '<hex>', 'actual' => '<hex>']  (mismatch)
 *   ['ok' => false, 'error' => 'not_found' | 'no_artifact' | 'unreachable',
 *                   'message' => '<str>']
 *
 * @return array<string,mixed>
 */
function ipam_backup_run_verify(\PDO $db, int $runId): array
{
    // CR feedback PR #1054: every early-return failure path now records a
    // backup_run.verify_failed audit so operator-driven verifies that fail
    // because the destination is broken stay visible in the audit trail.
    $st = $db->prepare("SELECT * FROM backup_runs WHERE id = :id");
    $st->execute([':id' => $runId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        audit($db, 'backup_run.verify_failed', 'backup_run', $runId, (string) json_encode(['error' => 'not_found']));
        return ['ok' => false, 'error' => 'not_found'];
    }
    $filename = to_str($row['filename'] ?? '');
    $expected = to_str($row['checksum'] ?? '');
    if ($filename === '' || $expected === '') {
        audit($db, 'backup_run.verify_failed', 'backup_run', $runId, (string) json_encode(['error' => 'no_artifact', 'reason' => 'filename or checksum empty']));
        return ['ok' => false, 'error' => 'no_artifact'];
    }
    $client = ipam_backup_client_for_destination($db, to_int($row['destination_id']));
    if (!$client) {
        audit($db, 'backup_run.verify_failed', 'backup_run', $runId, (string) json_encode(['error' => 'unreachable', 'reason' => 'destination missing or invalid']));
        return ['ok' => false, 'error' => 'unreachable', 'message' => 'destination missing or invalid'];
    }
    $tmp = sys_get_temp_dir() . '/ipam-verify-' . bin2hex(random_bytes(8));
    try {
        $found = $client->download($filename, $tmp);
        if (!$found) {
            audit($db, 'backup_run.verify_failed', 'backup_run', $runId, (string) json_encode(['error' => 'no_artifact', 'reason' => 'download returned false', 'filename' => $filename]));
            return ['ok' => false, 'error' => 'no_artifact'];
        }
        $hash = hash_file('sha256', $tmp);
        if ($hash === false) {
            audit($db, 'backup_run.verify_failed', 'backup_run', $runId, (string) json_encode(['error' => 'unreachable', 'reason' => 'hash_file failed']));
            return ['ok' => false, 'error' => 'unreachable', 'message' => 'hash_file failed'];
        }
    } catch (\Throwable $e) {
        audit($db, 'backup_run.verify_failed', 'backup_run', $runId, (string) json_encode(['error' => 'unreachable', 'reason' => $e->getMessage()]));
        return ['ok' => false, 'error' => 'unreachable', 'message' => $e->getMessage()];
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }

    $auditAction = ($hash === $expected) ? 'backup_run.verify' : 'backup_run.verify_failed';
    audit($db, $auditAction, 'backup_run', $runId, (string) json_encode(['expected' => $expected, 'actual' => $hash]));
    return ['ok' => $hash === $expected, 'expected' => $expected, 'actual' => $hash];
}

/**
 * POST dispatch for the History tab (#803).
 *
 * Handles two AJAX actions emitted by the per-row drawer:
 *   action=verify  → ipam_backup_run_verify()
 *   action=delete  → ipam_backup_run_delete() (DELETE confirm gate)
 *
 * Exits with a JSON response when the request matches; returns silently
 * so the caller can fall through to the GET render path otherwise.
 */
function ipam_backup_history_handle_post(\PDO $db): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $action = to_str($_POST['action'] ?? '');
    $jsonActions     = ['verify', 'delete'];
    $redirectActions = ['protect_run', 'unprotect_run'];
    if (!in_array($action, $jsonActions, true) && !in_array($action, $redirectActions, true)) {
        return;
    }
    csrf_require();
    $idRaw = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $id    = is_int($idRaw) && $idRaw > 0 ? $idRaw : 0;

    // v3.25.0 #847: protect / unprotect a backup_runs row. Plain POST →
    // header('Location:') redirect back to the history tab. The new
    // is_protected state shows on the badge in the next render; no flash
    // string is needed because the visual state itself is the feedback.
    if (in_array($action, $redirectActions, true)) {
        // Always redirect to the canonical history-tab URL — never reflect
        // HTTP_REFERER (CR #1096 major finding 2026-05-06: javascript:,
        // arbitrary internal paths, and CRLF-tainted strings can pass a
        // host-only allowlist). The history tab is the only legitimate
        // landing page for protect/unprotect anyway.
        $self = 'backup_admin.php?tab=history';
        if ($id !== 0) {
            $newFlag = $action === 'protect_run' ? 1 : 0;
            $stmt = $db->prepare("UPDATE backup_runs SET is_protected = :p WHERE id = :id");
            $stmt->execute([':p' => $newFlag, ':id' => $id]);
            if ($stmt->rowCount() > 0) {
                audit(
                    $db,
                    $newFlag === 1 ? 'backup.protect' : 'backup.unprotect',
                    'backup_run',
                    $id,
                    'is_protected=' . $newFlag
                );
            }
        }
        header('Location: ' . $self);
        exit;
    }

    header('Content-Type: application/json');

    if ($id === 0) {
        http_response_code(400);
        echo (string) json_encode(['ok' => false, 'error' => 'bad_request']);
        exit;
    }

    if ($action === 'verify') {
        echo (string) json_encode(ipam_backup_run_verify($db, $id));
        exit;
    }

    // action=delete — require the literal DELETE confirm string before
    // mutating. The drawer JS surfaces a type-to-confirm gate; this is
    // defence-in-depth for forged or scripted requests.
    if (to_str($_POST['confirm'] ?? '') !== 'DELETE') {
        http_response_code(400);
        echo (string) json_encode(['ok' => false, 'error' => 'confirm_required']);
        exit;
    }
    $result = ipam_backup_run_delete($db, $id);
    if (!($result['ok'] ?? false)) {
        $err = to_str($result['error'] ?? '');
        $status = match ($err) {
            'protected'               => 409,
            'destination_unreachable' => 502,
            'not_found'               => 404,
            default                   => 400,
        };
        http_response_code($status);
    }
    echo (string) json_encode($result);
    exit;
}

/**
 * Best-effort delete of a backup_runs row's artifact at the destination,
 * followed by the row delete. Refuses on is_protected = 1.
 *
 * Order matters: the file is removed first; only on success is the row
 * dropped. If the destination is unreachable the row is left intact so a
 * retry is possible (or, eventually, the auto-purge from #1053 will clean
 * it up after retention expires).
 *
 * Result keys:
 *   ['ok' => true,  'removed' => true]
 *   ['ok' => false, 'error' => 'not_found' | 'protected' | 'destination_unreachable',
 *                   'message' => '<str>']
 *
 * @return array<string,mixed>
 */
function ipam_backup_run_delete(\PDO $db, int $runId): array
{
    // CR feedback PR #1054: every refused / failed delete now records a
    // backup_run.delete_failed audit so denied destructive actions stay
    // visible in the audit trail.
    $st = $db->prepare("SELECT * FROM backup_runs WHERE id = :id");
    $st->execute([':id' => $runId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        audit($db, 'backup_run.delete_failed', 'backup_run', $runId, 'not_found');
        return ['ok' => false, 'error' => 'not_found'];
    }
    if (to_int($row['is_protected'] ?? 0) === 1) {
        audit($db, 'backup_run.delete_failed', 'backup_run', $runId, 'protected');
        return ['ok' => false, 'error' => 'protected'];
    }

    $filename = to_str($row['filename'] ?? '');
    if ($filename !== '') {
        // Defence-in-depth filename guard, mirroring remote_backups.php.
        // The filename is sourced from backup_runs.filename which the backup
        // engine sets to a generated name, but reject path-like values
        // before they reach any client->delete() implementation.
        if (str_contains($filename, '/') || str_contains($filename, '\\')
            || str_contains($filename, "\0") || str_starts_with($filename, '.')) {
            audit($db, 'backup_run.delete_failed', 'backup_run', $runId, 'filename_rejected_by_safety_guard');
            return ['ok' => false, 'error' => 'destination_unreachable', 'message' => 'Stored filename rejected by safety guard.'];
        }
        $client = ipam_backup_client_for_destination($db, to_int($row['destination_id']));
        if ($client === null) {
            // Destination row deleted or invalid — keep the backup_runs row so
            // the artifact reference is not lost. Operator must reconnect the
            // destination (or hand-delete via the storage console) before this
            // entry can be cleared. (CR feedback PR #1054.)
            audit($db, 'remote_backup.delete_failed', 'backup_run', $runId, 'destination client not found');
            return ['ok' => false, 'error' => 'destination_unreachable', 'message' => 'destination client not found'];
        }
        // CR feedback PR #1054 (plan doc:812): BackupClientInterface::delete()
        // returns bool — true on success, false on failure, exception on
        // transport errors. Treating "no exception" as success would let the
        // backup_runs row be deleted while the remote artifact persists.
        // Gate the row-level DELETE on an explicit `=== true`.
        try {
            $ok = $client->delete($filename); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $filename is DB-sourced and validated above against path separators
            if ($ok !== true) {
                audit($db, 'remote_backup.delete_failed', 'backup_run', $runId, 'client returned false for ' . $filename);
                return ['ok' => false, 'error' => 'remote_delete_failed', 'message' => 'destination client refused the delete'];
            }
            audit($db, 'remote_backup.delete', 'backup_run', $runId, $filename);
        } catch (\Throwable $e) {
            audit($db, 'remote_backup.delete_failed', 'backup_run', $runId, $e->getMessage());
            return ['ok' => false, 'error' => 'destination_unreachable', 'message' => $e->getMessage()];
        }
    }

    $db->prepare("DELETE FROM backup_runs WHERE id = :id")->execute([':id' => $runId]);
    audit($db, 'backup_run.delete', 'backup_run', $runId, '');
    return ['ok' => true, 'removed' => true];
}
