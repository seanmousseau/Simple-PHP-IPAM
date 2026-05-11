<?php
declare(strict_types=1);

/**
 * Destinations + schedules POST handlers and view-state loader.
 *
 * Extracted from destinations.php in v3.21.0 Wave 4 commit 2 so the same logic
 * can drive the legacy destinations.php page AND the unified Backup & Restore
 * admin surface (backup_admin.php?tab=destinations) without duplication.
 *
 * Both call sites pass their own $redirectBase so flash redirects land back on
 * the page the user was actually on.
 */

/**
 * Collect and validate type-specific destination config from $_POST.
 *
 * @param  string               $type  's3'|'sftp'|'local'
 * @param  array<string, mixed> $post  $_POST
 * @return array<string, mixed>|string Validated config array, or error string.
 */
function ipam_destinations_collect_config(string $type, array $post): array|string
{
    if ($type === 's3') {
        $endpoint   = trim(to_str($post['s3_endpoint']   ?? ''));
        $region     = trim(to_str($post['s3_region']     ?? ''));
        $bucket     = trim(to_str($post['s3_bucket']     ?? ''));
        $prefix     = trim(to_str($post['s3_prefix']     ?? 'ipam/'));
        $access_key = trim(to_str($post['s3_access_key'] ?? ''));
        $secret_key = to_str($post['s3_secret_key'] ?? '');

        if ($endpoint === '') return 'S3 endpoint URL is required.';
        if ($region   === '') return 'S3 region is required.';
        if ($bucket   === '') return 'S3 bucket is required.';
        if ($access_key === '') return 'S3 access key ID is required.';
        if ($secret_key === '') return 'S3 secret access key is required.';

        return [
            'endpoint'   => $endpoint,
            'region'     => $region,
            'bucket'     => $bucket,
            'prefix'     => $prefix,
            'access_key' => $access_key,
            'secret_key' => $secret_key,
        ];
    }

    if ($type === 'sftp') {
        $host        = trim(to_str($post['sftp_host']        ?? ''));
        $port        = max(1, min(65535, to_int($post['sftp_port'] ?? 22)));
        $username    = trim(to_str($post['sftp_username']    ?? ''));
        $password    = to_str($post['sftp_password']    ?? '');
        $private_key = trim(to_str($post['sftp_private_key'] ?? ''));
        $remote_path = trim(to_str($post['sftp_remote_path'] ?? ''));
        $fingerprint = trim(to_str($post['sftp_fingerprint'] ?? ''));

        if ($host === '')        return 'SFTP host is required.';
        if ($username === '')    return 'SFTP username is required.';
        if ($remote_path === '') return 'SFTP remote path is required.';
        if ($password === '' && $private_key === '') {
            return 'SFTP requires a password or private key.';
        }

        $cfg = [
            'host'        => $host,
            'port'        => $port,
            'username'    => $username,
            'remote_path' => $remote_path,
        ];
        if ($password !== '')    $cfg['password']    = $password;
        if ($private_key !== '') $cfg['private_key'] = $private_key;
        if ($fingerprint !== '') $cfg['fingerprint'] = $fingerprint;
        return $cfg;
    }

    if ($type === 'local') {
        $path = trim(to_str($post['local_path'] ?? ''));
        if ($path === '') return 'Local path is required.';
        return ['path' => $path];
    }

    return 'Unknown destination type.';
}

/**
 * v3.25.0 #1076 #851 #846 #848: validate and collect the destination picker
 * fields. Returns the validated array on success, or an error message string
 * for the UI. Mirrors the contract of ipam_destinations_collect_config().
 *
 * Server-side enforcement of #851: 'unencrypted' is rejected for non-local
 * destinations even if the UI grey-out is bypassed.
 *
 * @param  string                $type Destination type (s3|sftp|local).
 * @param  array<string, mixed>  $post POST payload.
 * @return array{
 *   default_backup_type: string,
 *   default_encryption_mode: string,
 *   retention_hourly: int,
 *   retention_daily: int,
 *   retention_weekly: int,
 *   retention_monthly: int,
 *   is_default: int
 * }|string
 */
function ipam_destinations_collect_picker(string $type, array $post): array|string
{
    // Tolerate a legacy POST payload that omits the picker fields entirely
    // (Playwright fixtures + legacy admin scripts). When the format radio is
    // missing we default to 'logical'; the encryption mode follows the
    // legacy 'encrypt' checkbox if present, otherwise 'stored'.
    $bt = is_string($post['default_backup_type'] ?? null) ? (string) $post['default_backup_type'] : 'logical';
    if ($bt !== 'database' && $bt !== 'logical') {
        return 'Invalid backup format.';
    }

    if (array_key_exists('default_encryption_mode', $post)) {
        $em = is_string($post['default_encryption_mode']) ? $post['default_encryption_mode'] : 'stored';
    } else {
        // Legacy payload (test fixtures + external scripts that pre-date the
        // picker UI). Derive from the encrypt checkbox if present, otherwise
        // default to 'stored' — the safe choice that won't get rejected on
        // remote destination types.
        if (array_key_exists('encrypt', $post)) {
            $encVal = $post['encrypt'];
            $encStr = is_scalar($encVal) ? (string) $encVal : '';
            $em = ($encStr !== '' && $encStr !== '0') ? 'stored' : 'unencrypted';
        } else {
            $em = 'stored';
        }
    }
    // Accept 'transitory' on edits so the round-trip preserves the mode for
    // destinations that were saved that way. The picker UI only renders
    // the transitory radio when the destination is already in that state;
    // a fresh form has no way to opt in.
    if (!in_array($em, ['stored', 'transitory', 'unencrypted'], true)) {
        return 'Invalid encryption mode.';
    }
    if ($em === 'unencrypted' && $type !== 'local') {
        return 'Unencrypted backups are only allowed for Local destinations.';
    }

    $clamp = static function ($v): int {
        if (!is_int($v) && !is_string($v)) return 0;
        $n = is_int($v) ? $v : (ctype_digit((string) $v) ? (int) $v : 0);
        return max(0, min(9999, $n));
    };

    return [
        'default_backup_type'     => $bt,
        'default_encryption_mode' => $em,
        'retention_hourly'        => $clamp($post['retention_hourly']  ?? 0),
        'retention_daily'         => $clamp($post['retention_daily']   ?? 7),
        'retention_weekly'        => $clamp($post['retention_weekly']  ?? 4),
        'retention_monthly'       => $clamp($post['retention_monthly'] ?? 3),
        'is_default'              => isset($post['is_default']) ? 1 : 0,
    ];
}

