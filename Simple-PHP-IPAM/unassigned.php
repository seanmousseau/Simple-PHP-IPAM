<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    require_write_access();
}

$MAX_ASSIGNABLE = 4096;

$st = $db->prepare("SELECT id, cidr, ip_version, network, prefix, network_bin FROM subnets ORDER BY ip_version ASC, cidr ASC");
$st->execute();
$subnets = $st->fetchAll();

$subnetId = (int)($_GET['subnet_id'] ?? ($_POST['subnet_id'] ?? 0));
$page = q_int('page', 1, 1, 1000000);
$pageSize = q_int('page_size', 100, 1, 500);

$err = '';
$msg = '';

$sub = null;
$items = [];
$totalUnassigned = 0;

if ($subnetId > 0) {
    $st = $db->prepare("SELECT id, cidr, ip_version, network, prefix, network_bin FROM subnets WHERE id = :id");
    $st->execute([':id' => $subnetId]);
    $sub = $st->fetch() ?: null;

    if ($sub && (int)$sub['ip_version'] !== 4) {
        $err = "Unassigned listing is IPv4-only.";
        $sub = null;
    }

    if ($sub) {
        $prefix = (int)$sub['prefix'];
        $netInt = ipv4_bin_to_int((string)$sub['network_bin']);
        $bcastInt = ipv4_broadcast_int($netInt, $prefix);

        // Determine assignable range (exclude network/bcast for <=/30)
        if ($prefix <= 30) {
            $first = $netInt + 1;
            $last  = $bcastInt - 1;
        } else {
            // /31 or /32: both/all are assignable
            $first = $netInt;
            $last  = $bcastInt;
        }

        $assignable = ipv4_assignable_count($prefix);
        if ($assignable > $MAX_ASSIGNABLE) {
            $err = "Subnet too large to list unassigned safely (assignable hosts: $assignable; limit: $MAX_ASSIGNABLE).";
        } else {
            // Fetch all assigned IPs in subnet (since small)
            $st = $db->prepare("SELECT ip FROM addresses WHERE subnet_id = :sid");
            $st->execute([':sid' => $subnetId]);
            $assigned = [];
            foreach ($st->fetchAll() as $r) {
                $assigned[(string)$r['ip']] = true;
            }

            // Build full unassigned list (since small), then paginate in PHP
            $unassigned = [];
            for ($i = $first; $i <= $last; $i++) {
                $ip = ipv4_int_to_text($i);
                if (!isset($assigned[$ip])) $unassigned[] = $ip;
            }

            $totalUnassigned = count($unassigned);
            $p = paginate($totalUnassigned, $page, $pageSize);

            $slice = array_slice($unassigned, $p['offset'], $p['limit']);
            foreach ($slice as $ip) {
                $items[] = $ip;
            }
        }
    }
}

