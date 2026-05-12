<?php
declare(strict_types=1);

/**
 * seed-large-db.php — bulk-populate the live IPAM database for backup-format
 * FIXTURE GENERATION only. NOT a real seed: it inserts large volumes of
 * synthetic data so that data/ipam.sqlite grows to >= ~30 MB, which makes the
 * resulting backup archives (in tests/fixtures/backup-library/) realistically
 * sized (multi-MB) rather than toy fixtures.
 *
 * Usage (inside a dockerized app instance -- e.g. `bootstrap-app.sh sqlite`):
 *   docker exec ipam-pw-test php /tmp/seed-large-db.php
 *
 * Idempotent-ish: if it detects it has already run (subnets row count above a
 * threshold), it bails without inserting again.
 *
 * Implementation notes: only literal-string PDO::query() / prepared statements;
 * no proc_open / shell_exec; no dynamic SQL execution.
 */

require '/var/www/html/init.php';
/** @var PDO $db */

$started = microtime(true);

// -- Already seeded? --------------------------------------------------------
$existingSubnets = (int) $db->query('SELECT COUNT(*) FROM subnets')->fetchColumn();
if ($existingSubnets > 2000) {
    $dbFile = '/var/www/html/data/ipam.sqlite';
    $sz = is_file($dbFile) ? filesize($dbFile) : 0;
    echo "Already seeded (subnets=$existingSubnets, ipam.sqlite=" . number_format((int) $sz) . " bytes). Nothing to do.\n";
    exit(0);
}

// Speed pragmas (best-effort; ignore failures on non-sqlite).
try {
    $db->query('PRAGMA synchronous = OFF');
    $db->query('PRAGMA journal_mode = MEMORY');
} catch (Throwable $e) {
    // non-sqlite or restricted -- fine
}

$ts_ago = static function (int $daysMax): string {
    $secs = random_int(0, $daysMax * 86400);
    return gmdate('Y-m-d H:i:s', time() - $secs);
};

// -- Sites / VLANs / VRFs / Contacts ---------------------------------------
echo "Seeding sites / vlans / vrfs / contacts ...\n";
$db->beginTransaction();

$insSite = $db->prepare('INSERT INTO sites (name, description) VALUES (:n, :d)');
$siteIds = [];
for ($i = 1; $i <= 40; $i++) {
    $insSite->execute([':n' => "Site-$i DC " . bin2hex(random_bytes(2)), ':d' => "Synthetic site $i for backup fixtures"]);
    $siteIds[] = (int) $db->lastInsertId();
}

$insVrf = $db->prepare('INSERT INTO vrfs (name, description, rd) VALUES (:n, :d, :rd)');
$vrfIds = [null]; // include the global (NULL) VRF
for ($i = 1; $i <= 25; $i++) {
    $insVrf->execute([':n' => "vrf-$i-" . bin2hex(random_bytes(2)), ':d' => "Synthetic VRF $i", ':rd' => "65000:$i"]);
    $vrfIds[] = (int) $db->lastInsertId();
}

$insVlan = $db->prepare('INSERT INTO vlans (vlan_id, name, description, site_id) VALUES (:v, :n, :d, :s)');
$vlanIds = [];
for ($i = 1; $i <= 500; $i++) {
    $vid = ($i % 4094) + 1;
    try {
        $insVlan->execute([
            ':v' => $vid,
            ':n' => "vlan-$vid-" . bin2hex(random_bytes(2)),
            ':d' => "Synthetic VLAN $i",
            ':s' => $siteIds[array_rand($siteIds)],
        ]);
        $vlanIds[] = (int) $db->lastInsertId();
    } catch (PDOException $e) {
        // UNIQUE(vlan_id, site_id) collision -- skip
    }
}

$insContact = $db->prepare('INSERT INTO contacts (name, email, phone, org, note) VALUES (:n, :e, :p, :o, :nt)');
$contactIds = [];
for ($i = 1; $i <= 2000; $i++) {
    $insContact->execute([
        ':n'  => "Contact $i " . bin2hex(random_bytes(3)),
        ':e'  => "contact$i@example.invalid",
        ':p'  => '+1-555-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
        ':o'  => "Org " . random_int(1, 50),
        ':nt' => str_repeat("contact note blob ", random_int(1, 8)),
    ]);
    $contactIds[] = (int) $db->lastInsertId();
}
$db->commit();
echo "  sites=" . count($siteIds) . " vrfs=" . (count($vrfIds) - 1) . " vlans=" . count($vlanIds) . " contacts=" . count($contactIds) . "\n";

// -- Subnets ----------------------------------------------------------------
echo "Seeding subnets ...\n";
$insSubnet = $db->prepare(
    'INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, notes, site_id, vlan_fk, vrf_id)
     VALUES (:cidr, :ver, :net, :netbin, :prefix, :desc, :notes, :site, :vlan, :vrf)'
);

