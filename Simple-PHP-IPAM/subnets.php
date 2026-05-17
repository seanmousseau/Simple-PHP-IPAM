<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var array<string, mixed> $config */
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

/**
 * POST controller for subnets.php. Behaviour-preserving extract of the inline
 * POST handling block. CSRF is verified by the caller before this runs. The
 * create/delete/save_scan_schedule/delete_scan_schedule actions terminate the
 * request directly (header+exit). For the inline 'create'/'update' actions
 * that fall through (validation errors, overlap confirmation) it returns the
 * render-facing state — err/msg/warn plus the overlap-confirmation triplet
 * (overlapWarning/pendingAction/pendingData) the render code replays.
 *
 * @param array<string, mixed>            $config
 * @param array<int, array<string, mixed>> $vlanMap     key = vlans.id
 * @param array<int, string>              $siteMap      key = sites.id
 * @param list<int>                       $tagIdsKnown  tag IDs known to exist
 * @return array{err: string, msg: string, warn: string, overlapWarning: string, pendingAction: string, pendingData: array<string, mixed>}
 */
function subnets_handle_post(\PDO $db, array $config, array $vlanMap, array $siteMap, array $tagIdsKnown): array
{
    $err = '';
    $msg = '';
    $warn = '';
    $overlapWarning = '';
    $pendingAction = '';
    $pendingData = [];

    /**
     * Identity passthrough that erases the overlap-confirm payload's literal
     * array shape down to the heterogeneous bag the render code consumes
     * (some keys present only for 'create', others only for 'update'). The
     * render code guards every shape-specific key with isset()/?? — keeping
     * pendingData as array<string,mixed> is its real contract.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    $ipam_pending_data = fn(array $data): array => $data;

    /** Sanitise POSTed tag_ids[] into a list of int IDs known to exist in tags. */
    $ipam_parse_subnet_tag_ids = function(array $known): array {
        $known = array_map('intval', $known);
        $rawIds = $_POST['tag_ids'] ?? [];
        if (!is_array($rawIds)) return [];
        $ids = [];
        foreach ($rawIds as $v) {
            $i = (int)to_str(is_scalar($v) ? $v : '');
            if ($i > 0 && in_array($i, $known, true)) $ids[$i] = true;
        }
        return array_keys($ids);
    };

    $action = to_str($_POST['action'] ?? '');

    if ($action === 'create') {
        require_write_access();
        $cidr   = trim(to_str($_POST['cidr'] ?? ''));
        $desc   = trim(to_str($_POST['description'] ?? ''));
        $notes  = trim(to_str($_POST['notes'] ?? ''));
        $siteId = to_int($_POST['site_id'] ?? 0);
        if ($siteId <= 0) $siteId = null;

        // vlan_fk: FK to vlans.id; also derive legacy vlan_id integer for the badge
        $vlanFk = to_int($_POST['vlan_fk'] ?? 0) ?: null;
        $vlanId = null;
        if ($vlanFk !== null && isset($vlanMap[$vlanFk])) {
            $vlanId = to_int($vlanMap[$vlanFk]['vlan_id']);
        }

        $vrfId = to_int($_POST['vrf_id'] ?? 0) ?: null;

        $doAutoReserve = !empty($_POST['auto_reserve']);
        $gateway       = trim(to_str($_POST['gateway'] ?? '')) ?: null;

        // Custom fields validation
        $cfDefs = custom_field_def_list($db, 'subnet');
        $cfValues = [];
        if ($cfDefs) {
            $cfPayload = [];
            foreach ($_POST as $k => $v) {
                if (is_string($k) && str_starts_with($k, 'cf_')) $cfPayload[substr($k, 3)] = to_str($v);
            }
            try {
                $cfValues = validate_custom_fields_payload($cfDefs, $cfPayload);
            } catch (\InvalidArgumentException $cfEx) {
                $err = 'Custom field error: ' . $cfEx->getMessage();
            }
        }

        $p = parse_cidr($cidr);
        if (!$p) {
            $err = 'Invalid CIDR. Examples: 192.168.1.0/24 or 2001:db8::/64';
        }
        if (!$err && $p !== null) {
            $normalized = $p['network'] . '/' . $p['prefix'];
            $overlaps = detect_subnet_overlaps($db, $normalized, null, $vrfId);
            // Inherit site from tightest parent if one exists
            $inheritedSiteId = find_parent_site_id($db, $normalized, null, $vrfId);
            if ($inheritedSiteId !== null) $siteId = $inheritedSiteId;

            // Pre-save overlap confirmation
            $hasOverlaps = !empty($overlaps['parents']) || !empty($overlaps['children']);
            if ($hasOverlaps && empty($_POST['confirm_overlap'])) {
                $overlapWarning = subnet_overlap_warning_text($overlaps);
                $pendingAction = 'create';
                $pendingContacts = !empty($_POST['contact_id_present']) ? parse_contact_assignments($_POST) : [];
                $pendingData = $ipam_pending_data([
                    'cidr'         => $cidr,
                    'description'  => $desc,
                    'notes'        => $notes,
                    'site_id'      => $siteId ?? 0,
                    'vlan_fk'      => $vlanFk ?? 0,
                    'vrf_id'       => $vrfId ?? 0,
                    'auto_reserve' => $doAutoReserve ? '1' : '0',
                    'gateway'      => $gateway ?? '',
                    'contacts'     => json_encode($pendingContacts),
                    // #1138: carry tag selections through the overlap-confirm
                    // round-trip so the user's tag picks aren't lost when
                    // they confirm the overlap.
                    'tag_ids'      => $ipam_parse_subnet_tag_ids($tagIdsKnown),
                ]);
            } else {
                try {
                    // UNIQUE(cidr, vrf_id) treats NULL as distinct from NULL in SQLite,
                    // so check for duplicates explicitly before inserting.
                    $dupChk = $db->prepare("SELECT id FROM subnets WHERE cidr = :cidr AND " . ipam_dialect()->null_safe_eq("vrf_id", ":vrf") . "");
                    $dupChk->execute([':cidr' => $normalized, ':vrf' => $vrfId]);
                    if ($dupChk->fetch()) {
                        $err = 'A subnet with this CIDR already exists.';
                    } else {
                    // #410/#388: bind network_bin via ipam_bind_binary() (PARAM_LOB).
                    $st = $db->prepare("INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, notes, site_id, vlan_id, vlan_fk, vrf_id, custom_fields)
                                        VALUES (:cidr,:ver,:net,:nb,:pre,:d,:notes,:site,:vlan,:vfk,:vrf,:cf)");
                    $st->bindValue(':cidr',  $normalized);
                    $st->bindValue(':ver',   $p['version'], PDO::PARAM_INT);
                    $st->bindValue(':net',   $p['network']);
                    ipam_bind_binary($st, ':nb', to_str($p['net_bin']));
                    $st->bindValue(':pre',   $p['prefix'],  PDO::PARAM_INT);
                    $st->bindValue(':d',     $desc);
                    $st->bindValue(':notes', $notes);
                    $st->bindValue(':site',  $siteId, $siteId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $st->bindValue(':vlan',  $vlanId, $vlanId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $st->bindValue(':vfk',   $vlanFk, $vlanFk === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $st->bindValue(':vrf',   $vrfId,  $vrfId  === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $st->bindValue(':cf',    serialize_custom_fields_row($cfValues));
                    $st->execute();
                    $newSubnetId = ipam_last_insert_id($db, 'subnets');
                    if (!empty($_POST['contact_id_present'])) {
                        save_contacts_for_entity($db, 'subnet', $newSubnetId, parse_contact_assignments($_POST));
                    }
                    // #1138: attach tags submitted from the create form/drawer.
                    save_tags_for_entity($db, 'subnet', $newSubnetId, $ipam_parse_subnet_tag_ids($tagIdsKnown));
                    audit($db, 'subnet.create', 'subnet', $newSubnetId, $normalized);
                    ipam_webhook_dispatch($db, 'subnet.create', ['id' => $newSubnetId, 'cidr' => $normalized, 'description' => $desc], $config);

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
                    }
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
        $notes  = trim(to_str($_POST['notes'] ?? ''));
        $siteId = to_int($_POST['site_id'] ?? 0);
        if ($siteId <= 0) $siteId = null;

        $vlanFk = to_int($_POST['vlan_fk'] ?? 0) ?: null;
        $vlanId = null;
        if ($vlanFk !== null && isset($vlanMap[$vlanFk])) {
            $vlanId = to_int($vlanMap[$vlanFk]['vlan_id']);
        }

        $vrfId = to_int($_POST['vrf_id'] ?? 0) ?: null;
        $alertsEnabled = isset($_POST['alerts_enabled']) ? 1 : 0;

        // DHCP options — nullable; empty string → NULL stored
        $dhcpRouters      = trim(to_str($_POST['dhcp_routers'] ?? '')) ?: null;
        $dhcpDnsServers   = trim(to_str($_POST['dhcp_dns_servers'] ?? '')) ?: null;
        $dhcpDomainName   = trim(to_str($_POST['dhcp_domain_name'] ?? '')) ?: null;
        $dhcpLeaseDefaultRaw = trim(to_str($_POST['dhcp_lease_default'] ?? ''));
        $dhcpLeaseMaxRaw     = trim(to_str($_POST['dhcp_lease_max'] ?? ''));
        $dhcpLeaseDefault    = $dhcpLeaseDefaultRaw === '' ? null : to_int($dhcpLeaseDefaultRaw);
        $dhcpLeaseMax        = $dhcpLeaseMaxRaw     === '' ? null : to_int($dhcpLeaseMaxRaw);
        $dhcpNextServer   = trim(to_str($_POST['dhcp_next_server'] ?? '')) ?: null;
        $dhcpBootFilename = trim(to_str($_POST['dhcp_boot_filename'] ?? '')) ?: null;

        if ($dhcpRouters !== null) {
            foreach (array_map('trim', explode(',', $dhcpRouters)) as $dhcpIp) {
                if ($dhcpIp !== '' && !filter_var($dhcpIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $err = 'Invalid IP in DHCP routers: ' . e($dhcpIp);
                    break;
                }
            }
        }
        if (!$err && $dhcpDnsServers !== null) {
            foreach (array_map('trim', explode(',', $dhcpDnsServers)) as $dhcpIp) {
                if ($dhcpIp !== '' && !filter_var($dhcpIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $err = 'Invalid IP in DHCP DNS servers (IPv4 only): ' . e($dhcpIp);
                    break;
                }
            }
        }
        if (!$err && $dhcpNextServer !== null && !filter_var($dhcpNextServer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $err = 'Invalid DHCP next-server IP.';
        }
        if (!$err && $dhcpLeaseDefault !== null && $dhcpLeaseDefault < 60) {
            $err = 'Default DHCP lease must be at least 60 seconds.';
        }
        if (!$err && $dhcpLeaseMax !== null && $dhcpLeaseMax < 60) {
            $err = 'Max DHCP lease must be at least 60 seconds.';
        }
        if (!$err && $dhcpLeaseDefault !== null && $dhcpLeaseMax !== null && $dhcpLeaseDefault > $dhcpLeaseMax) {
            $err = 'Default DHCP lease cannot exceed max lease time.';
        }

        // Custom fields validation (before touching the DB)
        $cfDefs = custom_field_def_list($db, 'subnet');
        $cfValues = [];
        if (!$err && $cfDefs) {
            $cfPayload = [];
            foreach ($_POST as $k => $v) {
                if (is_string($k) && str_starts_with($k, 'cf_')) $cfPayload[substr($k, 3)] = to_str($v);
            }
            try {
                $cfValues = validate_custom_fields_payload($cfDefs, $cfPayload);
            } catch (\InvalidArgumentException $cfEx) {
                $err = 'Custom field error: ' . $cfEx->getMessage();
            }
        }

        $p = parse_cidr($cidr);
        if (!$p) {
            $err = 'Invalid CIDR.';
        }
        if ($p !== null && !$err) {
            $normalized = $p['network'] . '/' . $p['prefix'];
            $overlaps = detect_subnet_overlaps($db, $normalized, $id, $vrfId);
            // Inherit site from tightest parent if one exists
            $inheritedSiteId = find_parent_site_id($db, $normalized, $id, $vrfId);
            if ($inheritedSiteId !== null) $siteId = $inheritedSiteId;

            // Pre-save overlap confirmation
            $hasOverlaps = !empty($overlaps['parents']) || !empty($overlaps['children']);
            if ($hasOverlaps && empty($_POST['confirm_overlap'])) {
                $overlapWarning = subnet_overlap_warning_text($overlaps);
                $pendingAction = 'update';
                $pendingContacts = !empty($_POST['contact_id_present']) ? parse_contact_assignments($_POST) : [];
                $pendingData = $ipam_pending_data([
                    'id' => $id, 'cidr' => $cidr, 'description' => $desc, 'notes' => $notes,
                    'site_id' => $siteId ?? 0, 'vlan_fk' => $vlanFk ?? 0, 'vrf_id' => $vrfId ?? 0,
                    'alerts_enabled' => $alertsEnabled,
                    'contacts' => json_encode($pendingContacts),
                    'dhcp_routers' => $dhcpRouters, 'dhcp_dns_servers' => $dhcpDnsServers,
                    'dhcp_domain_name' => $dhcpDomainName, 'dhcp_lease_default' => $dhcpLeaseDefault,
                    'dhcp_lease_max' => $dhcpLeaseMax, 'dhcp_next_server' => $dhcpNextServer,
                    'dhcp_boot_filename' => $dhcpBootFilename,
                    // #1138: carry tag picks + the present-marker through
                    // overlap confirmation so tag attach/detach state isn't
                    // lost on the confirm round-trip.
                    'tag_ids'         => !empty($_POST['tag_ids_present']) ? $ipam_parse_subnet_tag_ids($tagIdsKnown) : null,
                    'tag_ids_present' => !empty($_POST['tag_ids_present']) ? 1 : 0,
                ]);
            } else {
                $dupChk = $db->prepare("SELECT id FROM subnets WHERE cidr = :cidr AND " . ipam_dialect()->null_safe_eq("vrf_id", ":vrf") . " AND id != :self");
                $dupChk->execute([':cidr' => $normalized, ':vrf' => $vrfId, ':self' => $id]);
                if ($dupChk->fetch()) {
                    $err = 'A subnet with this CIDR already exists.';
                } else {
                    try {
                        // #410/#388: bind network_bin via ipam_bind_binary() (PARAM_LOB).
                        $st = $db->prepare("UPDATE subnets
                                            SET cidr=:cidr, ip_version=:ver, network=:net, network_bin=:nb, prefix=:pre, description=:d, notes=:notes, site_id=:site, vlan_id=:vlan, vlan_fk=:vfk, vrf_id=:vrf, alerts_enabled=:ae,
                                                dhcp_routers=:dr, dhcp_dns_servers=:dds, dhcp_domain_name=:ddn,
                                                dhcp_lease_default=:dld, dhcp_lease_max=:dlm,
                                                dhcp_next_server=:dns2, dhcp_boot_filename=:dbf,
                                                custom_fields=:cf
                                            WHERE id=:id");
                        $st->bindValue(':cidr',  $normalized);
                        $st->bindValue(':ver',   $p['version'], PDO::PARAM_INT);
                        $st->bindValue(':net',   $p['network']);
                        ipam_bind_binary($st, ':nb', to_str($p['net_bin']));
                        $st->bindValue(':pre',   $p['prefix'],  PDO::PARAM_INT);
                        $st->bindValue(':d',     $desc);
                        $st->bindValue(':notes', $notes);
                        $st->bindValue(':site',  $siteId, $siteId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                        $st->bindValue(':vlan',  $vlanId, $vlanId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                        $st->bindValue(':vfk',   $vlanFk, $vlanFk === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                        $st->bindValue(':vrf',   $vrfId,  $vrfId  === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                        $st->bindValue(':ae',    $alertsEnabled, PDO::PARAM_INT);
                        $st->bindValue(':dr',    $dhcpRouters,      $dhcpRouters      === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                        $st->bindValue(':dds',   $dhcpDnsServers,   $dhcpDnsServers   === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                        $st->bindValue(':ddn',   $dhcpDomainName,   $dhcpDomainName   === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                        $st->bindValue(':dld',   $dhcpLeaseDefault, $dhcpLeaseDefault === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                        $st->bindValue(':dlm',   $dhcpLeaseMax,     $dhcpLeaseMax     === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                        $st->bindValue(':dns2',  $dhcpNextServer,   $dhcpNextServer   === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                        $st->bindValue(':dbf',   $dhcpBootFilename, $dhcpBootFilename === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                        $st->bindValue(':cf',    serialize_custom_fields_row($cfValues));
                        $st->bindValue(':id',    $id,    PDO::PARAM_INT);
                        $st->execute();
                        // Clear alert_state rows when alerts are disabled (#457)
                        if ($alertsEnabled === 0) {
                            $db->prepare("DELETE FROM alert_state WHERE subnet_id = :sid")->execute([':sid' => $id]);
                        }
                        if (!empty($_POST['contact_id_present'])) {
                            save_contacts_for_entity($db, 'subnet', $id, parse_contact_assignments($_POST));
                        }
                        // #1138: tag attach/detach. The drawer always emits
                        // tag_ids_present=1 even when the operator clears
                        // every tag, so an empty tag_ids[] correctly maps to
                        // "remove all tags". Without the presence flag we
                        // can't distinguish "no UI surface for tags" from
                        // "user wants no tags" and would silently keep the
                        // old set.
                        if (!empty($_POST['tag_ids_present'])) {
                            save_tags_for_entity($db, 'subnet', $id, $ipam_parse_subnet_tag_ids($tagIdsKnown));
                        }
                        $auditDetails = $normalized . ($alertsEnabled ? '' : ' alerts_disabled');
                        audit($db, 'subnet.update', 'subnet', $id, $auditDetails);
                        ipam_webhook_dispatch($db, 'subnet.update', ['id' => $id, 'cidr' => $normalized, 'description' => $desc], $config);
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
        ipam_webhook_dispatch($db, 'subnet.delete', ['id' => $id, 'addresses_deleted' => $addrCount], $config);
        header('Location: subnets.php');
        exit;
    } elseif ($action === 'save_scan_schedule') {
        require_write_access();
        $id             = to_int($_POST['id'] ?? 0);
        $method         = to_str($_POST['scan_method'] ?? 'icmp');
        $tcpPort        = to_int($_POST['scan_tcp_port'] ?? 0) ?: null;
        $intervalMins   = max(1, to_int($_POST['scan_interval'] ?? 60));
        $isActive       = isset($_POST['scan_active']) ? 1 : 0;

        if (!in_array($method, ['icmp', 'tcp', 'both'], true)) $method = 'icmp';
        // Clear tcp_port for icmp-only; require a valid port for tcp/both
        if ($method === 'icmp') {
            $tcpPort = null;
        } elseif ($tcpPort === null || $tcpPort < 1 || $tcpPort > 65535) {
            flash_set('TCP port must be between 1 and 65535 when method is tcp or both.');
            header('Location: subnets.php');
            exit;
        }

        // Shared upsert + audit (v3.30.0 Task 8.1 #917): see ipam_scan_schedule_save().
        ipam_scan_schedule_save($db, $id, $method, $tcpPort, $intervalMins, $isActive);
        flash_set('Scan schedule saved.');
        header('Location: scan_history.php?subnet_id=' . $id);
        exit;
    } elseif ($action === 'delete_scan_schedule') {
        require_write_access();
        $id = to_int($_POST['id'] ?? 0);
        ipam_scan_schedule_delete($db, $id);
        flash_set('Scan schedule removed.');
        header('Location: scan_history.php?subnet_id=' . $id);
        exit;
    }

    return [
        'err'            => $err,
        'msg'            => $msg,
        'warn'           => $warn,
        'overlapWarning' => $overlapWarning,
        'pendingAction'  => $pendingAction,
        'pendingData'    => $pendingData,
    ];
}

$err = '';
$msg = '';
$warn = '';
$overlapWarning = '';
$pendingAction = '';
$pendingData = [];

// Flash warnings are now rendered by page_header() via flash_get()

$st = $db->prepare("SELECT id, name, parent_id FROM sites ORDER BY name ASC");
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

/** @var list<array<string, mixed>> $vrfList */
$vrfList = ($db->query("SELECT id, name FROM vrfs ORDER BY name ASC") ?: throw new \RuntimeException('Query failed'))->fetchAll();

$_cSt = $db->query("SELECT id, name, email FROM contacts ORDER BY name");
/** @var list<array<string, mixed>> $contactList */
$contactList = $_cSt !== false ? $_cSt->fetchAll() : [];

// #1138: tag picker on subnet add/edit drawer (WR-04). Loaded once for the
// page so every row's edit button can render the same shared multi-select.
$_tagSt = $db->query("SELECT id, name, colour FROM tags ORDER BY name");
/** @var list<array<string, mixed>> $tagList */
$tagList = $_tagSt !== false ? $_tagSt->fetchAll() : [];

// POST controller — extracted to subnets_handle_post() (v3.30.0 Task 8.3 #919).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tagIdsKnown = array_map(fn($t) => to_int($t['id']), $tagList);
    $postResult = subnets_handle_post($db, $config, $vlanMap, $siteMap, $tagIdsKnown);
    $err            = $postResult['err'];
    $msg            = $postResult['msg'];
    $warn           = $postResult['warn'];
    $overlapWarning = $postResult['overlapWarning'];
    $pendingAction  = $postResult['pendingAction'];
    $pendingData    = $postResult['pendingData'];
}

$st = $db->prepare("
    SELECT s.id, s.cidr, s.ip_version, s.network, s.network_bin, s.prefix, s.description, s.notes, s.updated_at, s.site_id, s.vlan_id, s.vlan_fk, s.vrf_id, s.alerts_enabled,
           s.custom_fields,
           s.dhcp_routers, s.dhcp_dns_servers, s.dhcp_domain_name,
           s.dhcp_lease_default, s.dhcp_lease_max, s.dhcp_next_server, s.dhcp_boot_filename,
           v.name AS vlan_name, vr.name AS vrf_name,
           ss.method AS scan_method, ss.tcp_port AS scan_tcp_port,
           ss.interval_minutes AS scan_interval, ss.is_active AS scan_active,
           ss.last_run_at AS scan_last_run_at
    FROM subnets s
    LEFT JOIN vlans v ON v.id = s.vlan_fk
    LEFT JOIN vrfs vr ON vr.id = s.vrf_id
    LEFT JOIN scan_schedules ss ON ss.subnet_id = s.id
    ORDER BY s.ip_version ASC, s.prefix ASC, s.network_bin ASC
");
$st->execute();
/** @var list<array<string, mixed>> $list */
$list = $st->fetchAll();

/** @var list<array<string,mixed>> $subnetCfDefs */
$subnetCfDefs = custom_field_def_list($db, 'subnet');

// Tree building is fast (O(N log N)); counts + utilization loaded async via JS (#565)
$tree = build_subnet_tree($list);
$direct = [];
$agg = [];
$ipv4Unassigned = [];
$ipv4UnassignedAgg = [];

$siteGroups = [];
foreach ($tree['roots'] as $rid) {
    $siteId = to_int($tree['byId'][$rid]['site_id'] ?? 0);
    $key = $siteId > 0 ? (string)$siteId : 'ungrouped';
    $label = $siteId > 0 ? ($siteMap[$siteId] ?? "Site #$siteId") : 'Ungrouped';
    $siteGroups[$key] ??= ['label' => $label, 'roots' => []];
    $siteGroups[$key]['roots'][] = $rid;
}
uasort($siteGroups, fn($a, $b) => strcasecmp($a['label'], $b['label']));

// Build site hierarchy for the filter strip (#629).
// siteById: id => ['id', 'name', 'parent_id', 'children' => [id,...]]
$siteById = [];
foreach ($siteList as $s) {
    $siteById[to_int($s['id'])] = ['id' => to_int($s['id']), 'name' => to_str($s['name']), 'parent_id' => to_int($s['parent_id'] ?? 0), 'children' => []];
}
foreach ($siteById as $sid => $_sv) {
    $pid = $siteById[$sid]['parent_id'];
    if ($pid > 0 && isset($siteById[$pid])) {
        $siteById[$pid]['children'][] = $sid;
    }
}
// Collect site IDs that actually have subnets in the tree (any depth)
$usedSiteIds = [];
foreach ($tree['byId'] as $sn) {
    $snSite = to_int($sn['site_id'] ?? 0);
    if ($snSite > 0) $usedSiteIds[$snSite] = true;
}
// Regions: parent sites. A site is a region if it has children that have used sites,
// or if it itself is a parent_id of any siteById entry.
$filterRegions = [];  // region_id => ['name', 'children' => [child_id,...] that are used]
$filterFlat    = [];  // site_id => name (sites with no parent, directly used)
foreach ($siteById as $sid => $sv) {
    if ($sv['parent_id'] > 0) continue; // skip child sites in this pass
    $usedChildren = array_filter($sv['children'], fn($cid) => isset($usedSiteIds[$cid]));
    $selfUsed = isset($usedSiteIds[$sid]);
    if ($sv['children'] !== []) {
        if (!empty($usedChildren) || $selfUsed) {
            $filterRegions[$sid] = ['name' => $sv['name'], 'children' => array_values($usedChildren), 'self_used' => $selfUsed];
        }
    } elseif ($selfUsed) {
        $filterFlat[$sid] = $sv['name'];
    }
}
// Count of distinct used site IDs that would appear in strip (regions + flat sites)
$stripSiteCount = count($filterFlat);
foreach ($filterRegions as $r) {
    if ($r['self_used']) $stripSiteCount++;
    $stripSiteCount += count($r['children']);
}
// Strip renders only when there are 2+ distinct used sites
$showFilterStrip = $stripSiteCount >= 2;

/**
 * @param array{byId: array<int, array<string, mixed>>, children: array<int, list<int>>} $tree
 * @param list<int> $roots
 * @param array{0: int} &$count
 */
function render_subnet_map_nodes(array $tree, array $roots, int $depth, array &$count): void
{
    foreach ($roots as $id) {
        if ($count[0] >= 200) {
            if ($count[0] === 200) {
                echo "<div class='map-node map-cap'>More than 200 nodes — remaining nodes not shown.</div>";
            }
            $count[0]++;
            continue;
        }
        $count[0]++;
        /** @var array<string, mixed> $row */
        $row = $tree['byId'][$id];
        $indent = $depth * 22;
        echo "<div class='map-node' data-indent='{$indent}'>";
        echo "<div class='map-node-inner' data-indent='{$indent}'>";
        echo "<a class='map-cidr' href='addresses.php?subnet_id=" . to_int($row['id']) . "'>" . e(to_str($row['cidr'])) . "</a>";
        if (to_str($row['description']) !== '') echo " <span class='map-desc muted'>" . e(to_str($row['description'])) . "</span>";
        echo "<span class='map-util' data-map-util='" . $id . "'><span class='util-bar'><span class='util-bar-fill' data-pct='0'></span></span><span class='map-pct muted'>—</span></span>";
        echo "</div>";
        echo "</div>";
        $children = $tree['children'][$id] ?? [];
        if ($children !== []) {
            render_subnet_map_nodes($tree, $children, $depth + 1, $count);
        }
    }
}

/**
 * @param array{byId: array<int, array<string, mixed>>, children: array<int, list<int>>} $tree
 * @param array<int, string> $siteMap
 * @param array<int, array<string, mixed>> $siteList
 * @param list<array<string, mixed>> $vlanList
 * @param list<array<string, mixed>> $vrfList
 */
function render_subnet_node_local(PDO $db, array $tree, array $siteMap, array $siteList, array $vlanList, array $vrfList, int $id, int $depth = 0): void
{
    $row = $tree['byId'][$id];
    $pad = $depth * 28;
    $siteName = '';
    $siteId = to_int($row['site_id'] ?? 0);
    if ($siteId > 0) $siteName = $siteMap[$siteId] ?? '';

    echo "<div class='subnet-node card' data-indent='{$pad}' data-site-id='" . ($siteId > 0 ? $siteId : '0') . "'>";
    echo "<details " . ($depth < 1 ? "open" : "") . ">";
    echo "<summary>";
    echo "<b><a href='addresses.php?subnet_id=" . to_int($row['id']) . "'>" . e(to_str($row['cidr'])) . "</a></b> ";
    echo "<span class='muted'>(v" . to_int($row['ip_version']) . ")</span> ";
    if ($siteName !== '') echo " <span class='badge'>" . e($siteName) . "</span>";
    $vlanFkVal = to_int($row['vlan_fk'] ?? 0);
    $vlanLabel = '';
    if ($vlanFkVal > 0 && !empty($row['vlan_name'])) {
        $vlanLabel = to_int($row['vlan_id']) . ' — ' . to_str($row['vlan_name']);
    } elseif (!empty($row['vlan_id'])) {
        $vlanLabel = 'VLAN ' . to_int($row['vlan_id']);
    }
    if ($vlanLabel !== '') echo " <span class='badge'>" . e($vlanLabel) . "</span>";
    $vrfName = to_str($row['vrf_name'] ?? '');
    if ($vrfName !== '') echo " <span class='badge'>VRF: " . e($vrfName) . "</span>";
    if (!to_int($row['alerts_enabled'] ?? 1)) {
        echo " <span class='badge badge-muted' title='Utilization alerts disabled'>"
           . "<svg viewBox='0 0 24 24' width='12' height='12' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true' style='vertical-align:-1px'>"
           . "<path d='M13.73 21a2 2 0 0 1-3.46 0'/>"
           . "<path d='M18.63 13A17.89 17.89 0 0 1 18 8'/>"
           . "<path d='M6.26 6.26A5.86 5.86 0 0 0 6 8c0 7-3 9-3 9h14'/>"
           . "<path d='M18 8a6 6 0 0 0-9.33-5'/>"
           . "<line x1='1' y1='1' x2='23' y2='23'/>"
           . "</svg> Alerts off</span>";
    }
    if (($row['description'] ?? '') !== '') echo " - " . e(to_str($row['description']));
    $hasChildren = !empty($tree['children'][$id]);
    echo "<br><span data-subnet-counts='" . $id . "' data-has-children='" . ($hasChildren ? '1' : '0') . "' class='subnet-stats-placeholder'></span>";
    if (to_int($row['ip_version']) === 4) {
        echo "<br><span data-subnet-util='" . $id . "' data-has-children='" . ($hasChildren ? '1' : '0') . "' class='subnet-stats-placeholder'></span>";
    }
    echo "</summary>";

    echo "<div class='mt-10'>";
    echo "<div class='page-actions mb-10'>";
    if (current_user()['role'] !== 'readonly') {
        echo "<button type='button' class='action-pill subnet-edit-btn'"
           . " data-sid='" . to_int($row['id']) . "'"
           . " data-cidr='" . e(to_str($row['cidr'])) . "'"
           . " data-description='" . e(to_str($row['description'])) . "'"
           . " data-notes='" . e(to_str($row['notes'] ?? '')) . "'"
           . " data-vlan-fk='" . to_int($row['vlan_fk'] ?? 0) . "'"
           . " data-vrf-id='" . to_int($row['vrf_id'] ?? 0) . "'"
           . " data-site-id='" . $siteId . "'"
           . " data-depth='" . $depth . "'"
           . " data-contacts='" . e(json_encode(get_contacts_for_entity($db, 'subnet', to_int($row['id'])), JSON_UNESCAPED_SLASHES) ?: '[]') . "'"
           . " data-alerts-enabled='" . (to_int($row['alerts_enabled'] ?? 1) ? '1' : '0') . "'"
           . " data-dhcp-routers='" . e(to_str($row['dhcp_routers'] ?? '')) . "'"
           . " data-dhcp-dns-servers='" . e(to_str($row['dhcp_dns_servers'] ?? '')) . "'"
           . " data-dhcp-domain-name='" . e(to_str($row['dhcp_domain_name'] ?? '')) . "'"
           . " data-dhcp-lease-default='" . e(to_str($row['dhcp_lease_default'] ?? '')) . "'"
           . " data-dhcp-lease-max='" . e(to_str($row['dhcp_lease_max'] ?? '')) . "'"
           . " data-dhcp-next-server='" . e(to_str($row['dhcp_next_server'] ?? '')) . "'"
           . " data-dhcp-boot-filename='" . e(to_str($row['dhcp_boot_filename'] ?? '')) . "'"
           . " data-custom-fields='" . e(to_str($row['custom_fields'] ?? '{}')) . "'"
           . " data-tag-ids='" . e(json_encode(array_map('intval', array_column(get_tags_for_entity($db, 'subnet', to_int($row['id'])), 'id'))) ?: '[]') . "'"
           . ">Edit</button>";
    }
    echo "<a class='action-pill' href='addresses.php?subnet_id=" . to_int($row['id']) . "'>View Addresses</a>";
    if (to_int($row['ip_version']) === 4) {
        echo "<a class='action-pill' href='unassigned.php?subnet_id=" . to_int($row['id']) . "'>Unassigned</a>";
    }
    $hasSched   = $row['scan_method'] !== null;
    $scanActive = (bool)($row['scan_active'] ?? false);
    $schedBadge = $hasSched
        ? ($scanActive ? " <span class='badge badge--success'>Active</span>" : " <span class='badge'>Inactive</span>")
        : '';
    echo "<a href='scan_history.php?subnet_id=" . to_int($row['id']) . "' class='action-pill'>Scan History &amp; Schedule" . $schedBadge . "</a>";
    if (current_user()['role'] !== 'readonly') {
        echo "<a class='action-pill' href='bulk_update.php?subnet_id=" . to_int($row['id']) . "'>Bulk Update</a>";
        if (to_int($row['ip_version']) === 4) {
            echo "<a class='action-pill' href='dhcp_pool.php?subnet_id=" . to_int($row['id']) . "'>DHCP Pool</a>";
        }
    }
    echo "</div>";

    $subnetContacts = render_contact_badges($db, 'subnet', to_int($row['id']));
    if ($subnetContacts) echo "<div class='mt-4'>" . $subnetContacts . "</div>";
    echo "<div class='muted'>Updated " . e(ipam_format_datetime(to_str($row['updated_at']))) . "</div>";

    if (current_user()['role'] === 'readonly') {
        echo "<p class='muted'>Read-only account.</p>";
    }

    foreach (($tree['children'][$id] ?? []) as $cid) {
        render_subnet_node_local($db, $tree, $siteMap, $siteList, $vlanList, $vrfList, (int)$cid, $depth + 1);
    }

    echo "</div></details></div>";
}

page_header('Subnets');
ipam_skeleton_flush();
?>

<div class="breadcrumbs">
  <a href="dashboard.php"><?= icon('home') ?> Dashboard</a><span class="sep">›</span><span><?= icon('server-stack') ?> Subnets</span>
</div>

<div class="toolbar">
  <div>
    <h1>Subnets</h1>
    <div class="muted">Grouped by site. Use the action links under each subnet to jump to related workflows.</div>
  </div>
</div>

<div class="page-actions">
  <?php if (current_user()['role'] !== 'readonly'): ?>
    <button class="action-pill" data-drawer-title="Add Subnet" data-drawer-tpl="tpl-add-subnet"><?= icon('plus') ?> Add Subnet</button>
  <?php endif; ?>
  <a class="action-pill" href="search.php"><?= icon('magnifying-glass') ?> Search Addresses</a>
  <a class="action-pill" href="export_subnets.php"><?= icon('download') ?> Export CSV</a>
  <?php if (current_user()['role'] === 'admin'): ?>
    <a class="action-pill" href="sites.php"><?= icon('building') ?> Manage Sites</a>
  <?php endif; ?>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>
<?php if ($warn): ?><p class="warning"><?= e($warn) ?></p><?php endif; ?>

<?php if (!empty($overlapWarning) && !empty($pendingAction) && !empty($pendingData)): ?>
  <div class="card mt-16 warning card--warn">
    <h2>⚠ Overlap Warning</h2>
    <p><?= e($overlapWarning) ?></p>
    <form method="post" action="subnets.php" class="d-inline">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="<?= e($pendingAction) ?>">
      <?php if (($pendingData['id'] ?? 0) > 0): ?>
        <input type="hidden" name="id" value="<?= to_int($pendingData['id']) ?>">
      <?php endif; ?>
      <input type="hidden" name="cidr" value="<?= e(to_str($pendingData['cidr'])) ?>">
      <input type="hidden" name="description" value="<?= e(to_str($pendingData['description'])) ?>">
      <input type="hidden" name="notes" value="<?= e(to_str($pendingData['notes'] ?? '')) ?>">
      <input type="hidden" name="site_id" value="<?= to_int($pendingData['site_id']) ?>">
      <input type="hidden" name="vlan_fk" value="<?= to_int($pendingData['vlan_fk'] ?? 0) ?>">
      <input type="hidden" name="vrf_id" value="<?= to_int($pendingData['vrf_id'] ?? 0) ?>">
      <input type="hidden" name="alerts_enabled" value="<?= to_int($pendingData['alerts_enabled'] ?? 1) ?>">
      <?php foreach (['dhcp_routers','dhcp_dns_servers','dhcp_domain_name','dhcp_next_server','dhcp_boot_filename'] as $_dhcpKey): ?>
        <?php if (isset($pendingData[$_dhcpKey])): ?>
          <input type="hidden" name="<?= e($_dhcpKey) ?>" value="<?= e(to_str($pendingData[$_dhcpKey])) ?>">
        <?php endif; ?>
      <?php endforeach; ?>
      <?php foreach (['dhcp_lease_default','dhcp_lease_max'] as $_dhcpKey): ?>
        <?php if (isset($pendingData[$_dhcpKey])): ?>
          <input type="hidden" name="<?= e($_dhcpKey) ?>" value="<?= to_int($pendingData[$_dhcpKey]) ?>">
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (isset($pendingData['auto_reserve'])): ?>
        <input type="hidden" name="auto_reserve" value="<?= to_int($pendingData['auto_reserve']) ?>">
        <input type="hidden" name="gateway" value="<?= e(to_str($pendingData['gateway'] ?? '')) ?>">
      <?php endif; ?>
      <input type="hidden" name="confirm_overlap" value="1">
      <?php if (!empty($pendingData['contacts'])):
        $pContacts = json_decode(to_str($pendingData['contacts']), true);
        if (is_array($pContacts)):
          echo '<input type="hidden" name="contact_id_present" value="1">';
          /** @var array{contact_id: int, role: string} $pc */
          foreach ($pContacts as $pc):
            echo '<input type="hidden" name="contact_id[]" value="' . to_int($pc['contact_id']) . '">';
            echo '<input type="hidden" name="contact_role[]" value="' . e(to_str($pc['role'])) . '">';
          endforeach;
        endif;
      endif; ?>
      <?php // #1138: replay tag picks through overlap-confirm. For 'create',
            // tag_ids is unconditionally a list (possibly empty); for 'update',
            // we only emit tag_ids[] + tag_ids_present if the user's submission
            // had the present-marker (preserves "no tag UI rendered" semantics). ?>
      <?php if (isset($pendingData['tag_ids']) && is_array($pendingData['tag_ids'])): ?>
        <?php if ($pendingAction === 'update'): ?>
          <?php if (!empty($pendingData['tag_ids_present'])): ?>
            <input type="hidden" name="tag_ids_present" value="1">
            <input type="hidden" name="tag_ids[]" value="">
            <?php foreach ($pendingData['tag_ids'] as $_tid): ?>
              <input type="hidden" name="tag_ids[]" value="<?= to_int($_tid) ?>">
            <?php endforeach; ?>
          <?php endif; ?>
        <?php else: ?>
          <input type="hidden" name="tag_ids[]" value="">
          <?php foreach ($pendingData['tag_ids'] as $_tid): ?>
            <input type="hidden" name="tag_ids[]" value="<?= to_int($_tid) ?>">
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>
      <button type="submit">Save anyway</button>
      <a class="action-pill" href="subnets.php">Cancel</a>
    </form>
  </div>
<?php endif; ?>

<div id="tpl-add-subnet" style="display:none"><?= ipam_render_string('subnet_form', [
    'vlanList'     => $vlanList,
    'vrfList'      => $vrfList,
    'siteList'     => $siteList,
    'subnetCfDefs' => $subnetCfDefs,
    'contactList'  => $contactList,
    'tagList'      => $tagList,
]) ?></div>

<?php if ($showFilterStrip): ?>
<div id="site-filter-strip" class="site-filter-strip" role="group" aria-label="Filter by site">
  <button type="button" class="site-filter-pill site-filter-pill--active" data-filter-site="all" aria-pressed="true">All sites</button>
  <?php foreach ($filterRegions as $rid => $region): ?>
    <?php $regionId = to_int($rid); ?>
    <div class="site-filter-region" data-region-id="<?= $regionId ?>">
      <button type="button"
              class="site-filter-pill site-filter-pill--region"
              data-filter-site="region:<?= $regionId ?>"
              aria-pressed="false"
              aria-expanded="true"
              data-region-toggle="<?= $regionId ?>">
        <?= e($region['name']) ?><span class="site-filter-caret" aria-hidden="true">&#9660;</span>
      </button>
      <div class="site-filter-region-children" data-region-children="<?= $regionId ?>">
        <?php if ($region['self_used']): ?>
          <button type="button" class="site-filter-pill site-filter-pill--child" data-filter-site="<?= $regionId ?>" aria-pressed="false"><?= e($region['name']) ?> (all)</button>
        <?php endif; ?>
        <?php foreach ($region['children'] as $cid): ?>
          <?php $childId = to_int($cid); if (!isset($siteById[$childId])) continue; ?>
          <button type="button" class="site-filter-pill site-filter-pill--child" data-filter-site="<?= $childId ?>" aria-pressed="false"><?= e($siteById[$childId]['name']) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php foreach ($filterFlat as $fid => $fname): ?>
    <button type="button" class="site-filter-pill" data-filter-site="<?= to_int($fid) ?>" aria-pressed="false"><?= e($fname) ?></button>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card mt-16">
  <div class="toolbar mb-8">
    <h2 class="mb-0">Grouped Hierarchy</h2>
    <div>
      <button class="action-pill subnet-view-btn" data-view="list" id="btn-view-list">&#9776; List</button>
      <button class="action-pill subnet-view-btn" data-view="map"  id="btn-view-map">&#9644; Map</button>
    </div>
  </div>

  <?php if (empty($siteGroups)): ?>
    <div class="empty-state">No subnets yet. <button class="action-pill" data-drawer-title="Add Subnet" data-drawer-tpl="tpl-add-subnet">+ Add Subnet</button></div>
  <?php else: ?>
    <!-- List view -->
    <div id="subnet-list-view">
    <?php foreach ($siteGroups as $key => $group): ?>
      <div class="site-group mb-24">
        <button type="button" class="site-group-toggle" aria-expanded="true" data-sg-key="<?= e((string)$key) ?>">
          <?= e(to_str($group['label'])) ?><span class="site-group-caret" aria-hidden="true">&#9660;</span>
        </button>
        <?php if ($key !== 'ungrouped'): ?>
          <?= render_contact_badges($db, 'site', to_int($key)) ?>
        <?php endif; ?>
        <div class="site-group-body">
          <?php foreach ($group['roots'] as $rid): ?>
            <?php render_subnet_node_local($db, $tree, $siteMap, $siteList, $vlanList, $vrfList, (int)$rid, 0); ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>

    <!-- Map view (#255) -->
    <div id="subnet-map-view" hidden>
    <?php $mapCount = [0]; foreach ($siteGroups as $group): ?>
      <div class="map-group mb-24">
        <div class="map-group-label"><?= e(to_str($group['label'])) ?></div>
        <?php render_subnet_map_nodes($tree, array_map('intval', $group['roots']), 0, $mapCount); ?>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Shared subnet edit drawer (#567) -->
<?php if (current_user()['role'] !== 'readonly'): ?>
<div id="subnet-edit-drawer" hidden>
  <h3 id="subnet-edit-title">Edit Subnet</h3>
  <form method="post" action="subnets.php" id="subnet-edit-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="confirm_overlap" value="1">
    <input type="hidden" name="id" id="subnet-edit-id" value="">
    <label>CIDR<br><input name="cidr" id="subnet-edit-cidr" required></label>
    <label>Description<br><input name="description" id="subnet-edit-description"></label>
    <label class="subnet-notes-edit">Notes<br><textarea name="notes" id="subnet-edit-notes" rows="4" placeholder="Long-form operational notes, runbook links, ownership context…"></textarea></label>
    <?php if ($vlanList): ?>
    <label>VLAN<br><select name="vlan_fk" id="subnet-edit-vlan">
      <option value="0">(none)</option>
      <?php foreach ($vlanList as $vl): ?>
        <option value="<?= to_int($vl['id']) ?>"><?= to_int($vl['vlan_id']) ?> — <?= e(to_str($vl['name'])) ?></option>
      <?php endforeach; ?>
    </select></label>
    <?php endif; ?>
    <?php if ($vrfList): ?>
    <label>VRF<br><select name="vrf_id" id="subnet-edit-vrf">
      <option value="0">(global)</option>
      <?php foreach ($vrfList as $vr): ?>
        <option value="<?= to_int($vr['id']) ?>"><?= e(to_str($vr['name'])) ?></option>
      <?php endforeach; ?>
    </select></label>
    <?php endif; ?>
    <div id="subnet-edit-site-wrap">
      <label>Site<br><select name="site_id" id="subnet-edit-site">
        <option value="0">(none)</option>
        <?php foreach ($siteList as $s): ?>
          <option value="<?= to_int($s['id']) ?>"><?= e(to_str($s['name'])) ?></option>
        <?php endforeach; ?>
      </select></label>
    </div>
    <div id="subnet-edit-site-locked" hidden>
      <input type="hidden" name="site_id" id="subnet-edit-site-hidden" value="" disabled>
      <label>Site<br><span class="badge" id="subnet-edit-site-badge"></span></label>
    </div>
    <?php if ($contactList): ?>
    <input type="hidden" name="contact_id_present" value="1">
    <div class="contact-picker" id="subnet-edit-contacts" data-contacts='<?= e(json_encode(array_map(fn($c) => ['id' => to_int($c['id']), 'name' => to_str($c['name']), 'email' => to_str($c['email'])], $contactList), JSON_UNESCAPED_SLASHES) ?: '[]') ?>' data-existing='[]'>
      <label>Contacts</label>
      <div class="contact-picker-rows"></div>
      <button type="button" class="button-secondary btn-sm contact-picker-add">+ Add contact</button>
    </div>
    <?php endif; ?>
    <label class="form-check" style="margin-top:0.5rem;">
      <input type="checkbox" name="alerts_enabled" value="1" id="subnet-edit-alerts" checked>
      Send utilization alerts for this subnet
    </label>
    <details class="dhcp-options-group" style="margin-top:1rem;">
      <summary style="cursor:pointer;font-weight:600;">DHCP Options <span class="muted font-xs">(optional — IPv4 only)</span></summary>
      <div style="margin-top:0.5rem;display:flex;flex-direction:column;gap:0.5rem;">
        <label>Default gateway(s) <span class="muted font-xs">comma-separated IPs</span><br>
          <input name="dhcp_routers" id="subnet-edit-dhcp-routers" placeholder="e.g. 10.0.0.1"></label>
        <label>DNS server(s) <span class="muted font-xs">comma-separated IPs</span><br>
          <input name="dhcp_dns_servers" id="subnet-edit-dhcp-dns-servers" placeholder="e.g. 8.8.8.8, 8.8.4.4"></label>
        <label>Domain name<br>
          <input name="dhcp_domain_name" id="subnet-edit-dhcp-domain-name" placeholder="e.g. example.com"></label>
        <div style="display:flex;gap:0.75rem;">
          <label style="flex:1">Default lease <span class="muted font-xs">seconds</span><br>
            <input name="dhcp_lease_default" id="subnet-edit-dhcp-lease-default" type="number" min="60" placeholder="e.g. 3600"></label>
          <label style="flex:1">Max lease <span class="muted font-xs">seconds</span><br>
            <input name="dhcp_lease_max" id="subnet-edit-dhcp-lease-max" type="number" min="60" placeholder="e.g. 86400"></label>
        </div>
        <label>TFTP next-server <span class="muted font-xs">PXE boot</span><br>
          <input name="dhcp_next_server" id="subnet-edit-dhcp-next-server" placeholder="e.g. 10.0.0.1"></label>
        <label>Boot filename <span class="muted font-xs">PXE boot</span><br>
          <input name="dhcp_boot_filename" id="subnet-edit-dhcp-boot-filename" placeholder="e.g. pxelinux.0"></label>
      </div>
    </details>

    <?php // #1138: tag picker on subnet edit drawer (WR-04). ?>
    <?php if ($tagList): ?>
    <label>Tags <span class="muted font-xs">Cmd/Ctrl-click to toggle</span><br>
      <input type="hidden" name="tag_ids_present" value="1">
      <input type="hidden" name="tag_ids[]" value="">
      <select name="tag_ids[]" id="subnet-edit-tag-ids" multiple size="<?= min(6, max(3, count($tagList))) ?>" class="w-full">
        <?php foreach ($tagList as $t): ?>
          <option value="<?= to_int($t['id']) ?>"><?= e(to_str($t['name'])) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <?php if ($subnetCfDefs): ?>
    <div id="subnet-edit-cf-inputs">
      <?= render_custom_field_inputs($subnetCfDefs, []) ?>
    </div>
    <?php endif; ?>
    <button type="submit">Save</button>
  </form>
  <form method="post" action="subnets.php" data-confirm="Delete subnet and all its addresses?" class="mt-8" id="subnet-delete-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="subnet-delete-id" value="">
    <button type="submit" class="button-danger">Delete</button>
  </form>
</div>
<?php endif; ?>

<?php ipam_skeleton_remove(); page_footer();
