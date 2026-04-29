<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var array<string, mixed> $config */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';
$flash = '';

// Phase data carried between steps via signed tokens
$stagedPath = '';
$stagedSig = '';
$stagedFilename = '';
$stagedSize = 0;
$stagedDestId = 0;
$dryRunResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (demo_mode_enabled()) {
        $err = 'Restore is disabled in demo mode.';
    } else {
        $step = to_str($_POST['step'] ?? '');

        if ($step === 'stage') {
            $destId = to_int($_POST['destination_id'] ?? 0);
            $name   = to_str($_POST['name'] ?? '');
            $staged = null;
            try {
                $staged = ipam_restore_prepare_for_restore($db, $config, $destId, $name);
                $stagedSig = ipam_restore_sign($config, $staged['path'], [
                    'filename' => $staged['filename'],
                    'destination_id' => $destId,
                    'size' => $staged['size'],
                ]);
                $stagedPath = $staged['path'];
                $stagedFilename = $staged['filename'];
                $stagedSize = $staged['size'];
                $stagedDestId = $destId;
                audit($db, 'db.restore_stage', 'destination', $destId, "name=$name");
            } catch (Throwable $e) {
                error_log('[restore_web] stage failed: ' . $e->getMessage());
                audit($db, 'db.restore_stage_failed', 'destination', $destId, "name=$name error=" . substr($e->getMessage(), 0, 200));
                // If prepareForRestore() materialised the staged file before sign()
                // or audit() threw, clean it up so we don't accumulate orphaned
                // files in data/tmp/. The path is verified to be under data/tmp/
                // by prepareForRestore() itself, so realpath()-then-unlink is safe.
                if (is_array($staged)) {
                    $orphan = realpath($staged['path']);
                    $tmpReal = realpath(__DIR__ . '/data/tmp');
                    if ($orphan !== false && $tmpReal !== false
                        && str_starts_with($orphan . '/', rtrim($tmpReal, '/') . '/')
                        && is_file($orphan)) {
                        @unlink($orphan); // nosemgrep: php.lang.security.unlink-use.unlink-use -- realpath() under data/tmp/
                    }
                }
                // Reset all staged-* so the wizard renders Step 1 again.
                $stagedPath = '';
                $stagedSig = '';
                $stagedFilename = '';
                $stagedSize = 0;
                $stagedDestId = 0;
                $err = 'Stage failed: ' . $e->getMessage();
            }
        }

        if ($step === 'dryrun') {
            $stagedPath = to_str($_POST['staged_path'] ?? '');
            $stagedSig = to_str($_POST['staged_sig'] ?? '');
            $stagedFilename = to_str($_POST['staged_filename'] ?? '');
            $stagedSize = to_int($_POST['staged_size'] ?? 0);
            $stagedDestId = to_int($_POST['staged_destination_id'] ?? 0);
            $verified = ipam_restore_verify_signed($config, $stagedPath, $stagedSig, [
                'filename' => $stagedFilename,
                'destination_id' => $stagedDestId,
                'size' => $stagedSize,
            ]);
            if ($verified === null) {
                $err = 'Invalid or expired staged file token.';
            } else {
                try {
                    $dryRunResult = ipam_restore_dry_run($db, $verified);
                    audit($db, 'db.restore_dryrun', 'system', null,
                          "file=$stagedFilename tables=" . count($dryRunResult['tables']));
                } catch (Throwable $e) {
                    error_log('[restore_web] dry run failed: ' . $e->getMessage());
                    audit($db, 'db.restore_dryrun_failed', 'system', null, "file=$stagedFilename error=" . substr($e->getMessage(), 0, 200));
                    $err = 'Dry run failed: ' . $e->getMessage();
                }
            }
        }

        if ($step === 'apply') {
            $stagedPath = to_str($_POST['staged_path'] ?? '');
            $stagedSig = to_str($_POST['staged_sig'] ?? '');
            $stagedFilename = to_str($_POST['staged_filename'] ?? '');
            $stagedSize = to_int($_POST['staged_size'] ?? 0);
            $stagedDestId = to_int($_POST['staged_destination_id'] ?? 0);
            $confirm = to_str($_POST['confirm'] ?? '');
            if ($confirm !== 'RESTORE') {
                $err = 'Confirmation text must be "RESTORE" exactly.';
            } else {
                $verified = ipam_restore_verify_signed($config, $stagedPath, $stagedSig, [
                    'filename' => $stagedFilename,
                    'destination_id' => $stagedDestId,
                    'size' => $stagedSize,
                ]);
                if ($verified === null) {
                    $err = 'Invalid or expired staged file token.';
                } else {
                    try {
                        $result = ipam_restore_apply($db, $verified, $stagedFilename, $stagedDestId > 0 ? $stagedDestId : null);
                        // ipam_restore_apply() already emits the success db.restore audit.
                        // Avoid double-writing here.
                        // Cleanup staged file after successful apply.
                        // Re-resolve via realpath() at the call site (project semgrep sanitizer pattern).
                        $cleanupReal = realpath($verified);
                        if ($cleanupReal !== false && is_file($cleanupReal)) {
                            @unlink($cleanupReal);
                        }
                        $flash = sprintf(
                            'Restore applied: %d tables, %d statements.',
                            $result['tables_restored'], $result['statements']
                        );
                        $stagedPath = $stagedSig = $stagedFilename = '';
                        $stagedSize = 0;
                        $dryRunResult = null;
                    } catch (Throwable $e) {
                        error_log('[restore_web] apply failed: ' . $e->getMessage());
                        audit($db, 'db.restore_failed', 'system', null, "file=$stagedFilename error=" . substr($e->getMessage(), 0, 200));
                        $err = 'Apply failed: ' . $e->getMessage();
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
  <?php if ($flash !== ''): ?><div class="card success"><?= e($flash) ?></div><?php endif; ?>

  <?php if ($stagedPath === ''): ?>
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
  <?php elseif ($dryRunResult === null): ?>
    <!-- Step 2: dry-run preview -->
    <section class="card">
      <h2>Step 2: dry-run preview</h2>
      <p>Staged: <strong><?= e($stagedFilename) ?></strong> (<?= number_format($stagedSize) ?> bytes)</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="step" value="dryrun">
        <input type="hidden" name="staged_path" value="<?= e($stagedPath) ?>">
        <input type="hidden" name="staged_sig" value="<?= e($stagedSig) ?>">
        <input type="hidden" name="staged_filename" value="<?= e($stagedFilename) ?>">
        <input type="hidden" name="staged_size" value="<?= e((string) $stagedSize) ?>">
        <input type="hidden" name="staged_destination_id" value="<?= e((string) $stagedDestId) ?>">
        <button type="submit" class="action-pill">Run dry-run</button>
      </form>
    </section>
  <?php else: ?>
    <!-- Step 3: review + confirm -->
    <?php require __DIR__ . '/views/restore_dryrun_result.php'; ?>
    <?php require __DIR__ . '/views/restore_confirm_dialog.php'; ?>
  <?php endif; ?>
</main>
<?php page_footer();