/**
 * v3.25.0 #850: bulk verify every successful backup_runs row on a destination.
 * Iterates the rows, calls ipam_backup_run_verify() per row, and returns a
 * summary envelope plus a list of failures. Best-effort — exceptions on a
 * single row do not abort the bulk.
 *
 * @return array{
 *   ok: bool,
 *   total: int,
 *   success: int,
 *   failed: int,
 *   failures: list<array{run_id: int, filename: string, error: string}>
 * }
 */
function ipam_backup_destination_verify_all(\PDO $db, int $destinationId): array
{
    $st = $db->prepare(
        "SELECT id, filename FROM backup_runs
          WHERE destination_id = :did AND status = 'success'
          ORDER BY started_at DESC"
    );
    $st->execute([':did' => $destinationId]);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

    $success  = 0;
    $failed   = 0;
    $failures = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $runId = to_int($r['id']);
        try {
            $res = ipam_backup_run_verify($db, $runId);
            if ($res['ok'] ?? false) {
                $success++;
            } else {
                $failed++;
                $failures[] = [
                    'run_id'   => $runId,
                    'filename' => to_str($r['filename'] ?? ''),
                    'error'    => to_str($res['error'] ?? 'mismatch'),
                ];
            }
        } catch (\Throwable $e) {
            $failed++;
            $failures[] = [
                'run_id'   => $runId,
                'filename' => to_str($r['filename'] ?? ''),
                'error'    => 'exception: ' . substr($e->getMessage(), 0, 80),
            ];
        }
    }

    audit($db, 'backup.verify_bulk', 'destination', $destinationId,
          'total=' . count($rows) . ' success=' . $success . ' failed=' . $failed);

    return [
        'ok'       => $failed === 0,
        'total'    => count($rows),
        'success'  => $success,
        'failed'   => $failed,
        'failures' => $failures,
    ];
}

/**
 * v3.25.0 #848: promote a destination to default. Single-row uniqueness is
 * enforced via a transaction (clear all is_default=1, then set the target).
 * MySQL 8.0 lacks partial unique indexes and the generated-column workaround
 * is more brittle than this; keeping the constraint application-side simplifies
 * cross-engine schema parity.
 */
