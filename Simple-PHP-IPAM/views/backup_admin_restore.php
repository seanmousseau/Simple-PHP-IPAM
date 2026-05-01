<?php
declare(strict_types=1);

/**
 * Restore wizard render block. Shared by restore_web.php (legacy) and
 * backup_admin.php?tab=restore (unified surface, v3.21.0 Wave 4).
 *
 * Three phases driven by the phase-locked HMAC tokens from
 * lib/restore_wizard.php (Wave 3 #807). The host page wraps in <main>+<h1>.
 *
 * @var string                                                                                                                              $err
 * @var string                                                                                                                              $phase
 * @var string                                                                                                                              $stagedPath
 * @var string                                                                                                                              $stagedSig
 * @var string                                                                                                                              $stagedFilename
 * @var int                                                                                                                                 $stagedSize
 * @var int                                                                                                                                 $stagedDestId
 * @var array{tables:list<mixed>, schema_diff:list<mixed>, total_statements:int, warnings:list<mixed>}|null                                 $dryRunResult
 * @var list<array<string, mixed>>                                                                                                          $destinations
 */
?>
  <?php if ($err !== ''): ?><div class="card danger"><?= e($err) ?></div><?php endif; ?>

  <?php if ($phase === ''): ?>
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
              <?php $dId = to_int($d['id']); ?>
              <option value="<?= $dId ?>"><?= e(to_str($d['name'])) ?> (<?= e(to_str($d['type'])) ?>)</option>
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
    <section class="card">
      <h2>Step 2: dry-run preview</h2>
      <p>Staged: <strong><?= e($stagedFilename) ?></strong> (<?= e(number_format($stagedSize)) ?> bytes)</p>
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
  <?php else: /* RESTORE_WIZARD_PHASE_DRYRUN_OK */ ?>
    <?php if ($dryRunResult !== null) require __DIR__ . '/restore_dryrun_result.php'; ?>
    <?php require __DIR__ . '/restore_confirm_dialog.php'; ?>
  <?php endif; ?>
