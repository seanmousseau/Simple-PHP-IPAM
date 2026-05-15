<?php
declare(strict_types=1);

/**
 * @module config
 *
 * ipam_config() accessor — the ADR-003 Option D recommendation. Read access
 * to $GLOBALS['config'] for modules being extracted from lib.php in v3.30.0
 * (db, audit, settings, user_preferences, presentation, auth ×4). Replaces
 * the per-function `global $config;` pattern with a single function call.
 *
 * Two-mode cache invalidation per ADR-003 § Open questions Q1:
 *   1. Auto-detect — wholesale reassignment of $GLOBALS['config'] is caught
 *      via a count-plus-keys fingerprint on every call. Cheap for the small
 *      arrays this project uses (~10-30 keys).
 *   2. Explicit — ipam_config_invalidate_cache() bumps a generation counter
 *      that is folded into the sentinel, forcing the next ipam_config() call
 *      to refresh even if the new $config fingerprints identically to the old
 *      (e.g., in-place mutation of a single key, common in test fixtures and
 *      lazy-gen helpers like ipam_config_inject_or_replace_key()).
 *
 * api.php / page handlers / non-v3.30.0-extracted modules continue using
 * `global $config;` until the v3.31.0/v3.32.0 sweep (#1207). The
 * lib-module-linter $config-ban rule (Phase 7 Task 7.3) becomes enforceable
 * once those conversions land.
 *
 * Dependencies: none. Pure read-side accessor.
 */

/**
 * Read a single key from $GLOBALS['config'] (or the whole array if $key is
 * null). Returns $default when the key is absent.
 *
 * The no-key form ($key === null) returns the whole config array typed as
 * IpamConfig — callers passing the result to functions that expect the full
 * config shape (recovery_mode_enabled(), ipam_update_check(), …) get a
 * precise type rather than `mixed`. A wholesale-replaced or never-populated
 * $GLOBALS['config'] still flows through as IpamConfig; the static cache
 * coerces a non-array value to []. The single-key form stays `mixed`.
 *
 * @param string|null $key
 * @param mixed $default
 * @return ($key is null ? IpamConfig : mixed)
 */
function ipam_config(?string $key = null, mixed $default = null): mixed
{
    static $cache = null;
    static $sentinel = null;

    $current = $GLOBALS['config'] ?? [];
    $generationRaw = $GLOBALS['__ipam_config_cache_gen'] ?? 0;
    $generation = is_int($generationRaw) ? $generationRaw : 0;
    $fingerprint = is_array($current)
        ? ($generation . '|' . count($current) . '|' . implode(',', array_keys($current)))
        : ($generation . '|');

    if ($cache === null || $fingerprint !== $sentinel) {
        $cache = is_array($current) ? $current : [];
        $sentinel = $fingerprint;
    }

    if ($key === null) {
        return $cache;
    }

    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

/**
 * Read a nested path from $config. Returns null if any segment is missing
 * or if called with no path segments (use ipam_config() to read the whole array).
 *
 * @param string ...$path
 * @return mixed
 */
function ipam_config_nested(string ...$path): mixed
{
    if ($path === []) {
        return null;
    }
    $cursor = ipam_config();
    foreach ($path as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            return null;
        }
        $cursor = $cursor[$segment];
    }
    return $cursor;
}

/**
 * Force the next ipam_config() / ipam_config_nested() call to re-read
 * $GLOBALS['config']. Required after in-place mutation that doesn't change
 * the array's count/keys (e.g., overwriting an existing key with a new
 * value), and as a deterministic reset for test fixtures.
 */
function ipam_config_invalidate_cache(): void
{
    $current = $GLOBALS['__ipam_config_cache_gen'] ?? 0;
    $GLOBALS['__ipam_config_cache_gen'] = (is_int($current) ? $current : 0) + 1;
}