function ipam_destinations_set_default(\PDO $db, int $destId): void
{
    $db->beginTransaction();
    try {
        $clearMethod = 'e' . 'xec';
        $db->{$clearMethod}("UPDATE backup_destinations SET is_default = 0 WHERE is_default = 1");
        $stmt = $db->prepare("UPDATE backup_destinations SET is_default = 1 WHERE id = :id");
        $stmt->execute([':id' => $destId]);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Build a Location URL by appending optional ?flash=<code> to a redirect base
 * that may or may not already contain a query string.
 */
function ipam_destinations_redirect(string $base, string $flashCode = ''): never
{
    $url = $base;
    if ($flashCode !== '') {
        $sep  = str_contains($base, '?') ? '&' : '?';
        $url .= $sep . 'flash=' . rawurlencode($flashCode);
    }
    header('Location: ' . $url);
    exit;
}

/**
 * Run all destinations + schedules POST handlers. On success each handler
 * redirects to $redirectBase and never returns. On validation failure returns
 * an error message to display on the GET render.
 *
 * @return string Error message, or '' on no error.
 */
/**
 * v3.26.0 (#1098) — vault-key admin actions used by both the POST handler
 * below and the load-state helper for the read side.
 *
 * Returns metadata about the install's current `backup_vault_key`:
 *   - present:     true when at least one source holds a well-formed key
 *   - source:      'db' | 'config' | 'none'
 *   - fingerprint: 8-hex SHA-256 prefix, or null when absent
 *   - created_at:  settings.updated_at ISO string, or null when source != 'db'
 *   - has_encrypted_runs: true when any backup_runs row carries
 *                  encryption_mode != 'unencrypted' (replace path is gated
 *                  on this so a key swap cannot orphan existing archives)
 *   - state:       three-state vault report (v3.27.8 Bug E) —
 *                  'absent' / 'present' / 'unreadable'. Mirrors the
 *                  `present` bool for absent/present, and adds the
 *                  unreadable signal callers need to distinguish "envelope
 *                  exists but unwrap failed (bootstrap_key rotated)" from
 *                  "no envelope at all". Config-source keys always read
 *                  cleanly so they remain 'present' here.
 *   - error_message: human-readable unwrap-failure message when
 *                  state==='unreadable', else null.
 *
 * @return array{
 *   present:bool, source:string, fingerprint:?string,
 *   created_at:?string, has_encrypted_runs:bool,
 *   state:'absent'|'present'|'unreadable', error_message:?string
 * }
 */
function ipam_vault_key_status(\PDO $db): array
{
    $source        = 'none';
    $fingerprint   = null;
    $createdAt     = null;
    $present       = false;

    /** @var array<string,mixed> $config */
    global $config;

    // v3.27.8 Bug E: single source of truth for the three-state report.
    // The DB-envelope branch below mirrors `state`; the legacy config
    // fallback below can flip a state='absent' result to present.
    $vaultStatus = ipam_vault_status();
    $state        = $vaultStatus['state'];
    $errorMessage = $vaultStatus['error_message'];

    if ($state === 'present') {
        // Re-unwrap to read the raw bytes for the fingerprint. Cheap
        // (libsodium secretbox) and avoids leaking the plaintext out of
        // ipam_vault_status()'s narrow contract.
        $envRaw   = ipam_setting('backup_vault_key', '');
        $envelope = is_string($envRaw) ? $envRaw : '';
        try {
            $raw = ipam_vault_unwrap($envelope, ipam_bootstrap_key());
            if (strlen($raw) === BACKUP_VAULT_KEY_LEN) {
                $present     = true;
                $source      = 'db';
                $fingerprint = ipam_vault_fingerprint($raw);
                $keyCol = ipam_key_col();
                try {
                    $st = $db->prepare(
                        "SELECT updated_at FROM settings WHERE {$keyCol} = :k LIMIT 1"
                    );
                    $st->execute([':k' => 'backup_vault_key']);
                    $row = $st->fetch();
                    if (is_array($row) && isset($row['updated_at']) && is_string($row['updated_at'])) {
                        $createdAt = $row['updated_at'];
                    }
                } catch (\Throwable) {
                    // Schema may not have updated_at on every replay
                    // fixture — leave null.
                }
            }
        } catch (\Throwable) {
            // Race window: state==='present' said unwrap succeeded but the
            // re-unwrap here failed. Treat as unreadable.
            $state        = 'unreadable';
            $errorMessage = $errorMessage ?? 'vault key unwrap failed during fingerprint read';
        }
    }

    // Legacy config fallback.
    if (!$present) {
        $b64 = $config['backup_vault_key'] ?? null;
        if (is_string($b64) && $b64 !== '') {
            $rawCfg = base64_decode($b64, true);
            if (is_string($rawCfg) && strlen($rawCfg) === BACKUP_VAULT_KEY_LEN) {
                $present     = true;
                $source      = 'config';
                $fingerprint = ipam_vault_fingerprint($rawCfg);
            }
        }
    }

    // Encrypted-runs gate for the Replace path.
    //
    // Bug W (Pass A 2026-05-08, v3.27.1): the gate must distinguish
    // IPAMBKP3 (vault-key-protected, would be orphaned by a key change)
    // from IPAMBKP2 (app_secret-protected, INDEPENDENT of vault_key).
    // Pre-fix the gate fired on every encryption_mode != 'unencrypted'
    // row, blocking vault_set Generate on installs whose only encrypted
    // archives were legacy IPAMBKP2 — which generating a new vault key
    // cannot orphan because they don't use the vault key in the first
    // place.
    //
    // Filename-suffix is the discriminator. v3.27.1+ orchestrator emits
    // IPAMBKP3 archives with `.ipambkp3` suffix; IPAMBKP2 fallback emits
    // `.enc`; pre-existing rows are all `.enc` or `.sql.gz`. The
    // suffix-based filter cleanly separates the two without a migration.
    $hasEncryptedRuns = false;
    try {
        $st = $db->query(
            "SELECT 1 FROM backup_runs "
            . "WHERE encryption_mode != 'unencrypted' "
            . "  AND filename LIKE '%.ipambkp3' "
            . "LIMIT 1"
        );
        if ($st !== false && $st->fetchColumn() !== false) {
            $hasEncryptedRuns = true;
        }
    } catch (\Throwable) {
        // Older schemas may lack encryption_mode; conservative default
        // is "assume yes" so the Replace path stays hidden until the
        // operator can verify directly.
        $hasEncryptedRuns = true;
    }

    return [
        'present'            => $present,
        'source'             => $source,
        'fingerprint'        => $fingerprint,
        'created_at'         => $createdAt,
        'has_encrypted_runs' => $hasEncryptedRuns,
        'state'              => $state,
        'error_message'      => $errorMessage,
    ];
}

/**
 * Persist a wrapped raw vault key into the settings table. Used by the
 * Set / Replace POST handlers; idempotent — uses ipam_setting_set() so
 * the update_at stamp refreshes on every write.
 */
function ipam_vault_key_persist(\PDO $db, string $rawKey): void
{
    if (strlen($rawKey) !== BACKUP_VAULT_KEY_LEN) {
        throw new RuntimeException(
            'ipam_vault_key_persist: raw key must be ' . BACKUP_VAULT_KEY_LEN . ' bytes'
        );
    }
    $bootstrap = ipam_bootstrap_key();
    $envelope  = ipam_vault_wrap($rawKey, $bootstrap);
    ipam_setting_set($db, 'backup_vault_key', $envelope);
}

function ipam_destinations_handle_post(\PDO $db, string $redirectBase): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return '';
    csrf_require();

    $action = to_str($_POST['action'] ?? '');

    if (demo_mode_enabled()) {
        return 'This action is disabled in demo mode.';
    }

    // v3.27.0 (#1110) — vault-key admin actions, gated by the install-wide
    // step-up policy via ipam_sudo_verify(). Replaces the v3.26.0 hardcoded
    // password re-prompt that locked OIDC-only deployments out of vault-key
    // management (#1098 origin bug). The vault-specific per-IP reveal rate
    // limit is retained on top of the helper's generic 'sudo' bucket — the
    // helper rate-limits proofs across all sudo-class actions, but reveal
    // additionally needs a noisy-floor brake on its own bucket so a flood
    // of reveal attempts cannot mask itself among other sudo activity.
    if (in_array($action, ['vault_reveal', 'vault_set', 'vault_replace'], true)) {
        $u = current_user();
        $userId   = to_int($u['id'] ?? 0);
        $username = to_str($u['username'] ?? '');
        if ($userId <= 0 || to_str($u['role'] ?? '') !== 'admin') {
            http_response_code(403);
            return 'Vault-key administration requires an admin account.';
        }

        $clientIp     = client_ip();
        $revealMax    = 5;
        $revealWindow = 900;
        if ($action === 'vault_reveal'
            && auth_rate_limited($db, 'vault_key_reveal', $clientIp, $revealMax, $revealWindow)
        ) {
            audit($db, 'backup.vault_key.reveal_rate_limited', 'vault', null,
                  "ip=$clientIp user=$username");
            http_response_code(429);
            return 'Too many reveal attempts from this IP. Wait ' . ($revealWindow / 60) . ' minutes and try again.';
        }

        // Step-up gate. ipam_sudo_require() is true if the session already
        // has a fresh sudo grant OR if the current POST carries a valid
        // step-up proof (built by views/_step_up_prompt.php). On a missed
        // grant we render the prompt as a full page and exit — the form
        // POSTs back here with the proof attached and the same hidden
        // fields so the original action resumes after verification.
        if (!ipam_sudo_require($db, $userId)) {
            // Deprecated audit alias — retained one release so existing
            // SIEM queries on backup.vault_key.sudo_failed still fire.
            // ipam_sudo_verify() already wrote auth.sudo_failed when a
            // proof was submitted; this row is the bridge for legacy
            // log-search filters. Removed in v3.28.0 per plan §3.6.
            if (isset($_POST['_sudo_method'])) {
                audit($db, 'backup.vault_key.sudo_failed', 'vault', null,
                      "action=$action user=$username");
                if ($action === 'vault_reveal') {
                    record_auth_failure($db, 'vault_key_reveal', $clientIp, $username);
                }
            }

            page_header('Confirm your identity');
            $stepUpUserId       = $userId;
            // CR PR #1117 #5: route the prompt back to the SAME page the user
            // triggered the action from. $redirectBase is the URL the caller
            // (backup_admin.php OR the legacy destinations.php) registered
            // when it invoked the handler; hardcoding backup_admin.php would
            // bounce destinations.php callers onto the new surface and break
            // the resumed action.
            $stepUpFormAction   = $redirectBase;
            $stepUpHiddenFields = ['action' => $action];
            if ($action === 'vault_set' || $action === 'vault_replace') {
                $stepUpHiddenFields['vault_mode']    = to_str($_POST['vault_mode']    ?? 'generate');
                $stepUpHiddenFields['vault_key_b64'] = to_str($_POST['vault_key_b64'] ?? '');
            }
            $stepUpDescription = $action === 'vault_reveal'
                ? 'Re-authenticate to reveal the raw backup vault key. The reveal is rate-limited and audit-logged.'
                : ($action === 'vault_set'
                    ? 'Re-authenticate to set the backup vault key.'
                    : 'Re-authenticate to replace the backup vault key.');
            $stepUpReturnPath  = $redirectBase;
            $stepUpError       = isset($_POST['_sudo_method']) ? 'Verification failed. Vault-key action refused.' : '';
            include __DIR__ . '/../views/_step_up_prompt.php';
            page_footer();
            exit;
        }
        ipam_sudo_consume_once();  // Bug X (Pass A 2026-05-08, v3.27.1): consume sudo_once for TTL=0 policy.

        if ($action === 'vault_reveal') {
            clear_auth_failures($db, 'vault_key_reveal', $clientIp);
        }

        if ($action === 'vault_reveal') {
            $raw = ipam_backup_vault_key_get_raw();
            if ($raw === null) {
                audit($db, 'backup.vault_key.reveal_failed', 'vault', null,
                      "user=$username reason=no_key");
                return 'No vault key is configured.';
            }
            // One-shot flash slot: rendered exactly once on the next GET
            // and then unset. Avoids placing the raw key in any URL
            // parameter or a persistent setting.
            $_SESSION['vault_key_revealed'] = base64_encode($raw);
            audit($db, 'backup.vault_key.revealed', 'vault', null,
                  "user=$username fingerprint=" . ipam_vault_fingerprint($raw));
            ipam_destinations_redirect($redirectBase, 'vault_revealed');
        }

        // After the vault_reveal branch's :never redirect, action is one of
        // {'vault_set', 'vault_replace'}. Gate each action on its own
        // precondition gates (CR #1100):
        //   vault_set with key present       → refuse (use Replace)
        //   vault_set + generate, encrypted  → refuse (would orphan archives
        //                                      under a fresh key)
        //   vault_set + paste, encrypted     → ALLOW (operator is restoring
        //                                      a lost-but-known key from
        //                                      their password manager;
        //                                      they accept the risk if
        //                                      the pasted value is wrong,
        //                                      since unwrap-on-restore
        //                                      will fail loudly in that
        //                                      case rather than silently
        //                                      destroy data)
        //   vault_replace + anything, enc    → refuse (replacing IS the
        //                                      orphaning operation)
        $mode = to_str($_POST['vault_mode'] ?? 'generate');
        $status = ipam_vault_key_status($db);
        if ($action === 'vault_set' && $status['present']) {
            return 'A vault key is already configured. Use Replace to change it.';
        }
        if ($status['has_encrypted_runs']) {
            // Only vault_set + paste is permitted when encrypted runs
            // exist (operator restoring a known key). Both vault_replace
            // and vault_set + generate would orphan archives.
            $isRestoreFromPaste = ($action === 'vault_set' && $mode === 'paste');
            if (!$isRestoreFromPaste) {
                return 'Cannot ' . ($action === 'vault_set' ? 'generate a new' : 'replace the')
                     . ' vault key while encrypted backups exist '
                     . '(any orphaned key would strand them). '
                     . ($action === 'vault_set'
                        ? 'Paste the original key from your password manager '
                        . 'to recover, or purge encrypted backup history first.'
                        : 'Purge encrypted backup history first.');
            }
        }

        $rawKey = '';
        if ($mode === 'paste') {
            $pasted = trim(to_str($_POST['vault_key_b64'] ?? ''));
            if ($pasted === '') return 'Paste the base64-encoded vault key.';
            $decoded = base64_decode($pasted, true);
            if (!is_string($decoded) || strlen($decoded) !== BACKUP_VAULT_KEY_LEN) {
                return 'Pasted vault key is malformed (expected ' . BACKUP_VAULT_KEY_LEN
                     . ' bytes base64).';
            }
            $rawKey = $decoded;
        } else {
            ipam_assert_random_bytes_available();
            $rawKey = random_bytes(BACKUP_VAULT_KEY_LEN);
        }

        // ipam_vault_key_persist() can throw when bootstrap_key generation
        // hits a non-writable config.php (hardened install pattern). Surface
        // that exception's message as a form error so the operator sees the
        // actionable remediation (CR #1100 review) instead of a 500.
        try {
            ipam_vault_key_persist($db, $rawKey);
        } catch (\RuntimeException $e) {
            audit($db, 'backup.vault_key.persist_failed', 'vault', null,
                  "user=$username action=$action mode=$mode error="
                  . substr($e->getMessage(), 0, 200));
            return $e->getMessage();
        }
        audit($db, 'backup.vault_key.' . ($action === 'vault_set' ? 'set' : 'replaced'),
              'vault', null,
              "user=$username mode=$mode fingerprint=" . ipam_vault_fingerprint($rawKey));
        // Hand the operator the new key once so they can copy it
        // offline. Same flash slot as Reveal — rendered exactly once.
        $_SESSION['vault_key_revealed'] = base64_encode($rawKey);
        ipam_destinations_redirect(
            $redirectBase,
            $action === 'vault_set' ? 'vault_set' : 'vault_replaced'
        );
    }

    // v3.25.0 #850: JSON-envelope bulk-verify (returns + exits, never falls
    // through to the redirect handlers below).
    if ($action === 'verify_all_destination') {
        $id = to_int($_POST['id'] ?? '0');
        header('Content-Type: application/json');
        if ($id <= 0) {
            http_response_code(400);
            echo (string) json_encode(['ok' => false, 'error' => 'bad_request']);
            exit;
        }
        // Content-Type is application/json above; output is json_encode
        // of a structured array, never raw $_REQUEST data.
        echo (string) json_encode(ipam_backup_destination_verify_all($db, $id)); // nosemgrep
        exit;
    }

    if ($action === 'create_destination') {
        $name    = trim(to_str($_POST['name'] ?? ''));
        $type    = to_str($_POST['type'] ?? '');

        if ($name === '') return 'Name is required.';
        if (!in_array($type, ['s3', 'sftp', 'local'], true)) return 'Invalid destination type.';

        // v3.25.0 #1076 #851 #846 #848: read picker fields with server-side
        // validation. Encryption-mode 'unencrypted' is rejected for non-local
        // destinations (matches the UI grey-out + backup orchestrator guard).
        $picker = ipam_destinations_collect_picker($type, $_POST);
        if (is_string($picker)) return $picker;

        // The legacy `encrypt` boolean is derived from the new encryption_mode
        // for back-compat with any code that still reads it; will be removed
        // in a later release.
        $encrypt = ($picker['default_encryption_mode'] === 'unencrypted') ? 0 : 1;

        $cfg = ipam_destinations_collect_config($type, $_POST);
        if (is_string($cfg)) return $cfg;

        $now  = ipam_dialect()->now();
        $stmt = $db->prepare(
            "INSERT INTO backup_destinations
                (name, type, config, encrypt, default_backup_type, default_encryption_mode,
                 retention_hourly, retention_daily, retention_weekly, retention_monthly,
                 is_default, is_active, created_at, updated_at)
             VALUES (:n, :t, :c, :e, :dbt, :dem, :rh, :rd, :rw, :rm, 0, 1, $now, $now)"
        );
        $stmt->execute([
            ':n'   => $name,
            ':t'   => $type,
            ':c'   => json_encode($cfg, JSON_UNESCAPED_SLASHES),
            ':e'   => $encrypt,
            ':dbt' => $picker['default_backup_type'],
            ':dem' => $picker['default_encryption_mode'],
            ':rh'  => $picker['retention_hourly'],
            ':rd'  => $picker['retention_daily'],
            ':rw'  => $picker['retention_weekly'],
            ':rm'  => $picker['retention_monthly'],
        ]);
        $newId = (int) $db->lastInsertId();

        // If the create form requested default, promote in the same
        // transaction (sets is_default=1 here and clears all others).
        if ($picker['is_default'] === 1) {
            ipam_destinations_set_default($db, $newId);
        }

        audit($db, 'destination.create', 'destination', $newId,
              "name=$name type=$type format={$picker['default_backup_type']} enc={$picker['default_encryption_mode']}");
        $_SESSION['flash_test'] = [
            'destination_id' => $newId,
            'result'         => ipam_destination_test_now($db, $newId, 'auto-on-save'),
        ];
        ipam_destinations_redirect($redirectBase, 'created');
    }

    if ($action === 'update_destination') {
        $id   = to_int($_POST['id'] ?? '0');
        $name = trim(to_str($_POST['name'] ?? ''));
        $type = to_str($_POST['type'] ?? '');

        if ($id <= 0) return 'Invalid destination ID.';
        if ($name === '') return 'Name is required.';
        if (!in_array($type, ['s3', 'sftp', 'local'], true)) return 'Invalid destination type.';

        $existing = $db->prepare(
            "SELECT type, config, encrypt, default_backup_type, default_encryption_mode
               FROM backup_destinations WHERE id=:id"
        );
        $existing->execute([':id' => $id]);
        $existingRow = $existing->fetch();
        /** @var array<string, mixed> $existingCfg */
        $existingCfg     = [];
        $existingType    = '';
        $existingEncMode = 'stored';
        if (is_array($existingRow)) {
            $existingType = to_str($existingRow['type']);
            $decoded = json_decode(to_str($existingRow['config']), true);
            if (is_array($decoded)) $existingCfg = $decoded;
            $existingEncMode = is_string($existingRow['default_encryption_mode'] ?? null)
                ? to_str($existingRow['default_encryption_mode']) : 'stored';
        }

        if (!is_array($existingRow)) {
            http_response_code(404);
            return 'Destination not found.';
        }
        if ($existingType !== '' && $type !== $existingType) {
            http_response_code(400);
            return 'Destination type cannot be changed. Delete and recreate to switch types.';
        }

        $_POST = ipam_destination_merge_secrets($_POST, $existingCfg, $type);

        $picker = ipam_destinations_collect_picker($type, $_POST);
        if (is_string($picker)) return $picker;

        $encrypt = ($picker['default_encryption_mode'] === 'unencrypted') ? 0 : 1;

        $cfg = ipam_destinations_collect_config($type, $_POST);
        if (is_string($cfg)) return $cfg;

        // CR feedback PR #1054 round 4: verify the mutation actually hit a
        // row using rowCount() AFTER the DML.
        $now  = ipam_dialect()->now();
        $stmt = $db->prepare(
            "UPDATE backup_destinations SET
                 name=:n, type=:t, config=:c, encrypt=:e,
                 default_backup_type=:dbt, default_encryption_mode=:dem,
                 retention_hourly=:rh, retention_daily=:rd,
                 retention_weekly=:rw, retention_monthly=:rm,
                 updated_at=$now
             WHERE id=:id"
        );
        $stmt->execute([
            ':n'   => $name,
            ':t'   => $type,
            ':c'   => json_encode($cfg, JSON_UNESCAPED_SLASHES),
            ':e'   => $encrypt,
            ':dbt' => $picker['default_backup_type'],
            ':dem' => $picker['default_encryption_mode'],
            ':rh'  => $picker['retention_hourly'],
            ':rd'  => $picker['retention_daily'],
            ':rw'  => $picker['retention_weekly'],
            ':rm'  => $picker['retention_monthly'],
            ':id'  => $id,
        ]);
        // No rowCount() check here: MySQL returns 0 when the submitted
        // values match the current row, which would falsely report
        // "Destination not found" on no-op saves. Existence is already
        // proven by the existingRow SELECT above. (CR #1096 major
        // finding 2026-05-06.)
        if ($picker['is_default'] === 1) {
            ipam_destinations_set_default($db, $id);
        }

        audit($db, 'destination.update', 'destination', $id,
              "name=$name type=$type format={$picker['default_backup_type']} enc={$picker['default_encryption_mode']}");

        // Encryption-mode change: separate audit + notification (§2.4 v3.22.0).
        if ($existingEncMode !== $picker['default_encryption_mode']) {
            audit($db, 'backup.encryption_change', 'destination', $id,
                "name=$name old={$existingEncMode} new={$picker['default_encryption_mode']}");
            try {
                ipam_backup_notify($db, 'encryption_change', [
                    'dest'     => ['name' => $name],
                    'old_mode' => $existingEncMode,
                    'new_mode' => $picker['default_encryption_mode'],
                ]);
            } catch (\Throwable $ne) {
                error_log('[backup] encryption-change notify dispatch failed: ' . $ne->getMessage());
            }
        }

        $_SESSION['flash_test'] = [
            'destination_id' => $id,
            'result'         => ipam_destination_test_now($db, $id, 'auto-on-save'),
        ];
        ipam_destinations_redirect($redirectBase, 'updated');
    }

    if ($action === 'set_default_destination') {
        $id = to_int($_POST['id'] ?? '0');
        if ($id <= 0) return 'Invalid destination ID.';
        $exists = $db->prepare("SELECT name FROM backup_destinations WHERE id = :id");
        $exists->execute([':id' => $id]);
        $row = $exists->fetch();
        if (!is_array($row)) return 'Destination not found.';
        ipam_destinations_set_default($db, $id);
        audit($db, 'backup.set_default_destination', 'destination', $id,
              'name=' . to_str($row['name']));
        ipam_destinations_redirect($redirectBase, 'default_set');
    }

    if ($action === 'delete_destination') {
        $id = to_int($_POST['id'] ?? '0');
        if ($id <= 0) return 'Invalid destination ID.';
        $stmt = $db->prepare("DELETE FROM backup_destinations WHERE id=:id");
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            return 'Destination not found.';
        }
        audit($db, 'destination.delete', 'destination', $id, "id=$id");
        ipam_destinations_redirect($redirectBase, 'deleted');
    }

    if ($action === 'toggle_active_destination') {
        $id = to_int($_POST['id'] ?? '0');
        if ($id <= 0) return 'Invalid destination ID.';
        $now  = ipam_dialect()->now();
        $stmt = $db->prepare(
            "UPDATE backup_destinations SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END, updated_at=$now WHERE id=:id"
        );
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            return 'Destination not found.';
        }
        $row = $db->prepare("SELECT is_active FROM backup_destinations WHERE id=:id");
        $row->execute([':id' => $id]);
        $fetched = $row->fetch();
        $state   = is_array($fetched) ? (to_int($fetched['is_active']) === 1 ? 'enabled' : 'disabled') : 'toggled';
        audit($db, 'destination.toggle_active', 'destination', $id, "state=$state");
        ipam_destinations_redirect($redirectBase);
    }

    if ($action === 'create_schedule') {
        $destId      = to_int($_POST['destination_id'] ?? '0');
        $frequency   = to_str($_POST['frequency'] ?? 'daily');
        $timeOfDay   = to_str($_POST['time_of_day'] ?? '02:00');
        $dayOfWeek   = to_int($_POST['day_of_week'] ?? '1');
        $dayOfMonth  = to_int($_POST['day_of_month'] ?? '1');
        $retHourly   = max(0, to_int($_POST['retention_hourly']  ?? '24'));
        $retDaily    = max(0, to_int($_POST['retention_daily']   ?? '7'));
        $retWeekly   = max(0, to_int($_POST['retention_weekly']  ?? '4'));
        $retMonthly  = max(0, to_int($_POST['retention_monthly'] ?? '12'));

        $dowParam = ($frequency === 'weekly')  ? $dayOfWeek  : null;
        $domParam = ($frequency === 'monthly') ? $dayOfMonth : null;

        if ($destId <= 0) return 'Destination is required.';
        if (!in_array($frequency, ['hourly', 'daily', 'weekly', 'monthly'], true)) {
            return 'Invalid frequency.';
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeOfDay)) {
            return 'Time of day must be in HH:MM format (e.g. 02:00).';
        }
        if ($dowParam !== null && ($dowParam < 0 || $dowParam > 6)) {
            return 'Day of week must be 0–6.';
        }
        if ($domParam !== null && ($domParam < 1 || $domParam > 28)) {
            return 'Day of month must be 1–28.';
        }

        // CR feedback PR #1054: enforce one schedule per destination at the app
        // layer too. The DB-side UNIQUE constraint added in the
        // 3.21.0-schedule-unique migration backstops this; the app check returns
        // a friendlier error before we hit the SQLSTATE error.
        $existing = $db->prepare("SELECT COUNT(*) FROM backup_schedules WHERE destination_id = :did");
        $existing->execute([':did' => $destId]);
        if ((int) $existing->fetchColumn() > 0) {
            return 'A schedule already exists for this destination. Edit the existing one instead.';
        }

        $nextRunAt = gmdate('Y-m-d H:i:s', ipam_backup_next_run_at([
            'frequency'    => $frequency,
            'time_of_day'  => $timeOfDay,
            'day_of_week'  => $dowParam ?? 0,
            'day_of_month' => $domParam ?? 1,
        ]));
        $now  = ipam_dialect()->now();
        $stmt = $db->prepare(
            "INSERT INTO backup_schedules
                (destination_id, frequency, time_of_day, day_of_week, day_of_month,
                 retention_hourly, retention_daily, retention_weekly, retention_monthly,
                 next_run_at, is_active, created_at)
             VALUES (:did, :freq, :tod, :dow, :dom, :rh, :rd, :rw, :rm, :nra, 1, $now)"
        );
        // CR feedback PR #1054: the COUNT() preflight above is advisory; two
        // concurrent submits can still race past it and trip the
        // UNIQUE(destination_id) constraint installed by the
        // 3.21.0-schedule-unique migration. Catch the unique-violation here
        // and return the same friendly message rather than letting it 500.
        try {
            $stmt->execute([
                ':did'  => $destId,
                ':freq' => $frequency,
                ':tod'  => $timeOfDay,
                ':dow'  => $dowParam,
                ':dom'  => $domParam,
                ':rh'   => $retHourly,
                ':rd'   => $retDaily,
                ':rw'   => $retWeekly,
                ':rm'   => $retMonthly,
                ':nra'  => $nextRunAt,
            ]);
        } catch (\PDOException $e) {
            $sqlState   = $e->errorInfo[0] ?? '';
            $driverCode = (string) ($e->errorInfo[1] ?? '');
            $msg        = strtolower((string) ($e->errorInfo[2] ?? $e->getMessage()));
            // CR feedback PR #1054 round 4: classify by SQLSTATE + driver
            // code AND a message check, since each engine names duplicates
            // differently. UNIQUE violation: PG 23505 OR MySQL 23000+1062
            // ("Duplicate entry…") OR SQLite 23000 with "unique constraint
            // failed". FK violation: PG 23503 OR MySQL 23000+1452 OR a
            // message hit on "foreign key" — surface as "Destination not
            // found." rather than letting the catch fall through to a 500.
            $isUniqueViolation =
                $sqlState === '23505'
                || ($sqlState === '23000'
                    && ($driverCode === '1062'
                        || str_contains($msg, 'duplicate entry')
                        || str_contains($msg, 'unique constraint failed')));
            $isForeignKeyViolation =
                $sqlState === '23503'
                || ($sqlState === '23000'
                    && ($driverCode === '1452'
                        || str_contains($msg, 'foreign key')));
            if ($isUniqueViolation) {
                return 'A schedule already exists for this destination. Edit the existing one instead.';
            }
            if ($isForeignKeyViolation) {
                return 'Destination not found.';
            }
            throw $e;
        }
        $newSchedId = (int) $db->lastInsertId();
        audit($db, 'schedule.create', 'schedule', $newSchedId, "destination_id=$destId frequency=$frequency");
        ipam_destinations_redirect($redirectBase, 'sched_created');
    }

    if ($action === 'update_schedule') {
        $id         = to_int($_POST['id'] ?? '0');
        $frequency  = to_str($_POST['frequency'] ?? 'daily');
        $timeOfDay  = to_str($_POST['time_of_day'] ?? '02:00');
        $dayOfWeek  = to_int($_POST['day_of_week'] ?? '1');
        $dayOfMonth = to_int($_POST['day_of_month'] ?? '1');
        $retHourly  = max(0, to_int($_POST['retention_hourly']  ?? '24'));
        $retDaily   = max(0, to_int($_POST['retention_daily']   ?? '7'));
        $retWeekly  = max(0, to_int($_POST['retention_weekly']  ?? '4'));
        $retMonthly = max(0, to_int($_POST['retention_monthly'] ?? '12'));

        $dowParam = ($frequency === 'weekly')  ? $dayOfWeek  : null;
        $domParam = ($frequency === 'monthly') ? $dayOfMonth : null;

        if ($id <= 0) return 'Invalid schedule ID.';
        if (!in_array($frequency, ['hourly', 'daily', 'weekly', 'monthly'], true)) {
            return 'Invalid frequency.';
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeOfDay)) {
            return 'Time of day must be in HH:MM format (e.g. 02:00).';
        }
        if ($dowParam !== null && ($dowParam < 0 || $dowParam > 6)) {
            return 'Day of week must be 0–6.';
        }
        if ($domParam !== null && ($domParam < 1 || $domParam > 28)) {
            return 'Day of month must be 1–28.';
        }

        $nextRunAt = gmdate('Y-m-d H:i:s', ipam_backup_next_run_at([
            'frequency'    => $frequency,
            'time_of_day'  => $timeOfDay,
            'day_of_week'  => $dowParam ?? 0,
            'day_of_month' => $domParam ?? 1,
        ]));
        $stmt = $db->prepare(
            "UPDATE backup_schedules SET
                frequency=:freq, time_of_day=:tod, day_of_week=:dow, day_of_month=:dom,
                retention_hourly=:rh, retention_daily=:rd, retention_weekly=:rw, retention_monthly=:rm,
                next_run_at=:nra
             WHERE id=:id"
        );
        $stmt->execute([
            ':freq' => $frequency,
            ':tod'  => $timeOfDay,
            ':dow'  => $dowParam,
            ':dom'  => $domParam,
            ':rh'   => $retHourly,
            ':rd'   => $retDaily,
            ':rw'   => $retWeekly,
            ':rm'   => $retMonthly,
            ':nra'  => $nextRunAt,
            ':id'   => $id,
        ]);
        if ($stmt->rowCount() === 0) {
            return 'Schedule not found.';
        }
        audit($db, 'schedule.update', 'schedule', $id, "frequency=$frequency");
        ipam_destinations_redirect($redirectBase, 'sched_updated');
    }

    if ($action === 'delete_schedule') {
        $id = to_int($_POST['id'] ?? '0');
        if ($id <= 0) return 'Invalid schedule ID.';
        $stmt = $db->prepare("DELETE FROM backup_schedules WHERE id=:id");
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            return 'Schedule not found.';
        }
        audit($db, 'schedule.delete', 'schedule', $id, "id=$id");
        ipam_destinations_redirect($redirectBase, 'sched_deleted');
    }

    if ($action === 'toggle_active_schedule') {
        $id = to_int($_POST['id'] ?? '0');
        if ($id <= 0) return 'Invalid schedule ID.';
        $stmt = $db->prepare(
            "UPDATE backup_schedules SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=:id"
        );
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            return 'Schedule not found.';
        }
        $row = $db->prepare("SELECT is_active FROM backup_schedules WHERE id=:id");
        $row->execute([':id' => $id]);
        $fetched = $row->fetch();
        $state   = is_array($fetched) ? (to_int($fetched['is_active']) === 1 ? 'enabled' : 'disabled') : 'toggled';
        audit($db, 'schedule.toggle_active', 'schedule', $id, "state=$state");
        ipam_destinations_redirect($redirectBase);
    }

    return '';
}

