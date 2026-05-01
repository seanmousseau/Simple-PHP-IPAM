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
    string $extraQuery = ''
): string {
    $parts = [];
    if ($extraQuery !== '') $parts[] = $extraQuery;
    if ($dest > 0)          $parts[] = 'destination_id=' . $dest;
    if ($status !== '')     $parts[] = 'status=' . urlencode($status);
    if ($from !== '')       $parts[] = 'from=' . urlencode($from);
    if ($to !== '')         $parts[] = 'to=' . urlencode($to);
    if ($type !== '')       $parts[] = 'type=' . urlencode($type);
    if ($page > 1)          $parts[] = 'page=' . $page;
    return $parts ? ($self . '?' . implode('&', $parts)) : $self;
}

/**
 * Load backup history rows + per-destination stats + filter values.
 *
 * @return array{
 *   rows: list<array<string, mixed>>,
 *   total: int,
 *   pages: int,
 *   page: int,
 *   perPage: int,
 *   stats: list<array<string, mixed>>,
 *   destinations: list<array<string, mixed>>,
 *   filterDest: int,
 *   filterStatus: string,
 *   filterFrom: string,
 *   filterTo: string,
 *   filterType: string,
 *   safeFrom: string,
 *   safeTo: string
 * }
 */
function ipam_backup_history_load_state(\PDO $db): array
{
    $page    = max(1, to_int($_GET['page'] ?? 1));
    $perPage = 50;

    $filterDest   = to_int($_GET['destination_id'] ?? 0);
    $filterStatus = to_str($_GET['status'] ?? '');
    $rawFrom      = to_str($_GET['from'] ?? '');
    $rawTo        = to_str($_GET['to']   ?? '');
    $filterFrom   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFrom) ? $rawFrom : '';
    $filterTo     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawTo)   ? $rawTo   : '';
    $safeFrom     = htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8');
    $safeTo       = htmlspecialchars($filterTo,   ENT_QUOTES, 'UTF-8');
    $filterType   = to_str($_GET['type'] ?? '');

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
    // v3.21.0 §A1 (#799/#808): backup_runs only tracks backup runs. The
    // restore-side type filter no longer matches anything; keep the URL
    // parameter for back-compat — 'restore' yields zero rows; 'backup' is
    // a no-op filter.
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
        'filterDest'   => $filterDest,
        'filterStatus' => $filterStatus,
        'filterFrom'   => $filterFrom,
        'filterTo'     => $filterTo,
        'filterType'   => $filterType,
        'safeFrom'     => $safeFrom,
        'safeTo'       => $safeTo,
    ];
}