$subnetMeta = []; // id => [network_bin, prefix, ip_version]
$db->beginTransaction();
$batch = 0;
$targetSubnets = 6000;
for ($i = 0; $i < $targetSubnets; $i++) {
    if ($i < 5400) {
        $a = 10;
        $b = intdiv($i, 256) % 256;
        $c = $i % 256;
        $net = "$a.$b.$c.0";
        $cidr = "$net/24";
        $prefix = 24;
        $ver = 4;
    } elseif ($i < 5800) {
        $b = 16 + (($i - 5400) % 16);
        $c = ($i - 5400) % 256;
        $net = "172.$b.$c.0";
        $cidr = "$net/24";
        $prefix = 24;
        $ver = 4;
    } else {
        $seg = dechex(($i - 5800) & 0xffff);
        $net = "2001:db8:$seg::";
        $cidr = "$net/64";
        $prefix = 64;
        $ver = 6;
    }
    $netBin = inet_pton($net);
    if ($netBin === false) {
        continue;
    }
    $stmt = $insSubnet;
    $stmt->bindValue(':cidr', $cidr);
    $stmt->bindValue(':ver', $ver, PDO::PARAM_INT);
    $stmt->bindValue(':net', $net);
    ipam_bind_binary($stmt, ':netbin', $netBin);
    $stmt->bindValue(':prefix', $prefix, PDO::PARAM_INT);
    $stmt->bindValue(':desc', "Synthetic subnet $i -- " . bin2hex(random_bytes(4)));
    $stmt->bindValue(':notes', str_repeat("operational note line $i. ", random_int(1, 6)));
    $stmt->bindValue(':site', $siteIds[array_rand($siteIds)], PDO::PARAM_INT);
    if ($vlanIds) {
        $stmt->bindValue(':vlan', $vlanIds[array_rand($vlanIds)], PDO::PARAM_INT);
    } else {
        $stmt->bindValue(':vlan', null, PDO::PARAM_NULL);
    }
    $vrf = $vrfIds[array_rand($vrfIds)];
    if ($vrf === null) {
        $stmt->bindValue(':vrf', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':vrf', $vrf, PDO::PARAM_INT);
    }
    try {
        $stmt->execute();
        $sid = (int) $db->lastInsertId();
        $subnetMeta[$sid] = [$netBin, $prefix, $ver];
    } catch (PDOException $e) {
        continue; // UNIQUE(cidr, vrf_id) collision
    }
    if (++$batch >= 1000) {
        $db->commit();
        $db->beginTransaction();
        $batch = 0;
        echo "  ... $i subnets\n";
    }
}
$db->commit();
echo "  subnets total=" . count($subnetMeta) . "\n";

// -- Addresses --------------------------------------------------------------
echo "Seeding addresses ...\n";
$insAddr = $db->prepare(
    'INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, grp, mac, status, owner_contact_id, expires_at)
     VALUES (:sid, :ip, :ipbin, :host, :owner, :note, :grp, :mac, :status, :cid, :exp)'
);
$statuses = ['used', 'used', 'used', 'reserved', 'free'];
$ipv4Subnets = [];
foreach ($subnetMeta as $sid => $m) {
    if ($m[2] === 4 && $m[1] === 24) {
        $ipv4Subnets[] = [$sid, $m[0]];
    }
}

