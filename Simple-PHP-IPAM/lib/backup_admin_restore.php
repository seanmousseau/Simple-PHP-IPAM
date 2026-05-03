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
                "SELECT id, filename, checksum, backup_type, status
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
                $name = $obj['name'];
                $run  = $runIndex[$name] ?? null;
                // Default to 'unknown' rather than 'database' for objects
                // with no backup_runs row. An IPAMBKL1 file copied into the
                // destination, or one whose history was pruned, has no
                // record here — defaulting to 'database' would let the
                // degraded-restore gate disable Restore on mysql/pgsql
                // installs missing the native CLI even though the file
                // is actually a Logical-format dump that doesn't need it.
                // The dispatcher in ipam_restore_apply() sniffs the magic
                // bytes at stage time so the safe default is "I don't
                // know yet, let staging decide".
                $type = is_array($run) && is_string($run['backup_type'] ?? null) ? $run['backup_type'] : 'unknown';
                $browseEntries[] = [
                    'name'         => $name,
                    'size'         => $obj['size'],
                    'last_modified' => $obj['last_modified'],
                    'is_encrypted' => str_ends_with($name, '.enc'),
                    'backup_type'  => $type,
                    'checksum'     => is_array($run) && is_string($run['checksum'] ?? null) ? $run['checksum'] : '',
                    'run_id'       => is_array($run) ? to_int($run['id'] ?? 0) : 0,
                ];
            }
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
