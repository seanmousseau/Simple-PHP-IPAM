<?php
// Props: $label (string), $value (string|int|float), $sub (string), $status ('ok'|'warn'|'crit')
?>
<div class="kpi-card kpi-card--<?= e($status ?? 'ok') ?>">
    <div class="kpi-label"><?= e($label) ?></div>
    <div class="kpi-value"><?= e((string)$value) ?></div>
    <?php if (!empty($sub)): ?>
    <div class="kpi-sub"><?= e($sub) ?></div>
    <?php endif; ?>
</div>