$db->beginTransaction();
$batch = 0;
$addrCount = 0;
$targetAddrs = 130000;
$perSubnet = (int) ceil($targetAddrs / max(1, count($ipv4Subnets)));
foreach ($ipv4Subnets as [$sid, $netBin]) {
    $base = ipv4_bin_to_int($netBin);
    $hostsThisSubnet = min(254, random_int((int) ($perSubnet * 0.6), max(2, $perSubnet)));
    $used = [];
    for ($k = 0; $k < $hostsThisSubnet; $k++) {
        $host = random_int(1, 254);
        if (isset($used[$host])) {
            continue;
        }
        $used[$host] = true;
        $ipBin = ipv4_int_to_bin($base + $host);
        $ipStr = inet_ntop($ipBin);
        if ($ipStr === false) {
            continue;
        }
        $stmt = $insAddr;
        $stmt->bindValue(':sid', $sid, PDO::PARAM_INT);
        $stmt->bindValue(':ip', $ipStr);
        ipam_bind_binary($stmt, ':ipbin', $ipBin);
        $stmt->bindValue(':host', "host-" . bin2hex(random_bytes(3)) . ".synthetic.local");
        $stmt->bindValue(':owner', "owner-" . random_int(1, 500));
        $stmt->bindValue(':note', str_repeat("addr note ", random_int(0, 5)));
        $stmt->bindValue(':grp', "grp-" . random_int(1, 30));
        $stmt->bindValue(':mac', sprintf('%02x:%02x:%02x:%02x:%02x:%02x', random_int(0, 255), random_int(0, 255), random_int(0, 255), random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        $stmt->bindValue(':status', $statuses[array_rand($statuses)]);
        $cid = (random_int(0, 2) === 0 && $contactIds) ? $contactIds[array_rand($contactIds)] : null;
        if ($cid === null) {
            $stmt->bindValue(':cid', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':cid', $cid, PDO::PARAM_INT);
        }
        $exp = (random_int(0, 4) === 0) ? gmdate('Y-m-d', time() + random_int(-30, 365) * 86400) : null;
        if ($exp === null) {
            $stmt->bindValue(':exp', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':exp', $exp, PDO::PARAM_STR);
        }
        try {
            $stmt->execute();
            $addrCount++;
        } catch (PDOException $e) {
            continue; // UNIQUE(subnet_id, ip)
        }
        if (++$batch >= 5000) {
            $db->commit();
            $db->beginTransaction();
            $batch = 0;
            echo "  ... $addrCount addresses\n";
        }
    }
    if ($addrCount >= $targetAddrs) {
        break;
    }
}
$db->commit();
echo "  addresses total=$addrCount\n";

// Collect a sample of address ids for history rows.
$addrIdSample = [];
foreach ($db->query('SELECT id, subnet_id, ip FROM addresses ORDER BY id LIMIT 15000') as $r) {
    $addrIdSample[] = $r;
}

// -- audit_log --------------------------------------------------------------
echo "Seeding audit_log ...\n";
$insAudit = $db->prepare(
    'INSERT INTO audit_log (created_at, user_id, username, action, entity_type, entity_id, ip, user_agent, details)
     VALUES (:c, :uid, :un, :a, :et, :eid, :ip, :ua, :d)'
);
$actions = ['subnet.create', 'subnet.update', 'address.create', 'address.update', 'address.delete', 'login.success', 'user.update', 'vlan.create'];
$db->beginTransaction();
$batch = 0;
for ($i = 0; $i < 25000; $i++) {
    $insAudit->execute([
        ':c'   => $ts_ago(180),
        ':uid' => 1,
        ':un'  => 'admin',
        ':a'   => $actions[array_rand($actions)],
        ':et'  => 'address',
        ':eid' => random_int(1, max(1, $addrCount)),
        ':ip'  => '192.0.2.' . random_int(1, 254),
        ':ua'  => 'Mozilla/5.0 (synthetic fixture agent ' . bin2hex(random_bytes(4)) . ')',
        ':d'   => json_encode(['note' => str_repeat('detail ', random_int(1, 6)), 'rnd' => bin2hex(random_bytes(8))]),
    ]);
    if (++$batch >= 4000) {
        $db->commit();
        $db->beginTransaction();
        $batch = 0;
    }
}
$db->commit();

// -- address_history --------------------------------------------------------
echo "Seeding address_history ...\n";
$insHist = $db->prepare(
    'INSERT INTO address_history (created_at, address_id, subnet_id, ip, action, user_id, username, client_ip, user_agent, before_json, after_json)
     VALUES (:c, :aid, :sid, :ip, :a, :uid, :un, :cip, :ua, :b, :af)'
);
$histActions = ['create', 'update', 'delete'];
$db->beginTransaction();
$batch = 0;
foreach ($addrIdSample as $r) {
    $reps = random_int(1, 3);
    for ($k = 0; $k < $reps; $k++) {
        $insHist->execute([
            ':c'   => $ts_ago(180),
            ':aid' => (int) $r['id'],
            ':sid' => (int) $r['subnet_id'],
            ':ip'  => $r['ip'],
            ':a'   => $histActions[array_rand($histActions)],
            ':uid' => 1,
            ':un'  => 'admin',
            ':cip' => '192.0.2.' . random_int(1, 254),
            ':ua'  => 'Mozilla/5.0 (synthetic ' . bin2hex(random_bytes(3)) . ')',
            ':b'   => json_encode(['hostname' => 'old-' . bin2hex(random_bytes(3)), 'owner' => 'old']),
            ':af'  => json_encode(['hostname' => 'new-' . bin2hex(random_bytes(3)), 'owner' => 'new', 'pad' => str_repeat('x', random_int(0, 200))]),
        ]);
        if (++$batch >= 4000) {
            $db->commit();
            $db->beginTransaction();
            $batch = 0;
        }
    }
}
$db->commit();

// -- Finalise: checkpoint WAL so the .sqlite file reflects everything --------
try {
    $db->query('PRAGMA wal_checkpoint(TRUNCATE)');
} catch (Throwable $e) {
    // non-sqlite -- fine
}

$dbFile = '/var/www/html/data/ipam.sqlite';
clearstatcache();
$sz = is_file($dbFile) ? filesize($dbFile) : 0;
$secs = round(microtime(true) - $started, 1);
echo "\nDone in {$secs}s.\n";
echo "  subnets=" . count($subnetMeta) . " addresses=$addrCount audit_log~25000 address_history~" . count($addrIdSample) . "+\n";
echo "  data/ipam.sqlite = " . number_format((int) $sz) . " bytes (" . round($sz / 1048576, 1) . " MB)\n";
if ($sz < 25 * 1048576) {
    echo "  WARNING: DB is under ~25 MB -- consider raising the target counts in this script.\n";
}
