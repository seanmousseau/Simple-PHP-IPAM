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
 * @var string                     $safeFrom
 * @var string                     $safeTo
 * @var string                     $self           Page URL (e.g. 'backup_history.php' or 'backup_admin.php?tab=history')
 * @var string                     $extraQuery     Optional extra query (e.g. 'tab=history') threaded through pagination links
 */

// Reset URL drops every filter, keeps $extraQuery (so tab=history persists).
$resetUrl = $extraQuery !== '' ? ($self . '?' . $extraQuery) : $self;
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
          <td><?= e(to_str($s['last_success'] ?? '—')) ?></td>
          <td><?= number_format(to_int($s['total_bytes'])) ?> bytes</td>
          <td><?= e(to_str($s['next_run'] ?? '—')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2>Filter</h2>
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
      <label>Type
        <select name="type">
          <option value="">— Any —</option>
          <option value="backup"  <?= $filterType === 'backup'  ? 'selected' : '' ?>>Backup</option>
          <option value="restore" <?= $filterType === 'restore' ? 'selected' : '' ?>>Restore</option>
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
        <thead><tr><th>Started</th><th>Destination</th><th>Trigger</th><th>Type</th><th>Status</th><th>Filename</th><th>Size</th><th>Duration</th><th>Checksum</th></tr></thead>
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
        ?>
          <?php $runId = to_int($r['id']); ?>
          <tr class="history-row"
              tabindex="0"
              data-run-id="<?= $runId ?>"
              data-drawer-url="backup_run_detail.php?id=<?= $runId ?>"
              data-drawer-title="Run #<?= $runId ?>"
              aria-label="Open details for run <?= $runId ?>">
            <td><?= e($started) ?></td>
            <td><?= e(to_str($r['dest_name'] ?? 'unknown')) ?></td>
            <td><?= e(to_str($r['triggered_by'])) ?></td>
            <td><span class="badge badge-backup">Backup</span></td>
            <td><span class="badge <?= e($statusClass) ?>"><?= e($statusVal) ?></span></td>
            <td><?= e(to_str($r['filename'] ?? '—')) ?></td>
            <td><?= $r['size_bytes'] !== null ? number_format(to_int($r['size_bytes'])) : '—' ?></td>
            <td><?= e($duration) ?></td>
            <td title="<?= e($cs) ?>"><?= e($csShort) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($pages > 1): ?>
      <nav class="pagination">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
          <a class="action-pill <?= $p === $page ? 'is-active' : '' ?>"
             href="<?= e(ipam_backup_history_qs($filterDest, $filterStatus, $filterFrom, $filterTo, $p, $filterType, $self, $extraQuery)) ?>"><?= $p ?></a>
        <?php endfor; ?>
      </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
