<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$definitions = ipam_setting_definitions();
$groups      = ipam_setting_groups();

/** @var array<string, string> $fieldErrors */
$fieldErrors = [];
/** @var array<string, string> $formOverrides */
$formOverrides = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (demo_mode_enabled()) {
        flash_set('Settings cannot be edited in demo mode.', 'warning');
        header('Location: settings.php');
        exit;
    }

    $postedGroup = to_str($_POST['group'] ?? '');
    if ($postedGroup === '' || !isset($groups[$postedGroup])) {
        flash_set('Unknown settings group.', 'danger');
        header('Location: settings.php');
        exit;
    }

    $user   = current_user();
    $userId = to_int($user['id'] ?? 0) ?: null;

    // Phase 1 — validate every field in the posted group into a pending map.
    // Nothing is written until the whole group validates cleanly; that way a
    // late validation error cannot leave earlier fields persisted and audited
    // while the page re-renders with an error banner.
    /** @var array<string, mixed> $pending */
    $pending = [];

    foreach ($definitions as $key => $def) {
        if (($def['group'] ?? '') !== $postedGroup) continue;

        $fieldName = 'k_' . str_replace('.', '__', $key);
        $type      = is_string($def['type'] ?? null) ? $def['type'] : 'string';
        $current   = ipam_setting($key);

        if ($type === 'bool') {
            $newValue = isset($_POST[$fieldName]);
        } elseif ($type === 'int') {
            $raw = trim(to_str($_POST[$fieldName] ?? ''));
            if ($raw !== '' && !preg_match('/^-?\d+$/', $raw)) {
                $fieldErrors[$key]   = 'Must be an integer.';
                $formOverrides[$key] = $raw;
                continue;
            }
            $newValue = $raw === '' ? 0 : (int)$raw;
        } elseif ($type === 'json') {
            $raw = to_str($_POST[$fieldName] ?? '');
            if (trim($raw) === '') {
                $newValue = null;
            } else {
                $decoded = json_decode($raw, true);
                if ($decoded === null && strtolower(trim($raw)) !== 'null') {
                    $fieldErrors[$key]   = 'Invalid JSON.';
                    $formOverrides[$key] = $raw;
                    continue;
                }
                $newValue = $decoded;
            }
        } else {
            $newValue = to_str($_POST[$fieldName] ?? '');
        }

        // Skip unchanged values to avoid audit log noise.
        if ($current === $newValue) continue;
        // Loose equality for int/bool so 0/'' or false/null don't churn.
        if ($type === 'int' && to_int($current) === to_int($newValue)) continue;
        if ($type === 'bool' && (bool)$current === (bool)$newValue) continue;

        $pending[$key] = $newValue;
    }

    // Phase 2 — only persist when the whole group validated. A failure inside
    // the transaction rolls the whole batch back so we never audit or leave
    // a half-applied group.
    $changed = 0;
    if (!$fieldErrors && $pending) {
        $db->beginTransaction();
        try {
            foreach ($pending as $key => $newValue) {
                ipam_setting_set($db, $key, $newValue, $userId);
                $changed++;
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $fieldErrors['_group'] = 'Save failed: ' . $e->getMessage();
            $changed = 0;
        }
    }

    if (!$fieldErrors) {
        $label = to_str($groups[$postedGroup]['label'] ?? $postedGroup);
        flash_set($changed > 0
            ? "Updated {$changed} setting(s) in {$label}."
            : "No changes to save in {$label}.");
        header('Location: settings.php#group-' . rawurlencode($postedGroup));
        exit;
    }
    // Fall through to re-render with errors.
}

page_header('Settings');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="#">Admin</a><span class="sep">›</span>
  <span>Settings</span>
</div>

<div class="toolbar">
  <div>
    <h1>Settings</h1>
    <div class="muted">
      Configure the application from the database. In v2.6.0 <code>config.php</code> still
      works as a fallback for any key you haven't saved yet; in v3.0.0 the fallback is removed.
    </div>
  </div>
</div>

