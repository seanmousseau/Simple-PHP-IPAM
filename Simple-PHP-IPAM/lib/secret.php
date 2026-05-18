<?php
declare(strict_types=1);

/**
 * @module secret
 *
 * Simple PHP IPAM — settings-secret encrypt-at-rest pipeline (v3.31.0 #1233).
 *
 * Every `settings` row whose registry definition is flagged `sensitive`
 * (except `backup_vault_key`, which carries its own IPAMWK1 envelope —
 * see lib/vault.php) is stored as an IPAMSEC1 envelope. The encryption
 * key is a domain-separated BLAKE2b subkey of `app_secret` (config.php,
 * never the DB), so a stolen DB dump alone yields ciphertext only.
 *
 * Envelope format:  IPAMSEC1.<base64(nonce || ciphertext)>
 *   - IPAMSEC1.   8-byte ASCII magic + version tag
 *   - nonce       24 bytes (SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), random per encrypt
 *   - ciphertext  sodium_crypto_secretbox(...), includes 16-byte Poly1305 tag
 *
 * ipam_secret_decrypt() returns non-envelope input verbatim (plaintext
 * passthrough) so the pre-migration window and config.php fallbacks are safe.
 *
 * Dependencies: lib/app_secret.php (ipam_app_secret()). Loaded after
 * lib/app_secret.php so that accessor is available.
 */

const IPAM_SECRET_ENVELOPE_PREFIX = 'IPAMSEC1.';
const IPAM_SECRET_KDF_CONTEXT     = 'ipam-settings-secret-v1';

/**
 * Thrown when a stored IPAMSEC1 envelope cannot be decrypted — wrong
 * app_secret or a corrupt/tampered row. Distinct from a generic
 * RuntimeException so callers can catch decrypt failure specifically.
 */
class IpamSecretDecryptException extends \RuntimeException
{
}

/**
 * Derive the 32-byte settings-secret key from a given app_secret value.
 *
 * Pure function (no I/O) — exposed separately from ipam_secret_key() so the
 * derivation can be exercised directly in tests with any app_secret form.
 *
 * app_secret has two valid forms: the modern auto-generated value (base64 of
 * 32 random bytes) and a legacy operator-set plain string. If the value
 * decodes as non-empty valid base64 the decoded bytes are used as key
 * material; otherwise the raw string bytes are used (so a value like `"="`,
 * which decodes to an empty string, falls through to the raw branch). The
 * material is normalised to a 32-byte root via unkeyed BLAKE2b (which accepts
 * any input length), then a domain-separated subkey is derived via keyed
 * BLAKE2b.
 *
 * @throws \RuntimeException if $appSecret is empty.
 */
