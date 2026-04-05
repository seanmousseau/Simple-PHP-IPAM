<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';
$msg = '';
$warn = '';

// Flash warnings are now rendered by page_header() via flash_get()

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
        $vlanIdRaw = trim((string)($_POST['vlan_id'] ?? ''));
        $vlanId = $vlanIdRaw !== '' ? (int)$vlanIdRaw : null;
        if ($vlanId !== null && ($vlanId < 1 || $vlanId > 4094)) {
            $err = 'VLAN ID must be between 1 and 4094.';
        }

        $p = $err ? null : parse_cidr($cidr);
        if (!$err && !$p) {
            $err = 'Invalid CIDR. Examples: 192.168.1.0/24 or 2001:db8::/64';
        }
        if (!$err) {
            $normalized = $p['network'] . '/' . $p['prefix'];
            $overlaps = detect_subnet_overlaps($db, $normalized);
            // Inherit site from tightest parent if one exists
            $inheritedSiteId = find_parent_site_id($db, $normalized);
            if ($inheritedSiteId !== null) $siteId = $inheritedSiteId;

            // Pre-save overlap confirmation
            $hasOverlaps = !empty($overlaps['parents']) || !empty($overlaps['children']);
            if ($hasOverlaps && empty($_POST['confirm_overlap'])) {
                $overlapWarning = subnet_overlap_warning_text($overlaps);
                $pendingAction = 'create';
                $pendingData = [
                    'cidr' => $cidr, 'description' => $desc,
                    'site_id' => $siteId ?? 0, 'vlan_id' => $vlanIdRaw,
                ];
            } else {
                try {
                    $st = $db->prepare("INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, site_id, vlan_id)
                                        VALUES (:cidr,:ver,:net,:nb,:pre,:d,:site,:vlan)");
                    $st->execute([
                        ':cidr' => $normalized,
                        ':ver' => $p['version'],
                        ':net' => $p['network'],
                        ':nb'  => $p['net_bin'],
                        ':pre' => $p['prefix'],
                        ':d' => $desc,
                        ':site' => $siteId,
                        ':vlan' => $vlanId,
                    ]);
                    audit($db, 'subnet.create', 'subnet', (int)$db->lastInsertId(), $normalized);
                    $flashMsg = '';
                    if ($inheritedSiteId !== null) {
                        $inheritedName = $siteMap[$inheritedSiteId] ?? "site #$inheritedSiteId";
                        $flashMsg = 'Site automatically set to "' . $inheritedName . '" inherited from parent subnet.';
                    }
                    if ($flashMsg) flash_set($flashMsg, 'warning');
                    else flash_set('Subnet created.');
                    header('Location: subnets.php');
                    exit;
                } catch (PDOException $e) {
                    $err = str_contains($e->getMessage(), 'UNIQUE')
                        ? 'A subnet with this CIDR already exists.'
                        : 'Could not create subnet. Please try again.';
                }
            }
        }
    } elseif ($action === 'update') {
        require_write_access();
        $id = (int)($_POST['id'] ?? 0);
        $cidr = trim((string)($_POST['cidr'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $siteId = (int)($_POST['site_id'] ?? 0);
        if ($siteId <= 0) $siteId = null;
        $vlanIdRaw = trim((string)($_POST['vlan_id'] ?? ''));
        $vlanId = $vlanIdRaw !== '' ? (int)$vlanIdRaw : null;
        if ($vlanId !== null && ($vlanId < 1 || $vlanId > 4094)) {
            $err = 'VLAN ID must be between 1 and 4094.';
        }

        $p = $err ? null : parse_cidr($cidr);
        if (!$err && !$p) {
            $err = 'Invalid CIDR.';
        }
        if (!$err) {
            $normalized = $p['network'] . '/' . $p['prefix'];
            $overlaps = detect_subnet_overlaps($db, $normalized, $id);
            // Inherit site from tightest parent if one exists
            $inheritedSiteId = find_parent_site_id($db, $normalized, $id);
            if ($inheritedSiteId !== null) $siteId = $inheritedSiteId;

            // Pre-save overlap confirmation
            $hasOverlaps = !empty($overlaps['parents']) || !empty($overlaps['children']);
            if ($hasOverlaps && empty($_POST['confirm_overlap'])) {
                $overlapWarning = subnet_overlap_warning_text($overlaps);
                $pendingAction = 'update';
                $pendingData = [
                    'id' => $id, 'cidr' => $cidr, 'description' => $desc,
                    'site_id' => $siteId ?? 0, 'vlan_id' => $vlanIdRaw,
                ];
            } else {
                try {
                    $st = $db->prepare("UPDATE subnets
                                        SET cidr=:cidr, ip_version=:ver, network=:net, network_bin=:nb, prefix=:pre, description=:d, site_id=:site, vlan_id=:vlan
                                        WHERE id=:id");
                    $st->execute([
                        ':cidr' => $normalized,
                        ':ver' => $p['version'],
                        ':net' => $p['network'],
                        ':nb'  => $p['net_bin'],
                        ':pre' => $p['prefix'],
                        ':d' => $desc,
                        ':site' => $siteId,
                        ':vlan' => $vlanId,
                        ':id' => $id
                    ]);
                    audit($db, 'subnet.update', 'subnet', $id, $normalized);
                    $msg = 'Subnet updated.';
                    if ($inheritedSiteId !== null) {
                        $inheritedName = $siteMap[$inheritedSiteId] ?? "site #$inheritedSiteId";
                        $warn = 'Site set to "' . $inheritedName . '" inherited from parent subnet.';
                    }
                } catch (PDOException $e) {
                    $err = 'Could not update subnet (duplicate?).';
                }
            }
        }
    } elseif ($action === 'delete') {
        require_write_access();
        $id = (int)($_POST['id'] ?? 0);
        $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM addresses WHERE subnet_id = :id");
        $cntSt->execute([':id' => $id]);
        $addrCount = (int)$cntSt->fetch()['c'];
        $st = $db->prepare("DELETE FROM subnets WHERE id = :id");
        $st->execute([':id' => $id]);
        audit($db, 'subnet.delete', 'subnet', $id, "addresses_deleted={$addrCount}");
        header('Location: subnets.php');
        exit;
    }
}

$st = $db->prepare("
    SELECT id, cidr, ip_version, network, network_bin, prefix, description, updated_at, site_id, vlan_id
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

    // Sort by ip_version ASC, prefix ASC (broadest first), network_bin ASC
    $sorted = $byId;
    uasort($sorted, function(array $a, array $b): int {
        $va = (int)$a['ip_version']; $vb = (int)$b['ip_version'];
        if ($va !== $vb) return $va <=> $vb;
        $pa = (int)$a['prefix']; $pb = (int)$b['prefix'];
        if ($pa !== $pb) return $pa <=> $pb;
        return strcmp($a['network_bin'], $b['network_bin']);
    });

    $children = [];
    $roots = [];

    // O(N log N) stack-based parent lookup: process broadest-first,
    // maintain a stack of candidate parents, pop until top contains child.
    $stack = [];

    foreach ($sorted as $id => $row) {
        $ver    = (int)$row['ip_version'];
        $prefix = (int)$row['prefix'];
        $netBin = $row['network_bin'];

        // Pop entries that cannot be a parent of this subnet
        while (!empty($stack)) {
            $top = end($stack);
            if ((int)$top['ip_version'] !== $ver) {
                $stack = [];
                break;
            }
            if ((int)$top['prefix'] < $prefix
                && subnet_contains_bin_local($top['network_bin'], (int)$top['prefix'], $netBin)) {
                break;
            }
            array_pop($stack);
        }

        if (!empty($stack)) {
            $parent = end($stack);
            $children[(int)$parent['id']][] = $id;
        } else {
            $roots[] = $id;
        }

        $stack[] = ['id' => $id, 'ip_version' => $ver, 'prefix' => $prefix, 'network_bin' => $netBin];
    }

    $cmpFn = function(int $a, int $b) use ($byId): int {
        $ra = $byId[$a]; $rb = $byId[$b];
        $va = (int)$ra['ip_version']; $vb = (int)$rb['ip_version'];
        if ($va !== $vb) return $va <=> $vb;
        $c = strcmp($ra['network_bin'], $rb['network_bin']);
        if ($c !== 0) return $c;
        return (int)$ra['prefix'] <=> (int)$rb['prefix'];
    };

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

    // Aggregate used/reserved counts per subnet instead of loading all blobs
    $cntSt = $db->prepare(
        "SELECT a.subnet_id, COUNT(*) AS c
         FROM addresses a JOIN subnets s ON s.id = a.subnet_id
         WHERE s.ip_version = 4 AND a.status IN ('used','reserved')
         GROUP BY a.subnet_id"
    );
    $cntSt->execute();
    $countBySubnet = [];
    foreach ($cntSt->fetchAll() as $r) {
        $countBySubnet[(int)$r['subnet_id']] = (int)$r['c'];
    }

    $out = [];
    foreach ($subs as $s) {
        $sid    = (int)$s['id'];
        $prefix = (int)$s['prefix'];
        $netBin = $s['network_bin'];

        $assignableTotal = ipv4_assignable_count($prefix);
        $assignedCount   = $countBySubnet[$sid] ?? 0;

        if ($prefix <= 30 && $assignedCount > 0) {
            // Exclude network/broadcast addresses from the count
            $bcast = ipv4_broadcast_bin_local($netBin, $prefix);
            $exclSt = $db->prepare(
                "SELECT COUNT(*) AS c FROM addresses
                 WHERE subnet_id = :sid AND status IN ('used','reserved')
                   AND (ip_bin = :net OR ip_bin = :bcast)"
            );
            $exclSt->execute([':sid' => $sid, ':net' => $netBin, ':bcast' => $bcast]);
            $excluded = (int)$exclSt->fetch()['c'];
            $assignedAssignable = $assignedCount - $excluded;
        } else {
            $assignedAssignable = $assignedCount;
        }

        if ($assignedAssignable < 0) $assignedAssignable = 0;
        $unassigned = $assignableTotal - $assignedAssignable;
        if ($unassigned < 0) $unassigned = 0;

        $out[$sid] = [
            'assignable_total'      => (int)$assignableTotal,
            'assigned_assignable'   => (int)$assignedAssignable,
            'unassigned_assignable' => (int)$unassigned,
        ];
    }
    return $out;
}

/**
 * Aggregate ipv4_unassigned_summary_local() stats up the subnet tree so that
 * parent subnets show rolled-up utilization across all descendants.
 */
function ipv4_unassigned_aggregated_local(array $tree, array $directUnassigned): array
{
    $children = $tree['children'];
    $agg = [];

    $sumNode = function(int $id) use (&$sumNode, &$agg, $children, $directUnassigned, $tree): array {
        if (isset($agg[$id])) return $agg[$id];

        // Only IPv4 subnets contribute to the util bar
        $ipVer = (int)($tree['byId'][$id]['ip_version'] ?? 0);
        $base = ($ipVer === 4 && isset($directUnassigned[$id]))
            ? $directUnassigned[$id]
            : ['assignable_total' => 0, 'assigned_assignable' => 0, 'unassigned_assignable' => 0];

        $sum = $base;
        foreach (($children[$id] ?? []) as $cid) {
            $c = $sumNode((int)$cid);
            $sum['assignable_total']      += $c['assignable_total'];
            $sum['assigned_assignable']   += $c['assigned_assignable'];
            $sum['unassigned_assignable'] += $c['unassigned_assignable'];
        }
        return $agg[$id] = $sum;
    };

    foreach ($tree['byId'] as $id => $_row) $sumNode((int)$id);
    return $agg;
}

function subnet_overlap_warning_text(array $overlaps): string
{
    $parts = [];
    if (!empty($overlaps['parents'])) {
        $list = implode(', ', $overlaps['parents']);
        $parts[] = 'nested inside: ' . $list;
    }
    if (!empty($overlaps['children'])) {
        $list = implode(', ', $overlaps['children']);
        $parts[] = 'parent of: ' . $list;
    }
    return 'Hierarchy notice — this subnet is ' . implode('; and ', $parts) . '. Verify this nesting is intentional.';
}

$tree = build_subnet_tree_local($list);
$direct = subnet_direct_counts_local($db);
$agg = subnet_aggregated_counts_local($tree, $direct);
$ipv4Unassigned = ipv4_unassigned_summary_local($db);
$ipv4UnassignedAgg = ipv4_unassigned_aggregated_local($tree, $ipv4Unassigned);

$siteGroups = [];
foreach ($tree['roots'] as $rid) {
    $siteId = (int)($tree['byId'][$rid]['site_id'] ?? 0);
    $key = $siteId > 0 ? (string)$siteId : 'ungrouped';
    $label = $siteId > 0 ? ($siteMap[$siteId] ?? "Site #$siteId") : 'Ungrouped';
    $siteGroups[$key] ??= ['label' => $label, 'roots' => []];
    $siteGroups[$key]['roots'][] = $rid;
}
uasort($siteGroups, fn($a, $b) => strcasecmp($a['label'], $b['label']));

function render_subnet_node_local(array $tree, array $direct, array $agg, array $ipv4Unassigned, array $ipv4UnassignedAgg, array $siteMap, array $siteList, int $id, int $depth = 0): void
{
    $row = $tree['byId'][$id];
    $pad = $depth * 18;
    $d = $direct[$id] ?? ['used'=>0,'reserved'=>0,'free'=>0,'total'=>0];
    $a = $agg[$id] ?? $d;
    $disabled = (current_user()['role'] === 'readonly') ? "disabled" : "";
    $siteName = '';
    $siteId = (int)($row['site_id'] ?? 0);
    if ($siteId > 0) $siteName = $siteMap[$siteId] ?? '';

    echo "<div class='subnet-node' data-indent='{$pad}'>";
    echo "<details " . ($depth < 1 ? "open" : "") . ">";
    echo "<summary>";
    echo "<b>" . e($row['cidr']) . "</b> ";
    echo "<span class='muted'>(v" . (int)$row['ip_version'] . ")</span> ";
    if ($siteName !== '') echo " <span class='badge'>" . e($siteName) . "</span>";
    if (!empty($row['vlan_id'])) echo " <span class='badge'>VLAN " . (int)$row['vlan_id'] . "</span>";
    if (($row['description'] ?? '') !== '') echo " - " . e((string)$row['description']);
    // Address count badges — direct counts on this subnet, aggregated in parens if children differ
    $countHtml = "<span class='status-used'>" . $d['used'] . " used</span>"
               . " &middot; <span class='status-reserved'>" . $d['reserved'] . " reserved</span>"
               . " &middot; <span class='status-free'>" . $d['free'] . " free</span>";
    if ($a['total'] !== $d['total']) {
        $countHtml .= " <span class='muted'>(subtree: " . $a['used'] . "u / " . $a['reserved'] . "r / " . $a['free'] . "f)</span>";
    }
    echo "<br>" . $countHtml;

    if ((int)$row['ip_version'] === 4) {
        $hasChildren = !empty($tree['children'][$id]);
        // Use aggregated stats for parents so the bar reflects all descendants
        $u = $hasChildren ? ($ipv4UnassignedAgg[$id] ?? null) : ($ipv4Unassigned[$id] ?? null);
        if ($u && (int)$u['assignable_total'] > 0) {
            $assignable = (int)$u['assignable_total'];
            $assigned   = (int)$u['assigned_assignable'];
            $pct = (int)round($assigned / $assignable * 100);
            $cfg = $GLOBALS['config'] ?? [];
            $warnThreshold = (int)($cfg['utilization_warn']     ?? 80);
            $critThreshold = (int)($cfg['utilization_critical'] ?? 95);
            $barClass = $pct >= $critThreshold ? 'util-bar-fill--crit'
                      : ($pct >= $warnThreshold ? 'util-bar-fill--warn' : '');
            $pctLabel = $pct >= $critThreshold ? "<span class='danger'>{$pct}%</span>"
                      : ($pct >= $warnThreshold ? "<span class='warning'>{$pct}%</span>"
                      : "<span>{$pct}%</span>");
            $rollupNote = $hasChildren ? " <span class='muted'>(incl. subnets)</span>" : '';
            echo "<br><span class='muted'>Assignable: " . e((string)$assignable) .
                 " | Assigned: " . e((string)$assigned) .
                 " | Unassigned: <b>" . e((string)$u['unassigned_assignable']) . "</b></span>"
               . $rollupNote
               . " <span class='util-bar'><span class='util-bar-fill {$barClass}' data-pct='{$pct}'></span></span>"
               . " {$pctLabel}";
        }
    }
    echo "</summary>";

    echo "<div class='mt-10'>";
    echo "<div class='page-actions mb-10'>";
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

    echo "<form method='post' action='subnets.php' class='row mt-8'>";
    echo "<input type='hidden' name='csrf' value='" . e(csrf_token()) . "'>";
    echo "<input type='hidden' name='action' value='update'>";
    echo "<input type='hidden' name='id' value='" . (int)$row['id'] . "'>";
    echo "<label>CIDR<br><input name='cidr' value='" . e($row['cidr']) . "' required></label>";
    echo "<label>Description<br><input name='description' value='" . e($row['description']) . "'></label>";
    $vlanVal = ($row['vlan_id'] !== null && $row['vlan_id'] !== '') ? (int)$row['vlan_id'] : '';
    echo "<label>VLAN ID<br><input name='vlan_id' type='number' min='1' max='4094' value='" . e((string)$vlanVal) . "' placeholder='1–4094' class='mw-80'></label>";

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

    echo "<form method='post' action='subnets.php' data-confirm='Delete subnet and all its addresses?' class='mt-8'>";
    echo "<input type='hidden' name='csrf' value='" . e(csrf_token()) . "'>";
    echo "<input type='hidden' name='action' value='delete'>";
    echo "<input type='hidden' name='id' value='" . (int)$row['id'] . "'>";
    echo "<button type='submit' class='button-danger' $disabled>Delete</button>";
    echo "</form>";

    if (current_user()['role'] === 'readonly') {
        echo "<p class='muted'>Read-only account.</p>";
    }

    foreach (($tree['children'][$id] ?? []) as $cid) {
        render_subnet_node_local($tree, $direct, $agg, $ipv4Unassigned, $ipv4UnassignedAgg, $siteMap, $siteList, (int)$cid, $depth + 1);
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
  <a class="action-pill" href="export_subnets.php">⬇ Export CSV</a>
  <?php if (current_user()['role'] === 'admin'): ?>
    <a class="action-pill" href="sites.php">📍 Manage Sites</a>
  <?php endif; ?>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>
<?php if ($warn): ?><p class="warning"><?= e($warn) ?></p><?php endif; ?>

<?php if (!empty($overlapWarning) && !empty($pendingAction) && !empty($pendingData)): ?>
  <div class="card mt-16 warning" style="border-left:4px solid var(--warn)">
    <h2>⚠ Overlap Warning</h2>
    <p><?= e($overlapWarning) ?></p>
    <form method="post" action="subnets.php" style="display:inline">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="<?= e($pendingAction) ?>">
      <?php if (($pendingData['id'] ?? 0) > 0): ?>
        <input type="hidden" name="id" value="<?= (int)$pendingData['id'] ?>">
      <?php endif; ?>
      <input type="hidden" name="cidr" value="<?= e((string)$pendingData['cidr']) ?>">
      <input type="hidden" name="description" value="<?= e((string)$pendingData['description']) ?>">
      <input type="hidden" name="site_id" value="<?= (int)$pendingData['site_id'] ?>">
      <input type="hidden" name="vlan_id" value="<?= e((string)($pendingData['vlan_id'] ?? '')) ?>">
      <input type="hidden" name="confirm_overlap" value="1">
      <button type="submit">Save anyway</button>
      <a class="action-pill" href="subnets.php">Cancel</a>
    </form>
  </div>
<?php endif; ?>

<div class="card mt-16" id="add-subnet">
  <h2>Add subnet</h2>
  <form method="post" action="subnets.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="row">
      <label>CIDR<br><input name="cidr" placeholder="10.0.0.0/24 or 2001:db8::/64" required data-validate="cidr"></label>
      <label>Description<br><input name="description" placeholder="Office LAN"></label>
      <label>VLAN ID<br><input name="vlan_id" type="number" min="1" max="4094" placeholder="1–4094" class="mw-80"></label>
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

<div class="card mt-16">
  <h2>Grouped Hierarchy</h2>

  <?php if (empty($siteGroups)): ?>
    <div class="empty-state">No subnets yet.</div>
  <?php else: ?>
    <?php foreach ($siteGroups as $key => $group): ?>
      <div class="site-group mb-24">
        <button type="button" class="site-group-toggle" aria-expanded="true" data-sg-key="<?= e((string)$key) ?>">
          <?= e($group['label']) ?><span class="site-group-caret" aria-hidden="true">&#9660;</span>
        </button>
        <div class="site-group-body">
          <?php foreach ($group['roots'] as $rid): ?>
            <?php render_subnet_node_local($tree, $direct, $agg, $ipv4Unassigned, $ipv4UnassignedAgg, $siteMap, $siteList, (int)$rid, 0); ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php page_footer();
