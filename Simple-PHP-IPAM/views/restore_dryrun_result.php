<?php
/** @var array{tables:list<array{name:string,current_rows:int,backup_rows:int,delta:int}>,schema_diff:list<string>,total_statements:int,warnings:list<string>} $dryRunResult */
?>
<section class="card">
  <h2>Dry-run results</h2>
  <p>The backup contains <strong><?= count($dryRunResult['tables']) ?></strong> tables and
     <strong><?= number_format($dryRunResult['total_statements']) ?></strong> INSERT statements.</p>

  <?php if (!empty($dryRunResult['warnings'])): ?>
    <div class="card warning">
      <strong>Warnings:</strong>
      <ul>
        <?php foreach ($dryRunResult['warnings'] as $w): ?>
          <li><?= e($w) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if (!empty($dryRunResult['schema_diff'])): ?>
    <div class="card warning">
      <strong>Schema differences:</strong>
      <ul>
        <?php foreach ($dryRunResult['schema_diff'] as $d): ?>
          <li><?= e($d) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <table class="data-table">
    <thead><tr><th>Table</th><th>Current rows</th><th>Backup rows</th><th>Delta</th></tr></thead>
    <tbody>
      <?php foreach ($dryRunResult['tables'] as $t): ?>
        <tr>
          <td><?= e($t['name']) ?></td>
          <td><?= number_format($t['current_rows']) ?></td>
          <td><?= number_format($t['backup_rows']) ?></td>
          <td class="<?= $t['delta'] < 0 ? 'danger' : ($t['delta'] > 0 ? 'success' : 'muted') ?>">
            <?= $t['delta'] >= 0 ? '+' : '' ?><?= number_format($t['delta']) ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
