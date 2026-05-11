<?php
declare(strict_types=1);

/**
 * Simple PHP IPAM — vault wrap/unwrap helpers (v3.26.0 #1098).
 *
 * Two-tier key model for `backup_vault_key`:
 *
 *   1. `bootstrap_key` lives in config.php only (auto-generated on first
 *      use, mirrors the lifecycle of `app_secret`). It never leaves the
 *      filesystem and is never written to the database.
 *   2. `backup_vault_key` — the 32 bytes that protect IPAMBKP3-encrypted
 *      archives — lives in the `settings` table, BUT wrapped with libsodium
 *      `crypto_secretbox` keyed by the bootstrap_key. The database row holds
 *      ciphertext only; the raw vault key is never persisted to the DB.
 *
 * Unwrap requires both the bootstrap_key (filesystem) and the wrapped
 * envelope (DB). A stolen DB dump alone yields ciphertext. A stolen config
 * snapshot alone yields the bootstrap_key but no envelope. The model is
 * defence-in-depth, not a hard cryptographic boundary — root on the host
 * still has both.
 *
 * Envelope format (versioned for future-proofing):
 *
 *   "IPAMWK1." || base64(nonce || ciphertext)
 *
 * - `IPAMWK1.` — 8-byte ASCII magic + version tag.
 * - nonce      — 24 bytes (SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); random per wrap.
 * - ciphertext — sodium_crypto_secretbox(plaintext, nonce, bootstrap_key);
 *                includes the 16-byte Poly1305 authentication tag.
 *
 * Round-trip is symmetric: `ipam_vault_unwrap(ipam_vault_wrap($pt, $k), $k) === $pt`.
 * Tampered ciphertext throws RuntimeException with no plaintext leak.
 */

const IPAM_VAULT_ENVELOPE_PREFIX = 'IPAMWK1.';
const IPAM_BOOTSTRAP_KEY_LEN     = 32; // SODIUM_CRYPTO_SECRETBOX_KEYBYTES

/**
 * Return the 32 raw bytes of the bootstrap key. Auto-generates on first
 * call and persists base64-encoded into config.php so subsequent requests
 * deterministically read the same value (mirrors backup_vault_key v3.7+
 * lifecycle).
 *
 * Behaviour matches ipam_backup_vault_key_or_init() so a hardened install
 * with a non-writable config.php surfaces an actionable error rather than
 * silently regenerating the key on every request.
 */
function ipam_bootstrap_key(): string
{
    static $cached = null;
    if (is_string($cached)) {
        return $cached;
    }

    /** @var array<string,mixed> $config */
    global $config;

    $existingB64 = (isset($config['bootstrap_key']) && is_string($config['bootstrap_key']))
        ? $config['bootstrap_key']
        : '';

    if ($existingB64 !== '') {
        $decoded = base64_decode($existingB64, true);
        if (is_string($decoded) && strlen($decoded) === IPAM_BOOTSTRAP_KEY_LEN) {
            $cached = $decoded;
            return $decoded;
        }
        throw new RuntimeException(
            'bootstrap_key in config.php is malformed (expected '
            . IPAM_BOOTSTRAP_KEY_LEN
            . ' bytes base64). Clear the value to allow auto-generation, '
            . 'or replace it with: php -r "echo base64_encode(random_bytes(32));"'
        );
    }

    if (function_exists('ipam_assert_random_bytes_available')) {
        ipam_assert_random_bytes_available();
    }
    $newRaw = random_bytes(IPAM_BOOTSTRAP_KEY_LEN);
    $newB64 = base64_encode($newRaw);

    $configPath = realpath(__DIR__ . '/../config.php');
    if ($configPath === false) {
        throw new RuntimeException(
            'bootstrap_key auto-generation: config.php path does not resolve. '
            . 'Generate a key manually with `php -r "echo base64_encode(random_bytes(32));"` '
            . "and add `'bootstrap_key' => '<value>',` to config.php, then retry."
        );
    }

    try {
        ipam_config_inject_or_replace_key($configPath, 'bootstrap_key', $newB64);
    } catch (Throwable $e) {
        throw new RuntimeException(
            'bootstrap_key auto-generation could not update config.php: '
            . $e->getMessage() . '. '
            . 'Generate a key manually with `php -r "echo base64_encode(random_bytes(32));"` '
            . "and add `'bootstrap_key' => '<value>',` to config.php, then retry.",
            0,
            $e
        );
    }

    // Concurrency guard mirrors backup_vault_key_or_init's: re-read the
    // file post-rename so two simultaneous racers converge on the persisted
    // key rather than each using their in-memory $newRaw.
    /** @var array<string,mixed>|false $persistedCfg */
    $persistedCfg = @include $configPath;
    if (is_array($persistedCfg) && isset($persistedCfg['bootstrap_key'])
        && is_string($persistedCfg['bootstrap_key'])) {
        $persistedDecoded = base64_decode($persistedCfg['bootstrap_key'], true);
        if (is_string($persistedDecoded) && strlen($persistedDecoded) === IPAM_BOOTSTRAP_KEY_LEN) {
            $newRaw = $persistedDecoded;
            $newB64 = $persistedCfg['bootstrap_key'];
        }
    }

    $config['bootstrap_key'] = $newB64;
    $cached = $newRaw;
    return $newRaw;
}

