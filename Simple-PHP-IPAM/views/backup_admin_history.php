<?php
declare(strict_types=1);

/**
 * Backup history render block. Shared by backup_history.php (legacy) and
 * backup_admin.php?tab=history (unified surface, v3.21.0 Wave 4).
 *
 * @var list<array<string, mixed>> $rows
 * @var int                        $total
 * @var int                        $pages
 * @var int                        $page
 * @var int                        $perPage
 * @var list<array<string, mixed>> $stats
 * @var list<array<string, mixed>> $destinations
 * @var int                        $filterDest
 * @var string                     $filterStatus
 * @var string                     $filterFrom
 * @var string                     $filterTo
 * @var string                     $filterType
 * @var string                     $filterBackupType
 * @var string                     $filterSince
 * @var string                     $safeFrom
 * @var string                     $safeTo
 * @var string                     $self           Page URL (e.g. 'backup_history.php' or 'backup_admin.php?tab=history')
 * @var string                     $extraQuery     Optional extra query (e.g. 'tab=history') threaded through pagination links
 */

// Reset URL drops every filter, keeps $extraQuery (so tab=history persists).
$resetUrl = $extraQuery !== '' ? ($self . '?' . $extraQuery) : $self;

// Snapshot of current filter state used to compute chip URLs (#804).
$currentFilters = [
    'destination_id' => $filterDest,
    'status'         => $filterStatus,
    'from'           => $filterFrom,
    'to'             => $filterTo,
    'type'           => $filterType,
    'backup_type'    => $filterBackupType,
    'since'          => $filterSince,
];
$chipUrl = static fn (string $key, string $value): string =>
    ipam_backup_history_chip_url($self, $extraQuery, $currentFilters, [$key => $value]);

$anyFilterActive = $filterDest > 0
    || $filterStatus !== ''
    || $filterFrom !== ''
    || $filterTo !== ''
    || $filterType !== ''
    || $filterBackupType !== ''
    || $filterSince !== '';
