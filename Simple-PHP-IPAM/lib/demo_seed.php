<?php
declare(strict_types=1);

/**
 * @module demo_seed
 *
 * Demo-data fixture seeder extracted from lib.php in v3.31.0 (ADR-004,
 * A14 — #909). A single function, demo_seed_data(), that populates an
 * empty schema with the canonical demo dataset: sites, VRFs, VLANs,
 * contacts, tags, IPv4/IPv6 subnets, users, addresses, audit-log and
 * address-history rows. Functions stay in the global namespace per
 * ADR-004 Option E.
 *
 * Inclusion rule: the demo-fixture seeder and any helpers used solely to
 * build that fixture. The CLI entry point (Simple-PHP-IPAM/demo_seed.php)
 * and the test-harness reset path both call demo_seed_data() — this module
 * holds the fixture data itself, not the reset/truncate orchestration.
 *
 * Dependencies: lib/ip.php (ipam_bind_binary, apply_prefix_mask),
 * lib/db.php (ipam_dialect), lib/utils.php (to_str, to_int). Loaded after
 * lib/db.php so ipam_dialect() is available.
 *
 * ADR-003: demo_seed_data() reads no `global $config;`, so no ipam_config()
 * conversion was needed at extraction time. `global $db` is not used — the
 * PDO handle arrives as an explicit parameter.
 */