/**
 * Wrap an arbitrary plaintext (typically the 32-byte raw vault key) under
 * the bootstrap key. Returns a printable envelope safe to store in a TEXT
 * settings row.
 *
 * @throws RuntimeException if the bootstrap key is the wrong length.
 */
function ipam_vault_wrap(string $plaintext, string $bootstrapKey): string
{
    if (strlen($bootstrapKey) !== IPAM_BOOTSTRAP_KEY_LEN) {
        throw new RuntimeException(
            'ipam_vault_wrap: bootstrap key must be ' . IPAM_BOOTSTRAP_KEY_LEN . ' bytes'
        );
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plaintext, $nonce, $bootstrapKey);
    return IPAM_VAULT_ENVELOPE_PREFIX . base64_encode($nonce . $cipher);
}

/**
 * Inverse of ipam_vault_wrap(). Returns the original plaintext on success.
 *
 * @throws RuntimeException on any decoding, length, or authentication
 *                          failure. The error message never echoes the
 *                          envelope or partial plaintext so callers can
 *                          surface it directly to operators without leaking
 *                          ciphertext-derived material.
 */
function ipam_vault_unwrap(string $envelope, string $bootstrapKey): string
{
    if (strlen($bootstrapKey) !== IPAM_BOOTSTRAP_KEY_LEN) {
        throw new RuntimeException(
            'ipam_vault_unwrap: bootstrap key must be ' . IPAM_BOOTSTRAP_KEY_LEN . ' bytes'
        );
    }
    if (!str_starts_with($envelope, IPAM_VAULT_ENVELOPE_PREFIX)) {
        throw new RuntimeException('ipam_vault_unwrap: missing IPAMWK1 envelope prefix');
    }
    $body = substr($envelope, strlen(IPAM_VAULT_ENVELOPE_PREFIX));
    $bin  = base64_decode($body, true);
    if ($bin === false) {
        throw new RuntimeException('ipam_vault_unwrap: malformed base64');
    }
    $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    if (strlen($bin) < $nonceLen + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        throw new RuntimeException('ipam_vault_unwrap: envelope too short');
    }
    $nonce  = substr($bin, 0, $nonceLen);
    $cipher = substr($bin, $nonceLen);

    $plain = sodium_crypto_secretbox_open($cipher, $nonce, $bootstrapKey);
    if ($plain === false) {
        throw new RuntimeException(
            'ipam_vault_unwrap: authentication failed (wrong bootstrap_key '
            . 'or tampered ciphertext)'
        );
    }
    return $plain;
}

/**
 * SHA-256 fingerprint of a raw vault key, returned as the first 8 hex
 * characters. Stable identifier for UI display ("which key is currently
 * in use?") that does not require revealing the key itself.
 *
 * Length choice: 8 hex chars = 32 bits. That's enough entropy for an
 * operator to confirm "the key on disk matches the one I copied to my
 * password manager" with negligible collision risk in this scope, while
 * staying short enough to read aloud or eyeball at a glance.
 */
function ipam_vault_fingerprint(string $rawKey): string
{
    return substr(hash('sha256', $rawKey), 0, 8);
}

/**
 * v3.27.8 (Bug E) — three-state report on the install's vault-key envelope.
 *
 * Pre-fix the rest of the codebase asked a single binary question
 * ("does ipam_setting('backup_vault_key') unwrap cleanly?") and treated
 * any failure mode as "no envelope". That meant the "No vault key
 * configured yet" card on the Destinations tab silently lit up whenever
 * the bootstrap_key had drifted from the one used to write the envelope,
 * even though the operator HAD set a key — the system just couldn't
 * read it. The contradiction surfaced as "no key" banner above a
 * destinations table still showing per-row 'Stored key' badges.
 *
 * This helper distinguishes the three real states so callers can render
 * a diagnostic banner for `unreadable` without touching the present /
 * absent paths.
 *
 *   - absent     → no envelope row in `settings`
 *   - present    → envelope row + unwrap succeeds with current bootstrap_key
 *   - unreadable → envelope row exists but unwrap fails. Error message is
 *                  the unwrap exception text (already operator-safe — see
 *                  ipam_vault_unwrap()'s contract: never echoes envelope
 *                  bytes or partial plaintext).
 *
 * v3.27.8 deliberately ships no in-band "Replace vault key" affordance
 * for the unreadable state — recovery is documented in
 * docs/upgrading.md §vault-recovery and goes through `config.php`
 * directly. Adding an in-app overwrite while the operator can't prove
 * key custody is a wider design decision than this hotfix scopes.
 *
 * @return array{state:'absent'|'present'|'unreadable', envelope_present:bool, error_message:?string}
 */
function ipam_vault_status(): array
{
    $envelope = '';
    try {
        $raw = ipam_setting('backup_vault_key', '');
        $envelope = is_string($raw) ? $raw : '';
    } catch (\Throwable) {
        $envelope = '';
    }

    if ($envelope === '') {
        return ['state' => 'absent', 'envelope_present' => false, 'error_message' => null];
    }

    try {
        ipam_vault_unwrap($envelope, ipam_bootstrap_key());
        return ['state' => 'present', 'envelope_present' => true, 'error_message' => null];
    } catch (\Throwable $e) {
        // Surface the helper's own message — it's intentionally
        // operator-facing and never echoes envelope/plaintext bytes.
        error_log('[ipam_vault_status] unreadable envelope: ' . $e->getMessage());
        return [
            'state'            => 'unreadable',
            'envelope_present' => true,
            'error_message'    => $e->getMessage(),
        ];
    }
}
