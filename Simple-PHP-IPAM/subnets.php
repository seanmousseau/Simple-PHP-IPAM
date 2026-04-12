<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';
$msg = '';
$warn = '';

// Flash warnings are now rendered by page_header() via flash_get()

$st = $db->prepare("SELECT id, name FROM sites ORDER BY name ASC");
$st->execute();
/** @var list<array<string, mixed>> $siteList */
$siteList = $st->fetchAll();

$siteMap = [];
foreach ($siteList as $s) {
    $siteMap[to_int($s['id'])] = to_str($s['name']);
}

/** @var list<array<string, mixed>> $vlanList */
$vlanList = ($db->query("SELECT id, vlan_id, name FROM vlans ORDER BY vlan_id ASC") ?: throw new \RuntimeException('Query failed'))->fetchAll();
/** @var array<int, array<string, mixed>> $vlanMap key = vlans.id */
$vlanMap = [];
foreach ($vlanList as $vl) {
    $vlanMap[to_int($vl['id'])] = $vl;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = to_str($_POST['action'] ?? '');

    if ($action === 'create') {
        require_write_access();
        $cidr   = trim(to_str($_POST['cidr'] ?? ''));
        $desc   = trim(to_str($_POST['description'] ?? ''));
        $siteId = to_int($_POST['site_id'] ?? 0);
        if ($siteId <= 0) $siteId = null;

        // vlan_fk: FK to vlans.id; also derive legacy vlan_id integer for the badge
        $vlanFk = to_int($_POST['vlan_fk'] ?? 0) ?: null;
        $vlanId = null;
        if ($vlanFk !== null && isset($vlanMap[$vlanFk])) {
            $vlanId = to_int($vlanMap[$vlanFk]['vlan_id']);
        }

        $doAutoReserve = !empty($_POST['auto_reserve']);
        $gateway       = trim(to_str($_POST['gateway'] ?? '')) ?: null;

        $p = parse_cidr($cidr);
        if (!$p) {
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
                    'cidr'         => $cidr,
                    'description'  => $desc,
                    'site_id'      => $siteId ?? 0,
                    'vlan_fk'      => $vlanFk ?? 0,
                    'auto_reserve' => $doAutoReserve ? '1' : '0',
                    'gateway'      => $gateway ?? '',
                ];
            } else {
                try {
                    $st = $db->prepare("INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, site_id, vlan_id, vlan_fk)
                                        VALUES (:cidr,:ver,:net,:nb,:pre,:d,:site,:vlan,:vfk)");
                    $st->execute([
                        ':cidr' => $normalized,
                        ':ver'  => $p['version'],
                        ':net'  => $p['network'],
                        ':nb'   => $p['net_bin'],
                        ':pre'  => $p['prefix'],
                        ':d'    => $desc,
                        ':site' => $siteId,
                        ':vlan' => $vlanId,
                        ':vfk'  => $vlanFk,
                    ]);
                    $newSubnetId = (int)$db->lastInsertId();
                    audit($db, 'subnet.create', 'subnet', $newSubnetId, $normalized);

                    if ($doAutoReserve) {
                        auto_reserve_subnet_ips($db, $newSubnetId, $normalized, $gateway);
                    }

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
        $id     = to_int($_POST['id'] ?? 0);
        $cidr   = trim(to_str($_POST['cidr'] ?? ''));
        $desc   = trim(to_str($_POST['description'] ?? ''));
        $siteId = to_int($_POST['site_id'] ?? 0);
        if ($siteId <= 0) $siteId = null;

        $vlanFk = to_int($_POST['vlan_fk'] ?? 0) ?: null;
        $vlanId = null;
        if ($vlanFk !== null && isset($vlanMap[$vlanFk])) {
            $vlanId = to_int($vlanMap[$vlanFk]['vlan_id']);
        }

        $p = parse_cidr($cidr);
        if (!$p) {
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
                    'site_id' => $siteId ?? 0, 'vlan_fk' => $vlanFk ?? 0,
                ];
            } else {
                try {
                    $st = $db->prepare("UPDATE subnets
                                        SET cidr=:cidr, ip_version=:ver, network=:net, network_bin=:nb, prefix=:pre, description=:d, site_id=:site, vlan_id=:vlan, vlan_fk=:vfk
                                        WHERE id=:id");
                    $st->execute([
                        ':cidr' => $normalized,
                        ':ver'  => $p['version'],
                        ':net'  => $p['network'],
                        ':nb'   => $p['net_bin'],
                        ':pre'  => $p['prefix'],
                        ':d'    => $desc,
                        ':site' => $siteId,
                        ':vlan' => $vlanId,
                        ':vfk'  => $vlanFk,
                        ':id'   => $id,
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
        $id = to_int($_POST['id'] ?? 0);
        $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM addresses WHERE subnet_id = :id");
        $cntSt->execute([':id' => $id]);
        /** @var array<string, mixed>|false $cntRow */

        $cntRow = $cntSt->fetch();

        $addrCount = is_array($cntRow) ? to_int($cntRow['c']) : 0;
        $st = $db->prepare("DELETE FROM subnets WHERE id = :id");
        $st->execute([':id' => $id]);
        audit($db, 'subnet.delete', 'subnet', $id, "addresses_deleted={$addrCount}");
        header('Location: subnets.php');
        exit;
    }
}

$st = $db->prepare("
    SELECT s.id, s.cidr, s.ip_version, s.network, s.network_bin, s.prefix, s.description, s.updated_at, s.site_id, s.vlan_id, s.vlan_fk,
           v.name AS vlan_name
    FROM subnets s
    LEFT JOIN vlans v ON v.id = s.vlan_fk
    ORDER BY s.ip_version ASC, s.prefix ASC, s.network_bin ASC
");
$st->execute();
/** @var list<array<string, mixed>> $list */
$list = $st->fetchAll();

function subnet_contains_bin_local(string $parentNetBin, int $parentPrefix, string $childNetBin): bool
{
    $masked = apply_prefix_mask($childNetBin, $parentPrefix);
    return hash_equals($masked, $parentNetBin);
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array{roots: list<int>, children: array<int, list<int>>, byId: array<int, array<string, mixed>>}
 */
function build_subnet_tree_local(array $rows): array
{
    $byId = [];
    foreach ($rows as $r) $byId[to_int($r['id'])] = $r;

    // Sort by ip_version ASC, prefix ASC (broadest first), network_bin ASC
    $sorted = $byId;
    uasort($sorted, function(array $a, array $b): int {
        $va = to_int($a['ip_version']); $vb = to_int($b['ip_version']);
        if ($va !== $vb) return $va <=> $vb;
        $pa = to_int($a['prefix']); $pb = to_int($b['prefix']);
        if ($pa !== $pb) return $pa <=> $pb;
        return strcmp(to_str($a['network_bin']), to_str($b['network_bin']));
    });

    $children = [];
    $roots = [];

    // O(N log N) stack-based parent lookup: process broadest-first,
    // maintain a stack of candidate parents, pop until top contains child.
    $stack = [];

    foreach ($sorted as $id => $row) {
        $ver    = to_int($row['ip_version']);
        $prefix = to_int($row['prefix']);
        $netBin = to_str($row['network_bin']);

        // Pop entries that cannot be a parent of this subnet
        while (!empty($stack)) {
            $top = end($stack);
            if (to_int($top['ip_version']) !== $ver) {
                $stack = [];
                break;
            }
            if (to_int($top['prefix']) < $prefix
                && subnet_contains_bin_local(to_str($top['network_bin']), to_int($top['prefix']), $netBin)) {
                break;
            }
            array_pop($stack);
        }

        if (!empty($stack)) {
            $parent = end($stack);
            $children[to_int($parent['id'])][] = $id;
        } else {
            $roots[] = $id;
        }

        $stack[] = ['id' => $id, 'ip_version' => $ver, 'prefix' => $prefix, 'network_bin' => $netBin];
    }

    $cmpFn = function(int $a, int $b) use ($byId): int {
        $ra = $byId[$a]; $rb = $byId[$b];
        $va = to_int($ra['ip_version']); $vb = to_int($rb['ip_version']);
        if ($va !== $vb) return $va <=> $vb;
        $c = strcmp(to_str($ra['network_bin']), to_str($rb['network_bin']));
        if ($c !== 0) return $c;
        return to_int($ra['prefix']) <=> to_int($rb['prefix']);
    };

    usort($roots, $cmpFn);
    foreach ($children as $pid => $arr) {
        usort($arr, $cmpFn);
        $children[$pid] = $arr;
    }

    return ['roots' => $roots, 'children' => $children, 'byId' => $byId];
}

/** @return array<int, array{used: int, reserved: int, free: int, total: int}> */
function subnet_direct_counts_local(PDO $db): array
{
    $st = $db->prepare("SELECT subnet_id, status, COUNT(*) AS c FROM addresses GROUP BY subnet_id, status");
    $st->execute();
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $sid = to_int($r['subnet_id']);
        $status = to_str($r['status']);
        $c = to_int($r['c']);
        $out[$sid] ??= ['used'=>0,'reserved'=>0,'free'=>0,'total'=>0];
        if (isset($out[$sid][$status])) $out[$sid][$status] += $c;
        $out[$sid]['total'] += $c;
    }
    return $out;
}

/**
 * @param array{roots: list<int>, children: array<int, list<int>>, byId: array<int, array<string, mixed>>} $tree
 * @param array<int, array{used: int, reserved: int, free: int, total: int}> $directCounts
 * @return array<int, array{used: int, reserved: int, free: int, total: int}>
 */
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

    $unpacked = unpack('N', $netBin);
    $n = $unpacked !== false ? $unpacked[1] : 0;
    $hostMask = ($hostBits === 32) ? 0xFFFFFFFF : ((1 << $hostBits) - 1);
    $b = ($n | $hostMask) & 0xFFFFFFFF;

    return pack('N', $b);
}

/** @return array<int, array{assignable_total: int, assigned_assignable: int, unassigned_assignable: int}> */
function ipv4_unassigned_summary_local(PDO $db): array
{
    $st = $db->prepare("SELECT id, prefix, network_bin FROM subnets WHERE ip_version=4");
    $st->execute();
    /** @var list<array<string, mixed>> $subs */
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
        $countBySubnet[to_int($r['subnet_id'])] = to_int($r['c']);
    }

    $out = [];
    foreach ($subs as $s) {
        $sid    = to_int($s['id']);
        $prefix = to_int($s['prefix']);
        $netBin = to_str($s['network_bin']);

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
            /** @var array<string, mixed>|false $cntRow */

            $cntRow = $exclSt->fetch();

            $excluded = is_array($cntRow) ? to_int($cntRow['c']) : 0;
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
/**
 * @param array{roots: list<int>, children: array<int, list<int>>, byId: array<int, array<string, mixed>>} $tree
 * @param array<int, array{assignable_total: int, assigned_assignable: int, unassigned_assignable: int}> $directUnassigned
 * @return array<int, array{assignable_total: int, assigned_assignable: int, unassigned_assignable: int}>
 */
function ipv4_unassigned_aggregated_local(array $tree, array $directUnassigned): array
{
    $children = $tree['children'];
    $agg = [];

    $sumNode = function(int $id) use (&$sumNode, &$agg, $children, $directUnassigned, $tree): array {
        if (isset($agg[$id])) return $agg[$id];

        // Only IPv4 subnets contribute to the util bar
        $ipVer = to_int($tree['byId'][$id]['ip_version'] ?? 0);
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

$tree = build_subnet_tree_local($list);
$direct = subnet_direct_counts_local($db);
$agg = subnet_aggregated_counts_local($tree, $direct);
$ipv4Unassigned = ipv4_unassigned_summary_local($db);
$ipv4UnassignedAgg = ipv4_unassigned_aggregated_local($tree, $ipv4Unassigned);

$siteGroups = [];
foreach ($tree['roots'] as $rid) {
    $siteId = to_int($tree['byId'][$rid]['site_id'] ?? 0);
    $key = $siteId > 0 ? (string)$siteId : 'ungrouped';
    $label = $siteId > 0 ? ($siteMap[$siteId] ?? "Site #$siteId") : 'Ungrouped';
    $siteGroups[$key] ??= ['label' => $label, 'roots' => []];
    $siteGroups[$key]['roots'][] = $rid;
}
uasort($siteGroups, fn($a, $b) => strcasecmp($a['label'], $b['label']));

/**
 * @param array{roots: list<int>, children: array<int, list<int>>, byId: array<int, array<string, mixed>>} $tree
 * @param array<int, array{used: int, reserved: int, free: int, total: int}> $direct
 * @param array<int, array{used: int, reserved: int, free: int, total: int}> $agg
 * @param array<int, array{assignable_total: int, assigned_assignable: int, unassigned_assignable: int}> $ipv4Unassigned
 * @param array<int, array{assignable_total: int, assigned_assignable: int, unassigned_assignable: int}> $ipv4UnassignedAgg
 * @param array<int, string> $siteMap
 * @param array<int, array<string, mixed>> $siteList
 * @param list<array<string, mixed>> $vlanList
 */
function render_subnet_node_local(array $tree, array $direct, array $agg, array $ipv4Unassigned, array $ipv4UnassignedAgg, array $siteMap, array $siteList, array $vlanList, int $id, int $depth = 0): void
{
    $row = $tree['byId'][$id];
    $pad = $depth * 18;
    $d = $direct[$id] ?? ['used'=>0,'reserved'=>0,'free'=>0,'total'=>0];
    $a = $agg[$id] ?? $d;
    $disabled = (current_user()['role'] === 'readonly') ? "disabled" : "";
    $siteName = '';
    $siteId = to_int($row['site_id'] ?? 0);
    if ($siteId > 0) $siteName = $siteMap[$siteId] ?? '';

    echo "<div class='subnet-node' data-indent='{$pad}'>";
    echo "<details " . ($depth < 1 ? "open" : "") . ">";
    echo "<summary>";
    echo "<b><a href='addresses.php?subnet_id=" . to_int($row['id']) . "'>" . e(to_str($row['cidr'])) . "</a></b> ";
    echo "<span class='muted'>(v" . to_int($row['ip_version']) . ")</span> ";
    if ($siteName !== '') echo " <span class='badge'>" . e($siteName) . "</span>";
    $vlanFkVal = to_int($row['vlan_fk'] ?? 0);
    $vlanLabel = '';
    if ($vlanFkVal > 0 && !empty($row['vlan_name'])) {
        $vlanLabel = to_int($row['vlan_id']) . ' \u{2014} ' . to_str($row['vlan_name']);
    } elseif (!empty($row['vlan_id'])) {
        $vlanLabel = 'VLAN ' . to_int($row['vlan_id']);
    }
    if ($vlanLabel !== '') echo " <span class='badge'>" . e($vlanLabel) . "</span>";
    if (($row['description'] ?? '') !== '') echo " - " . e(to_str($row['description']));
    // Address count badges — direct counts on this subnet, aggregated in parens if children differ
    $countHtml = "<span class='status-used'>" . $d['used'] . " used</span>"
               . " &middot; <span class='status-reserved'>" . $d['reserved'] . " reserved</span>"
               . " &middot; <span class='status-free'>" . $d['free'] . " free</span>";
    if ($a['total'] !== $d['total']) {
        $countHtml .= " <span class='muted'>(subtree: " . $a['used'] . "u / " . $a['reserved'] . "r / " . $a['free'] . "f)</span>";
    }
    echo "<br>" . $countHtml;

    if (to_int($row['ip_version']) === 4) {
        $hasChildren = !empty($tree['children'][$id]);
        // Use aggregated stats for parents so the bar reflects all descendants
        $u = $hasChildren ? ($ipv4UnassignedAgg[$id] ?? null) : ($ipv4Unassigned[$id] ?? null);
        if ($u && to_int($u['assignable_total']) > 0) {
            $assignable = to_int($u['assignable_total']);
            $assigned   = to_int($u['assigned_assignable']);
            $pct = (int)round($assigned / $assignable * 100);
            $globalCfg = $GLOBALS['config'];
            $warnThreshold = is_array($globalCfg) ? to_int($globalCfg['utilization_warn'] ?? 80) : 80;
            $critThreshold = is_array($globalCfg) ? to_int($globalCfg['utilization_critical'] ?? 95) : 95;
            $barClass = $pct >= $critThreshold ? 'util-bar-fill--crit'
                      : ($pct >= $warnThreshold ? 'util-bar-fill--warn' : '');
            $pctLabel = $pct >= $critThreshold ? "<span class='danger'>{$pct}%</span>"
                      : ($pct >= $warnThreshold ? "<span class='warning'>{$pct}%</span>"
                      : "<span>{$pct}%</span>");
            $rollupNote = $hasChildren ? " <span class='muted'>(incl. subnets)</span>" : '';
            echo "<br><span class='muted'>Assignable: " . e((string)$assignable) .
                 " | Assigned: " . e((string)$assigned) .
                 " | Unassigned: <b>" . e(to_str($u['unassigned_assignable'])) . "</b></span>"
               . $rollupNote
               . " <span class='util-bar'><span class='util-bar-fill {$barClass}' data-pct='{$pct}'></span></span>"
               . " {$pctLabel}";
        }
    }
    echo "</summary>";

    echo "<div class='mt-10'>";
    echo "<div class='page-actions mb-10'>";
    echo "<a class='action-pill' href='addresses.php?subnet_id=" . to_int($row['id']) . "'>🧾 View Addresses</a>";
    if (to_int($row['ip_version']) === 4) {
        echo "<a class='action-pill' href='unassigned.php?subnet_id=" . to_int($row['id']) . "'>✨ Unassigned</a>";
    }
    if (current_user()['role'] !== 'readonly') {
        echo "<a class='action-pill' href='bulk_update.php?subnet_id=" . to_int($row['id']) . "'>✏ Bulk Update</a>";
        if (to_int($row['ip_version']) === 4) {
            echo "<a class='action-pill' href='dhcp_pool.php?subnet_id=" . to_int($row['id']) . "'>🔒 DHCP Pool</a>";
        }
    }
    echo "</div>";

    echo "<div class='muted'>Updated " . e(to_str($row['updated_at'])) . "</div>";

    echo "<form method='post' action='subnets.php' class='row mt-8'>";
    echo "<input type='hidden' name='csrf' value='" . e(csrf_token()) . "'>";
    echo "<input type='hidden' name='action' value='update'>";
    echo "<input type='hidden' name='id' value='" . to_int($row['id']) . "'>";
    echo "<label>CIDR<br><input name='cidr' value='" . e(to_str($row['cidr'])) . "' required></label>";
    echo "<label>Description<br><input name='description' value='" . e(to_str($row['description'])) . "'></label>";
    if ($vlanList) {
        $curVlanFk = to_int($row['vlan_fk'] ?? 0);
        echo "<label>VLAN<br><select name='vlan_fk'><option value='0'>(none)</option>";
        foreach ($vlanList as $vl) {
            $vlId = to_int($vl['id']);
            $sel  = $vlId === $curVlanFk ? " selected" : "";
            echo "<option value='" . $vlId . "'" . $sel . ">" . to_int($vl['vlan_id']) . " \u{2014} " . e(to_str($vl['name'])) . "</option>";
        }
        echo "</select></label>";
    }

    if ($depth > 0) {
        // Child subnet: site is inherited from parent and cannot be changed here
        $lockedSiteName = ($siteId > 0 && isset($siteMap[$siteId])) ? $siteMap[$siteId] : '(none)';
        echo "<input type='hidden' name='site_id' value='" . $siteId . "'>";
        echo "<label>Site<br><span class='badge' title='Inherited from parent subnet'>" . e($lockedSiteName) . " ↑</span></label>";
    } else {
        echo "<label>Site<br><select name='site_id'>";
        echo "<option value='0' " . ($siteId === 0 ? "selected" : "") . ">(none)</option>";
        foreach ($siteList as $s) {
            $sid = to_int($s['id']);
            $sel = ($sid === $siteId) ? "selected" : "";
            echo "<option value='" . $sid . "' $sel>" . e(to_str($s['name'])) . "</option>";
        }
        echo "</select></label>";
    }

    echo "<button type='submit' $disabled>Save</button>";
    echo "</form>";

    echo "<form method='post' action='subnets.php' data-confirm='Delete subnet and all its addresses?' class='mt-8'>";
    echo "<input type='hidden' name='csrf' value='" . e(csrf_token()) . "'>";
    echo "<input type='hidden' name='action' value='delete'>";
    echo "<input type='hidden' name='id' value='" . to_int($row['id']) . "'>";
    echo "<button type='submit' class='button-danger' $disabled>Delete</button>";
    echo "</form>";

    if (current_user()['role'] === 'readonly') {
        echo "<p class='muted'>Read-only account.</p>";
    }

    foreach (($tree['children'][$id] ?? []) as $cid) {
        render_subnet_node_local($tree, $direct, $agg, $ipv4Unassigned, $ipv4UnassignedAgg, $siteMap, $siteList, $vlanList, (int)$cid, $depth + 1);
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
        <input type="hidden" name="id" value="<?= to_int($pendingData['id']) ?>">
      <?php endif; ?>
      <input type="hidden" name="cidr" value="<?= e(to_str($pendingData['cidr'])) ?>">
      <input type="hidden" name="description" value="<?= e(to_str($pendingData['description'])) ?>">
      <input type="hidden" name="site_id" value="<?= to_int($pendingData['site_id']) ?>">
      <input type="hidden" name="vlan_fk" value="<?= to_int($pendingData['vlan_fk'] ?? 0) ?>">
      <?php if (isset($pendingData['auto_reserve'])): ?>
        <input type="hidden" name="auto_reserve" value="<?= to_int($pendingData['auto_reserve']) ?>">
        <input type="hidden" name="gateway" value="<?= e(to_str($pendingData['gateway'] ?? '')) ?>">
      <?php endif; ?>
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
      <?php if ($vlanList): ?>
      <label>VLAN<br>
        <select name="vlan_fk">
          <option value="0">(none)</option>
          <?php foreach ($vlanList as $vl): ?>
            <option value="<?= to_int($vl['id']) ?>"><?= to_int($vl['vlan_id']) ?> &mdash; <?= e(to_str($vl['name'])) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <label>Site<br>
        <select name="site_id">
          <option value="0">(none)</option>
          <?php foreach ($siteList as $site): ?>
            <option value="<?= to_int($site['id']) ?>"><?= e(to_str($site['name'])) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" <?= (current_user()['role']==='readonly')?'disabled':'' ?>>Add</button>
    </div>
    <?php $autoReserveDefault = (bool)($config['auto_reserve_network_broadcast'] ?? true); ?>
    <div class="row mt-8">
      <label class="row-inline">
        <input type="checkbox" name="auto_reserve" value="1" <?= $autoReserveDefault ? 'checked' : '' ?>>
        Auto-reserve network, broadcast &amp; gateway IPs
      </label>
      <label>Gateway (optional)<br><input name="gateway" placeholder="e.g. 10.0.0.1"></label>
    </div>
    <?php if (current_user()['role']==='readonly'): ?><p class="muted">Read-only account.</p><?php endif; ?>
  </form>
</div>

<div class="card mt-16">
  <h2>Grouped Hierarchy</h2>

  <?php if (empty($siteGroups)): ?>
    <div class="empty-state">No subnets yet. <a class="action-pill" href="#add-subnet">+ Add Subnet</a></div>
  <?php else: ?>
    <?php foreach ($siteGroups as $key => $group): ?>
      <div class="site-group mb-24">
        <button type="button" class="site-group-toggle" aria-expanded="true" data-sg-key="<?= e((string)$key) ?>">
          <?= e(to_str($group['label'])) ?><span class="site-group-caret" aria-hidden="true">&#9660;</span>
        </button>
        <div class="site-group-body">
          <?php foreach ($group['roots'] as $rid): ?>
            <?php render_subnet_node_local($tree, $direct, $agg, $ipv4Unassigned, $ipv4UnassignedAgg, $siteMap, $siteList, $vlanList, (int)$rid, 0); ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php page_footer();
