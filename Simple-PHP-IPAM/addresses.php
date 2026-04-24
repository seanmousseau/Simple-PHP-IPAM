<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var array<string, mixed> $config */
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
}

$err = '';
$msg = '';

$st = $db->prepare("SELECT id, cidr, network, prefix, ip_version, site_id, description FROM subnets ORDER BY ip_version ASC, cidr ASC");
$st->execute();
/** @var list<array<string, mixed>> $subnetList */
$subnetList = $st->fetchAll();

/** @var list<array<string, mixed>> $addrSiteList */
$addrSiteList = ($db->query("SELECT id, name FROM sites ORDER BY name ASC") ?: throw new \RuntimeException('Query failed'))->fetchAll();
$addrSiteIds = array_filter(array_column($subnetList, 'site_id'), fn($v) => is_int($v) || (is_string($v) && $v !== ''));
$addrDistinctSiteCount = count(array_unique(array_map(fn($v) => (int)$v, array_values($addrSiteIds))));

$selectedSubnetId = to_int($_GET['subnet_id'] ?? ($_POST['subnet_id'] ?? 0));
$highlightId = to_int($_GET['highlight'] ?? 0);
$page = q_int('page', 1, 1, 1000000);
$pageSize = q_int('page_size', 254, 1, 500);

$addrSortCols = ['ip' => 'ip_bin', 'hostname' => 'hostname', 'owner' => 'owner',
                 'status' => 'status', 'updated' => 'updated_at'];
$addrSort = parse_sort($addrSortCols, 'ip');

$filterType = to_str($_GET['filter'] ?? '');
$filterDays = max(1, min(365, to_int($_GET['days'] ?? 30)));
$filterWhere = '';
$filterParams = [];
if ($filterType === 'expired') {
    $filterWhere = " AND (a.expires_at IS NOT NULL AND a.expires_at < :flt_today)";
    $filterParams[':flt_today'] = date('Y-m-d');
} elseif ($filterType === 'expiring') {
    $filterWhere = " AND (a.expires_at IS NOT NULL AND a.expires_at >= :flt_from AND a.expires_at < :flt_to)";
    $filterParams[':flt_from'] = date('Y-m-d');
    $filterParams[':flt_to']   = date('Y-m-d', (int)strtotime("+{$filterDays} days"));
}

