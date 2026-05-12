<?php
declare(strict_types=1);

/**
 * Restore wizard state loader. Extracted from restore_web.php in v3.21.0
 * Wave 4 commit 5 so the wizard can drive both the legacy restore_web.php
 * page AND backup_admin.php?tab=restore.
 *
 * Wraps the phase-locked wizard from lib/restore_wizard.php (Wave 3 #807):
 * the POST handler validates step transitions, signs/verifies tokens, and on
 * apply-success redirects to login.php?restored=1 (terminal — no return).
 */

require_once __DIR__ . '/restore_wizard.php';

/**
 * Process the restore-wizard POST step (if any) and load destinations for
 * step 1. Apply-success exits via header() and never returns.
 *
 * @return array{
 *   err: string,
 *   phase: string,
 *   stagedPath: string,
 *   stagedSig: string,
 *   stagedFilename: string,
 *   stagedSize: int,
 *   stagedDestId: int,
 *   dryRunResult: array{tables:list<mixed>, schema_diff:list<mixed>, total_statements:int, warnings:list<mixed>}|null,
 *   destinations: list<array<string, mixed>>
 * }
 *
 * @param array<string, mixed> $config
 */
function ipam_backup_admin_restore_handle(\PDO $db, array $config): array
{
    $err            = '';
    $phase          = '';
    $stagedPath     = '';
    $stagedSig      = '';
    $stagedFilename = '';
    $stagedSize     = 0;
    $stagedDestId   = 0;
    $dryRunResult   = null;

    $me       = current_user();
    $myUserId = is_int($me['id'] ?? null) ? $me['id'] : 0;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_require();
        if (demo_mode_enabled()) {
            $err = 'Restore is disabled in demo mode.';
        } elseif (ipam_restore_wizard_is_rate_limited($db, $myUserId ?: null)) {
            audit($db, 'db.restore_rate_limited', 'system', null, "user_id=$myUserId");
            $err = 'Too many restore attempts. Please wait a few minutes before trying again.';
        } else {
            $step = to_str($_POST['step'] ?? '');

            // 'upload' — operator-uploaded local file (#837, v3.24.0).
            // Same downstream pipeline as 'stage': produces a staged file
            // under data/tmp/ + a phase=staged token. Difference is the
            // input source ($_FILES instead of a configured destination)
            // and the credential surface (passphrase prompt for IPAMBKP3
            // transitory archives).
            if ($step === 'upload') {
                $passphrase = to_str($_POST['passphrase'] ?? '');
                $name       = to_str($_POST['display_name'] ?? '');
                if ($name === '') {
                    $upload = $_FILES['restore_upload'] ?? null;
                    if (is_array($upload) && is_string($upload['name'] ?? null)) {
                        $name = basename($upload['name']);
                    }
                }
                $staged = null;
                try {
                    $staged = ipam_restore_prepare_for_upload(
                        $config,
                        $passphrase !== '' ? $passphrase : null
                    );
                    $meta = [
                        'filename'       => $staged['filename'],
                        'destination_id' => 0, // upload path has no destination
                        'size'           => $staged['size'],
                    ];
                    $stagedSig      = ipam_restore_wizard_sign($config, RESTORE_WIZARD_PHASE_STAGED, $staged['path'], $meta);
                    $stagedPath     = $staged['path'];
                    $stagedFilename = $staged['filename'];
                    $stagedSize     = $staged['size'];
                    $stagedDestId   = 0;
                    $phase          = RESTORE_WIZARD_PHASE_STAGED;
                    audit($db, 'db.restore_upload', 'system', null, "filename=$name size=$stagedSize");
                } catch (IpamBackupKeyRequiredException $e) {
                    // The dispatcher raises IpamBackupKeyRequiredException for
                    // BOTH IPAMBKP3 transitory archives (passphrase missing,
                    // operator can re-upload + type it) AND IPAMBKP3 stored
                    // archives whose backup_vault_key is absent on this
                    // install (no in-band recovery — operator must restore
                    // the key in config.php). Distinguish by mode so we
                    // don't drop the admin onto a passphrase form they
                    // cannot solve.
                    $err = $e->getMessage();
                    if ($e->mode === BACKUP_V3_MODE_TRANSITORY) {
                        // The upload helper consumes (move + unlink) the
                        // $_FILES temp by the time we reach this branch,
                        // so the next POST has no archive to decrypt.
                        // Deliberate UX choice: the needs_passphrase view
                        // re-renders the file input alongside the new
                        // passphrase field (see views/backup_admin_restore.php)
                        // so the operator picks the file again. Persisting
                        // the upload in a session-keyed staging area would
                        // cleaner but requires session-cleanup discipline +
                        // extra security review for session-bound paths;
                        // tracked as a v3.25.0 polish item rather than
                        // shipping the half-built path-stash here.
                        audit($db, 'db.restore_upload_needs_passphrase', 'system', null, "filename=$name");
                        $phase = 'needs_passphrase';
                    } else {
                        // Stored-mode upload: the missing credential is
                        // backup_vault_key, not a passphrase. Stay on
                        // Step 1 with the error banner; operator must
                        // populate config.php['backup_vault_key'] and retry.
                        audit($db, 'db.restore_upload_failed', 'system', null,
                              "filename=$name reason=missing_backup_vault_key");
                        $phase = '';
                    }
                } catch (Throwable $e) {
                    error_log('[restore_web] upload failed: ' . $e->getMessage());
                    audit($db, 'db.restore_upload_failed', 'system', null, "filename=$name error=" . substr($e->getMessage(), 0, 200));
                    if (is_array($staged)) {
                        $orphan  = realpath($staged['path']);
                        $tmpReal = realpath(__DIR__ . '/../data/tmp');
                        if ($orphan !== false && $tmpReal !== false
                            && str_starts_with($orphan . '/', rtrim($tmpReal, '/') . '/')
                            && is_file($orphan)) {
                            @unlink($orphan); // nosemgrep: php.lang.security.unlink-use.unlink-use -- realpath() under data/tmp/
                        }
                    }
                    $stagedPath = $stagedSig = $stagedFilename = $phase = '';
                    $stagedSize = $stagedDestId = 0;
                    $err        = 'Upload failed: ' . $e->getMessage();
                }
            }

            if ($step === 'stage') {
                $destId = to_int($_POST['destination_id'] ?? 0);
                $name   = to_str($_POST['name'] ?? '');
                $staged = null;
                try {
                    $staged         = ipam_restore_prepare_for_restore($db, $config, $destId, $name);
                    $meta           = [
                        'filename'       => $staged['filename'],
                        'destination_id' => $destId,
                        'size'           => $staged['size'],
                    ];
                    $stagedSig      = ipam_restore_wizard_sign($config, RESTORE_WIZARD_PHASE_STAGED, $staged['path'], $meta);
                    $stagedPath     = $staged['path'];
                    $stagedFilename = $staged['filename'];
                    $stagedSize     = $staged['size'];
                    $stagedDestId   = $destId;
                    $phase          = RESTORE_WIZARD_PHASE_STAGED;
                    audit($db, 'db.restore_stage', 'destination', $destId, "name=$name");
                } catch (Throwable $e) {
                    error_log('[restore_web] stage failed: ' . $e->getMessage());
                    audit($db, 'db.restore_stage_failed', 'destination', $destId, "name=$name error=" . substr($e->getMessage(), 0, 200));
                    if (is_array($staged)) {
                        $orphan  = realpath($staged['path']);
                        $tmpReal = realpath(__DIR__ . '/../data/tmp');
                        if ($orphan !== false && $tmpReal !== false
                            && str_starts_with($orphan . '/', rtrim($tmpReal, '/') . '/')
                            && is_file($orphan)) {
                            @unlink($orphan); // nosemgrep: php.lang.security.unlink-use.unlink-use -- realpath() under data/tmp/
                        }
                    }
                    $stagedPath = $stagedSig = $stagedFilename = $phase = '';
                    $stagedSize = $stagedDestId = 0;
                    $err        = 'Stage failed: ' . $e->getMessage();
                }
            }

            if ($step === 'dryrun') {
                $stagedPath     = to_str($_POST['staged_path']     ?? '');
                $stagedSig      = to_str($_POST['staged_sig']      ?? '');
                $stagedFilename = to_str($_POST['staged_filename'] ?? '');
                $stagedSize     = to_int($_POST['staged_size']     ?? 0);
                $stagedDestId   = to_int($_POST['staged_destination_id'] ?? 0);
                $meta           = [
                    'filename'       => $stagedFilename,
                    'destination_id' => $stagedDestId,
                    'size'           => $stagedSize,
                ];
                $verified = ipam_restore_wizard_verify(
                    $config,
                    RESTORE_WIZARD_PHASE_STAGED,
                    $stagedPath,
                    $stagedSig,
                    $meta
                );
                if ($verified === null) {
                    audit($db, 'db.restore_dryrun_failed', 'system', null, "reason=invalid_token file=$stagedFilename");
                    $err        = 'Invalid or expired staged file token. Please restart the wizard.';
                    $stagedPath = $stagedSig = $stagedFilename = $phase = '';
                    $stagedSize = $stagedDestId = 0;
                } else {
                    try {
                        $dryRunResult = ipam_restore_dry_run($db, $verified);
                        audit($db, 'db.restore_dryrun', 'system', null,
                              "file=$stagedFilename tables=" . count($dryRunResult['tables']));
                        $stagedSig  = ipam_restore_wizard_sign($config, RESTORE_WIZARD_PHASE_DRYRUN_OK, $verified, $meta);
                        $stagedPath = $verified;
                        $phase      = RESTORE_WIZARD_PHASE_DRYRUN_OK;
                    } catch (Throwable $e) {
                        error_log('[restore_web] dry run failed: ' . $e->getMessage());
                        audit($db, 'db.restore_dryrun_failed', 'system', null, "file=$stagedFilename error=" . substr($e->getMessage(), 0, 200));
                        $err        = 'Dry run failed: ' . $e->getMessage();
                        $stagedSig  = ipam_restore_wizard_sign($config, RESTORE_WIZARD_PHASE_STAGED, $verified, $meta);
                        $stagedPath = $verified;
                        $phase      = RESTORE_WIZARD_PHASE_STAGED;
                    }
                }
            }

            if ($step === 'apply') {
                $stagedPath     = to_str($_POST['staged_path']     ?? '');
                $stagedSig      = to_str($_POST['staged_sig']      ?? '');
                $stagedFilename = to_str($_POST['staged_filename'] ?? '');
                $stagedSize     = to_int($_POST['staged_size']     ?? 0);
                $stagedDestId   = to_int($_POST['staged_destination_id'] ?? 0);
                $confirm        = to_str($_POST['confirm'] ?? '');
                $meta           = [
                    'filename'       => $stagedFilename,
                    'destination_id' => $stagedDestId,
                    'size'           => $stagedSize,
                ];
                if ($confirm !== 'RESTORE') {
                    $err          = 'Confirmation text must be "RESTORE" exactly.';
                    $phase        = RESTORE_WIZARD_PHASE_DRYRUN_OK;
                    $dryRunResult = ['tables' => [], 'schema_diff' => [], 'total_statements' => 0, 'warnings' => []];
                } else {
                    $verified = ipam_restore_wizard_verify(
                        $config,
                        RESTORE_WIZARD_PHASE_DRYRUN_OK,
                        $stagedPath,
                        $stagedSig,
                        $meta
                    );
                    if ($verified === null) {
                        audit($db, 'db.restore_failed', 'system', null, "reason=invalid_or_unauthorised_token file=$stagedFilename");
                        $err        = 'Invalid token, or dry-run was not completed. Please restart the wizard.';
                        $stagedPath = $stagedSig = $stagedFilename = $phase = '';
                        $stagedSize = $stagedDestId = 0;
                    } else {
                        @set_time_limit(0);
                        try {
                            ipam_restore_apply($db, $verified, $stagedFilename, $stagedDestId > 0 ? $stagedDestId : null);
                            // Match the staging-failure cleanup: only unlink
                            // when the resolved path lives under data/tmp/.
                            $cleanupReal = realpath($verified);
                            $tmpReal     = realpath(__DIR__ . '/../data/tmp');
                            if (
                                $cleanupReal !== false
                                && $tmpReal !== false
                                && str_starts_with($cleanupReal . DIRECTORY_SEPARATOR, rtrim($tmpReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
                                && is_file($cleanupReal)
                            ) {
                                @unlink($cleanupReal); // nosemgrep: php.lang.security.unlink-use.unlink-use -- realpath() under data/tmp/
                            }
                            ipam_restore_wizard_invalidate_session();
                            header('Location: login.php?restored=1');
                            exit;
                        } catch (Throwable $e) {
                            error_log('[restore_web] apply failed: ' . $e->getMessage());
                            audit($db, 'db.restore_failed', 'system', null, "file=$stagedFilename error=" . substr($e->getMessage(), 0, 200));
                            $err        = 'Apply failed: ' . $e->getMessage();
                            $stagedPath = $stagedSig = $stagedFilename = $phase = '';
                            $stagedSize = $stagedDestId = 0;
                        }
                    }
                }
            }
        }
    }

    $destStmt = $db->query("SELECT id, name, type FROM backup_destinations WHERE is_active = 1 ORDER BY name");
    /** @var list<array<string, mixed>> $destinations */
    $destinations = $destStmt !== false ? $destStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    // #1077 — destination-driven backup browser. When ?dest=N is set on the
    // Restore tab URL, enumerate the destination's contents via the
    // BackupClientInterface and join with backup_runs so per-row Verify and
    // Delete can dispatch back to the History-tab handlers (which already
    // own those operations on backup_runs rows).
    $browseDestId = to_int($_GET['dest'] ?? 0);
    /** @var list<array<string,mixed>> $browseEntries */
    $browseEntries = [];
    $browseError = '';
    $degraded    = ipam_restore_degraded_database_unsupported($config);
    if ($browseDestId > 0) {
        try {
            $browseDest = ipam_backup_dest_load($db, $browseDestId);
            $client = ipam_backup_dest_client($browseDest);
            $objects = $client->listObjects();

            // Map filename → backup_runs metadata (run_id, checksum, type,
            // status). Limited to runs against this destination so a same-
            // named file on another destination doesn't shadow.
            $runsStmt = $db->prepare(
                "SELECT id, filename, checksum, backup_type, encryption_mode, status
                   FROM backup_runs
                  WHERE destination_id = :d AND filename IS NOT NULL"
            );
            $runsStmt->execute([':d' => $browseDestId]);
            /** @var array<string,array<string,mixed>> $runIndex */
            $runIndex = [];
            foreach ($runsStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (is_array($r) && is_string($r['filename'] ?? null)) {
                    $runIndex[$r['filename']] = $r;
                }
            }

            foreach ($objects as $obj) {
                $run = $runIndex[$obj['name']] ?? null;
                $browseEntries[] = ipam_restore_browse_entry_derive($obj, $run);
            }
            // Newest-first is the natural default for a restore picker — the
            // most recent backup is almost always the one an operator wants.
            // Sort here so the order is consistent regardless of destination
            // type (the per-client listObjects() implementations don't all
            // sort the same way: LocalBackupClient does, S3/SFTP don't).
            $browseEntries = ipam_restore_browse_sort_newest_first($browseEntries);
        } catch (Throwable $e) {
            $browseError = 'Could not list backups for this destination: ' . $e->getMessage();
        }
    }

    return [
        'err'            => $err,
        'phase'          => $phase,
        'stagedPath'     => $stagedPath,
        'stagedSig'      => $stagedSig,
        'stagedFilename' => $stagedFilename,
        'stagedSize'     => $stagedSize,
        'stagedDestId'   => $stagedDestId,
        'dryRunResult'   => $dryRunResult,
        'destinations'   => $destinations,
        // #1077 browse-state
        'browseDestId'   => $browseDestId,
        'browseEntries'  => $browseEntries,
        'browseError'    => $browseError,
        'browseDegradedDb' => $degraded,
    ];
}

/**
 * v3.27.9 — sort restore-tab browse entries newest-first by `last_modified`.
 *
 * Pure (no DB, no I/O) so it's unit-testable. `last_modified` strings are
 * not uniformly formatted across BackupClient implementations (Local/SFTP
 * emit `Y-m-d\TH:i:s\Z`, S3 stringifies a DateTime as `Y-m-d H:i:s`), so we
 * parse with strtotime() rather than string-compare. Unparseable values sort
 * to the end (treated as timestamp 0).
 *
 * @param list<array<string,mixed>> $entries
 * @return list<array<string,mixed>>
 */
function ipam_restore_browse_sort_newest_first(array $entries): array
{
    usort($entries, static function (array $a, array $b): int {
        $la = $a['last_modified'] ?? null;
        $lb = $b['last_modified'] ?? null;
        $ta = is_string($la) ? (strtotime($la) ?: 0) : 0;
        $tb = is_string($lb) ? (strtotime($lb) ?: 0) : 0;
        return $tb <=> $ta;
    });
    return $entries;
}

/**
 * v3.27.8 (Bug B+C) — derive a single Restore-tab browse-entry row from
 * a destination object + its optional backup_runs row.
 *
 * Pure function (no DB, no I/O) so the badge ground-truth rules are
 * unit-testable. Rules:
 *
 *   • When a backup_runs row exists, its `encryption_mode` and
 *     `backup_type` win unconditionally — filename suffix is ignored.
 *     This is the fix for #v3.27.8-B+C: pre-fix, a file ending in `.enc`
 *     was always badged "encrypted" even if the recorded run was
 *     `encryption_mode='unencrypted'` (and vice versa).
 *   • When no run row is present (orphan object — listObjects() saw it
 *     but no `backup_runs` row exists for that destination_id+filename),
 *     `is_orphan=true` is set so the UI can surface a "no DB record"
 *     marker. `is_encrypted` falls back to the `.enc` filename heuristic;
 *     `encryption_mode` and `backup_type` are reported as `'unknown'`
 *     rather than guessed from suffix so downstream UI can render a
 *     distinct "Unknown" badge and the dispatcher in ipam_restore_apply()
 *     keeps responsibility for magic-byte sniffing at stage time.
 *
 * @param array{name:string,size:int,last_modified:string} $obj
 * @param array<string,mixed>|null $run
 * @return array{
 *   name:string,size:int,last_modified:string,
 *   is_encrypted:bool,encryption_mode:string,backup_type:string,
 *   checksum:string,run_id:int,is_orphan:bool
 * }
 */
function ipam_restore_browse_entry_derive(array $obj, ?array $run): array
{
    if ($run !== null) {
        $mode = is_string($run['encryption_mode'] ?? null)
            ? (string)$run['encryption_mode']
            : 'unencrypted';
        $type = is_string($run['backup_type'] ?? null)
            ? (string)$run['backup_type']
            : 'unknown';
        return [
            'name'           => $obj['name'],
            'size'           => $obj['size'],
            'last_modified'  => $obj['last_modified'],
            'is_encrypted'   => $mode !== 'unencrypted',
            'encryption_mode' => $mode,
            'backup_type'    => $type,
            'checksum'       => is_string($run['checksum'] ?? null) ? (string)$run['checksum'] : '',
            'run_id'         => to_int($run['id'] ?? 0),
            'is_orphan'      => false,
        ];
    }

    return [
        'name'            => $obj['name'],
        'size'            => $obj['size'],
        'last_modified'   => $obj['last_modified'],
        'is_encrypted'    => str_ends_with($obj['name'], '.enc'),
        'encryption_mode' => 'unknown',
        'backup_type'     => 'unknown',
        'checksum'        => '',
        'run_id'          => 0,
        'is_orphan'       => true,
    ];
}

/**
 * #1077 §5b — detect when the install can't restore a Database-format
 * backup in-place. Database backups are engine-native SQL dumps; on
 * mysql/pgsql installs they need the `mysql` / `psql` CLI on the
 * web-server's PATH. Returns a non-empty string explaining the gap when
 * applicable, else empty string.
 *
 * @param array<string,mixed> $config
 */
function ipam_restore_degraded_database_unsupported(array $config): string
{
    $driverRaw = $config['db_driver'] ?? 'sqlite';
    $driver = is_string($driverRaw) ? $driverRaw : 'sqlite';
    if ($driver === 'sqlite') return '';
    $bin = $driver === 'mysql' ? 'mysql' : ($driver === 'pgsql' ? 'psql' : '');
    if ($bin === '') return '';
    // which-style probe; tolerate failure silently and report degraded.
    $rc = 1;
    @exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $rc);
    if ($rc === 0) return '';
    return 'This install runs on ' . $driver . ' but the `' . $bin . '` CLI is not on the web server\'s PATH; '
        . 'Database-format backups can\'t be restored in-place. Download to your machine and replay manually, '
        . 'or restore from an existing Logical (IPAMBKL1) backup — those use the engine-agnostic PDO path '
        . 'and don\'t need the native CLI.';
}
