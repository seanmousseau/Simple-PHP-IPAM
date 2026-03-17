<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        require_write_access();
        $cidr = trim((string)($_POST['cidr'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));

        $p = parse_cidr($cidr);
        if (!$p) $err = 'Invalid CIDR. Examples: 192.168.1.0/24 or 2001:db8::/64';
        else {
            $normalized = $p['network'] . '/' . $p['prefix'];
            try {
                $st = $db->prepare("INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description)
                                    VALUES (:cidr,:ver,:net,:nb,:pre,:d)");
                $st->execute([
                    ':cidr' => $normalized,
                    ':ver' => $p['version'],
                    ':net' => $p['network'],
                    ':nb'  => $p['net_bin'],
                    ':pre' => $p['prefix'],
                    ':d' => $desc
                ]);
                audit($db, 'subnet.create', 'subnet', (int)$db->lastInsertId(), $normalized);
                header('Location: subnets.php');
                exit;
            } catch (PDOException $e) {
                $err = 'Could not create subnet (duplicate?).';
            }
        }
    } elseif ($action === 'update') {
        require_write_access();
        $id = (int)($_POST['id'] ?? 0);
        $cidr = trim((string)($_POST['cidr'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));

        $p = parse_cidr($cidr);
        if (!$p) $err = 'Invalid CIDR.';
        else {
            $normalized = $p['network'] . '/' . $p['prefix'];
            try {
                $st = $db->prepare("UPDATE subnets
                                    SET cidr=:cidr, ip_version=:ver, network=:net, network_bin=:nb, prefix=:pre, description=:d
                                    WHERE id=:id");
                $st->execute([
                    ':cidr' => $normalized,
                    ':ver' => $p['version'],
                    ':net' => $p['network'],
                    ':nb'  => $p['net_bin'],
                    ':pre' => $p['prefix'],
                    ':d' => $desc,
                    ':id' => $id
                ]);
                audit($db, 'subnet.update', 'subnet', $id, $normalized);
                $msg = 'Subnet updated.';
            } catch (PDOException $e) {
                $err = 'Could not update subnet (duplicate?).';
            }
        }
    } elseif ($action === 'delete') {
        require_write_access();
        $id = (int)($_POST['id'] ?? 0);
        $st = $db->prepare("DELETE FROM subnets WHERE id = :id");
        $st->execute([':id' => $id]);
        audit($db, 'subnet.delete', 'subnet', $id, '');
        header('Location: subnets.php');
        exit;
    }
}

$st = $db->prepare("SELECT id, cidr, ip_version, network, network_bin, prefix, description, updated_at
                    FROM subnets ORDER BY ip_version ASC, prefix ASC, network_bin ASC");
$st->execute();
$list = $st->fetchAll();

$tree = build_subnet_tree($list);
$direct = subnet_direct_counts($db);
$agg = subnet_aggregated_counts($tree, $direct);
$ipv4Unassigned = ipv4_unassigned_summary($db);

function render_subnet_node(array $tree, array $direct, array $agg, array $ipv4Unassigned, int $id, int $depth = 0): void
{
    $row = $tree['byId'][$id];
    $pad = $depth * 18;

    $d = $direct[$id] ?? ['used'=>0,'reserved'=>0,'free'=>0,'total'=>0];
    $a = $agg[$id] ?? $d;

    $disabled = (current_user()['role'] === 'readonly') ? "disabled" : "";

    echo "<div style='margin-left: {$pad}px; border-left: 2px solid #eee; padding-left: 10px; margin-top:6px'>";
    echo "<details " . ($depth < 1 ? "open" : "") . ">";
    echo "<summary>";
    echo "<b>" . e($row['cidr']) . "</b> ";
    echo "<span class='muted'>(v" . (int)$row['ip_version'] . ")</span> ";
    if ($row['description'] !== '') echo " - " . e($row['description']);
    echo "<br><span class='muted'>Direct: " . e(fmt_counts($d)) . " | With children: " . e(fmt_counts($a)) . "</span>";

    if ((int)$row['ip_version'] === 4 && isset($ipv4Unassigned[$id])) {
        $u = $ipv4Unassigned[$id];
        echo "<br><span class='muted'>Assignable: " . e((string)$u['assignable_total']) .
             " | Assigned: " . e((string)$u['assigned_assignable']) .
             " | Unassigned: <b>" . e((string)$u['unassigned_assignable']) . "</b></span>";
    }

    echo "</summary>";

    echo "<div style='margin-top:8px'>";
    echo "<div class='muted'>Updated " . e($row['updated_at']) . "</div>";

    echo "<form method='post' action='subnets.php' class='row' style='margin-top:8px'>";
    echo "<input type='hidden' name='csrf' value='" . e(csrf_token()) . "'>";
    echo "<input type='hidden' name='action' value='update'>";
    echo "<input type='hidden' name='id' value='" . (int)$row['id'] . "'>";
    echo "<label>CIDR<br><input name='cidr' value='" . e($row['cidr']) . "' required></label>";
    echo "<label>Description<br><input name='description' value='" . e($row['description']) . "'></label>";
    echo "<button type='submit' $disabled>Save</button>";
    echo "</form>";

    echo "<form method='post' action='subnets.php' onsubmit='return confirm(\"Delete subnet and all its addresses?\");' style='margin-top:8px'>";
    echo "<input type='hidden' name='csrf' value='" . e(csrf_token()) . "'>";
    echo "<input type='hidden' name='action' value='delete'>";
    echo "<input type='hidden' name='id' value='" . (int)$row['id'] . "'>";
    echo "<button type='submit' $disabled>Delete</button>";
    echo "</form>";

    if (current_user()['role'] === 'readonly') echo "<p class='muted'>Read-only account.</p>";

    foreach (($tree['children'][$id] ?? []) as $cid) {
        render_subnet_node($tree, $direct, $agg, $ipv4Unassigned, (int)$cid, $depth + 1);
    }

    echo "</div>";
    echo "</details>";
    echo "</div>";
}

page_header('Subnets');
?>
<h1>Subnets</h1>
<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p><?= e($msg) ?></p><?php endif; ?>

<h2>Add subnet</h2>
<form method="post" action="subnets.php">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="create">
  <div class="row">
    <label>CIDR<br><input name="cidr" placeholder="10.0.0.0/24 or 2001:db8::/64" required></label>
    <label>Description<br><input name="description" placeholder="Office LAN"></label>
    <button type="submit" <?= (current_user()['role']==='readonly')?'disabled':'' ?>>Add</button>
  </div>
  <?php if (current_user()['role']==='readonly'): ?><p class="muted">Read-only account.</p><?php endif; ?>
</form>

<h2>Hierarchy</h2>
<?php if (empty($tree['roots'])): ?>
  <p class="muted">No subnets yet.</p>
<?php else: ?>
  <?php foreach ($tree['roots'] as $rid): ?>
    <?php render_subnet_node($tree, $direct, $agg, $ipv4Unassigned, (int)$rid, 0); ?>
  <?php endforeach; ?>
<?php endif; ?>

<?php page_footer();
