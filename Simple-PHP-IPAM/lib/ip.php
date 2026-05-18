<?php
declare(strict_types=1);

/**
 * @module ip
 *
 * Pure IP/CIDR math + binary IP packing/unpacking helpers. Extracted from
 * lib.php in v3.30.0 (ADR-004 Phase 2 Task 2.2). Functions stay in the
 * global namespace per ADR-004 Option E.
 *
 * Dependencies: PHP built-ins + lib/utils.php (to_int, to_str, e). No
 * $config access, no ipam_db() / ipam_dialect() / ipam_setting() calls,
 * no HTML rendering. Anything that touches the DB lives in
 * lib/subnets.php / lib/addresses.php (deferred to v3.32.0).
 *
 * CLAUDE.md hot-cache invariant #1 lives here: ipam_bind_binary() binds
 * binary IPs at native length (4B / 16B) via PDO::PARAM_LOB on every
 * driver. The function body below is byte-identical to its pre-move form;
 * the surrounding doc-block from lib.php is preserved verbatim.
 */

/**
 * Bind a raw binary value (typically inet_pton output) to a PDOStatement
 * parameter using PDO::PARAM_LOB on every driver (#410).
 *
 * **Why this helper exists.** Pre-v2.9.0 the codebase passed binary IP
 * values through positional execute(), which ultimately hits PDO::PARAM_STR.
 * On SQLite this stores the value with TEXT affinity even though the column
 * is declared BLOB — SQLite's loose typing honors the binding's affinity at
 * insert time, not the column's declared type. The bytes round-trip
 * correctly, but `ORDER BY ip_bin` and range queries break the moment new
 * rows arrive bound as BLOB, because SQLite's comparison rules say any BLOB
 * sorts greater than any TEXT regardless of byte content.
 *
 * On MySQL / Postgres the situation is worse: PARAM_STR string-escapes high
 * bytes and truncates at null bytes, corrupting every stored IP.
 *
 * This helper uses PARAM_LOB unconditionally so the same binding works on
 * SQLite (BLOB affinity), MySQL (VARBINARY), and Postgres (BYTEA). The
 * existing-data normalization happens in the 2.9.0-blob-affinity migration.
 *
 * **Storage format**: native length, never padded. IPv4 = 4 bytes,
 * IPv6 = 16 bytes. All three engines do byte-wise memcmp comparison on
 * native-length values, so sort order is equivalent across engines.
 *
 * Round-trip test vectors (covered by tests/BinaryBindTest.php):
 *   inet_pton('10.0.0.0')         = \x0A\x00\x00\x00  (null bytes after first)
 *   inet_pton('2001:db8::')       = \x20\x01\x0D\xB8...  (mostly null bytes)
 *   inet_pton('255.255.255.255')  = \xFF\xFF\xFF\xFF  (all high bytes)
 */
/**
 * @param int|string $param 1-based positional index, or ':name' for a named placeholder.
 */
function ipam_bind_binary(PDOStatement $stmt, int|string $param, string $bin): void
{
    $stmt->bindValue($param, $bin, PDO::PARAM_LOB);
}

/**
 * True if $ip falls inside any of the supplied CIDR blocks. Accepts both
 * IPv4 and IPv6 strings and CIDRs; returns false on parse error.
 *
 * @param list<string> $cidrs
 */
function ip_in_any_cidr(string $ip, array $cidrs): bool
{
    $ipBin = @inet_pton($ip);
    if ($ipBin === false) {
        return false;
    }
    foreach ($cidrs as $cidr) {
        $cidr = trim($cidr);
        if ($cidr === '' || strpos($cidr, '/') === false) {
            continue;
        }
        [$net, $prefixStr] = explode('/', $cidr, 2);
        $net = trim($net);
        $prefixStr = trim($prefixStr);
        // CR #1100: reject non-numeric prefixes before casting. Without
        // this guard, a typo like "10.0.0.0/abc" would cast to /0 and
        // ip_in_any_cidr() would match every IPv4 address — turning a
        // proxy_trust_cidrs typo into a "trust everyone" XFF spoofing
        // foothold. ctype_digit() is strict (no whitespace, no signs).
        if ($prefixStr === '' || !ctype_digit($prefixStr)) {
            continue;
        }
        $netBin = @inet_pton($net);
        if ($netBin === false || strlen($netBin) !== strlen($ipBin)) {
            continue;
        }
        $prefix = (int)$prefixStr;
        $bits   = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $bits) {
            continue;
        }
        $masked   = apply_prefix_mask($ipBin, $prefix);
        $netMaskd = apply_prefix_mask($netBin, $prefix);
        if ($masked === $netMaskd) {
            return true;
        }
    }
    return false;
}

