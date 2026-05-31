<?php
declare(strict_types=1);

/**
 * Simple PHP IPAM — lazy auto-generation of `app_secret` (v3.28.2 #1178).
 *
 * `app_secret` protects TOTP secrets at rest, HMACs restore-staging tokens,
 * and decrypts legacy IPAMBKP1/IPAMBKP2 backups. Historically it shipped
 * blank in `config.php.example` and operators were expected to set it
 * manually; many never did, which silently broke 2FA enrollment and
 * restore-staging.
 *
 * This helper mirrors the lifecycle of `ipam_bootstrap_key()`
 * (see lib/vault.php): the first call that finds `app_secret` empty
 * generates `base64_encode(random_bytes(32))`, persists it into config.php
 * via the same atomic `ipam_config_inject_or_replace_key()` writer, and
 * caches the value for the lifetime of the request. Subsequent requests
 * read the persisted value on cold start.
 *
 * Failure modes mirror bootstrap_key's:
 *   - config.php read-only → RuntimeException with an actionable message
 *     quoting the manual command. The helper refuses to silently
 *     regenerate on every request (which would invalidate every
 *     previously-encrypted TOTP secret on the install).
 *   - inject failure → RuntimeException wrapping the underlying error,
 *     same actionable manual-step guidance.
 *
 * The static cache is keyed on the resolved config path so multiple
 * sandboxed configs (notably in the unit-test suite) do not collide.
 *
 * On the auto-generation path the helper records the event via
 * `ipam_install_key_announce_record('app_secret')` if that function is
 * loaded; the gate exists because this file is required from lib.php's
 * bootstrap and the announce helper itself lives further down in lib.php.
 * A failed announce never propagates (see ipam_install_key_announce_record).
 */

/**
 * Return the install's `app_secret`, auto-generating + persisting it on
 * first use if blank. See file docblock for the full contract.
 *
 * @param string|null $configPathOverride Test seam — production callers
 *   pass `null` and the helper resolves `config.php` adjacent to itself.
 * @throws \RuntimeException if the config path cannot be resolved, the
 *   file is not writable on the auto-gen path, or the injector fails.
 */
function ipam_app_secret(?string $configPathOverride = null): string
{
    /** @var array<string,string> $cached */
    static $cached = [];

    // v3.36.1 (#1329): short-circuit on in-memory app_secret BEFORE doing any
    // filesystem work. Pre-v3.36.1 config.php was tracked in git so it was
    // always on disk; now it's gitignored and a fresh checkout (CI, tests,
    // some container builds) starts without one. The realpath() check used
    // to assume the file always existed and threw "config.php path does
    // not resolve" before noticing that the in-memory $config already
    // carried a valid app_secret. The autogen path still requires the
    // file (we must persist) and still throws the same error there — this
    // change only spares callers who never need to write.
    if ($configPathOverride === null) {
        $cfgAppSecret = ipam_config('app_secret');
        if (is_string($cfgAppSecret) && $cfgAppSecret !== '') {
            // Per-path cache below is keyed on $configPath; when the in-memory
            // config is the source of truth we just return without caching.
            // Repeat calls re-hit ipam_config(), which has its own cache.
            return $cfgAppSecret;
        }
    }

    if ($configPathOverride !== null) {
        $configPath = realpath($configPathOverride);
        if ($configPath === false) {
            throw new RuntimeException(
                'app_secret auto-generation: override config path does not resolve: '
                . $configPathOverride
            );
        }
    } else {
        $resolved = realpath(__DIR__ . '/../config.php');
        if ($resolved === false) {
            throw new RuntimeException(
                'app_secret auto-generation: config.php path does not resolve. '
                . 'Generate a value manually with `php -r "echo base64_encode(random_bytes(32));"` '
                . "and add `'app_secret' => '<value>',` to config.php, then retry."
            );
        }
        $configPath = $resolved;
    }

    if (isset($cached[$configPath])) {
        return $cached[$configPath];
    }

    // ADR-003 (#1207): config read via ipam_config(), not `global $config;`.

    // When a $configPathOverride is in play (test seam, multi-config callers),
    // the source of truth for the existing value is THAT file's contents, not
    // the live config which still describes the request's primary
    // installation. Reading the override file ensures we don't return a
    // secret meant for a different config — and skip the write the override
    // path would have needed.
    $existing = '';
    if ($configPathOverride === null) {
        $cfgAppSecret = ipam_config('app_secret');
        $existing = is_string($cfgAppSecret) ? $cfgAppSecret : '';
    } else {
        /** @var array<string,mixed>|false $overrideCfg */
        $overrideCfg = @include $configPath;
        if (is_array($overrideCfg)
            && isset($overrideCfg['app_secret'])
            && is_string($overrideCfg['app_secret'])
        ) {
            $existing = $overrideCfg['app_secret'];
        }
    }

    if ($existing !== '') {
        $cached[$configPath] = $existing;
        return $existing;
    }

    if (function_exists('ipam_assert_random_bytes_available')) {
        ipam_assert_random_bytes_available();
    }
    $newValue = base64_encode(random_bytes(32));

    if (!is_writable($configPath)) {
        throw new RuntimeException(
            'app_secret auto-generation: config.php is not writable at ' . $configPath . '. '
            . 'Generate a value manually with `php -r "echo base64_encode(random_bytes(32));"` '
            . "and add `'app_secret' => '<value>',` to config.php, then retry."
        );
    }

    try {
        ipam_config_inject_or_replace_key($configPath, 'app_secret', $newValue);
    } catch (Throwable $e) {
        throw new RuntimeException(
            'app_secret auto-generation could not update config.php: '
            . $e->getMessage() . '. '
            . 'Generate a value manually with `php -r "echo base64_encode(random_bytes(32));"` '
            . "and add `'app_secret' => '<value>',` to config.php, then retry.",
            0,
            $e
        );
    }

    // Concurrency guard mirrors ipam_bootstrap_key(): re-read the file
    // post-rename so two simultaneous racers converge on the persisted
    // value rather than each using their in-memory $newValue.
    /** @var array<string,mixed>|false $persistedCfg */
    $persistedCfg = @include $configPath;
    if (is_array($persistedCfg)
        && isset($persistedCfg['app_secret'])
        && is_string($persistedCfg['app_secret'])
        && $persistedCfg['app_secret'] !== ''
    ) {
        $newValue = $persistedCfg['app_secret'];
    }

    $config['app_secret'] = $newValue;
    $cached[$configPath]  = $newValue;

    // Best-effort announce — gated because lib.php's announce helper may
    // not have parsed yet during early bootstrap.
    if (function_exists('ipam_install_key_announce_record')) {
        ipam_install_key_announce_record('app_secret');
    }

    return $newValue;
}
