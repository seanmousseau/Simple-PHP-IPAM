<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_once __DIR__ . '/lib/restore_wizard.php';
/** @var \PDO $db */
/** @var array<string, mixed> $config */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';

// Wizard state — derived once per request from a single phase-locked token.
$phase = '';                   // '', 'staged', 'dryrun_passed'
$stagedPath = '';
$stagedSig = '';
$stagedFilename = '';
$stagedSize = 0;
$stagedDestId = 0;
$dryRunResult = null;

$me = current_user();
$myUserId = is_int($me['id'] ?? null) ? $me['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (demo_mode_enabled()) {
        $err = 'Restore is disabled in demo mode.';
    } elseif (ipam_restore_wizard_is_rate_limited($db, $myUserId ?: null)) {
        // B-P2-61: per-user rolling-window throttle on db.restore_* attempts.
        audit($db, 'db.restore_rate_limited', 'system', null, "user_id=$myUserId");
        $err = 'Too many restore attempts. Please wait a few minutes before trying again.';
    } else {
        $step = to_str($_POST['step'] ?? '');

        // ----- Step 1: stage -----
        if ($step === 'stage') {
            $destId = to_int($_POST['destination_id'] ?? 0);
            $name   = to_str($_POST['name'] ?? '');
            $staged = null;
            try {
                $staged = ipam_restore_prepare_for_restore($db, $config, $destId, $name);
                $meta = [
                    'filename' => $staged['filename'],
                    'destination_id' => $destId,
                    'size' => $staged['size'],
                ];
                $stagedSig = ipam_restore_wizard_sign($config, RESTORE_WIZARD_PHASE_STAGED, $staged['path'], $meta);
                $stagedPath = $staged['path'];
                $stagedFilename = $staged['filename'];
                $stagedSize = $staged['size'];
                $stagedDestId = $destId;
                $phase = RESTORE_WIZARD_PHASE_STAGED;
                audit($db, 'db.restore_stage', 'destination', $destId, "name=$name");
            } catch (Throwable $e) {
                error_log('[restore_web] stage failed: ' . $e->getMessage());
                audit($db, 'db.restore_stage_failed', 'destination', $destId, "name=$name error=" . substr($e->getMessage(), 0, 200));
                if (is_array($staged)) {
                    $orphan = realpath($staged['path']);
                    $tmpReal = realpath(__DIR__ . '/data/tmp');
                    if ($orphan !== false && $tmpReal !== false
                        && str_starts_with($orphan . '/', rtrim($tmpReal, '/') . '/')
                        && is_file($orphan)) {
                        @unlink($orphan); // nosemgrep: php.lang.security.unlink-use.unlink-use -- realpath() under data/tmp/
                    }
                }
                $stagedPath = $stagedSig = $stagedFilename = $phase = '';
                $stagedSize = $stagedDestId = 0;
                $err = 'Stage failed: ' . $e->getMessage();
            }
        }

        // ----- Step 2: dry-run (requires phase=staged token) -----
        if ($step === 'dryrun') {
            $stagedPath = to_str($_POST['staged_path'] ?? '');
            $stagedSig = to_str($_POST['staged_sig'] ?? '');
            $stagedFilename = to_str($_POST['staged_filename'] ?? '');
            $stagedSize = to_int($_POST['staged_size'] ?? 0);
            $stagedDestId = to_int($_POST['staged_destination_id'] ?? 0);
            $meta = [
                'filename' => $stagedFilename,
                'destination_id' => $stagedDestId,
                'size' => $stagedSize,
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
                $err = 'Invalid or expired staged file token. Please restart the wizard.';
                $stagedPath = $stagedSig = $stagedFilename = $phase = '';
                $stagedSize = $stagedDestId = 0;
            } else {
                try {
                    $dryRunResult = ipam_restore_dry_run($db, $verified);
                    audit($db, 'db.restore_dryrun', 'system', null,
                          "file=$stagedFilename tables=" . count($dryRunResult['tables']));
                    // Issue a fresh phase=dryrun_passed token. Apply form below
                    // submits this — the original phase=staged token is no
                    // longer accepted.
                    $stagedSig = ipam_restore_wizard_sign($config, RESTORE_WIZARD_PHASE_DRYRUN_OK, $verified, $meta);
                    $stagedPath = $verified;
                    $phase = RESTORE_WIZARD_PHASE_DRYRUN_OK;
                } catch (Throwable $e) {
                    error_log('[restore_web] dry run failed: ' . $e->getMessage());
                    audit($db, 'db.restore_dryrun_failed', 'system', null, "file=$stagedFilename error=" . substr($e->getMessage(), 0, 200));
                    $err = 'Dry run failed: ' . $e->getMessage();
                    // No dryrun_passed token issued — user is stuck on Step 2
                    // with the phase=staged token. Re-render with the staged
                    // token so they can retry without re-staging.
                    $stagedSig = ipam_restore_wizard_sign($config, RESTORE_WIZARD_PHASE_STAGED, $verified, $meta);
                    $stagedPath = $verified;
                    $phase = RESTORE_WIZARD_PHASE_STAGED;
                }
            }
        }

        // ----- Step 3: apply (requires phase=dryrun_passed token) -----
        if ($step === 'apply') {
            $stagedPath = to_str($_POST['staged_path'] ?? '');
            $stagedSig = to_str($_POST['staged_sig'] ?? '');
            $stagedFilename = to_str($_POST['staged_filename'] ?? '');
            $stagedSize = to_int($_POST['staged_size'] ?? 0);
            $stagedDestId = to_int($_POST['staged_destination_id'] ?? 0);
            $confirm = to_str($_POST['confirm'] ?? '');
            $meta = [
                'filename' => $stagedFilename,
                'destination_id' => $stagedDestId,
                'size' => $stagedSize,
            ];
            if ($confirm !== 'RESTORE') {
                $err = 'Confirmation text must be "RESTORE" exactly.';
                // Keep dryrun_passed phase so the confirm view re-renders.
                $phase = RESTORE_WIZARD_PHASE_DRYRUN_OK;
                // Dry-run result is not re-computed; provide a minimal stub
                // so the confirm view still renders. The view only needs
                // staged_* hidden fields, which we already have.
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
                    // B-P2-6: covers both tampering and step-skip (caller
                    // submitted a phase=staged token to step=apply).
                    audit($db, 'db.restore_failed', 'system', null, "reason=invalid_or_unauthorised_token file=$stagedFilename");
                    $err = 'Invalid token, or dry-run was not completed. Please restart the wizard.';
                    $stagedPath = $stagedSig = $stagedFilename = $phase = '';
                    $stagedSize = $stagedDestId = 0;
                } else {
                    // B-P2-62: long restores must not die at the default PHP
                    // max_execution_time. Disable for the apply path only.
                    @set_time_limit(0);
                    try {
                        $result = ipam_restore_apply($db, $verified, $stagedFilename, $stagedDestId > 0 ? $stagedDestId : null);
                        $cleanupReal = realpath($verified);
                        if ($cleanupReal !== false && is_file($cleanupReal)) {
                            @unlink($cleanupReal); // nosemgrep: php.lang.security.unlink-use.unlink-use -- realpath() under data/tmp/
                        }
                        // B-P2-50: the restored DB may carry different
                        // user IDs / password hashes / MFA enrolments;
                        // tear down the session and force re-login.
                        ipam_restore_wizard_invalidate_session();
                        // Fixed-target redirect to login.php with a static
                        // sentinel — the user must re-authenticate before
                        // seeing details, and the new session has no
                        // continuity with the running one anyway.
                        header('Location: login.php?restored=1');
                        exit;
                    } catch (Throwable $e) {
                        error_log('[restore_web] apply failed: ' . $e->getMessage());
                        audit($db, 'db.restore_failed', 'system', null, "file=$stagedFilename error=" . substr($e->getMessage(), 0, 200));
                        $err = 'Apply failed: ' . $e->getMessage();
                        // Bump the user back to Step 1 — the dryrun_passed
                        // token is consumed by the failure regardless.
                        $stagedPath = $stagedSig = $stagedFilename = $phase = '';
                        $stagedSize = $stagedDestId = 0;
                    }
                }
            }
        }
    }
}

