<?php
/**
 * Renders one settings group as a card with an inline POST form.
 *
 * v3.16.0 (#749): extracted from settings.php so the new vertical-tab layout
 * can include the same group form from any tab view without duplicating the
 * field-rendering loop. The POST handler in settings.php is unchanged — this
 * partial only emits the form, the handler still keys off $_POST['group'].
 *
 * Required props (all populated by settings.php controller):
 *
 * @var \PDO                                       $db
 * @var string                                     $groupKey
 * @var array<string, mixed>                       $groupMeta
 * @var array<string, array<string, mixed>>        $definitions
 * @var array<string, string>                      $fieldErrors
 * @var array<string, string>                      $formOverrides
 */
declare(strict_types=1);

/** @var array<string, array<string, mixed>> $groupDefs */
$groupDefs = array_filter($definitions, fn($d) => ($d['group'] ?? '') === $groupKey);
if (!$groupDefs) return;

$groupLabel = to_str($groupMeta['label'] ?? $groupKey);
?>
<div class="card" id="group-<?= e($groupKey) ?>">
  <h3 class="settings-group-title"><?= e($groupLabel) ?></h3>
  <?php if (!empty($groupMeta['description'])): ?>
    <div class="muted"><?= e(to_str($groupMeta['description'])) ?></div>
  <?php endif; ?>

  <?php if ($groupKey === 'backup'): ?>
  <div class="warning settings-warning" role="status">
    <strong>This section is deprecated and will be removed in v3.26.0.</strong>
    The unified <a href="backup_admin.php">Backup &amp; Restore</a> admin surface replaces it &mdash;
    create destinations, schedules, and notification preferences there. The legacy keys below
    remain readable as a fallback for one release; new installs should not edit them.
  </div>
  <?php endif; ?>

  <?php if ($groupKey === 'mfa' && !(bool)to_int(ipam_setting('smtp.enabled', false))): ?>
  <div class="warning settings-warning">
    <strong>SMTP is not configured.</strong>
    Email OTP requires a working SMTP connection to deliver codes.
    Configure SMTP under <a href="settings.php?tab=notifications#group-smtp">Email Delivery</a> before enabling Email OTP.
  </div>
  <?php endif; ?>

  <?php
  // app_secret missing warning — only relevant for the MFA group.
  $_globalCfg = is_array($GLOBALS['config'] ?? null) ? $GLOBALS['config'] : [];
  $_appSecret = trim(to_str($_globalCfg['app_secret'] ?? ''));
  if ($groupKey === 'mfa'
      && (bool)to_int(ipam_setting('mfa.totp_enabled', true))
      && $_appSecret === ''): ?>
  <div class="warning settings-warning">
    <strong><code>app_secret</code> is not set in <code>config.php</code>.</strong>
    TOTP enrolment requires <code>app_secret</code> to encrypt user secrets at rest.
    Set a strong random value (e.g. <code>bin2hex(random_bytes(32))</code>) in <code>config.php</code>
    before users attempt to enroll.
  </div>
  <?php endif; unset($_globalCfg, $_appSecret); ?>

  <?php
  // Per-toggle save (#756): each boolean gets a hidden shadow <form> rendered
  // OUTSIDE the group form (forms cannot nest). app.js auto-submits the shadow
  // form on checkbox change with {key, value} for the per-key settings.php
  // path. The bool checkbox itself stays inside the group form with its
  // legacy `name="k_..."` attribute, so the group "Save" button continues to
  // function and existing tests keep finding the input by its established
  // selector. Net effect: clicking a bool flips just that bool (no sibling
  // cascade); clicking "Save Group" still fires the legacy group POST.
  /** @var array<string, array<string, mixed>> $boolDefs */
  $boolDefs = array_filter($groupDefs, fn($d) => ($d['type'] ?? 'string') === 'bool' && empty($d['deprecated']));
  foreach ($boolDefs as $bk => $bdef):
      $bFieldName = 'k_' . str_replace('.', '__', $bk);
  ?>
  <form method="post" action="settings.php" class="setting-toggle-form"
        id="toggle-<?= e($bFieldName) ?>" data-setting-toggle="<?= e($bk) ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="key"  value="<?= e($bk) ?>">
    <!-- value=... appended by app.js based on the bound checkbox state -->
  </form>
  <?php endforeach; ?>

  <form method="post" action="settings.php">
    <input type="hidden" name="csrf"  value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="group" value="<?= e($groupKey) ?>">

    <?php foreach ($groupDefs as $key => $def):
        if (!empty($def['deprecated'])) continue;
        $type      = to_str($def['type'] ?? 'string');
        $label     = to_str($def['label'] ?? $key);
        $help      = to_str($def['description'] ?? '');
        $sensitive = !empty($def['sensitive']);
        $fieldName = 'k_' . str_replace('.', '__', $key);
        $source    = ipam_setting_source($db, $key);
        $current   = ipam_setting($key);
        $shown     = $formOverrides[$key] ?? null;
        $err       = $fieldErrors[$key] ?? null;

        // Contract: ipam_setting_source() returns exactly one of 'db' | 'default'
        // (lib.php:2270). The match below is exhaustive — 'db' wins, default wins.
        $badge = match ($source) {
            'db'    => ['text' => '🟢 Database', 'cls' => 'success'],
            default => ['text' => '⚪ Default',   'cls' => 'muted'],
        };

        $inputId = 'f-' . $fieldName;
        $options = ($type === 'string') ? ipam_setting_options($def) : null;
        $badgeHtml =
            '<span class="badge badge-' . e($badge['cls']) . ' settings-row__badge">' . e($badge['text']) . '</span>'
          . '<code class="muted settings-row__key">' . e($key) . '</code>';
    ?>
      <div class="setting-row row settings-row__sub">
        <div class="flex-1">
          <?php if ($type === 'bool'):
              $boolChecked = $shown !== null ? $shown === '1' : (bool)$current;
          ?>
            <label for="<?= e($inputId) ?>" class="setting-head setting-head--bool">
              <input type="checkbox" id="<?= e($inputId) ?>" name="<?= e($fieldName) ?>" value="1"<?= $boolChecked ? ' checked' : '' ?>
                     data-setting-toggle-target="<?= e($key) ?>">
              <strong><?= e($label) ?></strong>
              <?= $badgeHtml ?>
            </label>
          <?php else: ?>
            <div class="setting-head">
              <label for="<?= e($inputId) ?>"><strong><?= e($label) ?></strong></label>
              <?= $badgeHtml ?>
            </div>
            <?php if ($type === 'int'):
                $minAttr = array_key_exists('min', $def) ? ' min="' . e((string)to_int($def['min'])) . '"' : '';
                $maxAttr = array_key_exists('max', $def) ? ' max="' . e((string)to_int($def['max'])) . '"' : '';
            ?>
              <input type="number" id="<?= e($inputId) ?>" name="<?= e($fieldName) ?>"
                     value="<?= e($shown !== null ? $shown : (string)to_int($current)) ?>"<?= $minAttr . $maxAttr ?>
                     class="mw-240">
            <?php elseif ($key === 'alert.recipient_user_ids'):
                $allUsers = $db->query(
                    "SELECT id, username, name, email FROM users
                     WHERE email IS NOT NULL AND email != '' AND is_active = 1
                     ORDER BY username"
                );
                $eligibleUsers = is_object($allUsers) ? $allUsers->fetchAll() : [];
                $selectedIds = [];
                if ($shown !== null) {
                    $decoded = json_decode($shown, true);
                    if (is_array($decoded)) {
                        $selectedIds = array_map(fn($v) => (int)to_str($v), $decoded);
                    }
                } elseif (is_array($current)) {
                    $selectedIds = array_map(fn($v) => (int)to_str($v), $current);
                }
                $selectedEmails = [];
            ?>
              <?php if ($eligibleUsers === []): ?>
                <div class="warning">
                  No active users have an email address set. Add an email to a user on
                  <a href="users.php">Users</a> to enable alert recipients.
                </div>
                <input type="hidden" name="<?= e($fieldName) ?>__select[]" value="">
              <?php else: ?>
                <input type="hidden" name="<?= e($fieldName) ?>__select[]" value="">
                <select multiple size="6" id="<?= e($inputId) ?>" name="<?= e($fieldName) ?>__select[]" class="w-full">
                  <?php foreach ($eligibleUsers as $u):
                      $uid   = to_int($u['id']);
                      $uname = to_str($u['username']);
                      $name  = to_str($u['name'] ?? '');
                      $em    = to_str($u['email'] ?? '');
                      $sel   = in_array($uid, $selectedIds, true);
                      if ($sel) $selectedEmails[] = $em;
                      $disp  = $name !== '' ? "{$uname} ({$name}) <{$em}>" : "{$uname} <{$em}>";
                  ?>
                    <option value="<?= e((string)$uid) ?>"<?= $sel ? ' selected' : '' ?>><?= e($disp) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="settings-multi-actions">
                  <button type="button" class="button-secondary settings-multi-clear" data-clear-select="<?= e($inputId) ?>">Clear all</button>
                  <span class="muted settings-row__inline-help">Cmd/Ctrl-click to toggle individual selections.</span>
                </div>
                <div class="muted settings-row__sub">
                  Emails will be sent to:
                  <?= $selectedEmails === [] ? '<em>(none)</em>' : e(implode(', ', $selectedEmails)) ?>
                </div>
              <?php endif; ?>
            <?php elseif ($type === 'json'): ?>
              <textarea id="<?= e($inputId) ?>" name="<?= e($fieldName) ?>" rows="4" class="w-full"><?php
                  if ($shown !== null) { echo e($shown); }
                  else { $j = json_encode($current, JSON_PRETTY_PRINT); echo e(is_string($j) ? $j : ''); }
              ?></textarea>
            <?php elseif ($sensitive):
                $isSet = is_string($current) && $current !== '';
                $statusText = $isSet ? 'Set — leave blank to keep current' : 'Not set';
                $statusCls  = $isSet ? 'success' : 'muted';
            ?>
              <span class="pw-wrap">
                <input type="password" id="<?= e($inputId) ?>" name="<?= e($fieldName) ?>"
                       value="" placeholder="<?= $isSet ? '••••••••' : '' ?>"
                       class="w-full pw-input" autocomplete="new-password">
                <button type="button" class="pw-toggle"
                        data-pw-toggle-for="<?= e($inputId) ?>"
                        <?php if ($isSet): ?>data-pw-reveal-key="<?= e($key) ?>" data-pw-reveal-csrf="<?= e(csrf_token()) ?>"<?php endif; ?>
                        aria-label="Show password" aria-pressed="false">
                  <svg class="pw-eye pw-eye--open" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="pw-eye pw-eye--closed" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a19.77 19.77 0 0 1 5.06-5.94"/><path d="M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a19.77 19.77 0 0 1-3.17 4.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
              </span>
              <span class="badge badge-<?= e($statusCls) ?> settings-row__badge"><?= e($statusText) ?></span>
            <?php elseif ($options !== null):
                $selected       = $shown !== null ? $shown : (is_scalar($current) ? (string)$current : '');
                $selectedValid  = array_key_exists($selected, $options);
            ?>
              <select id="<?= e($inputId) ?>" name="<?= e($fieldName) ?>" class="w-full">
                <?php if (!$selectedValid): ?>
                  <option value="<?= e($selected) ?>" selected>⚠ invalid: <?= e($selected) ?></option>
                <?php endif; ?>
                <?php foreach ($options as $optValue => $optLabel): ?>
                  <option value="<?= e((string)$optValue) ?>"<?= ($selectedValid && (string)$optValue === $selected) ? ' selected' : '' ?>><?= e((string)$optLabel) ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input type="text" id="<?= e($inputId) ?>" name="<?= e($fieldName) ?>"
                     value="<?= e($shown !== null ? $shown : (is_scalar($current) ? (string)$current : '')) ?>"
                     class="w-full">
            <?php endif; ?>
          <?php endif; // bool vs other ?>
          <?php if ($help): ?><div class="muted"><?= e($help) ?></div><?php endif; ?>
          <?php if ($err): ?><div class="danger"><?= e($err) ?></div><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="row settings-row__actions">
      <button type="submit" class="button-primary">Save <?= e($groupLabel) ?></button>
    </div>
  </form>

  <?php if ($groupKey === 'smtp'): ?>
  <div class="row settings-row__sub" style="gap:.5rem;align-items:center;">
    <button type="button" id="smtp-test-btn" class="button-secondary">Send test email</button>
    <span id="smtp-test-result" class="muted"></span>
  </div>
  <?php endif; ?>
</div>