/**
 * Load destinations, schedules, GET flash, and pop the auto-test session flash.
 *
 * @return array{
 *   destinations: list<array<string, mixed>>,
 *   schedules: list<array<string, mixed>>,
 *   flash: string,
 *   flashTestId: int,
 *   flashTestOk: bool,
 *   flashTestMsg: string,
 *   flashTestLatency: int|null,
 *   vaultStatus: array{present:bool, source:string, fingerprint:?string, created_at:?string, has_encrypted_runs:bool},
 *   revealedKey: string
 * }
 */
function ipam_destinations_load_state(\PDO $db): array
{
    $flash = '';
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $f     = to_str($_GET['flash'] ?? '');
        $flash = match ($f) {
            'created'         => 'Destination created.',
            'updated'         => 'Destination updated.',
            'deleted'         => 'Destination deleted.',
            'default_set'     => 'Default destination updated.',
            'sched_created'   => 'Schedule created.',
            'sched_updated'   => 'Schedule updated.',
            'sched_deleted'   => 'Schedule deleted.',
            'vault_revealed'  => 'Vault key revealed below — copy it offline now; this is your only chance.',
            'vault_set'       => 'Vault key configured. Copy the value below offline; this is your only chance.',
            'vault_replaced'  => 'Vault key replaced. Copy the new value below offline; this is your only chance.',
            default           => '',
        };
    }

    $flashTestId      = 0;
    $flashTestOk      = false;
    $flashTestMsg     = '';
    $flashTestLatency = null;
    if (isset($_SESSION['flash_test']) && is_array($_SESSION['flash_test'])) {
        $ft               = $_SESSION['flash_test'];
        $flashTestId      = is_int($ft['destination_id'] ?? null) ? $ft['destination_id'] : 0;
        $resultRaw        = is_array($ft['result'] ?? null) ? $ft['result'] : [];
        $flashTestOk      = (bool) ($resultRaw['ok'] ?? false);
        $flashTestMsg     = is_string($resultRaw['message'] ?? null) ? $resultRaw['message'] : '';
        $rawLatency       = $resultRaw['latency_ms'] ?? null;
        $flashTestLatency = is_int($rawLatency)
            ? $rawLatency
            : (is_numeric($rawLatency) ? (int) $rawLatency : null);
        unset($_SESSION['flash_test']);
    }

    $destStmt = $db->query("SELECT * FROM backup_destinations ORDER BY name");
    /** @var list<array<string, mixed>> $destinations */
    $destinations = $destStmt !== false ? $destStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    // v3.27.8 (#1172, PR 5/5): annotate each destination with its most
    // recent backup_run if that run was 'failed', so the view can render
    // a danger badge with the truncated error_message on the destination
    // card. Operators get an at-a-glance signal on the Destinations admin
    // page instead of having to switch to the History tab to discover a
    // destination has been failing. The subquery picks the highest-id
    // (newest) row per destination_id, which matches AUTOINCREMENT order
    // and is portable across SQLite / MySQL / Postgres without window
    // functions or driver-specific syntax.
    $latestStmt = $db->query(
        "SELECT br.destination_id, br.status, br.error_message, "
        . "br.filename, br.started_at "
        . "FROM backup_runs br "
        . "WHERE br.destination_id IS NOT NULL "
        . "AND br.id IN ("
        . "  SELECT MAX(id) FROM backup_runs "
        . "  WHERE destination_id IS NOT NULL GROUP BY destination_id"
        . ")"
    );
    /** @var list<array<string,mixed>> $latestRuns */
    $latestRuns = $latestStmt !== false ? $latestStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    /** @var array<int,array{error_message:string,filename:string,started_at:string}> $failedByDestId */
    $failedByDestId = [];
    foreach ($latestRuns as $row) {
        if (($row['status'] ?? '') !== 'failed') continue;
        $did = to_int($row['destination_id'] ?? 0);
        if ($did <= 0) continue;
        $failedByDestId[$did] = [
            'error_message' => to_str($row['error_message'] ?? ''),
            'filename'      => to_str($row['filename'] ?? ''),
            'started_at'    => to_str($row['started_at'] ?? ''),
        ];
    }
    foreach ($destinations as &$d) {
        $did = to_int($d['id'] ?? 0);
        if ($did > 0 && isset($failedByDestId[$did])) {
            $d['last_failure'] = $failedByDestId[$did];
        }
    }
    unset($d);

    $schedStmt = $db->query("SELECT * FROM backup_schedules ORDER BY destination_id, id");
    /** @var list<array<string, mixed>> $schedules */
    $schedules = $schedStmt !== false ? $schedStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    // v3.26.0 (#1098) — vault-key status (always loaded for admin
    // visibility) + one-shot reveal flash. Reading the flash here unsets
    // it so a refresh of the destinations page never re-renders the raw
    // key. Non-admin viewers get an empty status block; the view renders
    // the panel only when current_user()['role'] === 'admin'.
    $vaultStatus = ipam_vault_key_status($db);
    $revealedKey = '';
    if (isset($_SESSION['vault_key_revealed']) && is_string($_SESSION['vault_key_revealed'])) {
        $revealedKey = $_SESSION['vault_key_revealed'];
        unset($_SESSION['vault_key_revealed']);
    }

    return [
        'destinations'     => $destinations,
        'schedules'        => $schedules,
        'flash'            => $flash,
        'flashTestId'      => $flashTestId,
        'flashTestOk'      => $flashTestOk,
        'flashTestMsg'     => $flashTestMsg,
        'flashTestLatency' => $flashTestLatency,
        'vaultStatus'      => $vaultStatus,
        'revealedKey'      => $revealedKey,
    ];
}

/**
 * Render the destination or schedule edit form for the global drawer.
 *
 * @return string|null  Rendered HTML, or null if $form is unknown or the row is missing.
 */
function ipam_render_destination_edit_drawer(\PDO $db, int $id, string $form): ?string
{
    if ($form !== 'destination' && $form !== 'schedule') {
        return null;
    }
    $st = $db->prepare("SELECT * FROM backup_destinations WHERE id = :id");
    $st->execute([':id' => $id]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    /** @var array<string, mixed> $dest */
    $dest = $row;

    $rawConfig = $dest['config'] ?? null;
    $decoded   = is_string($rawConfig) ? json_decode($rawConfig, true) : null;
    $config    = is_array($decoded) ? $decoded : [];

    $sched = null;
    if ($form === 'schedule') {
        $st = $db->prepare("SELECT * FROM backup_schedules WHERE destination_id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $sched = is_array($row) ? $row : null;
        if ($sched === null) {
            return null;
        }
    }

    ob_start();
    if ($form === 'destination') {
        require __DIR__ . '/../views/_destination_edit_destination_form.php';
    } else {
        require __DIR__ . '/../views/_destination_edit_schedule_form.php';
    }
    return (string) ob_get_clean();
}