/** @return array{version: int, network: string, prefix: int, net_bin: string}|null */
function parse_cidr(string $cidr): ?array
{
    $cidr = trim($cidr);
    if (strpos($cidr, '/') === false) return null;
    [$ip, $prefixStr] = explode('/', $cidr, 2);

    $ip = trim($ip);
    $prefixStr = trim($prefixStr);

    $ipBin = @inet_pton($ip);
    if ($ipBin === false) return null;

    $len = strlen($ipBin);
    $version = ($len === 4) ? 4 : (($len === 16) ? 6 : 0);
    if ($version === 0) return null;

    if (!ctype_digit($prefixStr)) return null;
    $prefix = (int)$prefixStr;
    $max = ($version === 4) ? 32 : 128;
    if ($prefix < 0 || $prefix > $max) return null;

    $netBin = apply_prefix_mask($ipBin, $prefix);
    $network = inet_ntop($netBin);
    if ($network === false) return null;

    return [
        'version' => $version,
        'network' => $network,
        'prefix' => $prefix,
        'net_bin' => $netBin,
    ];
}

/**
 * Zero out the host bits of a binary IP address, returning the network
 * address at the same byte length. Works on 4-byte (IPv4) and 16-byte
 * (IPv6) inputs; $prefix is clamped to [0, bit-width]. Pure — no side effects.
 *
 * @param string $ipBin Raw binary IP (4 or 16 bytes, output of inet_pton()).
 * @param int    $prefix CIDR prefix length.
 * @return string        Binary network address, same length as $ipBin.
 */
function apply_prefix_mask(string $ipBin, int $prefix): string
{
    $len = strlen($ipBin);
    $maxBits = ($len === 4) ? 32 : 128;
    $prefix = max(0, min($prefix, $maxBits));

    $fullBytes = intdiv($prefix, 8);
    $remBits = $prefix % 8;

    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $b = ord($ipBin[$i]);
        if ($i < $fullBytes) $out .= chr($b);
        elseif ($i === $fullBytes && $remBits !== 0) {
            $mask = (0xFF << (8 - $remBits)) & 0xFF;
            $out .= chr($b & $mask);
        } else $out .= chr(0);
    }
    return $out;
}

/**
 * Compute the IPv4 broadcast address (binary) for a given network+prefix.
 *
 * Returns null when the concept does not apply:
 *   - IPv6 networks (no broadcast)
 *   - /32 (single host)
 *   - /31 (RFC 3021 point-to-point; both addresses are usable)
 *
 * @param string $netBin 4-byte IPv4 network address (output of apply_prefix_mask)
 */
function ipam_compute_broadcast_bin(string $netBin, int $prefix): ?string
{
    if (strlen($netBin) !== 4) return null;       // IPv6 has no broadcast
    if ($prefix < 0 || $prefix >= 31) return null; // /31, /32 have no broadcast
    $hostBits = 32 - $prefix;
    $out = '';
    for ($i = 0; $i < 4; $i++) {
        $byteIdx = 3 - $i; // little-endian walk from LSB
        $n = ord($netBin[$byteIdx]);
        if ($hostBits >= 8) {
            $out = chr(0xFF) . $out;
            $hostBits -= 8;
        } elseif ($hostBits > 0) {
            $lowMask = (1 << $hostBits) - 1;
            $out = chr($n | $lowMask) . $out;
            $hostBits = 0;
        } else {
            $out = chr($n) . $out;
        }
    }
    return $out;
}

