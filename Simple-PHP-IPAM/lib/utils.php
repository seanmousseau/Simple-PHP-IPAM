<?php
declare(strict_types=1);

/**
 * @module utils
 *
 * Pure helper functions with zero dependencies on $config, the DB, or other
 * lib modules. Extracted from lib.php in v3.30.0 (ADR-004 Phase 2). Functions
 * stay in the global namespace per ADR-004 Option E.
 *
 * Inclusion rule: a function lives here only if it has no `global $config`,
 * no ipam_db() call, no IP/CIDR math, no rendering, no settings access.
 */

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Safely coerce a mixed value (e.g. PDO fetch result or superglobal) to int.
 * Needed at PHPStan level 9 where (int) casts on mixed are disallowed.
 */
function to_int(mixed $value): int
{
    if (is_int($value)) return $value;
    if (is_float($value)) return (int)$value;
    if (is_string($value)) return (int)$value;
    if (is_bool($value)) return $value ? 1 : 0;
    return 0;
}

/**
 * Safely coerce a mixed value (e.g. PDO fetch result or superglobal) to string.
 * Needed at PHPStan level 9 where (string) casts on mixed are disallowed.
 *
 * Pre-v3.30.0 this function had two definitions (one in init.php, one in
 * lib.php guarded by function_exists) — issue #916. Both bodies were
 * byte-identical; the lib/utils.php definition is now the single source
 * of truth, loaded ahead of init.php's first call site via init.php's
 * require_once.
 */
function to_str(mixed $value): string
{
    if (is_string($value)) return $value;
    if (is_int($value) || is_float($value)) return (string)$value;
    if (is_bool($value)) return $value ? '1' : '';
    if ($value === null) return '';
    return '';
}

function format_bytes(int $bytes): string
{
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int)floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    $val = $bytes / (1024 ** $i);
    return ($i === 0 ? (string)$bytes : round($val, 1)) . ' ' . $units[$i];
}

function q_int(string $key, int $default, int $min, int $max): int
{
    $v = $_GET[$key] ?? null;
    if ($v === null || $v === '') return $default;
    if (!is_scalar($v)) return $default;
    if (!preg_match('/^-?\d+$/', (string)$v)) return $default;

    $n = (int)$v;
    if ($n < $min) return $min;
    if ($n > $max) return $max;
    return $n;
}

/**
 * Normalise a version string to three dot-separated segments so that
 * version_compare('1.2', '1.2.0') and version_compare('1.2.1', '1.2') work
 * as expected regardless of how many segments the installed version has.
 *
 * Examples: '1.2' → '1.2.0',  'v1.2.1' → '1.2.1',  '0.15' → '0.15.0'
 */
function ipam_normalise_version(string $v): string
{
    $v = ltrim($v, 'v');
    $parts = explode('.', $v);
    while (count($parts) < 3) $parts[] = '0';
    return implode('.', $parts);
}

function base64url_encode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function base64url_decode(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    $result = base64_decode($s, true);
    if ($result === false) throw new RuntimeException('Invalid base64url string');
    return $result;
}
