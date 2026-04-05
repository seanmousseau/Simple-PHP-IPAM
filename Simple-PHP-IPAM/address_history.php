<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

$addressId = (int)($_GET['address_id'] ?? 0);
if ($addressId <= 0) {
    http_response_code(400);
    exit('Missing address_id');
}

$st = $db->prepare("SELECT a.id, a.ip, a.subnet_id, s.cidr AS subnet_cidr
                    FROM addresses a
                    JOIN subnets s ON s.id = a.subnet_id
                    WHERE a.id = :id");
$st->execute([':id' => $addressId]);
$addr = $st->fetch();

if (!$addr) {
    // Address may already be deleted; fall back to history table
    $st = $db->prepare("SELECT address_id AS id, ip, subnet_id
                        FROM address_history
                        WHERE address_id = :id
                        ORDER BY id DESC
                        LIMIT 1");
    $st->execute([':id' => $addressId]);
    $fallback = $st->fetch();
    if (!$fallback) {
        http_response_code(404);
        exit('Address not found');
    }

    $st = $db->prepare("SELECT cidr FROM subnets WHERE id = :sid");
    $st->execute([':sid' => (int)$fallback['subnet_id']]);
    $sub = $st->fetch();

    $addr = [
        'id' => (int)$fallback['id'],
        'ip' => (string)$fallback['ip'],
        'subnet_id' => (int)$fallback['subnet_id'],
        'subnet_cidr' => (string)($sub['cidr'] ?? 'unknown'),
    ];
}

$page = q_int('page', 1, 1, 1000000);
$pageSize = q_int('page_size', 100, 1, 500);

$histSortCols = ['time' => 'created_at', 'action' => 'action', 'user' => 'username'];
$histSort = parse_sort($histSortCols, 'time', 'desc');

$st = $db->prepare("SELECT COUNT(*) AS c FROM address_history WHERE address_id = :aid");
$st->execute([':aid' => $addressId]);
$total = (int)$st->fetch()['c'];

$p = paginate($total, $page, $pageSize);

$st = $db->prepare("
    SELECT id, created_at, action, username, client_ip, before_json, after_json
    FROM address_history
    WHERE address_id = :aid
    ORDER BY {$histSort['sql']}
    LIMIT :lim OFFSET :off
");
$st->bindValue(':aid', $addressId, PDO::PARAM_INT);
$st->bindValue(':lim', $p['limit'], PDO::PARAM_INT);
$st->bindValue(':off', $p['offset'], PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

function j_pretty_hist(?string $json): string {
    if ($json === null || trim($json) === '') return '';
    $data = json_decode($json, true);
    if ($data === null) return $json;
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function render_history_diff(?string $beforeJson, ?string $afterJson): string {
    $before = ($beforeJson !== null && trim($beforeJson) !== '') ? json_decode($beforeJson, true) : null;
    $after  = ($afterJson  !== null && trim($afterJson)  !== '') ? json_decode($afterJson,  true) : null;
    if ($before === null && $after === null) return '<span class="muted">—</span>';

    $allKeys = array_unique(array_merge(
        $before !== null ? array_keys($before) : [],
        $after  !== null ? array_keys($after)  : []
    ));
    sort($allKeys);

    $html = '<table class="diff-table"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>';
    foreach ($allKeys as $key) {
        $bVal = $before[$key] ?? '';
        $aVal = $after[$key]  ?? '';
        $changed = (string)$bVal !== (string)$aVal;
        $cls = $changed ? ' class="diff-changed"' : '';
        $html .= '<tr' . $cls . '>'
               . '<td><b>' . e($key) . '</b></td>'
               . '<td>' . ($bVal !== '' ? e((string)$bVal) : '<span class="muted">—</span>') . '</td>'
               . '<td>' . ($aVal !== '' ? e((string)$aVal) : '<span class="muted">—</span>') . '</td>'
               . '</tr>';
    }
    $html .= '</tbody></table>';
    return $html;
}

function build_query_hist(array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    return http_build_query($q);
}

page_header('Address History');
?>

<div class="breadcrumbs">
  <a href="dashboard.php">🏠 Dashboard</a>
  <span class="sep">›</span>
  <a href="addresses.php?subnet_id=<?= (int)$addr['subnet_id'] ?>">🧾 Addresses</a>
  <span class="sep">›</span>
  <span><?= e($addr['ip']) ?></span>
  <span class="sep">›</span>
  <span>📜 History</span>
</div>

<div class="toolbar">
  <div>
    <h1>Address History</h1>
    <div class="muted">Address: <b><?= e($addr['ip']) ?></b> in subnet <b><?= e($addr['subnet_cidr']) ?></b></div>
  </div>
</div>

<div class="page-actions">
  <a class="action-pill" href="addresses.php?subnet_id=<?= (int)$addr['subnet_id'] ?>">🧾 Back to Addresses</a>
  <a class="action-pill" href="search.php?q=<?= urlencode($addr['ip']) ?>">🔎 Search this IP</a>
</div>

<div class="card mt-16">
  <div class="muted">
    Events: <b><?= e((string)$total) ?></b>
    <?php if ($total > 0): ?>
      &nbsp;|&nbsp; Page <b><?= e((string)$p['page']) ?></b> of <b><?= e((string)$p['pages']) ?></b>
    <?php endif; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="empty-state mt-12">No history entries yet.</div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="mt-12">
      <thead>
        <tr>
          <?php $histQsBase = '?address_id=' . $addressId . '&page_size=' . $pageSize;
                echo sort_th('time',   'Time',   $histSort['col'], $histSort['dir'], $histQsBase);
                echo sort_th('action', 'Action', $histSort['col'], $histSort['dir'], $histQsBase);
                echo sort_th('user',   'User',   $histSort['col'], $histSort['dir'], $histQsBase);
          ?>
          <th>Client IP</th>
          <th>Changes</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="muted"><?= e($r['created_at']) ?></td>
          <td><?= e($r['action']) ?></td>
          <td><?= e((string)($r['username'] ?? '')) ?></td>
          <td class="muted"><?= e((string)($r['client_ip'] ?? '')) ?></td>
          <td><?= render_history_diff($r['before_json'], $r['after_json']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <p class="mt-12">
      <?php if ($p['page'] > 1): ?>
        <a href="address_history.php?<?= e(build_query_hist(['page' => $p['page'] - 1])) ?>">&laquo; Prev</a>
      <?php endif; ?>
      <?php if ($p['page'] < $p['pages']): ?>
        <a class="ml-12" href="address_history.php?<?= e(build_query_hist(['page' => $p['page'] + 1])) ?>">Next &raquo;</a>
      <?php endif; ?>
    </p>
  <?php endif; ?>
</div>

<?php page_footer();