/**
 * Compute the conventional first-usable-host gateway address (network + 1)
 * for an IPv4 network. Returns null when $netBin is not 4 bytes (e.g. an
 * IPv6 network) or when $prefix is outside 0..30 (no usable host range).
 * Pure — no side effects.
 *
 * @param string $netBin 4-byte IPv4 network address.
 * @param int    $prefix CIDR prefix length.
 * @return string|null   4-byte gateway address, or null when not applicable.
 */
function ipam_compute_gateway_bin(string $netBin, int $prefix): ?string
{
    if (strlen($netBin) !== 4) return null;
    if ($prefix < 0 || $prefix > 30) return null;
    $int = ipv4_bin_to_int($netBin);
    return ipv4_int_to_bin($int + 1);
}

/**
 * True when $ip falls inside the network defined by $network/$prefix.
 * Accepts IPv4 or IPv6 strings; returns false on parse error or when the
 * IP and network are different families. Pure — no side effects.
 *
 * @param string $ip      IP address string to test.
 * @param string $network Network address string.
 * @param int    $prefix  CIDR prefix length.
 */
function ip_in_cidr(string $ip, string $network, int $prefix): bool
{
    $ipBin = @inet_pton(trim($ip));
    $netBin = @inet_pton(trim($network));
    if ($ipBin === false || $netBin === false) return false;
    if (strlen($ipBin) !== strlen($netBin)) return false;
    return hash_equals(apply_prefix_mask($ipBin, $prefix), $netBin);
}

/** @return array{ip: string, bin: string, version: int}|null */
function normalize_ip(string $ip): ?array
{
    $bin = @inet_pton(trim($ip));
    if ($bin === false) return null;
    $normalized = inet_ntop($bin);
    if ($normalized === false) return null;
    return ['ip' => $normalized, 'bin' => $bin, 'version' => (strlen($bin) === 4) ? 4 : 6];
}

/* ---------------- IPv4 helpers ---------------- */

/**
 * Convert a 4-byte big-endian IPv4 binary address to an unsigned 32-bit
 * integer. Pure — no side effects.
 *
 * @param string $bin 4-byte IPv4 address (output of inet_pton()).
 * @return int        Unsigned 32-bit integer in the range [0, 0xFFFFFFFF].
 */
function ipv4_bin_to_int(string $bin): int
{
    $unpacked = unpack('N', $bin);
    $n = $unpacked !== false ? $unpacked[1] : 0;
    return to_int($n & 0xFFFFFFFF);
}

/**
 * Convert a 32-bit integer to a 4-byte big-endian IPv4 binary address.
 * The value is masked to 32 bits. Pure — no side effects.
 *
 * @param int $n IPv4 address as an integer.
 * @return string 4-byte binary IPv4 address.
 */
function ipv4_int_to_bin(int $n): string
{
    $n = $n & 0xFFFFFFFF;
    return pack('N', $n);
}

/**
 * Convert a 32-bit integer IPv4 address to dotted-quad text. Returns ''
 * if conversion fails. Pure — no side effects.
 *
 * @param int $n IPv4 address as an integer.
 * @return string Dotted-quad string, e.g. '10.0.0.1', or '' on failure.
 */
function ipv4_int_to_text(int $n): string
{
    return inet_ntop(ipv4_int_to_bin($n)) ?: '';
}

/**
 * Number of assignable host addresses in an IPv4 network of the given
 * prefix. Returns 1 for /32, 2 for /31 (RFC 3021), and total minus the
 * network+broadcast pair otherwise. Pure — no side effects.
 *
 * @param int $prefix CIDR prefix length (0–32).
 * @return int        Count of assignable hosts.
 */
function ipv4_assignable_count(int $prefix): int
{
    if ($prefix >= 32) return 1;
    if ($prefix === 31) return 2;
    $hostBits = 32 - $prefix;
    $total = ($hostBits === 32) ? 4294967296 : (1 << $hostBits);
    $assignable = $total - 2;
    return ($assignable > 0) ? (int)$assignable : 0;
}

/**
 * True when the child network is contained within the parent network at the
 * parent's prefix length. Both binary addresses must be the same family/length.
 * Pure — no side effects.
 *
 * @param string $parentNetBin Parent network binary address.
 * @param int    $parentPrefix Parent CIDR prefix length.
 * @param string $childNetBin  Child network binary address.
 */
