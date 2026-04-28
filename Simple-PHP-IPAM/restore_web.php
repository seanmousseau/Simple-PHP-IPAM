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
$dryRunResult = null;

$engine = new RestoreEngine($db, $config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (demo_mode_enabled()) {
        $err = 'Restore is disabled in demo mode.';
    } else {
        $step = to_str($_POST['step'] ?? '');

        if ($step === 'stage') {
            $destId = to_int($_POST['destination_id'] ?? 0);
            $name   = to_str($_POST['name'] ?? '');
            try {
                $staged = $engine->prepareForRestore($destId, $name);
                $stagedPath = $staged['path'];
                $stagedSig = $engine->sign($stagedPath);
                $stagedFilename = $staged['filename'];
                $stagedSize = $staged['size'];
                audit($db, 'db.restore_stage', 'destination', $destId, "name=$name");
            } catch (Throwable $e) {
                $err = 'Stage failed: ' . $e->getMessage();
            }
        }

        if ($step === 'dryrun') {
            $stagedPath = to_str($_POST['staged_path'] ?? '');
            $stagedSig = to_str($_POST['staged_sig'] ?? '');
            $stagedFilename = to_str($_POST['staged_filename'] ?? '');
            $stagedSize = to_int($_POST['staged_size'] ?? 0);
            $verified = $engine->verifySigned($stagedPath, $stagedSig);
            if ($verified === null) {
                $err = 'Invalid or expired staged file token.';
            } else {
                try {
                    $dryRunResult = $engine->dryRun($verified);
                    audit($db, 'db.restore_dryrun', 'system', null,
                          "file=$stagedFilename tables=" . count($dryRunResult['tables']));
                } catch (Throwable $e) {
                    $err = 'Dry run failed: ' . $e->getMessage();
                }
            }
        }

        if ($step === 'apply') {
            $stagedPath = to_str($_POST['staged_path'] ?? '');
            $stagedSig = to_str($_POST['staged_sig'] ?? '');
            $stagedFilename = to_str($_POST['staged_filename'] ?? '');
            $stagedSize = to_int($_POST['staged_size'] ?? 0);
            $confirm = to_str($_POST['confirm'] ?? '');
            if ($confirm !== 'RESTORE') {
                $err = 'Confirmation text must be "RESTORE" exactly.';
            } else {
                $verified = $engine->verifySigned($stagedPath, $stagedSig);
                if ($verified === null) {
                    $err = 'Invalid or expired staged file token.';
                } else {
                    try {
                        $result = $engine->apply($verified);
                        audit($db, 'db.restore', 'system', null,
                              "file=$stagedFilename tables=" . $result['tables_restored']
                              . " statements=" . $result['statements']);
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
        <input type="hidden" name="staged_size" value="<?= $stagedSize ?>">
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