// For step 1 (select source), enumerate destinations
$destStmt = $db->query("SELECT id, name, type FROM backup_destinations WHERE is_active = 1 ORDER BY name");
$destinations = $destStmt !== false ? $destStmt->fetchAll(PDO::FETCH_ASSOC) : [];

page_header('Restore Database');
?>
<main class="container">
  <h1>Restore Database</h1>
  <p class="muted">Restore the database from a remote backup file. Includes dry-run preview before live apply.
     <a href="remote_backups.php">Browse remote files &rarr;</a></p>
  <?php if ($err !== ''): ?><div class="card danger"><?= e($err) ?></div><?php endif; ?>

  <?php if ($phase === ''): ?>
    <!-- Step 1: select source -->
    <section class="card">
      <h2>Step 1: choose backup file</h2>
      <p>Pick a destination, then enter the filename to stage it.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="step" value="stage">
        <label>Destination
          <select name="destination_id" required>
            <option value="">&#x2014; Select &#x2014;</option>
            <?php foreach ($destinations as $d): ?>
              <option value="<?= (int)$d['id'] ?>"><?= e(to_str($d['name'])) ?> (<?= e(to_str($d['type'])) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Filename
          <input type="text" name="name" required maxlength="200" placeholder="ipam-backup-20260428-123456.enc">
        </label>
        <button type="submit" class="action-pill">Stage backup</button>
      </form>
    </section>
  <?php elseif ($phase === RESTORE_WIZARD_PHASE_STAGED): ?>
    <!-- Step 2: dry-run preview -->
    <section class="card">
      <h2>Step 2: dry-run preview</h2>
      <p>Staged: <strong><?= htmlspecialchars($stagedFilename, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong> (<?= htmlspecialchars(number_format((int) $stagedSize), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> bytes)</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="step" value="dryrun">
        <input type="hidden" name="staged_path" value="<?= htmlspecialchars($stagedPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="staged_sig" value="<?= htmlspecialchars($stagedSig, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="staged_filename" value="<?= htmlspecialchars($stagedFilename, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="staged_size" value="<?= htmlspecialchars((string) $stagedSize, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="staged_destination_id" value="<?= htmlspecialchars((string) $stagedDestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <button type="submit" class="action-pill">Run dry-run</button>
      </form>
    </section>
  <?php else: /* RESTORE_WIZARD_PHASE_DRYRUN_OK */ ?>
    <!-- Step 3: review + confirm -->
    <?php if ($dryRunResult !== null) require __DIR__ . '/views/restore_dryrun_result.php'; ?>
    <?php require __DIR__ . '/views/restore_confirm_dialog.php'; ?>
  <?php endif; ?>
</main>
<?php page_footer();