function subnet_contains_bin(string $parentNetBin, int $parentPrefix, string $childNetBin): bool
{
    $masked = apply_prefix_mask($childNetBin, $parentPrefix);
    return hash_equals($masked, $parentNetBin);
}

/**
 * Compute the IPv4 broadcast address (binary) by setting all host bits.
 * Unlike ipam_compute_broadcast_bin(), this returns a value for every
 * prefix (e.g. the network itself for /32). Pure — no side effects.
 *
 * @param string $netBin 4-byte IPv4 network address.
 * @param int    $prefix CIDR prefix length.
 * @return string        4-byte binary broadcast address.
 */
function ipv4_broadcast_bin(string $netBin, int $prefix): string
{
    $hostBits = 32 - $prefix;
    if ($hostBits <= 0) return $netBin;

    $unpacked = unpack('N', $netBin);
    $n = $unpacked !== false ? $unpacked[1] : 0;
    $hostMask = ($hostBits === 32) ? 0xFFFFFFFF : ((1 << $hostBits) - 1);
    $b = ($n | $hostMask) & 0xFFFFFFFF;

    return pack('N', $b);
}

/**
 * Compute the IPv4 broadcast address as an integer by setting all host bits
 * of the given network integer. Pure — no side effects.
 *
 * @param int $networkInt IPv4 network address as an integer.
 * @param int $prefix     CIDR prefix length.
 * @return int            Broadcast address as an unsigned 32-bit integer.
 */
function ipv4_broadcast_int(int $networkInt, int $prefix): int
{
    $hostBits = 32 - $prefix;
    if ($hostBits <= 0) return $networkInt;
    $hostMask = ($hostBits === 32) ? 0xFFFFFFFF : ((1 << $hostBits) - 1);
    return to_int(($networkInt | $hostMask) & 0xFFFFFFFF);
}

/* ---------------- IPv6 helpers ---------------- */

/**
 * Increment a 16-byte IPv6 binary address by 1.
 * Returns all-zeros if the address overflows (all-0xFF).
 */
function ipv6_bin_increment(string $bin): string
{
    /** @var array<int, int> $bytes */
    $bytes = array_values(unpack('C16', $bin) ?: []);
    for ($i = 15; $i >= 0; $i--) {
        if ($bytes[$i] < 255) {
            $bytes[$i]++;
            return pack('C16', ...$bytes);
        }
        $bytes[$i] = 0;
    }
    return pack('C16', ...array_fill(0, 16, 0));
}

/* ---------------- CIDR helpers ---------------- */

/**
 * Convert a dotted-quad IPv4 netmask to a CIDR prefix length. Returns null
 * when the mask is not parseable IPv4 or is not contiguous (a 1-bit appears
 * after a 0-bit). Pure — no side effects.
 *
 * @param string $mask Dotted-quad netmask, e.g. '255.255.255.0'.
 * @return int|null    Prefix length 0–32, or null on invalid/non-contiguous mask.
 */
function netmask_to_prefix(string $mask): ?int
{
    $bin = @inet_pton(trim($mask));
    if ($bin === false || strlen($bin) !== 4) return null;

    $unpacked = unpack('N', $bin);
    $n = $unpacked !== false ? $unpacked[1] : 0;
    $prefix = 0;
    $seenZero = false;

    for ($i = 31; $i >= 0; $i--) {
        $bit = ($n >> $i) & 1;
        if ($bit === 1) {
            if ($seenZero) return null;
            $prefix++;
        } else {
            $seenZero = true;
        }
    }
    return $prefix;
}

/** @param array{ip: string, bin: string, version: int} $normIp */
function cidr_from_ip_and_prefix(array $normIp, int $prefix): string
{
    $max = ($normIp['version'] === 4) ? 32 : 128;
    if ($prefix < 0 || $prefix > $max) throw new RuntimeException("Bad prefix");
    $netBin = apply_prefix_mask($normIp['bin'], $prefix);
    return inet_ntop($netBin) . '/' . $prefix;
}

/* ---------------- Subnet overlap presentation ---------------- */

/** @param array{parents: list<string>, children: list<string>} $overlaps */
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
