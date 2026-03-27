<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';
$msg = '';
$warn = '';

// Consume any flash warning left by a create redirect
if (!empty($_SESSION['ipam_flash_warn'])) {
    $warn = (string)$_SESSION['ipam_flash_warn'];
    unset($_SESSION['ipam_flash_warn']);
}

$st = $db->prepare("SELECT id, name FROM sites ORDER BY name ASC");
$st->execute();
$siteList = $st->fetchAll();

$siteMap = [];
foreach ($siteList as $s) {
    $siteMap[(int)$s['id']] = $s['name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        require_write_access();
        $cidr = trim((string)($_POST['cidr'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $siteId = (int)($_POST['site_id'] ?? 0);
        if ($siteId <= 0) $siteId = null;

        $p = parse_cidr($cidr);
        if (!$p) {
            $err = 'Invalid CIDR. Examples: 192.168.1.0/24 or 2001:db8::/64';
        } else {
            $normalized = $p['network'] . '/' . $p['prefix'];
            $overlaps = detect_subnet_overlaps($db, $normalized);
            // Inherit site from tightest parent if one exists
            $inheritedSiteId = find_parent_site_id($db, $normalized);
            if ($inheritedSiteId !== null) $siteId = $inheritedSiteId;
            try {
                $st = $db->prepare("INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, site_id)
                                    VALUES (:cidr,:ver,:net,:nb,:pre,:d,:site)");
                $st->execute([
                    ':cidr' => $normalized,
                    ':ver' => $p['version'],
                    ':net' => $p['network'],
                    ':nb'  => $p['net_bin'],
                    ':pre' => $p['prefix'],
                    ':d' => $desc,
                    ':site' => $siteId,
                ]);
                audit($db, 'subnet.create', 'subnet', (int)$db->lastInsertId(), $normalized);
                $warn = '';
                if (!empty($overlaps['parents']) || !empty($overlaps['children'])) {
                    $warn = subnet_overlap_warning_text($overlaps);
                }
                if ($inheritedSiteId !== null) {
                    $inheritedName = $siteMap[$inheritedSiteId] ?? "site #$inheritedSiteId";
                    $siteNote = "Site automatically set to \"{$inheritedName}\" inherited from parent subnet.";
                    $warn = $warn ? $warn . ' ' . $siteNote : $siteNote;
                }
                if ($warn) $_SESSION['ipam_flash_warn'] = $warn;
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
        $siteId = (int)($_POST['site_id'] ?? 0);
        if ($siteId <= 0) $siteId = null;

        $p = parse_cidr($cidr);
        if (!$p) {
            $err = 'Invalid CIDR.';
        } else {
            $normalized = $p['network'] . '/' . $p['prefix'];
            $overlaps = detect_subnet_overlaps($db, $normalized, $id);
            // Inherit site from tightest parent if one exists
            $inheritedSiteId = find_parent_site_id($db, $normalized, $id);
            if ($inheritedSiteId !== null) $siteId = $inheritedSiteId;
            try {
                $st = $db->prepare("UPDATE subnets
                                    SET cidr=:cidr, ip_version=:ver, network=:net, network_bin=:nb, prefix=:pre, description=:d, site_id=:site
                                    WHERE id=:id");
                $st->execute([
                    ':cidr' => $normalized,
                    ':ver' => $p['version'],
                    ':net' => $p['network'],
                    ':nb'  => $p['net_bin'],
                    ':pre' => $p['prefix'],
                    ':d' => $desc,
                    ':site' => $siteId,
                    ':id' => $id
                ]);
                audit($db, 'subnet.update', 'subnet', $id, $normalized);
                $msg = 'Subnet updated.';
                if (!empty($overlaps['parents']) || !empty($overlaps['children'])) {
                    $warn = subnet_overlap_warning_text($overlaps);
                }
                if ($inheritedSiteId !== null) {
                    $inheritedName = $siteMap[$inheritedSiteId] ?? "site #$inheritedSiteId";
                    $siteNote = "Site set to \"{$inheritedName}\" inherited from parent subnet.";
                    $warn = $warn ? $warn . ' ' . $siteNote : $siteNote;
                }
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

$st = $db->prepare("
    SELECT id, cidr, ip_version, network, network_bin, prefix, description, updated_at, site_id
    FROM subnets
    ORDER BY ip_version ASC, prefix ASC, network_bin ASC
");
$st->execute();
$list = $st->fetchAll();

function subnet_contains_bin_local(string $parentNetBin, int $parentPrefix, string $childNetBin): bool
{
    $masked = apply_prefix_mask($childNetBin, $parentPrefix);
    return hash_equals($masked, $parentNetBin);
}

function build_subnet_tree_local(array $rows): array
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

    return ['roots' => $roots, 'children' => $children, 'byId' => $byId];
}

function subnet_direct_counts_local(PDO $db): array
{
    $st = $db->prepare("SELECT subnet_id, status, COUNT(*) AS c FROM addresses GROUP BY subnet_id, status");
    $st->execute();
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $sid = (int)$r['subnet_id'];
        $status = (string)$r['status'];
        $c = (int)$r['c'];
        $out[$sid] ??= ['used'=>0,'reserved'=>0,'free'=>0,'total'=>0];
        if (isset($out[$sid][$status])) $out[$sid][$status] += $c;
        $out[$sid]['total'] += $c;
    }
    return $out;
}

function subnet_aggregated_counts_local(array $tree, array $directCounts): array
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

    foreach ($tree['byId'] as $id => $_row) $sumNode((int)$id);
    return $agg;
}

function fmt_counts_local(array $c): string
{
    return "total {$c['total']} (used {$c['used']}, res {$c['reserved']}, free {$c['free']})";
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

function ipv4_unassigned_summary_local(PDO $db): array
{
    $st = $db->prepare("SELECT id, prefix, network_bin FROM subnets WHERE ip_version=4");
    $st->execute();
    $subs = $st->fetchAll();
    if (!$subs) return [];

    $st = $db->prepare("SELECT a.subnet_id, a.ip_bin FROM addresses a JOIN subnets s ON s.id=a.subnet_id WHERE s.ip_version=4");
    $st->execute();
    $addrRows = $st->fetchAll();

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

        $assignableTotal = ipv4_assignable_count($prefix);
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

function subnet_overlap_warning_text(array $overlaps): string
{
    $parts = [];
    if (!empty($overlaps['parents'])) {
        $list = implode(', ', array_map('e', $overlaps['parents']));
        $parts[] = 'nested inside: ' . $list;
    }
    if (!empty($overlaps['children'])) {
        $list = implode(', ', array_map('e', $overlaps['children']));
        $parts[] = 'parent of: ' . $list;
    }
    return 'Hierarchy notice — this subnet is ' . implode('; and ', $parts) . '. Verify this nesting is intentional.';
}

$tree = build_subnet_tree_local($list);
$direct = subnet_direct_counts_local($db);
$agg = subnet_aggregated_counts_local($tree, $direct);
$ipv4Unassigned = ipv4_unassigned_summary_local($db);

$siteGroups = [];
foreach ($tree['roots'] as $rid) {
    $siteId = (int)($tree['byId'][$rid]['site_id'] ?? 0);
    $key = $siteId > 0 ? (string)$siteId : 'ungrouped';
    $label = $siteId > 0 ? ($siteMap[$siteId] ?? "Site #$siteId") : 'Ungrouped';
    $siteGroups[$key] ??= ['label' => $label, 'roots' => []];
    $siteGroups[$key]['roots'][] = $rid;
}
uasort($siteGroups, fn($a, $b) => strcasecmp($a['label'], $b['label']));

function render_subnet_node_local(array $tree, array $direct, array $agg, array $ipv4Unassigned, array $siteMap, array $siteList, int $id, int $depth = 0): void
{
    $row = $tree['byId'][$id];
    $pad = $depth * 18;
    $d = $direct[$id] ?? ['used'=>0,'reserved'=>0,'free'=>0,'total'=>0];
    $a = $agg[$id] ?? $d;
    $disabled = (current_user()['role'] === 'readonly') ? "disabled" : "";
    $siteName = '';
    $siteId = (int)($row['site_id'] ?? 0);
    if ($siteId > 0) $siteName = $siteMap[$siteId] ?? '';

    echo "<div style='margin-left: {$pad}px; border-left: 2px solid var(--border); padding-left: 10px; margin-top:8px'>";
    echo "<details " . ($depth < 1 ? "open" : "") . ">";
    echo "<summary>";
    echo "<b>" . e($row['cidr']) . "</b> ";
    echo "<span class='muted'>(v" . (int)$row['ip_version'] . ")</span> ";
    if ($siteName !== '') echo " <span class='badge'>" . e($siteName) . "</span>";
    if ($row['description'] !== '') echo " - " . e($row['description']);
    echo "<br><span class='muted'>Direct: " . e(fmt_counts_local($d)) . " | With children: " . e(fmt_counts_local($a)) . "</span>";

    if ((int)$row['ip_version'] === 4 && isset($ipv4Unassigned[$id])) {
        $u = $ipv4Unassigned[$id];
        $assignable = (int)$u['assignable_total'];
        $assigned   = (int)$u['assigned_assignable'];
        $pct = $assignable > 0 ? (int)round($assigned / $assignable * 100) : 0;
        $cfg = $GLOBALS['config'] ?? [];
        $warnThreshold = (int)($cfg['utilization_warn']     ?? 80);
        $critThreshold = (int)($cfg['utilization_critical'] ?? 95);
        $barClass = $pct >= $critThreshold ? 'util-bar-fill--crit'
                  : ($pct >= $warnThreshold ? 'util-bar-fill--warn' : '');
        $pctLabel = $pct >= $critThreshold ? "<span class='danger'>{$pct}%</span>"
                  : ($pct >= $warnThreshold ? "<span class='warning'>{$pct}%</span>"
                  : "<span>{$pct}%</span>");
        echo "<br><span class='muted'>Assignable: " . e((string)$assignable) .
             " | Assigned: " . e((string)$assigned) .
             " | Unassigned: <b>" . e((string)$u['unassigned_assignable']) . "</b></span>"
           . " <span class='util-bar'><span class='util-bar-fill {$barClass}' style='width:{$pct}%'></span></span>"
           . " {$pctLabel}";
    }
    echo "</summary>";

    echo "<div style='margin-top:10px'>";
    echo "<div class='page-actions' style='margin-bottom:10px'>";
    echo "<a class='action-pill' href='addresses.php?subnet_id=" . (int)$row['id'] . "'>🧾 View Addresses</a>";
    if ((int)$row['ip_version'] === 4) {
        echo "<a class='action-pill' href='unassigned.php?subnet_id=" . (int)$row['id'] . "'>✨ Unassigned</a>";
    }
    if (current_user()['role'] !== 'readonly') {
        echo "<a class='action-pill' href='bulk_update.php?subnet_id=" . (int)$row['id'] . "'>✏ Bulk Update</a>";
        if ((int)$row['ip_version'] === 4) {
            echo "<a class='action-pill' href='dhcp_pool.php?subnet_id=" . (int)$row['id'] . "'>🔒 DHCP Pool</a>";
        }
    }
    echo "</div>";

    echo "<div class='muted'>Updated " . e($row['updated_at']) . "</div>";

    echo "<form method='post' action='subnets.php' class='row' style='margin-top:8px'>";
    echo "<input type='hidden' name='csrf' value='" . e(csrf_token()) . "'>";
    echo "<input type='hidden' name='action' value='update'>";
    echo "<input type='hidden' name='id' value='" . (int)$row['id'] . "'>";
    echo "<label>CIDR<br><input name='cidr' value='" . e($row['cidr']) . "' required></label>";
    echo "<label>Description<br><input name='description' value='" . e($row['description']) . "'></label>";

    if ($depth > 0) {
        // Child subnet: site is inherited from parent and cannot be changed here
        $lockedSiteName = ($siteId > 0 && isset($siteMap[$siteId])) ? $siteMap[$siteId] : '(none)';
        echo "<input type='hidden' name='site_id' value='" . $siteId . "'>";
        echo "<label>Site<br><span class='badge' title='Inherited from parent subnet'>" . e($lockedSiteName) . " ↑</span></label>";
    } else {
        echo "<label>Site<br><select name='site_id'>";
        echo "<option value='0' " . ($siteId === 0 ? "selected" : "") . ">(none)</option>";
        foreach ($siteList as $s) {
            $sid = (int)$s['id'];
            $sel = ($sid === $siteId) ? "selected" : "";
            echo "<option value='" . $sid . "' $sel>" . e($s['name']) . "</option>";
        }
        echo "</select></label>";
    }

    echo "<button type='submit' $disabled>Save</button>";
    echo "</form>";

    echo "<form method='post' action='subnets.php' onsubmit='return confirm(\"Delete subnet and all its addresses?\");' style='margin-top:8px'>";
    echo "<input type='hidden' name='csrf' value='" . e(csrf_token()) . "'>";
    echo "<input type='hidden' name='action' value='delete'>";
    echo "<input type='hidden' name='id' value='" . (int)$row['id'] . "'>";
    echo "<button type='submit' class='button-danger' $disabled>Delete</button>";
    echo "</form>";

    if (current_user()['role'] === 'readonly') {
        echo "<p class='muted'>Read-only account.</p>";
    }

    foreach (($tree['children'][$id] ?? []) as $cid) {
        render_subnet_node_local($tree, $direct, $agg, $ipv4Unassigned, $siteMap, $siteList, (int)$cid, $depth + 1);
    }

    echo "</div></details></div>";
}

page_header('Subnets');
?>

<div class="breadcrumbs">
  <a href="dashboard.php">🏠 Dashboard</a><span class="sep">›</span><span>🌐 Subnets</span>
</div>

<div class="toolbar">
  <div>
    <h1>Subnets</h1>
    <div class="muted">Grouped by site. Use the action links under each subnet to jump to related workflows.</div>
  </div>
</div>

<div class="page-actions">
  <?php if (current_user()['role'] !== 'readonly'): ?>
    <a class="action-pill" href="#add-subnet">➕ Add Subnet</a>
  <?php endif; ?>
  <a class="action-pill" href="search.php">🔎 Search Addresses</a>
  <?php if (current_user()['role'] === 'admin'): ?>
    <a class="action-pill" href="sites.php">📍 Manage Sites</a>
  <?php endif; ?>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>
<?php if ($warn): ?><p class="warning"><?= $warn ?></p><?php endif; ?>

<div class="card" id="add-subnet" style="margin-top:16px">
  <h2>Add subnet</h2>
  <form method="post" action="subnets.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="row">
      <label>CIDR<br><input name="cidr" placeholder="10.0.0.0/24 or 2001:db8::/64" required></label>
      <label>Description<br><input name="description" placeholder="Office LAN"></label>
      <label>Site<br>
        <select name="site_id">
          <option value="0">(none)</option>
          <?php foreach ($siteList as $site): ?>
            <option value="<?= (int)$site['id'] ?>"><?= e($site['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" <?= (current_user()['role']==='readonly')?'disabled':'' ?>>Add</button>
    </div>
    <?php if (current_user()['role']==='readonly'): ?><p class="muted">Read-only account.</p><?php endif; ?>
  </form>
</div>

<div class="card" style="margin-top:16px">
  <h2>Grouped Hierarchy</h2>

  <?php if (empty($siteGroups)): ?>
    <div class="empty-state">No subnets yet.</div>
  <?php else: ?>
    <?php foreach ($siteGroups as $group): ?>
      <div class="site-group">
        <h2><?= e($group['label']) ?></h2>
        <?php foreach ($group['roots'] as $rid): ?>
          <?php render_subnet_node_local($tree, $direct, $agg, $ipv4Unassigned, $siteMap, $siteList, (int)$rid, 0); ?>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php page_footer();