function demo_seed_data(PDO $db): void
{
    // --- Sites (id=5,6 are region parents inserted first for self-referential FK) ---
    $si = $db->prepare("INSERT INTO sites (id, name, description, parent_id) VALUES (?,?,?,?)");
    foreach ([
        [5, 'EMEA Region',     'Europe, Middle East & Africa', null],
        [6, 'Americas Region', 'North & South America',        null],
        [1, 'London HQ',       'Primary headquarters',         5],
        [2, 'New York DC',     'East coast data centre',       6],
        [3, 'Sydney Office',   'APAC regional office',         null],
        [4, 'AWS eu-west-1',   'Cloud infrastructure',         5],
        ] as $s) $si->execute($s);

    // --- VRFs ---
    $vr = $db->prepare("INSERT INTO vrfs (id, name, description, rd) VALUES (?,?,?,?)");
    foreach ([
        [1, 'DEFAULT',  'Default global routing table', '65000:0'],
        [2, 'MGMT-VRF', 'Management plane VRF',         '65000:100'],
        ] as $v) $vr->execute($v);

    // --- VLANs ---
    $vl = $db->prepare("INSERT INTO vlans (id, vlan_id, name, description, site_id) VALUES (?,?,?,?,?)");
    foreach ([
        [1, 10,  'Management',    'Out-of-band management VLAN',    1],
        [2, 20,  'Servers',       'Server infrastructure VLAN',      1],
        [3, 30,  'DMZ',           'Demilitarised zone',              1],
        [4, 100, 'Cloud-Connect', 'AWS Direct Connect peering VLAN', 4],
        ] as $v) $vl->execute($v);

    // --- Contacts ---
    $ct = $db->prepare("INSERT INTO contacts (id, name, email, phone, org, note) VALUES (?,?,?,?,?,?)");
    foreach ([
        [1, 'Alice Smith', 'alice@example.com', '+44 20 7946 0001', 'NetOps',   'Primary network contact'],
        [2, 'Bob Jones',   'bob@example.com',   '+44 20 7946 0002', 'DBA',      'Database team lead'],
        [3, 'Carol Wu',    'carol@example.com', '+1 212 555 0103',  'Security', 'Security operations engineer'],
        ] as $c) $ct->execute($c);

    // --- Tags ---
    $tg = $db->prepare("INSERT INTO tags (id, name, colour) VALUES (?,?,?)");
    foreach ([
        [1, 'Production',  '#28a745'],
        [2, 'Development', '#17a2b8'],
        [3, 'Critical',    '#dc3545'],
        [4, 'Monitored',   '#6c757d'],
        ] as $t) $tg->execute($t);

    // #379/#410: helper that binds the ten subnet columns onto a prepared
    // INSERT, routing network_bin through ipam_bind_binary() (PARAM_LOB) so
    // every demo-seeded row is BLOB-affinity from the start. Positional ? in
    // the prepare maps to 1-based bindValue indexes.
    $bindSubnetRow = function (PDOStatement $stmt, int $id, string $cidr, string $netNorm, string $netBin, int $pfx, string $desc, ?int $siteId, ?int $vlanFk, ?int $vrfId): void {
        $stmt->bindValue(1,  $id,      PDO::PARAM_INT);
        $stmt->bindValue(2,  $cidr,    PDO::PARAM_STR);
        $stmt->bindValue(3,  $netNorm, PDO::PARAM_STR);
        ipam_bind_binary($stmt, 4, $netBin);
        $stmt->bindValue(5,  $pfx,     PDO::PARAM_INT);
        $stmt->bindValue(6,  $desc,    PDO::PARAM_STR);
        $stmt->bindValue(7,  $siteId,  $siteId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(8,  $vlanFk,  $vlanFk === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(9,  $vrfId,   $vrfId  === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
    };

    // --- Subnets (IPv4) ---
    // [id, cidr, site_id, vlan_fk, vrf_id, description]
    $sn = $db->prepare(
        "INSERT INTO subnets (id, cidr, ip_version, network, network_bin, prefix, description, site_id, vlan_fk, vrf_id)
         VALUES (?,?,4,?,?,?,?,?,?,?)"
    );
    foreach ([
        [1,  '10.0.0.0/8',    null, null, null, 'RFC-1918 supernet (informational)'],
        [2,  '10.10.0.0/16',  1,    null, null, 'London HQ corporate'],
        [3,  '10.10.1.0/24',  1,    1,    2,    'London management'],
        [4,  '10.10.2.0/24',  1,    2,    null, 'London servers'],
        [5,  '10.10.3.0/27',  1,    3,    null, 'London DMZ'],
        [6,  '10.20.0.0/16',  2,    null, null, 'New York DC corporate'],
        [7,  '10.20.1.0/24',  2,    null, null, 'New York servers'],
        [8,  '10.20.2.0/24',  2,    null, null, 'New York management'],
        [9,  '172.16.0.0/16', 3,    null, null, 'Sydney corporate'],
        [10, '172.16.1.0/24', 3,    null, null, 'Sydney servers'],
        ] as [$id, $cidr, $siteId, $vlanFk, $vrfId, $desc]) {
        [$net, $pfx] = explode('/', $cidr);
        $rawBin  = inet_pton($net) ?: throw new \RuntimeException("Invalid IP: $net");
        $netNorm = inet_ntop(apply_prefix_mask($rawBin, (int)$pfx)) ?: throw new \RuntimeException("inet_ntop failed");
        $netBin  = inet_pton($netNorm) ?: throw new \RuntimeException("inet_pton failed on $netNorm");
        $bindSubnetRow($sn, $id, $cidr, $netNorm, $netBin, (int)$pfx, $desc, $siteId, $vlanFk, $vrfId);
    }

    // --- Subnets (IPv6) ---
    $sn6 = $db->prepare(
        "INSERT INTO subnets (id, cidr, ip_version, network, network_bin, prefix, description, site_id, vlan_fk, vrf_id)
         VALUES (?,?,6,?,?,?,?,?,?,?)"
    );
    foreach ([
        [11, '2001:db8::/32',   1, null, null, 'London HQ IPv6 allocation'],
        [12, '2001:db8:1::/48', 1, null, null, 'London servers IPv6'],
        [13, '2001:db8:2::/64', 2, null, null, 'New York IPv6 segment'],
        ] as [$id, $cidr, $siteId, $vlanFk, $vrfId, $desc]) {
        [$net, $pfx] = explode('/', $cidr);
        $rawBin6  = inet_pton($net) ?: throw new \RuntimeException("Invalid IP: $net");
        $netNorm6 = inet_ntop(apply_prefix_mask($rawBin6, (int)$pfx)) ?: throw new \RuntimeException("inet_ntop failed");
        $netBin6  = inet_pton($netNorm6) ?: throw new \RuntimeException("inet_pton failed on $netNorm6");
        $bindSubnetRow($sn6, $id, $cidr, $netNorm6, $netBin6, (int)$pfx, $desc, $siteId, $vlanFk, $vrfId);
    }

    // --- Subnet tags ---
    $st = $db->prepare("INSERT INTO subnet_tags (subnet_id, tag_id) VALUES (?,?)");
    foreach ([
        [4, 1], [4, 4],   // London servers: Production, Monitored
        [5, 1], [5, 3],   // London DMZ: Production, Critical
        [12, 2],          // London servers IPv6: Development
        ] as $t) $st->execute($t);

    // --- Users ---
    // demo: admin, password 'demo' | readonly-user / netops-user: locked accounts for display
    $us = $db->prepare(
        "INSERT INTO users (username, password_hash, role, is_active, name, email) VALUES (?,?,?,?,?,?)"
    );
    foreach ([
        ['demo',          password_hash('demo', PASSWORD_DEFAULT), 'admin',    1, 'Demo Admin',  'demo@example.com'],
        ['readonly-user', '!disabled',                              'readonly', 1, 'Read Only',   'readonly@example.com'],
        ['netops-user',   '!disabled',                              'netops',   1, 'NetOps User', 'netops@example.com'],
        ] as $u) $us->execute($u);

    // --- Addresses ---
    // [subnet_id, ip, hostname, owner, status, note, mac, expires_at, owner_contact_id]
    // Address IDs assigned sequentially: subnet 3 = 1–9, subnet 4 = 10–27,
    // subnet 5 = 28–37, subnet 7 = 38–45, subnet 8 = 46–49, subnet 10 = 50–54.
    $ai = $db->prepare(
        "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, status, note, mac, expires_at, owner_contact_id)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    foreach ([
        // 10.10.1.0/24 — London management (id=1..9)
        [3, '10.10.1.1',   'gw-lon-mgmt',    'NetOps',   'used',     'Default gateway',        'aa:bb:cc:00:01:01', null,         1],
        [3, '10.10.1.2',   'sw-lon-core-01', 'NetOps',   'used',     'Core switch',            'aa:bb:cc:00:01:02', null,         1],
        [3, '10.10.1.3',   'sw-lon-core-02', 'NetOps',   'used',     'Core switch redundant',  'aa:bb:cc:00:01:03', null,         1],
        [3, '10.10.1.10',  'mon-lon-01',     'NetOps',   'used',     'Monitoring server',      '',                  null,         null],
        [3, '10.10.1.20',  'ntp-lon-01',     'NetOps',   'used',     'NTP server',             '',                  null,         null],
        [3, '10.10.1.30',  'dns-lon-01',     'NetOps',   'used',     'Primary DNS',            '',                  null,         null],
        [3, '10.10.1.31',  'dns-lon-02',     'NetOps',   'used',     'Secondary DNS',          '',                  null,         null],
        [3, '10.10.1.50',  '',               '',         'reserved', 'Reserved for IPMI',      '',                  null,         null],
        [3, '10.10.1.51',  '',               '',         'reserved', 'Reserved for IPMI',      '',                  null,         null],
        // 10.10.2.0/24 — London servers (id=10..27)
        [4, '10.10.2.1',   'gw-lon-srv',     'NetOps',   'used',     'Server gateway',         'aa:bb:cc:00:02:01', null,         1],
        [4, '10.10.2.10',  'web-lon-01',     'WebTeam',  'used',     'Web frontend 1',         'de:ad:be:ef:00:01', '2027-06-30', null],
        [4, '10.10.2.11',  'web-lon-02',     'WebTeam',  'used',     'Web frontend 2',         'de:ad:be:ef:00:02', '2027-06-30', null],
        [4, '10.10.2.12',  'web-lon-03',     'WebTeam',  'used',     'Web frontend 3',         'de:ad:be:ef:00:03', '2027-06-30', null],
        [4, '10.10.2.20',  'app-lon-01',     'AppTeam',  'used',     'Application server 1',   '',                  null,         null],
        [4, '10.10.2.21',  'app-lon-02',     'AppTeam',  'used',     'Application server 2',   '',                  null,         null],
        [4, '10.10.2.22',  'app-lon-03',     'AppTeam',  'used',     'Application server 3',   '',                  null,         null],
        [4, '10.10.2.30',  'db-lon-01',      'DBA',      'used',     'Primary database',       'fa:ce:b0:00:00:01', null,         2],
        [4, '10.10.2.31',  'db-lon-02',      'DBA',      'used',     'Replica database',       'fa:ce:b0:00:00:02', null,         2],
        [4, '10.10.2.32',  'db-lon-03',      'DBA',      'used',     'Backup database',        'fa:ce:b0:00:00:03', null,         2],
        [4, '10.10.2.40',  'cache-lon-01',   'AppTeam',  'used',     'Redis cache 1',          '',                  null,         null],
        [4, '10.10.2.41',  'cache-lon-02',   'AppTeam',  'used',     'Redis cache 2',          '',                  null,         null],
        [4, '10.10.2.50',  'storage-lon-01', 'Infra',    'used',     'NFS storage',            '',                  null,         null],
        [4, '10.10.2.51',  'storage-lon-02', 'Infra',    'used',     'NFS storage replica',    '',                  null,         null],
        [4, '10.10.2.100', 'backup-lon-01',  'Infra',    'used',     'Backup server',          '',                  null,         null],
        [4, '10.10.2.200', '',               '',         'free',     '',                        '',                  null,         null],
        [4, '10.10.2.201', '',               '',         'free',     '',                        '',                  null,         null],
        [4, '10.10.2.202', '',               '',         'free',     '',                        '',                  null,         null],
        // 10.10.3.0/27 — London DMZ (id=28..37)
        [5, '10.10.3.1',   'fw-lon-dmz',     'Security', 'used',     'DMZ firewall inside',    '00:50:56:a1:b2:c3', null,         3],
        [5, '10.10.3.2',   'proxy-lon-01',   'Security', 'used',     'Squid proxy',            '00:50:56:a1:b2:c4', null,         3],
        [5, '10.10.3.3',   'proxy-lon-02',   'Security', 'used',     'Squid proxy standby',    '00:50:56:a1:b2:c5', null,         3],
        [5, '10.10.3.4',   'waf-lon-01',     'Security', 'used',     'WAF node 1',             '',                  '2026-12-31', null],
        [5, '10.10.3.5',   'waf-lon-02',     'Security', 'used',     'WAF node 2',             '',                  '2026-12-31', null],
        [5, '10.10.3.6',   'mailgw-lon-01',  'Infra',    'used',     'Mail gateway',           '',                  null,         null],
        [5, '10.10.3.10',  'vpn-lon-01',     'NetOps',   'used',     'VPN concentrator',       '',                  null,         1],
        [5, '10.10.3.20',  '',               '',         'reserved', 'Future load balancer',   '',                  null,         null],
        [5, '10.10.3.21',  '',               '',         'reserved', 'Future load balancer',   '',                  null,         null],
        [5, '10.10.3.30',  '',               '',         'free',     '',                        '',                  null,         null],
        // 10.20.1.0/24 — New York servers (id=38..45)
        [7, '10.20.1.1',   'gw-nyc-srv',     'NetOps',   'used',     'NY server gateway',      '',                  null,         null],
        [7, '10.20.1.10',  'web-nyc-01',     'WebTeam',  'used',     'Web server NY 1',        '',                  '2027-03-31', null],
        [7, '10.20.1.11',  'web-nyc-02',     'WebTeam',  'used',     'Web server NY 2',        '',                  '2027-03-31', null],
        [7, '10.20.1.20',  'app-nyc-01',     'AppTeam',  'used',     'App server NY 1',        '',                  null,         null],
        [7, '10.20.1.21',  'app-nyc-02',     'AppTeam',  'used',     'App server NY 2',        '',                  null,         null],
        [7, '10.20.1.30',  'db-nyc-01',      'DBA',      'used',     'NY database',            '',                  null,         2],
        [7, '10.20.1.40',  'backup-nyc-01',  'Infra',    'used',     'NY backup server',       '',                  null,         null],
        [7, '10.20.1.200', '',               '',         'free',     '',                        '',                  null,         null],
        // 10.20.2.0/24 — New York management (id=46..49)
        [8, '10.20.2.1',   'gw-nyc-mgmt',   'NetOps',   'used',     'NY mgmt gateway',        '',                  null,         1],
        [8, '10.20.2.10',  'mon-nyc-01',    'NetOps',   'used',     'NY monitoring',           '',                  null,         null],
        [8, '10.20.2.20',  'ntp-nyc-01',    'NetOps',   'used',     'NY NTP',                  '',                  null,         null],
        [8, '10.20.2.30',  'dns-nyc-01',    'NetOps',   'used',     'NY DNS primary',          '',                  null,         null],
        // 172.16.1.0/24 — Sydney servers (id=50..54)
        [10, '172.16.1.1',  'gw-syd-srv',   'NetOps',   'used',     'Sydney server gateway',  '',                  null,         null],
        [10, '172.16.1.10', 'web-syd-01',   'WebTeam',  'used',     'Sydney web server',      '',                  null,         null],
        [10, '172.16.1.20', 'app-syd-01',   'AppTeam',  'used',     'Sydney app server',      '',                  null,         null],
        [10, '172.16.1.30', 'db-syd-01',    'DBA',      'used',     'Sydney database',        '',                  null,         null],
        [10, '172.16.1.100','',             '',          'free',     '',                        '',                  null,         null],
        ] as [$sid, $ip, $hn, $ow, $st, $nt, $mac, $exp, $cid]) {
        $bin = inet_pton($ip);
        if ($bin === false) throw new \RuntimeException("inet_pton failed on $ip");
        // #379/#410: bind ip_bin via ipam_bind_binary() (PARAM_LOB) so demo
        // seed rows are BLOB affinity from the start. Other params use
        // bindValue with explicit PARAM_* so the prepare's positional ?
        // placeholders bind cleanly (no execute(array) shorthand).
        $ai->bindValue(1,  $sid, PDO::PARAM_INT);
        $ai->bindValue(2,  $ip,  PDO::PARAM_STR);
        ipam_bind_binary($ai, 3, $bin);
        $ai->bindValue(4,  $hn,  PDO::PARAM_STR);
        $ai->bindValue(5,  $ow,  PDO::PARAM_STR);
        $ai->bindValue(6,  $st,  PDO::PARAM_STR);
        $ai->bindValue(7,  $nt,  PDO::PARAM_STR);
        $ai->bindValue(8,  $mac, PDO::PARAM_STR);
        $ai->bindValue(9,  $exp, $exp === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $ai->bindValue(10, $cid, $cid === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $ai->execute();
    }

    // --- Address tags ---
    // db-lon-01 id=17: Critical(3), Monitored(4) | db-lon-02 id=18: Monitored(4) | fw-lon-dmz id=28: Critical(3)
    //
    // We look up the target IDs by IP rather than hard-coding them because
    // MySQL and SQLite can disagree on the starting value and increment of
    // AUTO_INCREMENT under some configurations (especially when a schema
    // file has pre-populated another table with explicit IDs — which is
    // exactly what v2.10.0 schema.mysql.sql does for schema_migrations).
    // Locking the address_tags fixtures to the actual inserted row IDs
    // keeps the demo fixture engine-agnostic.
    $idByIp = [];
    $idSt = $db->prepare("SELECT id, ip FROM addresses WHERE ip IN ('10.10.2.30','10.10.2.31','10.10.3.1')");
    $idSt->execute();
    /** @var list<array<string, mixed>> $idRows */
    $idRows = $idSt->fetchAll();
    foreach ($idRows as $r) {
        $idByIp[to_str($r['ip'])] = to_int($r['id']);
    }
    $at = $db->prepare("INSERT INTO address_tags (address_id, tag_id) VALUES (?,?)");
    foreach ([
        // db-lon-01 → Critical + Monitored
        [$idByIp['10.10.2.30'] ?? 0, 3],
        [$idByIp['10.10.2.30'] ?? 0, 4],
        // db-lon-02 → Monitored
        [$idByIp['10.10.2.31'] ?? 0, 4],
        // fw-lon-dmz → Critical
        [$idByIp['10.10.3.1']  ?? 0, 3],
        ] as $t) {
        if ($t[0] > 0) $at->execute($t);
    }

    // --- API Keys ---
    $ak = $db->prepare(
        "INSERT INTO api_keys (name, key_hash, is_active, created_by) VALUES (?,?,?,?)"
    );
    $ak->execute(['Monitoring (active)',   hash('sha256', 'demo-api-key-monitoring-1234567890abcdef'), 1, 'demo']);
    $ak->execute(['Old script (inactive)', hash('sha256', 'demo-api-key-old-script-0987654321fedcba'), 0, 'demo']);

    // --- Audit log (backdated) ---
    // Compute the backdated created_at timestamp in PHP so the SQL stays
    // engine-agnostic. The last tuple element remains a human-readable
    // offset string ('-30 days', '-5 days', '-0 seconds', etc.) that
    // strtotime() parses directly, and we format the result as ISO
    // 'YYYY-MM-DD HH:MM:SS' UTC which both SQLite TEXT storage and
    // MySQL DATETIME accept.
    $al = $db->prepare(
        "INSERT INTO audit_log (action, entity_type, entity_id, username, ip, details, created_at)
         VALUES (?,?,?,?,?,?,?)"
    );
    foreach ([
        ['auth.login',        'user',    1,    'demo',          '192.168.1.100', 'login ok',                               '-30 days'],
        ['subnet.create',     'subnet',  3,    'demo',          '192.168.1.100', 'cidr=10.10.1.0/24',                      '-29 days'],
        ['subnet.create',     'subnet',  4,    'demo',          '192.168.1.100', 'cidr=10.10.2.0/24',                      '-29 days'],
        ['address.create',    'address', 1,    'demo',          '192.168.1.100', 'ip=10.10.1.1 subnet_id=3',               '-28 days'],
        ['address.create',    'address', 2,    'demo',          '192.168.1.100', 'ip=10.10.1.2 subnet_id=3',               '-28 days'],
        ['user.create',       'user',    2,    'demo',          '192.168.1.100', 'username=readonly-user role=readonly',   '-27 days'],
        ['user.create',       'user',    3,    'demo',          '192.168.1.100', 'username=netops-user role=netops',        '-27 days'],
        ['auth.login',        'user',    2,    'readonly-user', '10.10.1.55',    'login ok',                               '-26 days'],
        ['address.update',    'address', 11,   'demo',          '192.168.1.100', 'hostname=web-lon-01',                    '-25 days'],
        ['address.update',    'address', 12,   'demo',          '192.168.1.100', 'hostname=web-lon-02',                    '-25 days'],
        ['subnet.create',     'subnet',  5,    'demo',          '192.168.1.100', 'cidr=10.10.3.0/27',                      '-24 days'],
        ['address.create',    'address', 28,   'demo',          '192.168.1.100', 'ip=10.10.3.1 subnet_id=5',               '-23 days'],
        ['apikey.create',     'api_key', 1,    'demo',          '192.168.1.100', 'name=Monitoring (active)',                '-22 days'],
        ['apikey.create',     'api_key', 2,    'demo',          '192.168.1.100', 'name=Old script (inactive)',              '-22 days'],
        ['apikey.deactivate', 'api_key', 2,    'demo',          '192.168.1.100', '',                                       '-21 days'],
        ['site.create',       'site',    2,    'demo',          '192.168.1.100', 'name=New York DC',                       '-20 days'],
        ['site.create',       'site',    3,    'demo',          '192.168.1.100', 'name=Sydney Office',                     '-20 days'],
        ['auth.login',        'user',    3,    'netops-user',   '10.20.1.55',    'login ok',                               '-19 days'],
        ['address.create',    'address', 38,   'demo',          '192.168.1.100', 'ip=10.20.1.1 subnet_id=7',               '-18 days'],
        ['address.create',    'address', 50,   'demo',          '192.168.1.100', 'ip=172.16.1.1 subnet_id=10',             '-17 days'],
        ['vlan.create',       'vlan',    1,    'demo',          '192.168.1.100', 'vlan_id=10 name=Management',             '-16 days'],
        ['vrf.create',        'vrf',     2,    'demo',          '192.168.1.100', 'name=MGMT-VRF rd=65000:100',             '-16 days'],
        ['contact.create',    'contact', 1,    'demo',          '192.168.1.100', 'name=Alice Smith',                       '-15 days'],
        ['contact.create',    'contact', 2,    'demo',          '192.168.1.100', 'name=Bob Jones',                         '-15 days'],
        ['auth.login_failed', 'user',    null, '',              '203.0.113.50',  'username=hacker',                        '-15 days'],
        ['auth.login_failed', 'user',    null, '',              '203.0.113.50',  'username=hacker',                        '-15 days'],
        ['auth.login_blocked','user',    null, '',              '203.0.113.50',  'ip=203.0.113.50',                        '-15 days'],
        ['tag.create',        'tag',     1,    'demo',          '192.168.1.100', 'name=Production colour=#28a745',         '-14 days'],
        ['address.update',    'address', 35,   'demo',          '192.168.1.100', 'status=reserved',                        '-10 days'],
        ['export.csv',        'address', null, 'demo',          '192.168.1.100', 'subnet_id=4',                            '-8 days'],
        ['address.create',    'address', 51,   'demo',          '192.168.1.100', 'ip=172.16.1.10 subnet_id=10',            '-5 days'],
        ['auth.login',        'user',    1,    'demo',          '192.168.1.100', 'login ok',                               '-3 days'],
        ['address.bulk_update','address',null, 'demo',          '192.168.1.100', 'subnet_id=4 selected=3 affected=3',      '-2 days'],
        ['auth.login',        'user',    1,    'demo',          '192.168.1.100', 'login ok',                               '-1 day'],
        ['auth.login',        'user',    1,    'demo',          '192.168.1.100', 'login ok',                               '-0 seconds'],
        ] as $e) {
        // Replace the human-readable offset (last element) with an
        // absolute 'YYYY-MM-DD HH:MM:SS' UTC timestamp computed in PHP.
        $offset = array_pop($e);
        $ts = strtotime((string)$offset);
        $e[] = ($ts !== false) ? gmdate('Y-m-d H:i:s', $ts) : gmdate('Y-m-d H:i:s');
        $al->execute($e);
    }

    // --- Address history ---
    // Address IDs looked up by IP so we don't hardcode AUTO_INCREMENT
    // positions (same rationale as the address_tags block above).
    // Backdated created_at timestamps computed in PHP because the SQLite
    // relative-time modifier syntax is not portable to MySQL.
    $histIds = [];
    $histIdSt = $db->prepare("SELECT id, ip FROM addresses WHERE ip IN ('10.10.1.1','10.10.2.10','10.10.2.12','10.10.2.20','10.10.3.1','10.10.3.20')");
    $histIdSt->execute();
    /** @var list<array<string, mixed>> $histIdRows */
    $histIdRows = $histIdSt->fetchAll();
    foreach ($histIdRows as $r) {
        $histIds[to_str($r['ip'])] = to_int($r['id']);
    }

    $hist = $db->prepare(
        "INSERT INTO address_history (address_id, subnet_id, ip, action, username, client_ip, before_json, after_json, created_at)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    foreach ([
        // gw-lon-mgmt
        [$histIds['10.10.1.1']  ?? 0, 3, '10.10.1.1',  'create', 'demo', '192.168.1.100', null,
         '{"hostname":"gw-lon-mgmt","owner":"NetOps","status":"used","note":"Default gateway","mac":"aa:bb:cc:00:01:01"}',
         '-28 days'],
        // web-lon-01
        [$histIds['10.10.2.10'] ?? 0, 4, '10.10.2.10', 'create', 'demo', '192.168.1.100', null,
         '{"hostname":"","owner":"WebTeam","status":"used","note":"","mac":""}',
         '-28 days'],
        [$histIds['10.10.2.10'] ?? 0, 4, '10.10.2.10', 'update', 'demo', '192.168.1.100',
         '{"hostname":"","owner":"WebTeam","status":"used","note":"","mac":""}',
         '{"hostname":"web-lon-01","owner":"WebTeam","status":"used","note":"Web frontend 1","mac":"de:ad:be:ef:00:01","expires_at":"2027-06-30"}',
         '-25 days'],
        // web-lon-03
        [$histIds['10.10.2.12'] ?? 0, 4, '10.10.2.12', 'create', 'demo', '192.168.1.100', null,
         '{"hostname":"web-lon-03","owner":"WebTeam","status":"used","note":"Web frontend 3","mac":"de:ad:be:ef:00:03"}',
         '-28 days'],
        // app-lon-01
        [$histIds['10.10.2.20'] ?? 0, 4, '10.10.2.20', 'create', 'demo', '192.168.1.100', null,
         '{"hostname":"app-lon-01","owner":"AppTeam","status":"used","note":"Application server 1","mac":""}',
         '-28 days'],
        // fw-lon-dmz
        [$histIds['10.10.3.1']  ?? 0, 5, '10.10.3.1',  'create', 'demo', '192.168.1.100', null,
         '{"hostname":"fw-lon-dmz","owner":"Security","status":"used","note":"DMZ firewall inside","mac":"00:50:56:a1:b2:c3"}',
         '-23 days'],
        // 10.10.3.20 (future load balancer)
        [$histIds['10.10.3.20'] ?? 0, 5, '10.10.3.20', 'create', 'demo', '192.168.1.100', null,
         '{"hostname":"","owner":"","status":"free","note":"","mac":""}',
         '-23 days'],
        [$histIds['10.10.3.20'] ?? 0, 5, '10.10.3.20', 'update', 'demo', '192.168.1.100',
         '{"hostname":"","owner":"","status":"free","note":"","mac":""}',
         '{"hostname":"","owner":"","status":"reserved","note":"Future load balancer","mac":""}',
         '-10 days'],
        ] as $h) {
        if ($h[0] === 0) continue;
        // Replace the human-readable offset with an absolute UTC timestamp.
        $offset = array_pop($h);
        $ts = strtotime((string)$offset);
        $h[] = ($ts !== false) ? gmdate('Y-m-d H:i:s', $ts) : gmdate('Y-m-d H:i:s');
        $hist->execute($h);
    }

    // v2.11.0 #388: advance every identity sequence past the explicit IDs
    // the seed just inserted. Postgres `GENERATED BY DEFAULT AS IDENTITY`
    // columns accept explicit ids at INSERT time, but do NOT auto-advance
    // the backing sequence — so a subsequent implicit insert would pick
    // id=1 and collide against our fixture ids. SQLite and MySQL handle
    // this automatically via ROWID / AUTO_INCREMENT.
    if (ipam_dialect()->driver_name() === 'pgsql') {
        $seedTables = [
            'sites', 'vrfs', 'vlans', 'vlan_ranges', 'subnets', 'contacts',
            'tags', 'addresses', 'api_keys', 'users', 'aggregates',
            'pd_pools', 'pd_delegations', 'scan_schedules', 'scan_results',
            'address_history', 'audit_log',
        ];
        foreach ($seedTables as $t) {
            $db->exec(
                "SELECT setval(pg_get_serial_sequence('$t', 'id'), "
                . "COALESCE((SELECT MAX(id) FROM $t), 1), "
                . "(SELECT MAX(id) FROM $t) IS NOT NULL)"
            );
        }
    }
}
