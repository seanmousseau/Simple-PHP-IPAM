<?php
/** @var string $stagedPath */
/** @var string $stagedSig */
/** @var string $stagedFilename */
/** @var int $stagedSize */
?>
<section class="card restore-confirm">
  <h2>Step 3: live restore</h2>
  <div class="card danger" style="border-left: 4px solid var(--danger); padding: var(--space-8);">
    <strong>&#x26A0; This action cannot be undone.</strong>
    <p>Applying this backup will <strong>overwrite all current data</strong> in the database.
       Type the word <code>RESTORE</code> below to enable the Apply button.</p>
  </div>
  <form method="post" id="restore-apply-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="step" value="apply">
    <input type="hidden" name="staged_path" value="<?= e($stagedPath) ?>">
    <input type="hidden" name="staged_sig" value="<?= e($stagedSig) ?>">
    <input type="hidden" name="staged_filename" value="<?= e($stagedFilename) ?>">
    <input type="hidden" name="staged_size" value="<?= $stagedSize ?>">
    <label>Confirmation text
      <input type="text" name="confirm" id="restore-confirm-input" autocomplete="off"
             placeholder="Type RESTORE to enable" required>
    </label>
    <button type="submit" id="restore-apply-button" class="action-pill button-danger" disabled>
      Apply restore
    </button>
    <a class="action-pill" href="restore_web.php">Cancel</a>
  </form>
</section>