function ipam_secret_derive_key(string $appSecret): string
{
    if ($appSecret === '') {
        throw new \RuntimeException('app_secret is empty; cannot derive the settings-secret key.');
    }
    $decoded  = base64_decode($appSecret, true);
    $material = ($decoded !== false && $decoded !== '') ? $decoded : $appSecret;
    $root     = sodium_crypto_generichash($material); // 32-byte digest, any input length
    return sodium_crypto_generichash(IPAM_SECRET_KDF_CONTEXT, $root, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
}

/**
 * Derive the 32-byte settings-secret key from the process app_secret.
 *
 * Thin wrapper over ipam_secret_derive_key() — reads app_secret from
 * config.php (never the DB) and delegates the pure derivation.
 *
 * @throws \RuntimeException if app_secret is empty.
 */
function ipam_secret_key(): string
{
    return ipam_secret_derive_key(ipam_app_secret());
}

/** True if $value carries the IPAMSEC1 envelope prefix. */
function ipam_secret_is_envelope(string $value): bool
{
    return str_starts_with($value, IPAM_SECRET_ENVELOPE_PREFIX);
}

/** Encrypt $plaintext into an IPAMSEC1 envelope. */
function ipam_secret_encrypt(string $plaintext): string
{
    $key   = ipam_secret_key();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    try {
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
    } finally {
        sodium_memzero($key);
    }
    return IPAM_SECRET_ENVELOPE_PREFIX . base64_encode($nonce . $cipher);
}

/**
 * Decrypt an IPAMSEC1 envelope.
 *
 * - Non-envelope input is returned verbatim (legacy plaintext passthrough).
 * - A malformed or tampered envelope returns null (no plaintext leak).
 */
function ipam_secret_decrypt(string $stored): ?string
{
    if (!ipam_secret_is_envelope($stored)) {
        return $stored;
    }
    $blob = base64_decode(substr($stored, strlen(IPAM_SECRET_ENVELOPE_PREFIX)), true);
    $minLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
    if ($blob === false || strlen($blob) < $minLen) {
        return null;
    }
    $nonce  = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $key    = ipam_secret_key();
    try {
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    } finally {
        sodium_memzero($key);
    }
    return $plain === false ? null : $plain;
}

/**
 * Setting keys the encrypt-at-rest pipeline manages: every registry
 * entry flagged `sensitive`, MINUS `backup_vault_key` (which carries
 * its own IPAMWK1 envelope under the bootstrap key — see lib/vault.php;
 * double-wrapping it would break ipam_vault_unwrap()).
 *
 * @return list<string>
 */
function ipam_secret_managed_keys(): array
{
    $keys = [];
    foreach (ipam_setting_definitions() as $key => $def) {
        if (!empty($def['sensitive']) && $key !== 'backup_vault_key') {
            $keys[] = (string) $key;
        }
    }
    return $keys;
}

/** True if $key is a settings secret managed by the encrypt-at-rest pipeline. */
function ipam_secret_is_managed_key(string $key): bool
{
    return in_array($key, ipam_secret_managed_keys(), true);
}

/**
 * Read a managed settings secret, decrypted. Thin alias over ipam_setting()
 * (which already auto-decrypts) — exists for call-site clarity.
 *
 * Contract: $key MUST be one of the managed settings secrets returned by
 * ipam_secret_managed_keys(). Passing a non-managed key throws
 * \InvalidArgumentException — reading a non-sensitive key through this API
 * would silently bypass the encrypt-at-rest pipeline.
 *
 * @throws \InvalidArgumentException if $key is not a managed settings secret.
 */
function ipam_secret_get(string $key, ?string $default = null): ?string
{
    if (!ipam_secret_is_managed_key($key)) {
        throw new \InvalidArgumentException(
            "ipam_secret_get(): '{$key}' is not a managed settings secret. "
            . 'Managed keys: ' . implode(', ', ipam_secret_managed_keys()) . '.'
        );
    }
    $value = ipam_setting($key, $default);
    if ($value === null) {
        return null;
    }
    // Managed settings secrets are always scalar strings; a non-scalar
    // here means a misconfigured registry entry, not a usable secret.
    return is_scalar($value) ? (string) $value : null;
}

/**
 * Write a managed settings secret. Thin alias over ipam_setting_set()
 * (which already auto-encrypts sensitive keys).
 *
 * Contract: $key MUST be one of the managed settings secrets returned by
 * ipam_secret_managed_keys(). Passing a non-managed key throws
 * \InvalidArgumentException — writing a non-sensitive key through this API
 * would silently store it as plaintext, bypassing encrypt-at-rest.
 *
 * @throws \InvalidArgumentException if $key is not a managed settings secret.
 */
function ipam_secret_set(PDO $db, string $key, string $value, ?int $userId = null): void
{
    if (!ipam_secret_is_managed_key($key)) {
        throw new \InvalidArgumentException(
            "ipam_secret_set(): '{$key}' is not a managed settings secret. "
            . 'Managed keys: ' . implode(', ', ipam_secret_managed_keys()) . '.'
        );
    }
    ipam_setting_set($db, $key, $value, $userId);
}
