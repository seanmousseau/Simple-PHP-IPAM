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
 * Derive the 32-byte settings-secret key from app_secret.
 *
 * @throws \RuntimeException if app_secret is unreadable or malformed.
 */
function ipam_secret_key(): string
{
    $appSecret = ipam_app_secret();
    $raw = base64_decode($appSecret, true);
    // 16 bytes is the minimum entropy floor: app_secret is normally
    // base64_encode(random_bytes(32)) = 32 bytes, but legacy/manually-set
    // installs may carry a shorter operator-chosen value. The floor tolerates
    // those while still rejecting an empty, truncated, or corrupt value.
    if ($raw === false || strlen($raw) < 16) {
        throw new \RuntimeException('app_secret is missing or malformed; cannot derive settings-secret key.');
    }
    return sodium_crypto_generichash(IPAM_SECRET_KDF_CONTEXT, $raw, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
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
    $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
    sodium_memzero($key);
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
    $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    sodium_memzero($key);
    return $plain === false ? null : $plain;
}
