#!/usr/bin/env php
<?php
/**
 * Generate a large IPAM database for testing streaming export.
 * Usage: php gen_large_db.php
 *
 * Creates ~50,000 addresses, ~500 subnets, ~100,000 audit rows, ~50,000 history rows.
 * DB file: Simple-PHP-IPAM/data/ipam.sqlite
 */
declare(strict_types=1);

$dbPath = __DIR__ . '/../../../Simple-PHP-IPAM/data/ipam.sqlite';

// Ensure data dir exists
@mkdir(dirname($dbPath), 0755, true);

// Remove old DB if present
if (file_exists($dbPath)) {
    unlink($dbPath);
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');
}

$db = new PDO('sqlite:' . $dbPath, '', '', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA synchronous = NORMAL');
$db->exec('PRAGMA foreign_keys = ON');

// Load schema
$schema = file_get_contents(__DIR__ . '/../../../Simple-PHP-IPAM/schema.sql');
$db->exec($schema);

echo "Schema loaded.\n";

// --- Admin user ---
$adminHash = password_hash('admin123', PASSWORD_BCRYPT);
$db->prepare("INSERT INTO users (username, password_hash, role, is_active, name, email, password_changed_at)
    VALUES ('admin', :h, 'admin', 1, 'Administrator', 'admin@example.com', datetime('now'))")
    ->execute([':h' => $adminHash]);
echo "Admin user created (admin / admin123)\n";

// --- Sites ---
$siteNames = ['HQ-East', 'HQ-West', 'DC-Chicago', 'DC-Dallas', 'DC-London', 'DC-Frankfurt',
              'Branch-NYC', 'Branch-LA', 'Branch-Tokyo', 'Branch-Sydney'];
$siteIds = [];
$stSite = $db->prepare("INSERT INTO sites (name, description) VALUES (:n, :d)");
foreach ($siteNames as $i => $name) {
    $stSite->execute([':n' => $name, ':d' => "Site $name - auto-generated"]);
    $siteIds[] = (int)$db->lastInsertId();
}
echo count($siteIds) . " sites created.\n";

// --- Subnets ---
// Generate ~500 subnets: mix of /16, /20, /24, /28, /30, /31, /32 and some IPv6
$subnetData = []; // [cidr, version, network, netBin, prefix, desc, siteId, vlanId]

// /16 parents
$base16 = [];
for ($i = 0; $i < 10; $i++) {
    $oct1 = 10;
    $oct2 = $i;
    $cidr = "$oct1.$oct2.0.0/16";
    $net = "$oct1.$oct2.0.0";
    $base16[] = [$oct1, $oct2];
    $subnetData[] = [$cidr, 4, $net, inet_pton($net), 16,
        "Core /$oct1.$oct2 block", $siteIds[$i % count($siteIds)], ($i * 100 + 100)];
}

// /24 children — 20 per /16 parent = 200 subnets
foreach ($base16 as $idx => [$o1, $o2]) {
    for ($o3 = 0; $o3 < 20; $o3++) {
        $cidr = "$o1.$o2.$o3.0/24";
        $net = "$o1.$o2.$o3.0";
        $vlan = ($idx * 20 + $o3 + 1);
        if ($vlan > 4094) $vlan = null;
        $subnetData[] = [$cidr, 4, $net, inet_pton($net), 24,
            "Segment $o1.$o2.$o3", $siteIds[$idx % count($siteIds)], $vlan];
    }
}

// /28 subnets — 200 more
for ($i = 0; $i < 200; $i++) {
    $o1 = 172; $o2 = 16 + intdiv($i, 16); $o3 = ($i % 16) * 16;
    $cidr = "$o1.$o2.$o3.0/28";
    $net = "$o1.$o2.$o3.0";
    $subnetData[] = [$cidr, 4, $net, inet_pton($net), 28,
        "DMZ segment $i", $siteIds[$i % count($siteIds)], null];
}

// /30 P2P links — 50
for ($i = 0; $i < 50; $i++) {
    $o3 = intdiv($i, 64); $o4 = ($i % 64) * 4;
    $cidr = "192.168.$o3.$o4/30";
    $net = "192.168.$o3.$o4";
    $subnetData[] = [$cidr, 4, $net, inet_pton($net), 30,
        "P2P link $i", $siteIds[$i % count($siteIds)], null];
}

// IPv6 /48 and /64 — 40
for ($i = 0; $i < 20; $i++) {
    $hex = str_pad(dechex($i + 1), 4, '0', STR_PAD_LEFT);
    $net48 = "2001:db8:$hex::";
    $cidr48 = "$net48/48";
    $subnetData[] = [$cidr48, 6, $net48, inet_pton($net48), 48,
        "IPv6 /48 block $i", $siteIds[$i % count($siteIds)], null];

    $net64 = "2001:db8:$hex:1::";
    $cidr64 = "$net64/64";
    $subnetData[] = [$cidr64, 6, $net64, inet_pton($net64), 64,
        "IPv6 /64 segment $i", $siteIds[$i % count($siteIds)], null];
}

$stSub = $db->prepare("INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, site_id, vlan_id)
    VALUES (:c, :v, :n, :nb, :p, :d, :s, :vl)");

$db->beginTransaction();
$subnetIds = [];
foreach ($subnetData as $s) {
    $stSub->execute([':c' => $s[0], ':v' => $s[1], ':n' => $s[2], ':nb' => $s[3],
        ':p' => $s[4], ':d' => $s[5], ':s' => $s[6], ':vl' => $s[7]]);
    $subnetIds[] = (int)$db->lastInsertId();
}
$db->commit();
echo count($subnetIds) . " subnets created.\n";

// --- Addresses ---
// Fill /24 subnets with ~200 addresses each (first 200 /24 subnets = ~40,000 addresses)
// Fill /28 subnets with ~10 addresses each (200 subnets = ~2,000 addresses)
// Fill /30 subnets with 2 addresses each (50 subnets = 100 addresses)
// IPv6 /64 subnets with ~50 addresses each (20 subnets = 1,000 addresses)

$statuses = ['used', 'reserved', 'free'];
$groups = ['servers', 'workstations', 'printers', 'phones', 'iot', 'infra', 'mgmt', ''];
$owners = ['netops', 'sysadmin', 'devops', 'security', 'helpdesk', 'facilities', ''];
$hostPrefixes = ['srv', 'ws', 'sw', 'rtr', 'fw', 'ap', 'cam', 'prt', 'vm', 'ct'];

$stAddr = $db->prepare("INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, grp, status)
    VALUES (:sid, :ip, :ib, :h, :o, :n, :g, :st)");

$totalAddresses = 0;
$batchSize = 5000;
$db->beginTransaction();

// /24 subnets (indices 10..209 in subnetIds)
for ($si = 10; $si < 210 && $si < count($subnetIds); $si++) {
    $sid = $subnetIds[$si];
    $sd = $subnetData[$si];
    $parts = explode('.', $sd[2]); // network
    for ($host = 1; $host <= 200; $host++) {
        $ip = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.' . $host;
        $ipBin = inet_pton($ip);
        $status = $statuses[array_rand($statuses)];
        $grp = $groups[array_rand($groups)];
        $owner = $owners[array_rand($owners)];
        $hpfx = $hostPrefixes[array_rand($hostPrefixes)];
        $hostname = "$hpfx-" . $parts[2] . "-$host." . strtolower(str_replace('-', '.', $siteNames[$si % count($siteNames)])) . ".local";
        $stAddr->execute([':sid' => $sid, ':ip' => $ip, ':ib' => $ipBin,
            ':h' => $hostname, ':o' => $owner, ':n' => "Auto-generated address",
            ':g' => $grp, ':st' => $status]);
        $totalAddresses++;
        if ($totalAddresses % $batchSize === 0) {
            $db->commit();
            $db->beginTransaction();
            echo "  $totalAddresses addresses...\n";
        }
    }
}

// /28 subnets (indices 210..409)
for ($si = 210; $si < 410 && $si < count($subnetIds); $si++) {
    $sid = $subnetIds[$si];
    $sd = $subnetData[$si];
    $parts = explode('.', $sd[2]);
    for ($host = 1; $host <= 10; $host++) {
        $ip = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.' . ((int)$parts[3] + $host);
        $ipBin = inet_pton($ip);
        $status = $statuses[array_rand($statuses)];
        $stAddr->execute([':sid' => $sid, ':ip' => $ip, ':ib' => $ipBin,
            ':h' => "dmz-$host.segment$si.local", ':o' => 'security', ':n' => '',
            ':g' => 'dmz', ':st' => $status]);
        $totalAddresses++;
    }
}

// /30 P2P (indices 410..459)
for ($si = 410; $si < 460 && $si < count($subnetIds); $si++) {
    $sid = $subnetIds[$si];
    $sd = $subnetData[$si];
    $parts = explode('.', $sd[2]);
    for ($host = 1; $host <= 2; $host++) {
        $ip = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.' . ((int)$parts[3] + $host);
        $ipBin = inet_pton($ip);
        $stAddr->execute([':sid' => $sid, ':ip' => $ip, ':ib' => $ipBin,
            ':h' => "p2p-$si-$host.core.local", ':o' => 'netops', ':n' => 'P2P link endpoint',
            ':g' => 'infra', ':st' => 'used']);
        $totalAddresses++;
    }
}

// IPv6 /64 (odd indices 461,463,465... — every other one is the /64)
for ($si = 460; $si < count($subnetIds); $si++) {
    $sd = $subnetData[$si];
    if ($sd[4] !== 64) continue;
    $sid = $subnetIds[$si];
    $netBase = rtrim($sd[2], ':');
    for ($host = 1; $host <= 50; $host++) {
        $ip = $netBase . '::' . dechex($host);
        // Normalize via inet_pton/ntop
        $ipBin = inet_pton($ip);
        if ($ipBin === false) continue;
        $ip = inet_ntop($ipBin);
        $stAddr->execute([':sid' => $sid, ':ip' => $ip, ':ib' => $ipBin,
            ':h' => "v6host-$host.ipv6.local", ':o' => 'sysadmin', ':n' => '',
            ':g' => 'servers', ':st' => $statuses[array_rand($statuses)]]);
        $totalAddresses++;
    }
}

$db->commit();
echo "$totalAddresses total addresses created.\n";

// --- Audit log ---
// We need to drop the triggers temporarily to bulk insert
$db->exec("DROP TRIGGER IF EXISTS audit_log_no_update");
$db->exec("DROP TRIGGER IF EXISTS audit_log_no_delete");

$actions = ['subnet.create', 'subnet.update', 'subnet.delete',
            'address.create', 'address.update', 'address.delete',
            'auth.login', 'auth.login_failed', 'auth.login_blocked',
            'user.create', 'user.set_role', 'user.toggle_active',
            'site.create', 'site.update', 'apikey.create', 'apikey.deactivate',
            'export.addresses', 'export.audit', 'import.apply',
            'db.export', 'db.import', 'db.backup'];
$entityTypes = ['subnet', 'address', 'user', 'site', 'apikey', 'auth', 'db'];

$stAudit = $db->prepare("INSERT INTO audit_log (created_at, user_id, username, action, entity_type, entity_id, ip, user_agent, details)
    VALUES (:ca, :uid, :un, :a, :et, :eid, :ip, :ua, :d)");

$auditCount = 100000;
echo "Generating $auditCount audit log entries...\n";
$db->beginTransaction();
for ($i = 0; $i < $auditCount; $i++) {
    // Spread over past 365 days
    $daysAgo = rand(0, 365);
    $hoursAgo = rand(0, 23);
    $minutesAgo = rand(0, 59);
    $ts = date('Y-m-d H:i:s', strtotime("-$daysAgo days -$hoursAgo hours -$minutesAgo minutes"));
    $action = $actions[array_rand($actions)];
    $et = $entityTypes[array_rand($entityTypes)];
    $stAudit->execute([
        ':ca'  => $ts,
        ':uid' => 1,
        ':un'  => 'admin',
        ':a'   => $action,
        ':et'  => $et,
        ':eid' => rand(1, 500),
        ':ip'  => '192.168.1.' . rand(1, 254),
        ':ua'  => 'Mozilla/5.0 (gen_large_db)',
        ':d'   => "Auto-generated audit entry #$i for $action",
    ]);
    if ($i % 10000 === 0 && $i > 0) {
        $db->commit();
        $db->beginTransaction();
        echo "  $i audit entries...\n";
    }
}
$db->commit();

// Recreate triggers
$db->exec("CREATE TRIGGER IF NOT EXISTS audit_log_no_update
BEFORE UPDATE ON audit_log BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END");
$db->exec("CREATE TRIGGER IF NOT EXISTS audit_log_no_delete
BEFORE DELETE ON audit_log BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END");

echo "$auditCount audit log entries created.\n";

// --- Address history ---
$histActions = ['create', 'update', 'delete'];
$stHist = $db->prepare("INSERT INTO address_history (created_at, address_id, subnet_id, ip, action, user_id, username, client_ip, user_agent, before_json, after_json)
    VALUES (:ca, :aid, :sid, :ip, :a, :uid, :un, :cip, :ua, :bj, :aj)");

$histCount = 50000;
echo "Generating $histCount address history entries...\n";
$db->beginTransaction();
for ($i = 0; $i < $histCount; $i++) {
    $daysAgo = rand(0, 365);
    $ts = date('Y-m-d H:i:s', strtotime("-$daysAgo days -" . rand(0,23) . " hours"));
    $act = $histActions[array_rand($histActions)];
    $sid = $subnetIds[array_rand($subnetIds)];
    $fakeIp = '10.' . rand(0,9) . '.' . rand(0,19) . '.' . rand(1,254);
    $before = $act !== 'create' ? json_encode(['ip' => $fakeIp, 'hostname' => 'old-host', 'status' => 'used']) : null;
    $after = $act !== 'delete' ? json_encode(['ip' => $fakeIp, 'hostname' => 'new-host', 'status' => 'reserved']) : null;
    $stHist->execute([
        ':ca'  => $ts,
        ':aid' => rand(1, $totalAddresses),
        ':sid' => $sid,
        ':ip'  => $fakeIp,
        ':a'   => $act,
        ':uid' => 1,
        ':un'  => 'admin',
        ':cip' => '192.168.1.' . rand(1, 254),
        ':ua'  => 'Mozilla/5.0 (gen_large_db)',
        ':bj'  => $before,
        ':aj'  => $after,
    ]);
    if ($i % 10000 === 0 && $i > 0) {
        $db->commit();
        $db->beginTransaction();
        echo "  $i history entries...\n";
    }
}
$db->commit();
echo "$histCount address history entries created.\n";

// --- Stamp schema migrations ---
$versions = ['0.1','0.2','0.3','0.4','0.5','0.6','0.7','0.8','0.9','0.10','0.11','0.12',
             '0.13','0.14','0.15','1.0','1.1','1.2','1.2.3','1.3','1.4','1.5','1.6',
             '1.7','1.8','1.9','1.10','1.11','1.12','1.13'];
$stMig = $db->prepare("INSERT OR IGNORE INTO schema_migrations (version) VALUES (:v)");
foreach ($versions as $v) {
    $stMig->execute([':v' => $v]);
}
echo count($versions) . " migration versions stamped.\n";

// Final stats
$stats = [];
foreach (['users', 'sites', 'subnets', 'addresses', 'audit_log', 'address_history'] as $t) {
    $stats[$t] = (int)$db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
}
$dbSize = filesize($dbPath);

echo "\n=== Database Summary ===\n";
foreach ($stats as $table => $count) {
    echo "  $table: " . number_format($count) . " rows\n";
}
echo "  DB size: " . round($dbSize / 1024 / 1024, 1) . " MB\n";
echo "  Path: $dbPath\n";
echo "\n  Login: admin / admin123\n";
