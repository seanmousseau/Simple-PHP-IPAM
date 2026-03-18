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
        if (!$p) {
            $err = 'Invalid CIDR. Examples: 192.168.1.0/24 or 2001:db8::/64';
        } else {
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
        if (!$p) {
            $err = 'Invalid CIDR.';
        } else {
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
                    FROM subnets
                    ORDER BY ip_version ASC, prefix ASC, network_bin ASC");
$st->execute();
$list = $st->fetchAll();

/**
 * Build subnet tree locally (subnets.php depends on these helpers being present).
 * If you already have build_subnet_tree/subnet_direct_counts in your older lib.php,
 * you can remove these local definitions. This bundle keeps them here for completeness
 * in case you are upgrading from an earlier tree.
 */
if (!function_exists('build_subnet_tree')) {
    function subnet_contains_bin_local(string $parentNetBin, int $parentPrefix, string $childNetBin): bool
    {
        $masked = apply_prefix_mask($childNetBin, $parentPrefix);
        return hash_equals($masked, $parentNetBin);
    }

    function build_subnet_tree(array $rows): array
    {
        $byId = [];
        foreach ($rows as $r) $byId[(int)$r['id']] = $r;

        $ids = array_keys($byId);
        $parentOf = [];
        $children = [];
        $roots = [];

        foreach ($ids as $childId) {
            $child = $byId[$childId];
            $bestParent = null;
            $bestPrefix = -1;

            foreach ($ids as $parentId) {
                if ($parentId === $childId) continue;
                $parent = $byId[$parentId];

                if ((int)$parent['ip_version'] !== (int)$child['ip_version']) continue;
                $pp = (int)$parent['prefix'];
                $cp = (int)$child['prefix'];
                if ($pp >= $cp) continue;

                if (subnet_contains_bin_local($parent['network_bin'], $pp, $child['network_bin']) && $pp > $bestPrefix) {
                    $bestPrefix = $pp;
                    $bestParent = $parentId;
                }
            }
            $parentOf[$childId] = $bestParent;
        }

        $cmpFn = function(int $a, int $b) use ($byId): int {
            $ra = $byId[$a]; $rb = $byId[$b];
            $va = (int)$ra['ip_version']; $vb = (int)$rb['ip_version'];
            if ($va !== $vb) return $va <=> $vb;
            $c = strcmp($ra['network_bin'], $rb['network_bin']);
            if ($c !== 0) return $c;
            return (int)$ra['prefix'] <=> (int)$rb['prefix'];
        };

        foreach ($ids as $id) {
            $p = $parentOf[$id];
            if ($p === null) $roots[] = $id;
            else $children[$p][] = $id;
        }

        usort($roots, $cmpFn);
        foreach ($children as $pid => $arr) {
            usort($arr, $cmpFn);
            $children[$pid] = $arr;
        }

        return ['roots'=>$roots,'children'=>$children,'byId'=>$byId];
    }

    function subnet_direct_counts(PDO $db): array
    {
        $st2 = $db->prepare("SELECT subnet_id, status, COUNT(*) AS c FROM addresses GROUP BY subnet_id, status");
        $st2->execute();
        $out = [];
        foreach ($st2->fetchAll() as $r) {
            $sid = (int)$r['subnet_id'];
            $status = (string)$r['status'];
            $c = (int)$r['c'];
            $out[$sid] ??= ['used'=>0,'reserved'=>0,'free'=>0,'total'=>0];
            if (isset($out[$sid][$status])) $out[$sid][$status] += $c;
            $out[$sid]['total'] += $c;
        }
        return $out;
    }

    function subnet_aggregated_counts(array $tree, array $directCounts): array
    {
        $children = $tree['children'];
        $agg = [];

        $sumNode = function(int $id) use (&$sumNode, &$agg, $children, $directCounts): array {
            if (isset($agg[$id])) return $agg[$id];

            $base = $directCounts[$id] ?? ['used'=>0,'reserved'=>0,'free'=>0,'total'=>0];
            $sum = $base;

            foreach (($children[$id] ?? []) as $cid) {
                $c = $sumNode((int)$cid);
                $sum['used'] += $c['used'];
                $sum['reserved'] += $c['reserved'];
                $sum['free'] += $c['free'];
                $sum['total'] += $c['total'];
            }
            return $agg[$id] = $sum;
        };

        foreach ($tree['byId'] as $id => $_) $sumNode((int)$id);
        return $agg;
    }

    function fmt_counts(array $c): string
    {
        return "total {$c['total']} (used {$c['used']}, res {$c['reserved']}, free {$c['free']})";
    }

    function ipv4_assignable_count_local(int $prefix): int
    {
        if ($prefix >= 32) return 1;
        if ($prefix === 31) return 2;
        $hostBits = 32 - $prefix;
        $total = ($hostBits === 32) ? 4294967296 : (1 << $hostBits);
        $assignable = $total - 2;
        return ($assignable > 0) ? (int)$assignable : 0;
    }

    function ipv4_broadcast_bin_local(string $netBin, int $prefix): string
    {
        $hostBits = 32 - $prefix;
        if ($hostBits <= 0) return $netBin;

        $n = unpack('N', $netBin)[1];
        $hostMask = ($hostBits === 32) ? 0xFFFFFFFF : ((1 << $hostBits) - 1);
        $b = ($n | $hostMask) & 0xFFFFFFFF;

        return pack('N', $b);
    }

    function ipv4_unassigned_summary(PDO $db): array
    {
        $st3 = $db->prepare("SELECT id, prefix, network_bin FROM subnets WHERE ip_version=4");
        $st3->execute();
        $subs = $st3->fetchAll();
        if (!$subs) return [];

        $st4 = $db->prepare("SELECT a.subnet_id, a.ip_bin FROM addresses a JOIN subnets s ON s.id=a.subnet_id WHERE s.ip_version=4");
        $st4->execute();
        $addrRows = $st4->fetchAll();

        $ipsBySubnet = [];
        foreach ($addrRows as $r) {
            $sid = (int)$r['subnet_id'];
            $ipsBySubnet[$sid] ??= [];
            $ipsBySubnet[$sid][] = $r['ip_bin'];
        }

        $out = [];
        foreach ($subs as $s) {
            $sid = (int)$s['id'];
            $prefix = (int)$s['prefix'];
            $netBin = $s['network_bin'];
            $assignableTotal = ipv4_assignable_count_local($prefix);
            $ips = $ipsBySubnet[$sid] ?? [];

            if ($prefix <= 30) {
                $bcast = ipv4_broadcast_bin_local($netBin, $prefix);
                $assignedAssignable = 0;
                foreach ($ips as $ipb) {
                    if (hash_equals($ipb, $netBin) || hash_equals($ipb, $bcast)) continue;
                    $assignedAssignable++;
                }
            } else {
                $assignedAssignable = count($ips);
            }

            $unassigned = $assignableTotal - $assignedAssignable;
            if ($unassigned < 0) $unassigned = 0;

            $out[$sid] = [
                'assignable_total' => (int)$assignableTotal,
                'assigned_assignable' => (int)$assignedAssignable,
                'unassigned_assignable' => (int)$unassigned,
            ];
        }
        return $out;
    }
}

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