<?php $flash = flash_get(); if ($flash): ?>
  <p class="<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></p>
<?php endif; ?>
<?php if (!empty($fieldErrors['_group'])): ?>
  <p class="danger"><?= e($fieldErrors['_group']) ?></p>
<?php endif; ?>

<?php foreach ($groups as $groupKey => $groupMeta): ?>
  <?php
  $groupDefs = array_filter($definitions, fn($d) => ($d['group'] ?? '') === $groupKey);
  if (!$groupDefs) continue;
  ?>
  <?php $groupLabel = to_str($groupMeta['label'] ?? $groupKey); ?>
  <div class="card" id="group-<?= e($groupKey) ?>">
    <h2><?= e($groupLabel) ?></h2>
    <div class="muted"><?= e(to_str($groupMeta['description'] ?? '')) ?></div>

    <form method="post" action="settings.php">
      <input type="hidden" name="csrf"  value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="group" value="<?= e($groupKey) ?>">

      <?php foreach ($groupDefs as $key => $def):
          $type      = to_str($def['type'] ?? 'string');
          $label     = to_str($def['label'] ?? $key);
          $help      = to_str($def['description'] ?? '');
          $sensitive = !empty($def['sensitive']);
          $fieldName = 'k_' . str_replace('.', '__', $key);
          $source    = ipam_setting_source($db, $key);
          $current   = ipam_setting($key);
          $shown     = $formOverrides[$key] ?? null;
          $err       = $fieldErrors[$key] ?? null;

          $badge = match ($source) {
              'db'      => ['text' => '🟢 Database', 'cls' => 'success'],
              'config'  => ['text' => '🟡 config.php',  'cls' => 'warning'],
              default   => ['text' => '⚪ Default',   'cls' => 'muted'],
          };
      ?>
        <div class="row" style="align-items:flex-start;margin-top:0.75rem;">
          <div class="flex-1">
            <label>
              <strong><?= e($label) ?></strong>
              <span class="badge badge-<?= e($badge['cls']) ?>" style="margin-left:0.5rem;"><?= e($badge['text']) ?></span>
              <code class="muted" style="margin-left:0.5rem;"><?= e($key) ?></code>
              <br>
              <?php if ($type === 'bool'): ?>
                <input type="checkbox" name="<?= e($fieldName) ?>" value="1"<?= $current ? ' checked' : '' ?>>
              <?php elseif ($type === 'int'): ?>
                <input type="number" name="<?= e($fieldName) ?>"
                       value="<?= e($shown !== null ? $shown : (string)to_int($current)) ?>"
                       class="mw-240">
              <?php elseif ($type === 'json'): ?>
                <textarea name="<?= e($fieldName) ?>" rows="4" class="w-full"><?php
                    if ($shown !== null) { echo e($shown); }
                    else { $j = json_encode($current, JSON_PRETTY_PRINT); echo e(is_string($j) ? $j : ''); }
                ?></textarea>
              <?php elseif ($sensitive): ?>
                <input type="password" name="<?= e($fieldName) ?>" id="pw-<?= e($fieldName) ?>"
                       value="<?= e($shown !== null ? $shown : (is_scalar($current) ? (string)$current : '')) ?>"
                       class="w-full" autocomplete="new-password">
                <label class="muted" style="font-weight:normal;">
                  <input type="checkbox" data-password-toggle="pw-<?= e($fieldName) ?>"> show
                </label>
              <?php else: ?>
                <input type="text" name="<?= e($fieldName) ?>"
                       value="<?= e($shown !== null ? $shown : (is_scalar($current) ? (string)$current : '')) ?>"
                       class="w-full">
              <?php endif; ?>
            </label>
            <?php if ($help): ?><div class="muted"><?= e($help) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="danger"><?= e($err) ?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="row" style="margin-top:1rem;">
        <button type="submit" class="button-primary">Save <?= e($groupLabel) ?></button>
      </div>
    </form>
  </div>
<?php endforeach; ?>

<?php page_footer(); ?>
