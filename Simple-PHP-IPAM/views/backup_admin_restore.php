<?php
declare(strict_types=1);

/**
 * Restore wizard render block. Shared by restore_web.php (legacy) and
 * backup_admin.php?tab=restore (unified surface, v3.21.0 Wave 4).
 *
 * Three phases driven by the phase-locked HMAC tokens from
 * lib/restore_wizard.php (Wave 3 #807). The host page wraps in <main>+<h1>.
 *
 * v3.23.0 #1077: Step 1 is now a destination-driven backup browser.
 * Selecting a destination from the picker reloads the page with ?dest=N
 * which the controller uses to call BackupClientInterface::listObjects()
 * and join with backup_runs. The free-text filename input is preserved
 * under an Advanced disclosure for the rare case where LIST is slow or
 * the operator already knows the name.
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
 * @var int                                                                                                                                 $browseDestId
 * @var list<array{name:string,size:int,last_modified:string,is_encrypted:bool,backup_type:string,checksum:string,run_id:int}>             $browseEntries
 * @var string                                                                                                                              $browseError
 * @var string                                                                                                                              $browseDegradedDb
 */
?>
  <?php if ($err !== ''): ?><div class="card danger"><?= e($err) ?></div><?php endif; ?>

  <?php if ($phase === ''): ?>
    <section class="card">
      <h2>Step 1: choose a backup</h2>

      <!-- Destination picker — submit reloads with ?dest=N so the
           controller can call listObjects() against that destination. -->
      <form method="get" action="backup_admin.php">
        <input type="hidden" name="tab" value="restore">
        <label>Destination
          <select name="dest" onchange="this.form.submit()">
            <option value="">&#x2014; Select &#x2014;</option>
            <?php foreach ($destinations as $d): ?>
              <?php $dId = to_int($d['id']); ?>
              <option value="<?= $dId ?>" <?= $browseDestId === $dId ? 'selected' : '' ?>>
                <?= e(to_str($d['name'])) ?> (<?= e(to_str($d['type'])) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <noscript><button type="submit" class="action-pill">Browse</button></noscript>
      </form>

      <?php if ($browseDestId > 0): ?>
        <?php if ($browseError !== ''): ?>
          <div class="warning settings-warning"><?= e($browseError) ?></div>
        <?php elseif ($browseEntries === []): ?>
          <p class="muted">
            No backups in this destination yet &mdash; kick off a Run-now from the
            <a href="backup_admin.php?tab=backup">Backup tab</a>.
          </p>
        <?php else: ?>
          <table class="data-table" style="margin-top:1rem;">
            <thead>
              <tr>
                <th scope="col">Filename</th>
                <th scope="col">Size</th>
                <th scope="col">Date</th>
                <th scope="col">Encryption</th>
                <th scope="col">Type</th>
                <th scope="col">Checksum</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($browseEntries as $row):
                  // Only gate Restore on rows EXPLICITLY recorded as
                  // database-type. 'unknown' (no backup_runs row) lets the
                  // dispatcher in ipam_restore_apply() sniff the magic
                  // bytes at stage time — IPAMBKL1 files copied in or
                  // surviving history pruning are still restorable.
                  $isDb = $row['backup_type'] === 'database';
                  $degraded = $isDb && $browseDegradedDb !== '';
                  $checksum = $row['checksum'];
                  $checksumShort = $checksum !== '' ? substr($checksum, 0, 12) . '&hellip;' : '&mdash;';
              ?>
                <tr>
                  <td><code><?= e($row['name']) ?></code></td>
                  <td><?= e(number_format($row['size'])) ?></td>
                  <td><?= e($row['last_modified']) ?></td>
                  <td>
                    <?php if ($row['is_encrypted']): ?>
                      <span class="badge badge-success" title="Encrypted with app_secret-derived AES-256-GCM key">IPAMBKP1+</span>
                    <?php else: ?>
                      <span class="badge">plaintext</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($row['backup_type'] === 'logical'): ?>
                      <span class="badge badge-success">Logical</span>
                    <?php elseif ($row['backup_type'] === 'database'): ?>
                      <span class="badge">Database</span>
                    <?php else: ?>
                      <span class="badge muted" title="No backup_runs row for this object — type will be sniffed at stage time">Unknown</span>
                    <?php endif; ?>
                  </td>
                  <td><span title="<?= e($checksum) ?>"><?= $checksumShort ?></span></td>
                  <td>
                    <!-- Download — POSTs to existing endpoint -->
                    <form method="post" action="download_remote_backup.php" style="display:inline;">
                      <input type="hidden" name="csrf"           value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="destination_id" value="<?= $browseDestId ?>">
                      <input type="hidden" name="name"           value="<?= e($row['name']) ?>">
                      <button type="submit" class="action-pill">Download</button>
                    </form>

                    <?php if ($row['run_id'] > 0): ?>
                      <a class="action-pill" href="backup_run_detail.php?id=<?= $row['run_id'] ?>">Verify / Delete</a>
                    <?php endif; ?>

                    <!-- Restore — pre-fills the existing stage form -->
                    <?php if ($degraded): ?>
                      <button type="button" class="action-pill" disabled
                              title="<?= e($browseDegradedDb) ?>">
                        Restore (unsupported)
                      </button>
                    <?php else: ?>
                      <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf"           value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="step"           value="stage">
                        <input type="hidden" name="destination_id" value="<?= $browseDestId ?>">
                        <input type="hidden" name="name"           value="<?= e($row['name']) ?>">
                        <button type="submit" class="action-pill">Restore</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php if ($degraded): ?>
                  <tr><td colspan="7" class="muted" style="padding-left:1.2rem;border-top:none;">
                    <?= e($browseDegradedDb) ?>
                  </td></tr>
                <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      <?php endif; ?>

      <!-- Advanced fallback: free-text filename entry. Useful when LIST is
           slow (huge S3 buckets) or the operator knows the exact name. -->
      <details style="margin-top:1.25rem;">
        <summary>Advanced &mdash; stage by filename</summary>
        <form method="post" style="margin-top:.75rem;">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="step" value="stage">
          <label>Destination
            <select name="destination_id" required>
              <option value="">&#x2014; Select &#x2014;</option>
              <?php foreach ($destinations as $d): ?>
                <?php $dId = to_int($d['id']); ?>
                <option value="<?= $dId ?>" <?= $browseDestId === $dId ? 'selected' : '' ?>>
                  <?= e(to_str($d['name'])) ?> (<?= e(to_str($d['type'])) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Filename
            <input type="text" name="name" required maxlength="200"
                   placeholder="ipam-backup-20260428-123456.enc">
          </label>
          <button type="submit" class="action-pill">Stage backup</button>
        </form>
      </details>
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