?>
  <?php if (!empty($stats)): ?>
  <section class="card">
    <h2>Status by destination</h2>
    <table class="data-table">
      <thead><tr><th>Destination</th><th>Last successful</th><th>Total stored</th><th>Next scheduled</th></tr></thead>
      <tbody>
      <?php foreach ($stats as $s): ?>
        <tr>
          <td><?= e(to_str($s['dest_name'])) ?></td>
          <td><?= e(ipam_format_datetime(to_str($s['last_success'] ?? '')) ?: '—') ?></td>
          <td><?= number_format(to_int($s['total_bytes'])) ?> bytes</td>
          <td><?= e(ipam_format_datetime(to_str($s['next_run'] ?? '')) ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2>Filter</h2>

    <?php
      // #804 — chip rows for the three single-value dimensions (status, backup
      // type, time preset). Each chip is a plain <a> that mutates exactly one
      // URL parameter; tests can rely on the rendered href without JS.
      $statusChips = [
          ''                 => 'All',
          'running'          => 'Running',
          'success'          => 'Success',
          'failed'           => 'Failed',
          'retention_pruned' => 'Retention pruned',
      ];
      $typeChips = [
          ''         => 'All',
          'database' => 'Database',
          'logical'  => 'Logical',
      ];
      $sinceChips = [
          ''    => 'All time',
          '24h' => 'Last 24h',
          '7d'  => 'Last 7d',
          '30d' => 'Last 30d',
      ];
    ?>
    <div class="filter-chips" data-filter-chips>
      <div class="filter-chip-row">
        <span class="filter-chip-label">Status</span>
        <?php foreach ($statusChips as $val => $label): ?>
          <a class="filter-chip <?= $filterStatus === $val ? 'is-active' : '' ?>"
             data-chip-dim="status"
             data-chip-value="<?= e($val) ?>"
             href="<?= e($chipUrl('status', $val)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="filter-chip-row">
        <span class="filter-chip-label">Backup type</span>
        <?php foreach ($typeChips as $val => $label): ?>
          <a class="filter-chip <?= $filterBackupType === $val ? 'is-active' : '' ?>"
             data-chip-dim="backup_type"
             data-chip-value="<?= e($val) ?>"
             href="<?= e($chipUrl('backup_type', $val)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="filter-chip-row">
        <span class="filter-chip-label">Time</span>
        <?php foreach ($sinceChips as $val => $label): ?>
          <a class="filter-chip <?= $filterSince === $val ? 'is-active' : '' ?>"
             data-chip-dim="since"
             data-chip-value="<?= e($val) ?>"
             href="<?= e($chipUrl('since', $val)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
      <?php if ($anyFilterActive): ?>
        <a class="filter-chip filter-chip-clear" href="<?= e($resetUrl) ?>">Clear all</a>
      <?php endif; ?>
    </div>

    <form method="get" class="filter-bar">
      <?php if ($extraQuery !== ''): ?>
        <?php
        // Preserve tab=history (or any caller-threaded params) on filter submit.
        foreach (explode('&', $extraQuery) as $kv) {
            if ($kv === '') continue;
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }
        ?>
      <?php endif; ?>
      <label>Destination
        <select name="destination_id">
          <option value="0">— Any —</option>
          <?php foreach ($destinations as $d): ?>
            <?php $dId = to_int($d['id']); ?>
            <option value="<?= $dId ?>" <?= $filterDest === $dId ? 'selected' : '' ?>><?= e(to_str($d['name'])) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Status
        <select name="status">
          <option value="">— Any —</option>
          <?php foreach (['running','success','failed','retention_pruned'] as $st): ?>
            <option value="<?= e($st) ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= e($st) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Backup type
        <select name="backup_type">
          <option value="">— Any —</option>
          <option value="database" <?= $filterBackupType === 'database' ? 'selected' : '' ?>>Database</option>
          <option value="logical"  <?= $filterBackupType === 'logical'  ? 'selected' : '' ?>>Logical</option>
        </select>
      </label>
      <label>From <input type="date" name="from" value="<?= e($filterFrom) ?>"></label>
      <label>To <input type="date" name="to" value="<?= e($filterTo) ?>"></label>
      <button type="submit" class="action-pill">Filter</button>
      <a class="action-pill" href="<?= e($resetUrl) ?>">Reset</a>
    </form>
  </section>

  <section class="card">
    <h2>Log entries (<?= number_format($total) ?>)</h2>
    <?php if (count($rows) === 0): ?>
      <p class="muted">No backup runs found.</p>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Started</th><th>Destination</th><th>Trigger</th><th>Type</th><th>Encryption</th><th>Status</th><th>Filename</th><th>Size</th><th>Duration</th><th>Checksum</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          $started   = to_str($r['started_at']);
          $completed = to_str($r['completed_at'] ?? '');
          $duration  = '—';
          if ($completed !== '' && $started !== '') {
              $secs     = max(0, strtotime($completed) - strtotime($started));
              $duration = $secs . 's';
          }
          $statusVal   = to_str($r['status']);
          $statusClass = 'badge-' . $statusVal;
          $cs          = to_str($r['checksum'] ?? '');
          $csShort     = $cs !== '' ? (substr($cs, 0, 12) . '…') : '—';
          // v3.25.0 #847: protected badge.
          $isProtected = to_int($r['is_protected'] ?? 0) === 1;
          // v3.25.0 #857: encryption-format badge derived from encryption_mode
          // + source_version. IPAMBKP3 was introduced in v3.24.0; older
          // backups taken with encrypt=1 are IPAMBKP1/2 depending on when.
          $encMode = to_str($r['encryption_mode'] ?? 'stored');
          $sourceVer = to_str($r['source_version'] ?? '0.0.0');
          if ($encMode === 'unencrypted') {
              $encLabel = 'Plaintext';
              $encClass = 'badge-warn';
              $encTip   = 'Plaintext payload (Local destination opt-out). No re-encryption available.';
          } elseif ($encMode === 'transitory') {
              $encLabel = 'Per-passphrase';
              $encClass = 'badge-success';
              $encTip   = 'IPAMBKP3 transitory mode (manual passphrase). Restore requires the original passphrase.';
          } else {
              // Heuristic: IPAMBKP3 from v3.24.0 onwards; before that, IPAMBKP1/2.
              $verPad = ipam_normalise_version($sourceVer);
              if (function_exists('version_compare') && version_compare($verPad, '3.24.0', '>=')) {
                  $encLabel = 'v3';
                  $encTip   = 'IPAMBKP3 stored-key encryption.';
              } elseif (version_compare($verPad, '3.17.0', '>=')) {
                  $encLabel = 'v2';
                  $encTip   = 'IPAMBKP2 encryption (pre-v3.24). Re-encrypt by re-running the backup on this install.';
              } else {
                  $encLabel = 'v1';
                  $encTip   = 'IPAMBKP1 legacy encryption.';
              }
              $encClass = 'badge-success';
          }
        ?>
          <?php $runId = to_int($r['id']); ?>
          <tr class="history-row<?= $isProtected ? ' row-protected' : '' ?>"
              tabindex="0"
              data-run-id="<?= $runId ?>"
              data-drawer-url="backup_run_detail.php?id=<?= $runId ?>"
              data-drawer-title="Run #<?= $runId ?>"
              aria-label="Open details for run <?= $runId ?>">
            <td><?= e(ipam_format_datetime($started)) ?></td>
            <td><?= e(to_str($r['dest_name'] ?? 'unknown')) ?></td>
            <td><?= e(to_str($r['triggered_by'])) ?></td>
            <?php $btType = to_str($r['backup_type'] ?? ''); ?>
            <td><span class="badge badge-backup"><?= e($btType !== '' ? ucfirst($btType) : 'Backup') ?></span></td>
            <td><span class="badge <?= e($encClass) ?>" title="<?= e($encTip) ?>"><?= e($encLabel) ?></span></td>
            <td>
              <span class="badge <?= e($statusClass) ?>"><?= e($statusVal) ?></span>
              <?php if ($isProtected): ?>
                <span class="badge badge-protected" title="Protected from retention auto-prune">★ protected</span>
              <?php endif; ?>
            </td>
            <td><?= e(to_str($r['filename'] ?? '—')) ?></td>
            <td><?= $r['size_bytes'] !== null ? number_format(to_int($r['size_bytes'])) : '—' ?></td>
            <td><?= e($duration) ?></td>
            <td title="<?= e($cs) ?>"><?= e($csShort) ?></td>
            <td class="actions" data-stop-propagation>
              <form method="post" style="display:inline" data-no-drawer>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= $isProtected ? 'unprotect_run' : 'protect_run' ?>">
                <input type="hidden" name="id" value="<?= $runId ?>">
                <button class="action-pill button-secondary" type="submit"
                        title="<?= $isProtected ? 'Allow retention to prune this row' : 'Exclude this row from retention auto-prune' ?>">
                  <?= $isProtected ? 'Unprotect' : 'Protect' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($pages > 1): ?>
      <nav class="pagination">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
          <a class="action-pill <?= $p === $page ? 'is-active' : '' ?>"
             href="<?= e(ipam_backup_history_qs($filterDest, $filterStatus, $filterFrom, $filterTo, $p, $filterType, $self, $extraQuery, $filterBackupType, $filterSince)) ?>"><?= $p ?></a>
        <?php endfor; ?>
      </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