// Quick-add handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add' && $subnetId > 0) {
    $ip = trim((string)($_POST['ip'] ?? ''));
    $hostname = trim((string)($_POST['hostname'] ?? ''));
    $owner = trim((string)($_POST['owner'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $status = (string)($_POST['status'] ?? 'used');
    if (!in_array($status, ['used','reserved','free'], true)) $status = 'used';

    $st = $db->prepare("SELECT id, cidr, network, prefix, ip_version FROM subnets WHERE id = :id");
    $st->execute([':id' => $subnetId]);
    $subCheck = $st->fetch();

    if (!$subCheck) {
        $err = "Invalid subnet.";
    } else {
        $norm = normalize_ip($ip);
        if (!$norm || $norm['version'] !== 4) {
            $err = "Invalid IPv4 address.";
        } elseif (!ip_in_cidr($norm['ip'], (string)$subCheck['network'], (int)$subCheck['prefix'])) {
            $err = "IP not in subnet.";
        } else {
            try {
                // Check existing
                $sel = $db->prepare("SELECT id, hostname, owner, note, status FROM addresses WHERE subnet_id=:sid AND ip=:ip");
                $sel->execute([':sid' => $subnetId, ':ip' => $norm['ip']]);
                if ($sel->fetch()) {
                    $err = "Address already exists.";
                } else {
                    $ins = $db->prepare("INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, status)
                                         VALUES (:sid,:ip,:bin,:hn,:ow,:nt,:st)");
                    $ins->execute([
                        ':sid' => $subnetId,
                        ':ip'  => $norm['ip'],
                        ':bin' => $norm['bin'],
                        ':hn'  => $hostname,
                        ':ow'  => $owner,
                        ':nt'  => $note,
                        ':st'  => $status,
                    ]);
                    $aid = (int)$db->lastInsertId();

                    history_log_address($db, 'create', $subnetId, $norm['ip'], $aid, null, [
                        'hostname' => $hostname,
                        'owner' => $owner,
                        'note' => $note,
                        'status' => $status,
                    ]);
                    audit($db, 'address.create', 'address', $aid, "unassigned quick-add ip={$norm['ip']} subnet_id=$subnetId");

                    header('Location: unassigned.php?subnet_id=' . $subnetId);
                    exit;
                }
            } catch (Throwable $e) {
                $err = "Add failed: " . $e->getMessage();
            }
        }
    }
}

function build_query_un(array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    return http_build_query($q);
}

page_header('Unassigned IPv4');
?>
<h1>Unassigned IPv4</h1>
<p class="muted">Lists unassigned (no row in addresses) assignable IPv4 hosts for small subnets (≤ <?= e((string)$MAX_ASSIGNABLE) ?>).</p>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p><?= e($msg) ?></p><?php endif; ?>

<form method="get" action="unassigned.php" class="row">
  <label>Subnet<br>
    <select name="subnet_id">
      <option value="0">-- Select IPv4 subnet --</option>
      <?php foreach ($subnets as $s): ?>
        <?php if ((int)$s['ip_version'] !== 4) continue; ?>
        <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === $subnetId) ? 'selected' : '' ?>>
          <?= e($s['cidr']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <label>Page size<br>
    <select name="page_size">
      <?php foreach ([50,100,200,500] as $sz): ?>
        <option value="<?= $sz ?>" <?= $pageSize===$sz?'selected':'' ?>><?= $sz ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <button type="submit">Load</button>
</form>

<?php if ($sub): ?>
  <h2>Subnet: <?= e($sub['cidr']) ?></h2>
  <p class="muted">Unassigned: <b><?= e((string)$totalUnassigned) ?></b></p>

  <?php if ($items): ?>
    <table>
      <thead>
        <tr>
          <th>IP</th>
          <th>Add (inline)</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $ip): ?>
        <tr>
          <td><b><?= e($ip) ?></b></td>
          <td>
            <form method="post" action="unassigned.php?<?= e(build_query_un()) ?>" class="row" style="gap:6px">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="subnet_id" value="<?= (int)$subnetId ?>">
              <input type="hidden" name="ip" value="<?= e($ip) ?>">
              <label>Hostname<br><input name="hostname" style="width:160px"></label>
              <label>Owner<br><input name="owner" style="width:140px"></label>
              <label>Status<br>
                <select name="status">
                  <option value="used" selected>used</option>
                  <option value="reserved">reserved</option>
                  <option value="free">free</option>
                </select>
              </label>
              <label>Note<br><input name="note" style="width:220px"></label>
              <button type="submit">Add</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php
      // compute pagination for display if we had a valid sub
      if (isset($p)):
    ?>
      <p style="margin-top:12px">
        <?php if ($p['page'] > 1): ?>
          <a href="unassigned.php?<?= e(build_query_un(['page' => $p['page'] - 1])) ?>">&laquo; Prev</a>
        <?php endif; ?>
        <?php if ($p['page'] < $p['pages']): ?>
          <a style="margin-left:12px" href="unassigned.php?<?= e(build_query_un(['page' => $p['page'] + 1])) ?>">Next &raquo;</a>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  <?php else: ?>
    <p class="muted">No unassigned IPs to show (or subnet too large).</p>
  <?php endif; ?>

<?php else: ?>
  <p class="muted">Select an IPv4 subnet to list unassigned IPs.</p>
<?php endif; ?>

<?php page_footer();