$selectedSubnet = null;
if ($selectedSubnetId > 0) {
    $st = $db->prepare("SELECT id, cidr, network, prefix, ip_version, site_id, description, notes FROM subnets WHERE id = :id");
    $st->execute([':id' => $selectedSubnetId]);
    /** @var array<string, mixed>|false $selRow */
    $selRow = $st->fetch();
    $selectedSubnet = $selRow ?: null;
}
$preselectSiteId = to_int($selectedSubnet['site_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = to_str($_POST['action'] ?? '');

    if ($action === 'create') {
        require_write_access();

        $subnetId = to_int($_POST['subnet_id'] ?? 0);
        $ipInput = trim(to_str($_POST['ip'] ?? ''));

        $hostname        = substr(trim(to_str($_POST['hostname']        ?? '')), 0, 253);
        $owner           = substr(trim(to_str($_POST['owner']          ?? '')), 0, 255);
        $note            = substr(trim(to_str($_POST['note']           ?? '')), 0, 1000);
        $grp             = substr(trim(to_str($_POST['grp']            ?? '')), 0, 100);
        $mac             = substr(trim(to_str($_POST['mac']            ?? '')), 0, 64);
        $ownerContactId  = to_int($_POST['owner_contact_id'] ?? 0) ?: null;
        if ($ownerContactId !== null) {
            $cck = $db->prepare("SELECT id FROM contacts WHERE id = :id");
            $cck->execute([':id' => $ownerContactId]);
            if (!$cck->fetch()) $ownerContactId = null;
        }
        $deviceId    = to_int($_POST['device_id']    ?? 0) ?: null;
        $interfaceId = to_int($_POST['interface_id'] ?? 0) ?: null;
        if ($deviceId === null) $interfaceId = null;
        if ($deviceId !== null) {
            $devchk = $db->prepare("SELECT id FROM devices WHERE id=:id");
            $devchk->execute([':id' => $deviceId]);
            if (!$devchk->fetch()) { $deviceId = null; $interfaceId = null; }
        }
        if ($interfaceId !== null) {
            $dchk = $db->prepare("SELECT id FROM device_interfaces WHERE id=:id AND device_id=:did");
            $dchk->execute([':id' => $interfaceId, ':did' => $deviceId]);
            if (!$dchk->fetch()) $interfaceId = null;
        }
        $expiresAt = trim(to_str($_POST['expires_at'] ?? ''));
        if ($expiresAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
            $expiresAt = '';
        }
        $status = to_str($_POST['status'] ?? 'used');

        // Custom fields
        $cfAddrDefs = custom_field_def_list($db, 'address');
        $cfAddrValues = [];
        if ($cfAddrDefs) {
            $cfPayload = [];
            foreach ($_POST as $k => $v) {
                if (is_string($k) && str_starts_with($k, 'cf_')) $cfPayload[substr($k, 3)] = to_str($v);
            }
            try {
                $cfAddrValues = validate_custom_fields_payload($cfAddrDefs, $cfPayload);
            } catch (\InvalidArgumentException $cfEx) {
                $err = 'Custom field error: ' . $cfEx->getMessage();
            }
        }

        $st = $db->prepare("SELECT id, network, prefix, ip_version FROM subnets WHERE id = :id");
        $st->execute([':id' => $subnetId]);
        /** @var array<string, mixed>|false $sub */
        $sub = $st->fetch();

        if (!$sub) {
            $err = 'Invalid subnet.';
        } else {
            $norm = normalize_ip($ipInput);
            if (!$norm) {
                $err = 'Invalid IP (IPv4/IPv6).';
            } elseif (to_int($sub['ip_version']) !== to_int($norm['version'])) {
                $err = 'IP version does not match subnet.';
            } elseif (!ip_in_cidr($norm['ip'], to_str($sub['network']), to_int($sub['prefix']))) {
                $err = 'IP is not within selected subnet.';
            } elseif (!in_array($status, ['used','reserved','free'], true)) {
                $err = 'Invalid status.';
            } else {
                try {
                    // #410/#388: bind ip_bin via ipam_bind_binary() (PARAM_LOB)
                    // so the stored value has BLOB affinity on SQLite,
                    // round-trips high bytes correctly through MySQL
                    // VARBINARY, and does not UTF-8-validate on Postgres BYTEA.
                    $ins = $db->prepare("INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, grp, mac, expires_at, status, owner_contact_id, device_id, interface_id, custom_fields)
                                         VALUES (:sid,:ip,:bin,:hn,:ow,:nt,:grp,:mac,:exp,:st,:cid,:did,:iid,:cf)");
                    $ins->bindValue(':sid', $subnetId, PDO::PARAM_INT);
                    $ins->bindValue(':ip',  $norm['ip']);
                    ipam_bind_binary($ins, ':bin', to_str($norm['bin']));
                    $ins->bindValue(':hn',  $hostname);
                    $ins->bindValue(':ow',  $owner);
                    $ins->bindValue(':nt',  $note);
                    $ins->bindValue(':grp', $grp);
                    $ins->bindValue(':mac', $mac);
                    $ins->bindValue(':exp', $expiresAt !== '' ? $expiresAt : null,
                        $expiresAt !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                    $ins->bindValue(':st',  $status);
                    $ins->bindValue(':cid', $ownerContactId,
                        $ownerContactId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $ins->bindValue(':did', $deviceId,
                        $deviceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $ins->bindValue(':iid', $interfaceId,
                        $interfaceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $ins->bindValue(':cf', serialize_custom_fields_row($cfAddrValues));
                    $ins->execute();
                    $aid = ipam_last_insert_id($db, 'addresses');

                    history_log_address($db, 'create', $subnetId, $norm['ip'], $aid, null, [
                        'hostname'        => $hostname,
                        'owner'           => $owner,
                        'note'            => $note,
                        'grp'             => $grp,
                        'mac'             => $mac,
                        'expires_at'      => $expiresAt !== '' ? $expiresAt : null,
                        'status'          => $status,
                        'owner_contact_id' => $ownerContactId,
                        'device_id'       => $deviceId,
                        'interface_id'    => $interfaceId,
                    ]);
                    audit($db, 'address.create', 'address', $aid, "ip={$norm['ip']} subnet_id=$subnetId");
                    ipam_webhook_dispatch($db, 'address.create', ['id' => $aid, 'ip' => $norm['ip'], 'subnet_id' => $subnetId], $config);

                    flash_set('Address created.');
                    header('Location: addresses.php?subnet_id=' . $subnetId);
                    exit;
                } catch (PDOException $e) {
                    $err = str_contains($e->getMessage(), 'UNIQUE')
                        ? 'An address record for this IP already exists in the subnet.'
                        : 'Could not add address. Please try again.';
                }
            }
        }
    } elseif ($action === 'update') {
        require_write_access();

        $id = to_int($_POST['id'] ?? 0);
        $subnetId = to_int($_POST['subnet_id'] ?? 0);
        $hostname       = substr(trim(to_str($_POST['hostname']       ?? '')), 0, 253);
        $owner          = substr(trim(to_str($_POST['owner']         ?? '')), 0, 255);
        $note           = substr(trim(to_str($_POST['note']          ?? '')), 0, 1000);
        $grp            = substr(trim(to_str($_POST['grp']           ?? '')), 0, 100);
        $mac            = substr(trim(to_str($_POST['mac']           ?? '')), 0, 64);
        $ownerContactId = to_int($_POST['owner_contact_id'] ?? 0) ?: null;
        if ($ownerContactId !== null) {
            $cck = $db->prepare("SELECT id FROM contacts WHERE id = :id");
            $cck->execute([':id' => $ownerContactId]);
            if (!$cck->fetch()) $ownerContactId = null;
        }
        $deviceId    = to_int($_POST['device_id']    ?? 0) ?: null;
        $interfaceId = to_int($_POST['interface_id'] ?? 0) ?: null;
        if ($deviceId === null) $interfaceId = null;
        if ($deviceId !== null) {
            $devchk = $db->prepare("SELECT id FROM devices WHERE id=:id");
            $devchk->execute([':id' => $deviceId]);
            if (!$devchk->fetch()) { $deviceId = null; $interfaceId = null; }
        }
        if ($interfaceId !== null) {
            $dchk = $db->prepare("SELECT id FROM device_interfaces WHERE id=:id AND device_id=:did");
            $dchk->execute([':id' => $interfaceId, ':did' => $deviceId]);
            if (!$dchk->fetch()) $interfaceId = null;
        }
        $expiresAt = trim(to_str($_POST['expires_at'] ?? ''));
        if ($expiresAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
            $expiresAt = '';
        }
        $status = to_str($_POST['status'] ?? 'used');

        // Custom fields
        $cfAddrDefs = custom_field_def_list($db, 'address');
        $cfAddrValues = [];
        if ($cfAddrDefs) {
            $cfPayload = [];
            foreach ($_POST as $k => $v) {
                if (is_string($k) && str_starts_with($k, 'cf_')) $cfPayload[substr($k, 3)] = to_str($v);
            }
            try {
                $cfAddrValues = validate_custom_fields_payload($cfAddrDefs, $cfPayload);
            } catch (\InvalidArgumentException $cfEx) {
                $err = 'Custom field error: ' . $cfEx->getMessage();
            }
        }

        if (!in_array($status, ['used','reserved','free'], true)) {
            $err = 'Invalid status.';
        } else {
            $sel = $db->prepare("SELECT id, ip, hostname, owner, note, grp, mac, expires_at, status, owner_contact_id, device_id, interface_id FROM addresses WHERE id=:id AND subnet_id=:sid");
            $sel->execute([':id' => $id, ':sid' => $subnetId]);
            /** @var array<string, mixed>|false $before */
            $before = $sel->fetch();

            if (!$before) {
                $err = 'Address not found.';
            } else {
                $up = $db->prepare("UPDATE addresses
                                    SET hostname=:hn, owner=:ow, note=:nt, grp=:grp, mac=:mac, expires_at=:exp, status=:st, owner_contact_id=:cid, device_id=:did, interface_id=:iid, custom_fields=:cf
                                    WHERE id=:id AND subnet_id=:sid");
                $up->bindValue(':hn',  $hostname);
                $up->bindValue(':ow',  $owner);
                $up->bindValue(':nt',  $note);
                $up->bindValue(':grp', $grp);
                $up->bindValue(':mac', $mac);
                $up->bindValue(':exp', $expiresAt !== '' ? $expiresAt : null,
                    $expiresAt !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $up->bindValue(':st',  $status);
                $up->bindValue(':cid', $ownerContactId,
                    $ownerContactId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $up->bindValue(':did', $deviceId,
                    $deviceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $up->bindValue(':iid', $interfaceId,
                    $interfaceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $up->bindValue(':cf',  serialize_custom_fields_row($cfAddrValues));
                $up->bindValue(':id',  $id,        PDO::PARAM_INT);
                $up->bindValue(':sid', $subnetId,  PDO::PARAM_INT);
                $up->execute();

                history_log_address($db, 'update', $subnetId, to_str($before['ip']), $id,
                    [
                        'hostname'        => to_str($before['hostname']),
                        'owner'           => to_str($before['owner']),
                        'note'            => to_str($before['note']),
                        'grp'             => to_str($before['grp']),
                        'mac'             => to_str($before['mac']),
                        'expires_at'      => isset($before['expires_at']) ? to_str($before['expires_at']) : null,
                        'status'          => to_str($before['status']),
                        'owner_contact_id' => $before['owner_contact_id'] !== null ? to_int($before['owner_contact_id']) : null,
                        'device_id'       => $before['device_id'] !== null ? to_int($before['device_id']) : null,
                        'interface_id'    => $before['interface_id'] !== null ? to_int($before['interface_id']) : null,
                    ],
                    [
                        'hostname'        => $hostname,
                        'owner'           => $owner,
                        'note'            => $note,
                        'grp'             => $grp,
                        'mac'             => $mac,
                        'expires_at'      => $expiresAt !== '' ? $expiresAt : null,
                        'status'          => $status,
                        'owner_contact_id' => $ownerContactId,
                        'device_id'       => $deviceId,
                        'interface_id'    => $interfaceId,
                    ]
                );

                audit($db, 'address.update', 'address', $id, "subnet_id=$subnetId");
                ipam_webhook_dispatch($db, 'address.update', ['id' => $id, 'subnet_id' => $subnetId], $config);
                $msg = 'Address updated.';
            }
        }
    } elseif ($action === 'update_status') {
        // Inline status toggle — JSON response for JS fetch; graceful-degrades on non-JS
        require_write_access();
        $id        = to_int($_POST['id']        ?? 0);
        $subnetId  = to_int($_POST['subnet_id'] ?? 0);
        $newStatus = to_str($_POST['status']    ?? '');
        if (!in_array($newStatus, ['used', 'reserved', 'free'], true) || $id <= 0 || $subnetId <= 0) {
            header('Content-Type: application/json');
            echo '{"ok":false,"error":"Invalid request"}';
            exit;
        }
        $st = $db->prepare("UPDATE addresses SET status=:s, updated_at=" . ipam_dialect()->now() . " WHERE id=:id AND subnet_id=:sid");
        $st->execute([':s' => $newStatus, ':id' => $id, ':sid' => $subnetId]);
        if ($st->rowCount()) {
            audit($db, 'address.update', 'address', $id, "status=$newStatus via inline toggle");
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'status' => $newStatus]); // nosemgrep: php.lang.security.xss,php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag
        exit;
    } elseif ($action === 'update_cell') {
        // Inline cell edit — JSON response; CSRF already verified above
        require_write_access();
        header('Content-Type: application/json');
        $id       = to_int($_POST['id']        ?? 0);
        $subnetId = to_int($_POST['subnet_id'] ?? 0);
        $field    = to_str($_POST['field']     ?? '');
        $value    = to_str($_POST['value']     ?? '');
        $allowed = ['hostname', 'owner', 'note', 'grp'];
        if ($id <= 0 || $subnetId <= 0 || !in_array($field, $allowed, true)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
            exit;
        }
        $maxLen = ['hostname' => 253, 'owner' => 255, 'note' => 1000, 'grp' => 100];
        $value = substr(trim($value), 0, $maxLen[$field]);
        $sel = $db->prepare("SELECT id, ip, hostname, owner, note, grp FROM addresses WHERE id=:id AND subnet_id=:sid");
        $sel->execute([':id' => $id, ':sid' => $subnetId]);
        /** @var array<string, mixed>|false $before */
        $before = $sel->fetch();
        if (!$before) {
            echo json_encode(['ok' => false, 'error' => 'Address not found.']);
            exit;
        }
        // Static SQL per field — no interpolation of user-controlled data
        // Editing 'owner' free-text clears the structured contact link to keep them in sync.
        $updateSql = match ($field) {
            'hostname' => "UPDATE addresses SET hostname=:v, updated_at=" . ipam_dialect()->now() . " WHERE id=:id AND subnet_id=:sid",
            'owner'    => "UPDATE addresses SET owner=:v, owner_contact_id=NULL, updated_at=" . ipam_dialect()->now() . " WHERE id=:id AND subnet_id=:sid",
            'note'     => "UPDATE addresses SET note=:v, updated_at=" . ipam_dialect()->now() . " WHERE id=:id AND subnet_id=:sid",
            'grp'      => "UPDATE addresses SET grp=:v, updated_at=" . ipam_dialect()->now() . " WHERE id=:id AND subnet_id=:sid",
        };
        $db->prepare($updateSql)->execute([':v' => $value, ':id' => $id, ':sid' => $subnetId]);
        $after = array_merge(
            ['hostname' => to_str($before['hostname']), 'owner' => to_str($before['owner']),
             'note'     => to_str($before['note']),     'grp'   => to_str($before['grp'])],
            [$field => $value]
        );
        history_log_address($db, 'update', $subnetId, to_str($before['ip']), $id,
            ['hostname' => to_str($before['hostname']), 'owner' => to_str($before['owner']),
             'note'     => to_str($before['note']),     'grp'   => to_str($before['grp'])],
            $after
        );
        audit($db, 'address.update', 'address', $id, "inline_cell=$field");
        echo json_encode(['ok' => true, 'value' => $value]); // nosemgrep: php.lang.security.xss,php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag
        exit;
    } elseif ($action === 'delete') {
        require_write_access();

        $id = to_int($_POST['id'] ?? 0);
        $subnetId = to_int($_POST['subnet_id'] ?? 0);

        $sel = $db->prepare("SELECT id, ip, hostname, owner, note, grp, mac, expires_at, status FROM addresses WHERE id=:id AND subnet_id=:sid");
        $sel->execute([':id' => $id, ':sid' => $subnetId]);
        /** @var array<string, mixed>|false $before */
        $before = $sel->fetch();

        $del = $db->prepare("DELETE FROM addresses WHERE id = :id AND subnet_id = :sid");
        $del->execute([':id' => $id, ':sid' => $subnetId]);

        if ($before) {
            history_log_address($db, 'delete', $subnetId, to_str($before['ip']), $id,
                [
                    'hostname'   => to_str($before['hostname']),
                    'owner'      => to_str($before['owner']),
                    'note'       => to_str($before['note']),
                    'grp'        => to_str($before['grp']),
                    'mac'        => to_str($before['mac']),
                    'expires_at' => isset($before['expires_at']) ? to_str($before['expires_at']) : null,
                    'status'     => to_str($before['status']),
                ],
                null
            );
        }

        audit($db, 'address.delete', 'address', $id, "subnet_id=$subnetId");
        ipam_webhook_dispatch($db, 'address.delete', ['id' => $id, 'subnet_id' => $subnetId], $config);
        flash_set('Address deleted.');
        header('Location: addresses.php?subnet_id=' . $subnetId);
        exit;
    } elseif ($action === 'reserve_infra') {
        require_write_access();
        $subnetId = to_int($_POST['subnet_id'] ?? 0);
        $st = $db->prepare("SELECT cidr FROM subnets WHERE id = :id");
        $st->execute([':id' => $subnetId]);
        /** @var array<string, mixed>|false $subRow */
        $subRow = $st->fetch();
        if (is_array($subRow)) {
            $gwIp = trim(to_str($_POST['gateway_ip'] ?? ''));
            if ($gwIp === '') $gwIp = null;
            if ($gwIp !== null) {
                $gwNorm = normalize_ip($gwIp);
                $parsed = parse_cidr(to_str($subRow['cidr']));
                if (!$gwNorm || !$parsed || !ip_in_cidr($gwNorm['ip'], $parsed['network'], $parsed['prefix'])) {
                    $gwIp = null;
                    flash_set('Gateway IP is not in this subnet — skipped. Network and broadcast reserved.', 'warning');
                } else {
                    $gwIp = $gwNorm['ip'];
                }
            }
            auto_reserve_subnet_ips($db, $subnetId, to_str($subRow['cidr']), $gwIp);
            if (!isset($_SESSION['flash'])) flash_set('Infrastructure addresses reserved.');
        }
        header('Location: addresses.php?subnet_id=' . $subnetId);
        exit;
    }
}

$addresses = [];
$total = 0;
$p = null;

if ($selectedSubnetId > 0) {
    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM addresses a WHERE a.subnet_id = :sid{$filterWhere}");
    $cntSt->bindValue(':sid', $selectedSubnetId, PDO::PARAM_INT);
    foreach ($filterParams as $k => $v) $cntSt->bindValue($k, $v);
    $cntSt->execute();
    /** @var array<string, mixed>|false $cntRow */

    $cntRow = $cntSt->fetch();

    $total = is_array($cntRow) ? to_int($cntRow['c']) : 0;

    $p = paginate($total, $page, $pageSize);

    $st = $db->prepare("SELECT a.id, a.ip, a.ip_bin, a.hostname, a.owner, a.note, a.grp, a.mac, a.expires_at, a.status, a.updated_at,
                               a.owner_contact_id, c.name AS owner_contact_name, c.email AS owner_contact_email,
                               a.last_seen_at, a.is_stale,
                               a.device_id, a.interface_id, a.custom_fields,
                               dv.name AS device_name, di.name AS interface_name
                        FROM addresses a
                        LEFT JOIN contacts c ON c.id = a.owner_contact_id
                        LEFT JOIN devices dv ON dv.id = a.device_id
                        LEFT JOIN device_interfaces di ON di.id = a.interface_id
                        WHERE a.subnet_id = :sid{$filterWhere}
                        ORDER BY {$addrSort['sql']}
                        LIMIT :lim OFFSET :off");
    $st->bindValue(':sid', $selectedSubnetId, PDO::PARAM_INT);
    foreach ($filterParams as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':lim', $p['limit'], PDO::PARAM_INT);
    $st->bindValue(':off', $p['offset'], PDO::PARAM_INT);
    $st->execute();
    /** @var list<array<string, mixed>> $addresses */
    $addresses = $st->fetchAll();
}

// Compute network/broadcast/gateway bins for badge rendering
$networkBin = null;
$broadcastBin = null;
if ($selectedSubnet) {
    $parsed = parse_cidr(to_str($selectedSubnet['cidr']));
    if ($parsed !== null) {
        $networkBin = $parsed['net_bin'];
        $broadcastBin = ipam_compute_broadcast_bin($parsed['net_bin'], $parsed['prefix']);
    }
}

// Next available IP (IPv4 only, for subnets with room)
$nextAvailableIp = null;
if ($selectedSubnet && to_int($selectedSubnet['ip_version']) === 4) {
    $nextAvailableIp = find_next_available_ipv4($db, $selectedSubnetId,
        to_str($selectedSubnet['network']), to_int($selectedSubnet['prefix']));
}

// Pre-validated IP pre-fill from ?next_ip= query param (used in the Add Address drawer)
$prefillIp = trim(to_str($_GET['next_ip'] ?? ''));

// Check if network/broadcast are missing (for "Reserve infra" button)
$missingInfra = false;
if ($selectedSubnetId > 0 && $networkBin !== null) {
    $infraBins = array_values(array_filter([$networkBin, $broadcastBin]));
    if ($infraBins) {
        $placeholders = implode(',', array_fill(0, count($infraBins), '?'));
        $chk = $db->prepare("SELECT ip_bin FROM addresses WHERE subnet_id = ? AND ip_bin IN ($placeholders)");
        $chk->bindValue(1, $selectedSubnetId, PDO::PARAM_INT);
        foreach ($infraBins as $i => $b) {
            ipam_bind_binary($chk, $i + 2, $b);
        }
        $chk->execute();
        $found = $chk->fetchAll(PDO::FETCH_COLUMN);
        $missingInfra = count($found) < count($infraBins);
    }
}

/** @var list<array<string,mixed>> $addrCfDefs */
$addrCfDefs = custom_field_def_list($db, 'address');

// Devices + interfaces for dropdowns (keyed by device_id for JS)
/** @var list<array<string,mixed>> $deviceList */
$deviceList = ($db->query("SELECT id, name FROM devices ORDER BY name") ?: throw new \RuntimeException('Query failed'))->fetchAll();
/** @var array<int,list<array<string,mixed>>> $ifacesByDeviceId */
$ifacesByDeviceId = [];
if ($deviceList) {
    $dids = array_map(fn($d) => to_int($d['id']), $deviceList);
    $dph  = implode(',', array_fill(0, count($dids), '?'));
    $difs = $db->prepare("SELECT id, device_id, name FROM device_interfaces WHERE device_id IN ($dph) ORDER BY name");
    $difs->execute($dids);
    foreach ($difs->fetchAll() as $dif) {
        $ifacesByDeviceId[to_int($dif['device_id'])][] = ['id' => to_int($dif['id']), 'name' => to_str($dif['name'])];
    }
}

page_header('Addresses', ['page' => 'addresses']);
ipam_skeleton_flush();
?>

<div class="breadcrumbs">
  <a href="dashboard.php">🏠 Dashboard</a>
  <span class="sep">›</span>
  <?php if ($selectedSubnet): ?>
    <a href="subnets.php">🌐 Subnets</a>
    <span class="sep">›</span>
    <span><?= e(to_str($selectedSubnet['cidr'])) ?></span>
    <span class="sep">›</span>
  <?php endif; ?>
  <span>🧾 Addresses</span>
</div>

<div class="toolbar">
  <div>
    <h1>Addresses</h1>
    <div class="muted">Manage address records within a subnet.</div>
  </div>
</div>

<div class="page-actions">
  <?php if ($selectedSubnetId > 0): ?>
    <?php if (current_user()['role'] !== 'readonly'): ?>
      <button class="action-pill" data-drawer-title="Add Address" data-drawer-tpl="tpl-add-address">➕ Add Address <kbd class="kbd-hint">⌘N</kbd></button>
      <a class="action-pill" href="bulk_update.php?subnet_id=<?= (int)$selectedSubnetId ?>">✏ Bulk Update</a>
      <?php if ($missingInfra): ?>
        <a class="action-pill" href="#reserve-infra" data-open-drawer="reserve-infra" data-drawer-title="Reserve Infrastructure IPs">🔒 Reserve Infra IPs</a>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($selectedSubnet && to_int($selectedSubnet['ip_version']) === 4): ?>
      <a class="action-pill" href="unassigned.php?subnet_id=<?= (int)$selectedSubnetId ?>">✨ Unassigned</a>
    <?php endif; ?>
    <a class="action-pill" href="search.php?subnet_id=<?= (int)$selectedSubnetId ?>">🔎 Search in Subnet</a>
    <a class="action-pill" href="export_addresses.php?subnet_id=<?= (int)$selectedSubnetId ?>">⬇ Export CSV</a>
    <a class="action-pill" href="export_dns.php?subnet_id=<?= (int)$selectedSubnetId ?>">🌐 DNS Export</a>
  <?php endif; ?>
</div>

<div class="card mt-16">
  <form method="get" action="addresses.php" class="row">
    <?php if ($addrDistinctSiteCount >= 2): ?>
    <label>Site<br>
      <select id="addrSiteFilter" name="_site_filter" aria-label="Filter by site">
        <option value="0"<?= $preselectSiteId === 0 ? ' selected' : '' ?>>-- All sites --</option>
        <?php foreach ($addrSiteList as $site): ?>
          <option value="<?= to_int($site['id']) ?>"<?= to_int($site['id']) === $preselectSiteId ? ' selected' : '' ?>><?= e(to_str($site['name'])) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>

    <label>Subnet<br>
      <select name="subnet_id">
        <option value="0">-- Select --</option>
        <?php foreach ($subnetList as $s): ?>
          <option value="<?= to_int($s['id']) ?>" <?= (to_int($s['id']) === $selectedSubnetId) ? 'selected' : '' ?> data-site-id="<?= to_int($s['site_id'] ?? 0) ?>">
            <?= e(to_str($s['cidr'])) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Page size<br>
      <select name="page_size">
        <?php foreach ([50,100,254,500] as $sz): ?>
          <option value="<?= $sz ?>" <?= $pageSize===$sz?'selected':'' ?>><?= $sz ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <button type="submit">Load</button>
  </form>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>

<?php if ($selectedSubnetId > 0): ?>
  <div class="card mt-16">
    <div class="toolbar">
      <div>
        <h2>Subnet: <?= e(to_str($selectedSubnet['cidr'] ?? '')) ?></h2>
        <?php $subnetDesc = trim(to_str($selectedSubnet['description'] ?? '')); ?>
        <?php if ($subnetDesc !== ''): ?>
        <p class="muted font-sm" style="margin-top:0.15rem"><?= e($subnetDesc) ?></p>
        <?php endif; ?>
        <div class="muted">Rows: <b><?= e((string)$total) ?></b><?php if ($p): ?> | Page <b><?= e(to_str($p['page'])) ?></b> of <b><?= e(to_str($p['pages'])) ?></b><?php endif; ?></div>
      </div>
    </div>
  </div>
  <?php
    // #316: render long-form subnet notes (if any) above the address table.
    // <details> keeps it collapsible so a long runbook doesn't dominate the
    // page; default-open if non-empty so the operator notices it.
    $subnetNotes = to_str($selectedSubnet['notes'] ?? '');
    if ($subnetNotes !== '') {
        echo '<details class="card mt-16 subnet-notes" open>'
           . '<summary><b>📝 Subnet notes</b></summary>'
           . '<div class="subnet-notes-body">' . nl2br(e($subnetNotes)) . '</div>'
           . '</details>';
    }
  ?>
<?php endif; ?>

<div id="tpl-add-address" style="display:none"><?= ipam_render_string('address_form', [
    'selectedSubnetId' => $selectedSubnetId,
    'selectedSubnet'   => $selectedSubnet,
    'nextAvailableIp'  => $nextAvailableIp,
    'prefillIp'        => $prefillIp,
    'addrCfDefs'       => $addrCfDefs,
    'deviceList'       => $deviceList,
]) ?></div>

<?php if ($missingInfra && $selectedSubnet): ?>
<div class="card mt-16 drawer-form-card" id="reserve-infra">
  <h2>Reserve infrastructure IPs</h2>
  <p class="muted">Creates reserved address records for the network and broadcast addresses (determined from the CIDR). Gateway is optional — enter an IP if this subnet has a known gateway.</p>
  <form method="post" action="addresses.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="reserve_infra">
    <input type="hidden" name="subnet_id" value="<?= (int)$selectedSubnetId ?>">
    <div class="row">
      <label>Gateway IP (optional)<br>
        <input name="gateway_ip" placeholder="<?= e(to_str($selectedSubnet['network'])) ?>" data-validate="ip">
      </label>
    </div>
    <p><button type="submit">Reserve</button></p>
  </form>
</div>
<?php endif; ?>

<div class="card mt-16">
  <h2>List</h2>
  <?php if ($selectedSubnetId <= 0): ?>
    <div class="empty-state">No subnet selected. <a href="subnets.php">Go to Subnets</a> to create or select one.</div>
  <?php elseif (!$addresses): ?>
    <div class="empty-state">No addresses in this subnet yet. <button class="action-pill" data-drawer-title="Add Address" data-drawer-tpl="tpl-add-address">+ Add Address</button></div>
  <?php else: ?>
    <div class="table-wrap">
    <table data-col-table="addresses" class="data-table">
      <thead>
        <tr>
          <?php if (current_user()['role'] !== 'readonly'): ?><th><input type="checkbox" id="select-all-addresses" aria-label="Select all"></th><?php else: ?><th></th><?php endif; ?>
          <?php $addrQsParams = ['subnet_id' => $selectedSubnetId, 'page_size' => $pageSize];
                if ($filterType !== '') { $addrQsParams['filter'] = $filterType; }
                if ($filterType === 'expiring') { $addrQsParams['days'] = $filterDays; }
                $addrQs = '?' . http_build_query($addrQsParams);
                echo sort_th('ip',       'IP',       $addrSort['col'], $addrSort['dir'], $addrQs, 'ip');
                echo sort_th('hostname', 'Hostname', $addrSort['col'], $addrSort['dir'], $addrQs, 'hostname');
                echo sort_th('owner',    'Owner',    $addrSort['col'], $addrSort['dir'], $addrQs, 'owner');
                echo sort_th('status',   'Status',   $addrSort['col'], $addrSort['dir'], $addrQs, 'status');
          ?>
          <th data-col="group">Group</th>
          <th data-col="mac">MAC</th>
          <th data-col="expires">Expires</th>
          <th data-col="note">Note</th>
          <?php echo sort_th('updated', 'Updated', $addrSort['col'], $addrSort['dir'], $addrQs, 'updated'); ?>
          <th data-col="last-seen" data-col-default-hidden="1">Last Seen</th>
          <th data-col="device" data-col-default-hidden="1">Device</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php
        $isWrite = (current_user()['role'] !== 'readonly');
        foreach ($addresses as $a):
          $isHighlighted = $highlightId > 0 && to_int($a['id']) === $highlightId;
          $isExpired = isset($a['expires_at']) && to_str($a['expires_at']) < date('Y-m-d');
          $aid = to_int($a['id']);
          $rowClasses = array_filter([$isHighlighted ? 'highlight-row' : '', $isExpired ? 'expired-row' : '']);
      ?>
        <tr id="addr-<?= $aid ?>"<?= $rowClasses ? ' class="' . e(implode(' ', $rowClasses)) . '"' : '' ?>>
          <td><?php if ($isWrite): ?><input type="checkbox" class="row-select" value="<?= $aid ?>" aria-label="Select row"><?php endif; ?></td>
          <td class="ip-cell"><?= e(to_str($a['ip'])) ?><?php
            $ipBin = is_string($a['ip_bin'] ?? null) ? $a['ip_bin'] : '';
            if ($ipBin !== '') {
                if ($networkBin !== null && hash_equals($networkBin, $ipBin)) echo ' <span class="badge badge-network" title="Network address">Net</span>';
                if ($broadcastBin !== null && hash_equals($broadcastBin, $ipBin)) echo ' <span class="badge badge-broadcast" title="Broadcast address">Bcast</span>';
            }
            if (to_str($a['hostname'] ?? '') === 'gateway') echo ' <span class="badge badge-gateway" title="Gateway address">GW</span>';
            if (!empty($a['is_stale'])): ?> <span class="badge" style="background:var(--danger);color:#fff;font-size:.7rem" title="Host missed recent scans">Stale</span><?php endif ?>
          </td>
          <td<?= $isWrite ? ' data-editable="hostname" data-addr-id="' . $aid . '"' : '' ?>><?= e(to_str($a['hostname'])) ?></td>
          <td<?= $isWrite ? ' data-editable="owner" data-addr-id="' . $aid . '"' : '' ?>><?php
            $ownContactId    = to_int($a['owner_contact_id'] ?? 0);
            $ownContactName  = to_str($a['owner_contact_name'] ?? '');
            $ownContactEmail = to_str($a['owner_contact_email'] ?? '');
            if ($ownContactId > 0 && $ownContactName !== '') {
                echo '<a href="contacts.php" class="contact-card-trigger" data-contact-id="' . $ownContactId . '">' . e($ownContactName) . '</a>';
                if ($ownContactEmail !== '') echo ' <a href="mailto:' . e($ownContactEmail) . '" class="muted" title="' . e($ownContactEmail) . '">✉</a>';
            } else {
                echo e(to_str($a['owner']));
            }
          ?></td>
          <td><?php
            $addrStatus = e(to_str($a['status']));
            $canToggle  = $isWrite ? ' data-addr-id="' . $aid . '"' : '';
            echo "<span class='status-badge status-{$addrStatus}'{$canToggle} title='Click to cycle status'>{$addrStatus}</span>";
          ?></td>
          <td<?= $isWrite ? ' data-editable="grp" data-addr-id="' . $aid . '"' : '' ?>><?php if ($a['grp'] !== ''): ?><span class="badge"><?= e(to_str($a['grp'])) ?></span><?php endif; ?></td>
          <td class="muted ip-cell"><?= e(to_str($a['mac'])) ?></td>
          <td class="muted"><?= e(to_str($a['expires_at'] ?? '')) ?></td>
          <td<?= $isWrite ? ' data-editable="note" data-addr-id="' . $aid . '"' : '' ?>><?= e(to_str($a['note'])) ?></td>
          <td class="muted"><?= e(ipam_format_datetime(to_str($a['updated_at']))) ?></td>
          <td data-col="last-seen" class="muted"><?= isset($a['last_seen_at']) && to_str($a['last_seen_at']) !== '' ? e(ipam_format_datetime(to_str($a['last_seen_at']))) : '—' ?></td>
          <td data-col="device" class="muted"><?php
            $devName  = to_str($a['device_name']    ?? '');
            $ifName   = to_str($a['interface_name'] ?? '');
            if ($devName !== '') {
                echo '<a href="devices.php" class="muted">' . e($devName) . '</a>';
                if ($ifName !== '') echo '<br><span class="muted" style="font-size:.8em">' . e($ifName) . '</span>';
            } else { echo '—'; }
          ?></td>
          <td>
            <div class="actions-inline">
              <a href="address_history.php?address_id=<?= to_int($a['id']) ?>">History</a>
              <button type="button" class="ping-btn" data-address-id="<?= to_int($a['id']) ?>"
                      data-csrf="<?= e(csrf_token()) ?>" style="font-size:.8rem;padding:2px 8px">Ping</button>
              <span class="ping-result-<?= to_int($a['id']) ?> muted" style="font-size:.8rem"></span>
            </div>

            <details class="mt-6"<?= $isHighlighted ? ' open' : '' ?>>
              <summary>Edit/Delete</summary>

              <form method="post" action="addresses.php" class="mt-8">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="subnet_id" value="<?= (int)$selectedSubnetId ?>">
                <input type="hidden" name="id" value="<?= to_int($a['id']) ?>">

                <div class="row">
                  <label>Hostname<br><input name="hostname" maxlength="253" value="<?= e(to_str($a['hostname'])) ?>"></label>
                  <label>Owner<br>
                    <input name="owner" maxlength="255" value="<?= e(to_str($a['owner'])) ?>" autocomplete="off" data-contact-typeahead>
                    <input type="hidden" name="owner_contact_id" value="<?= to_int($a['owner_contact_id'] ?? 0) ?>">
                  </label>
                  <label>Group<br><input name="grp" value="<?= e(to_str($a['grp'] ?? '')) ?>" maxlength="100" placeholder="e.g. web-tier" class="mw-160"></label>
                  <label>MAC<br><input name="mac" maxlength="64" value="<?= e(to_str($a['mac'])) ?>" placeholder="e.g. aa:bb:cc:dd:ee:ff" class="mw-160"></label>
                  <label>Expires<br><input name="expires_at" type="date" value="<?= e(to_str($a['expires_at'] ?? '')) ?>" class="mw-160"></label>
                  <label>Status<br>
                    <select name="status">
                      <option value="used" <?= ($a['status']==='used')?'selected':'' ?>>used</option>
                      <option value="reserved" <?= ($a['status']==='reserved')?'selected':'' ?>>reserved</option>
                      <option value="free" <?= ($a['status']==='free')?'selected':'' ?>>free</option>
                    </select>
                  </label>
                </div>

                <div class="row">
                  <label class="flex-1">Note<br><input name="note" maxlength="1000" class="w-full" value="<?= e(to_str($a['note'])) ?>"></label>
                </div>
                <?php if ($deviceList): ?>
                <div class="row">
                  <label>Device<br>
                    <select name="device_id" class="addr-device-select" data-iface-target="iface-select-<?= $aid ?>">
                      <option value="0">(none)</option>
                      <?php foreach ($deviceList as $dv):
                            $dvId = to_int($dv['id']); ?>
                        <option value="<?= $dvId ?>"<?= to_int($a['device_id'] ?? 0) === $dvId ? ' selected' : '' ?>><?= e(to_str($dv['name'])) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>Interface<br>
                    <select name="interface_id" id="iface-select-<?= $aid ?>">
                      <option value="0">(none)</option>
                      <?php $curDid = to_int($a['device_id'] ?? 0);
                            foreach ($ifacesByDeviceId[$curDid] ?? [] as $dif): ?>
                        <option value="<?= to_int($dif['id']) ?>"<?= to_int($a['interface_id'] ?? 0) === to_int($dif['id']) ? ' selected' : '' ?>><?= e(to_str($dif['name'])) ?></option>
                            <?php endforeach; ?>
                    </select>
                  </label>
                </div>
                <?php endif; ?>

                <?php if ($addrCfDefs): ?>
                <?= render_custom_field_inputs($addrCfDefs, parse_custom_fields_row(to_str($a['custom_fields'] ?? '{}'))) ?>
                <?php endif; ?>

                <button type="submit" <?= (current_user()['role']==='readonly')?'disabled':'' ?>>Save</button>
              </form>

              <form method="post" action="addresses.php" data-confirm="Delete this address?" class="mt-8">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="subnet_id" value="<?= (int)$selectedSubnetId ?>">
                <input type="hidden" name="id" value="<?= to_int($a['id']) ?>">
                <button type="submit" class="button-danger" <?= (current_user()['role']==='readonly')?'disabled':'' ?>>Delete</button>
              </form>
            </details>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php
      $qsBase = ['subnet_id' => $selectedSubnetId, 'page_size' => $pageSize,
                 'sort' => $addrSort['col'], 'dir' => $addrSort['dir']];
      if ($filterType !== '') $qsBase['filter'] = $filterType;
      if ($filterType === 'expiring') $qsBase['days'] = $filterDays;
      $base = 'addresses.php?' . http_build_query($qsBase);
    ?>
    <p class="mt-12">
      <?php if ($p && $p['page'] > 1): ?>
        <a href="<?= e($base . '&page=' . ($p['page']-1)) ?>">&laquo; Prev</a>
      <?php endif; ?>
      <?php if ($p && $p['page'] < $p['pages']): ?>
        <a class="ml-12" href="<?= e($base . '&page=' . ($p['page']+1)) ?>">Next &raquo;</a>
      <?php endif; ?>
    </p>
  <?php endif; ?>
</div>

<?php if ($deviceList): ?>
<div id="iface-data" hidden
     data-ifaces="<?= e((string)(json_encode($ifacesByDeviceId, JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}')) ?>"></div>
<?php endif; ?>
<?php
if (current_user()['role'] !== 'readonly') {
    echo "<div id='bulk-bar' class='bulk-bar' role='status' aria-live='polite' data-subnet-id='" . (int)$selectedSubnetId . "'>";
    echo "  <span class='bulk-bar-count' id='bulk-bar-count'>0 selected</span>";
    echo "  <a class='button-secondary' id='bulk-bar-link' href='bulk_update.php?subnet_id=" . (int)$selectedSubnetId . "'>Bulk Edit</a>";
    echo "</div>";
}
ipam_skeleton_remove(); page_footer();
