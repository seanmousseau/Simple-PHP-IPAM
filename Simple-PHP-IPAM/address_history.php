<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

$addressId = (int)($_GET['address_id'] ?? 0);
if ($addressId <= 0) {
    http_response_code(400);
    exit('Missing address_id');
}

// Load address (for header)
$st = $db->prepare("SELECT a.id, a.ip, s.cidr AS subnet_cidr
                    FROM addresses a JOIN subnets s ON s.id = a.subnet_id
                    WHERE a.id = :id");
$st->execute([':id' => $addressId]);
$addr = $st->fetch();
if (!$addr) {
    http_response_code(404);
    exit('Address not found');
}

$page = q_int('page', 1, 1, 1000000);
$pageSize = q_int('page_size', 50, 1, 200);

$st = $db->prepare("SELECT COUNT(*) AS c FROM address_history WHERE address_id = :aid");
$st->execute([':aid' => $addressId]);
$total = (int)$st->fetch()['c'];

$p = paginate($total, $page, $pageSize);

$st = $db->prepare("
    SELECT id, created_at, action, username, client_ip, before_json, after_json
    FROM address_history
    WHERE address_id = :aid
    ORDER BY id DESC
    LIMIT :lim OFFSET :off
");
$st->bindValue(':aid', $addressId, PDO::PARAM_INT);
$st->bindValue(':lim', $p['limit'], PDO::PARAM_INT);
$st->bindValue(':off', $p['offset'], PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

function j_pretty(?string $json): string {
    if ($json === null || trim($json) === '') return '';
    $data = json_decode($json, true);
    if ($data === null) return $json;
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
<h1>Address History</h1>
<p>
  Address: <b><?= e($addr['ip']) ?></b> in subnet <b><?= e($addr['subnet_cidr']) ?></b>
</p>

<p class="muted">
  Events: <b><?= e((string)$total) ?></b>
  <?php if ($total > 0): ?>
    &nbsp;|&nbsp; Page <b><?= e((string)$p['page']) ?></b> of <b><?= e((string)$p['pages']) ?></b>
  <?php endif; ?>
</p>

<?php if (!$rows): ?>
  <p class="muted">No history entries yet.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Time</th>
        <th>Action</th>
        <th>User</th>
        <th>Client IP</th>
        <th>Before</th>
        <th>After</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="muted"><?= e($r['created_at']) ?></td>
        <td><?= e($r['action']) ?></td>
        <td><?= e((string)($r['username'] ?? '')) ?></td>
        <td class="muted"><?= e((string)($r['client_ip'] ?? '')) ?></td>
        <td><pre style="white-space:pre-wrap;margin:0"><?= e(j_pretty($r['before_json'])) ?></pre></td>
        <td><pre style="white-space:pre-wrap;margin:0"><?= e(j_pretty($r['after_json'])) ?></pre></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <p style="margin-top:12px">
    <?php if ($p['page'] > 1): ?>
      <a href="address_history.php?<?= e(build_query_hist(['page' => $p['page'] - 1])) ?>">&laquo; Prev</a>
    <?php endif; ?>
    <?php if ($p['page'] < $p['pages']): ?>
      <a style="margin-left:12px" href="address_history.php?<?= e(build_query_hist(['page' => $p['page'] + 1])) ?>">Next &raquo;</a>
    <?php endif; ?>
  </p>
<?php endif; ?>

<?php page_footer();
