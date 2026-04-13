<?php
declare(strict_types=1);
/**
 * export_dns.php — BIND-format zone file export
 *
 * Exports forward (A/AAAA) and/or reverse (PTR) zone data for a given subnet.
 *
 * GET params:
 *   subnet_id  — int, required
 *   type       — "forward" | "reverse" | "both" (default: both)
 *
 * Produces a plain-text BIND zone file download.
 */
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

$subnetId = to_int($_GET['subnet_id'] ?? 0);
$type     = to_str($_GET['type'] ?? 'both');
if (!in_array($type, ['forward', 'reverse', 'both'], true)) {
    $type = 'both';
}

if ($subnetId <= 0) {
    http_response_code(400);
    echo 'subnet_id required.';
    exit;
}

// Load subnet
$st = $db->prepare("SELECT id, cidr, ip_version, network, prefix, description FROM subnets WHERE id = :id");
$st->execute([':id' => $subnetId]);
/** @var array<string,mixed>|false $subnet */
$subnet = $st->fetch();
if (!$subnet) {
    http_response_code(404);
    echo 'Subnet not found.';
    exit;
}

// Load addresses with hostnames
$st = $db->prepare("
    SELECT ip, hostname
    FROM addresses
    WHERE subnet_id = :sid AND hostname != ''
    ORDER BY ip_bin
");
$st->execute([':sid' => $subnetId]);
/** @var list<array<string,mixed>> $addresses */
$addresses = $st->fetchAll();

$cidr      = to_str($subnet['cidr']);
$version   = to_int($subnet['ip_version']);
$prefix    = to_int($subnet['prefix']);
$network   = to_str($subnet['network']);
$cidrSlug  = str_replace(['/', ':'], ['-', '-'], $cidr);
$filename  = safe_export_filename("ipam-dns-{$cidrSlug}") . '.txt';

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');

$serial = date('Ymd') . '01';
$origin = '';

// --------------------------------------------------------------------------
// Forward zone (A/AAAA records)
// --------------------------------------------------------------------------
if ($type === 'forward' || $type === 'both') {
    $rrtype = $version === 6 ? 'AAAA' : 'A';
    echo "; BIND forward zone export — $cidr\n";
    echo "; Generated: " . date('c') . "\n";
    echo "; Simple PHP IPAM\n\n";
    echo "\$ORIGIN .\n";
    echo "\$TTL 3600\n\n";

    $fwdEntries = [];
    foreach ($addresses as $addr) {
        $ip       = to_str($addr['ip']);
        $hostname = to_str($addr['hostname']);
        if ($hostname === '') continue;
        // Strip trailing dot if user included one; we'll add it back
        $fqdn = rtrim($hostname, '.') . '.';
        $fwdEntries[] = sprintf("%-40s IN  %-6s %s\n", $fqdn, $rrtype, $ip);
    }
    if ($fwdEntries) {
        foreach ($fwdEntries as $line) {
            echo $line;
        }
    } else {
        echo "; No hostnames found in this subnet.\n";
    }

    if ($type === 'both') echo "\n\n";
}

// --------------------------------------------------------------------------
// Reverse zone (PTR records)
// --------------------------------------------------------------------------
if ($type === 'reverse' || $type === 'both') {
    echo "; BIND reverse zone export — $cidr\n";
    echo "; Generated: " . date('c') . "\n";
    echo "; Simple PHP IPAM\n\n";

    if ($version === 4) {
        // Build ORIGIN from network octets reversed
        $octets   = explode('.', $network);
        $revParts = array_slice(array_reverse($octets), 0, (int)floor((32 - $prefix) / 8));
        // Drop the zero octets from the host portion — keep only network portion reversed
        $fullRev  = implode('.', array_reverse($octets));
        // arpa origin: reverse first N octets based on prefix
        $netOctets = (int)ceil($prefix / 8);
        $origin    = implode('.', array_reverse(array_slice($octets, 0, $netOctets))) . '.in-addr.arpa.';

        echo "\$ORIGIN $origin\n";
        echo "\$TTL 3600\n\n";

        foreach ($addresses as $addr) {
            $ip       = to_str($addr['ip']);
            $hostname = to_str($addr['hostname']);
            if ($hostname === '') continue;
            $parts    = explode('.', $ip);
            // PTR label: last (4-netOctets) octets reversed
            $hostParts = array_reverse(array_slice($parts, $netOctets));
            $label     = implode('.', $hostParts);
            $fqdn      = rtrim($hostname, '.') . '.';
            echo sprintf("%-30s IN  PTR  %s\n", $label, $fqdn);
        }
    } else {
        // IPv6 reverse zone (nibble format)
        // Expand the network address to full 32-hex-char form
        /** @var string|false $binNet */
        $binNet = inet_pton($network);
        if ($binNet === false) {
            echo "; Cannot compute reverse zone for this address.\n";
        } else {
            $hex    = bin2hex($binNet);
            // The origin covers the network prefix bits (rounded up to nibble boundary)
            $nibbles = (int)ceil($prefix / 4);
            $revHex  = implode('.', array_reverse(str_split($hex)));
            $origin  = implode('.', array_reverse(str_split(substr($hex, 0, $nibbles)))) . '.ip6.arpa.';

            echo "\$ORIGIN $origin\n";
            echo "\$TTL 3600\n\n";

            foreach ($addresses as $addr) {
                $ip       = to_str($addr['ip']);
                $hostname = to_str($addr['hostname']);
                if ($hostname === '') continue;
                /** @var string|false $binIp */
                $binIp = inet_pton($ip);
                if ($binIp === false) continue;
                $ipHex    = bin2hex($binIp);
                // Host nibbles: everything after the network prefix
                $hostNibbles = array_reverse(str_split(substr($ipHex, $nibbles)));
                $label       = implode('.', $hostNibbles);
                $fqdn        = rtrim($hostname, '.') . '.';
                echo sprintf("%-60s IN  PTR  %s\n", $label, $fqdn);
            }
        }
    }
    if (!$addresses || !array_filter($addresses, fn($a) => to_str($a['hostname']) !== '')) {
        echo "; No hostnames found in this subnet.\n";
    }
}
