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
    // Every submitted value is recorded in $formOverrides so the re-render
    // path (used on validation error) shows the admin what they typed, not
    // the stale DB/config value. Nothing is written until the whole group
    // validates cleanly; that way a late validation error cannot leave
    // earlier fields persisted and audited while the page re-renders.
    /** @var array<string, mixed> $pending */
    $pending = [];

    foreach ($definitions as $key => $def) {
        if (($def['group'] ?? '') !== $postedGroup) continue;
        // CodeRabbit M2 (PR #450): mirror the renderer's deprecated guard.
        // The save loop must not process deprecated keys, otherwise saving any
        // group containing them would silently overwrite the stored value with
        // an empty string (since the field is absent from the form). Affected
        // alert.email after #443 — it would be wiped on every Alerting save.
        if (!empty($def['deprecated'])) continue;

        $fieldName = 'k_' . str_replace('.', '__', $key);
        $type      = is_string($def['type'] ?? null) ? $def['type'] : 'string';
        $sensitive = !empty($def['sensitive']);
        $current   = ipam_setting($key);

        // #443: the alert.recipient_user_ids field uses a multi-select tied
        // to the users table instead of the generic JSON textarea. The select
        // posts ${fieldName}__select[] (an int[]) which we re-encode as JSON
        // here so the rest of the pipeline (validator, formOverrides, json
        // type branch) handles it unchanged.
        if ($key === 'alert.recipient_user_ids') {
            $rawSel = $_POST[$fieldName . '__select'] ?? null;
            if (is_array($rawSel)) {
                $intIds = array_values(array_unique(array_map(fn($v) => (int)to_str($v), $rawSel)));
                $intIds = array_values(array_filter($intIds, fn($i) => $i > 0));
                $encoded = json_encode($intIds, JSON_UNESCAPED_SLASHES);
                $_POST[$fieldName] = is_string($encoded) ? $encoded : '[]';
            }
        }

        // Record the raw submitted value up front so a later validation
        // failure in this group does not cause earlier inputs to snap back
        // to their stored state on re-render. Sensitive fields deliberately
        // render empty on every request, so never echo an old value back.
        if ($type === 'bool') {
            $formOverrides[$key] = isset($_POST[$fieldName]) ? '1' : '0';
        } elseif ($sensitive) {
            $formOverrides[$key] = '';
        } else {
            $formOverrides[$key] = to_str($_POST[$fieldName] ?? '');
        }

        // Sensitive fields follow the "leave blank to keep current" pattern:
        // an empty submission means the admin didn't touch the field, so we
        // must not re-write (or re-audit) the stored value with itself. A
        // non-empty submission is treated as an explicit new value.
        if ($sensitive) {
            $raw = to_str($_POST[$fieldName] ?? '');
            if ($raw === '') continue;
            $pending[$key] = $raw;
            continue;
        }

        if ($type === 'bool') {
            $newValue = isset($_POST[$fieldName]);
        } elseif ($type === 'int') {
            $raw = trim(to_str($_POST[$fieldName] ?? ''));
            if ($raw !== '' && !preg_match('/^-?\d+$/', $raw)) {
                $fieldErrors[$key] = 'Must be an integer.';
                continue;
            }
            $newValue = $raw === '' ? 0 : (int)$raw;

            // Enforce per-definition min/max so admins cannot persist nonsense
            // like a negative session_idle_seconds or login_max_attempts.
            $min = array_key_exists('min', $def) ? to_int($def['min']) : null;
            $max = array_key_exists('max', $def) ? to_int($def['max']) : null;
            if ($min !== null && $newValue < $min) {
                $fieldErrors[$key] = "Must be at least {$min}.";
                continue;
            }
            if ($max !== null && $newValue > $max) {
                $fieldErrors[$key] = "Must be at most {$max}.";
                continue;
            }
        } elseif ($type === 'json') {
            $raw = to_str($_POST[$fieldName] ?? '');
            if (trim($raw) === '') {
                $newValue = null;
            } else {
                $decoded = json_decode($raw, true);
                if ($decoded === null && strtolower(trim($raw)) !== 'null') {
                    $fieldErrors[$key] = 'Invalid JSON.';
                    continue;
                }
                $newValue = $decoded;
            }
        } else {
            $newValue = to_str($_POST[$fieldName] ?? '');

            // Enum validation: if the registry declares an `options` set for
            // this string setting, reject any value outside it. Keeps typos
            // from silently breaking the login protection or timezone
            // subsystems. The registry is the single source of truth for the
            // valid set — settings.php never hard-codes these lists.
            //
            // We also guard against the "invalid stored value coerced by
            // unrelated save" trap (see CodeRabbit review on #448): if the
            // currently stored value is already outside the option set,
            // the <select> renders its first option as selected and the
            // browser submits that on save. A plain array_key_exists check
            // on the submitted value would accept the first option and
            // silently overwrite the invalid-but-untouched stored value.
            // Block those saves unless the submitted value is an explicit,
            // valid fix that differs from the invalid stored value.
            $options = ipam_setting_options($def);
            if ($options !== null) {
                $currentStr = is_scalar($current) ? (string)$current : '';
                $storedValid    = array_key_exists($currentStr, $options);
                $submittedValid = array_key_exists($newValue, $options);

                if (!$submittedValid) {
                    $fieldErrors[$key] = 'Must be one of the listed values.';
                    continue;
                }
                if (!$storedValid && $newValue === $currentStr) {
                    $fieldErrors[$key] = 'Stored value is not a valid option. Select a valid option to fix it.';
                    continue;
                }
            }
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
            // Log the underlying error server-side; keep the UI message
            // generic so PDO error text (e.g. UNIQUE constraint failed on
            // settings.key) is never rendered back to the browser.
            error_log('settings.php save failed: ' . $e->getMessage());
            $fieldErrors['_group'] = 'Save failed. Please try again.';
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

<?php /** @var list<string> $_staleKeys */ $_staleKeys = $GLOBALS['config_stale_keys'] ?? []; if ($_staleKeys): ?>
  <div class="card admin-notice admin-notice--warning">
    <h2 style="margin-top:0;">config.php cleanup needed</h2>
    <p class="muted">
      These <?= count($_staleKeys) ?> key(s) are no longer read from <code>config.php</code>.
      All settings now live in the database. Remove them from <code>config.php</code>:
    </p>
    <p><code><?= e(implode(', ', $_staleKeys)) ?></code></p>
  </div>
<?php endif; unset($_staleKeys); ?>

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
          // #443: hide deprecated registry entries from the UI. The values
          // are still in the table so migrations can read them.
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

          $badge = match ($source) {
              'db'    => ['text' => '🟢 Database', 'cls' => 'success'],
              default => ['text' => '⚪ Default',   'cls' => 'muted'],
          };
      ?>
        <?php
        $inputId = 'f-' . $fieldName;
        $options = ($type === 'string') ? ipam_setting_options($def) : null;
        $badgeHtml =
            '<span class="badge badge-' . e($badge['cls']) . '" style="margin-left:0.5rem;">' . e($badge['text']) . '</span>'
          . '<code class="muted" style="margin-left:0.5rem;">' . e($key) . '</code>';
        ?>
        <div class="setting-row row" style="align-items:flex-start;margin-top:0.75rem;">
          <div class="flex-1">
            <?php if ($type === 'bool'):
                // #441: render the checkbox inline with the label, badge, and
                // key code so the control sits next to the setting it toggles
                // instead of on a line by itself underneath. The whole row is
                // one <label for="..."> so clicking anywhere on the title
                // toggles the checkbox.
                $boolChecked = $shown !== null ? $shown === '1' : (bool)$current;
            ?>
              <label for="<?= e($inputId) ?>" class="setting-head setting-head--bool">
                <input type="checkbox" id="<?= e($inputId) ?>" name="<?= e($fieldName) ?>" value="1"<?= $boolChecked ? ' checked' : '' ?>>
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
                  // #443: render a multi-select picker tied to the users table
                  // instead of a raw JSON textarea. Only active users with a
                  // non-empty email are eligible. Submitted form value is a
                  // JSON-encoded list[int] so it round-trips through the
                  // existing 'json' POST validator unchanged.
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
                  <!-- Sentinel posts an empty value so the POST handler always
                       sees the __select key, even when the user deselects all
                       options (browsers omit empty <select multiple> fields). -->
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
                    <span class="muted" style="margin-left:0.5rem;font-size:0.85em;">Cmd/Ctrl-click to toggle individual selections.</span>
                  </div>
                  <div class="muted" style="margin-top:0.25rem;">
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
                  // Sensitive fields intentionally render blank on every request
                  // so the stored secret is never echoed back into the HTML
                  // source or browser autofill. The admin must re-type the
                  // secret to update it; leaving the field blank leaves the
                  // stored value unchanged (handled in the POST loop above).
                  $isSet = is_string($current) && $current !== '';
                  $statusText = $isSet ? 'Set — leave blank to keep current' : 'Not set';
                  $statusCls  = $isSet ? 'success' : 'muted';
              ?>
                <span class="pw-wrap">
                  <input type="password" id="<?= e($inputId) ?>" name="<?= e($fieldName) ?>"
                         value="" placeholder="<?= $isSet ? '••••••••' : '' ?>"
                         class="w-full pw-input" autocomplete="new-password">
                  <!--
                    #449: eye-icon button replaces the v2.6.0/v2.7.0 checkbox toggles,
                    which both regressed in real browsers despite passing headless
                    Playwright. type="button" is mandatory or Enter submits the form.
                    Positioned at right:36px so password-manager icons (1Password,
                    Bitwarden) can paint at right:0–8px without overlap.

                    #449 follow-up: when the field has a stored value (isSet),
                    render data-pw-reveal-key + data-pw-reveal-csrf so the JS
                    handler can fetch the actual stored secret from
                    settings_reveal.php on first reveal click. Without this,
                    the toggle could only reveal what the user had just typed,
                    which is not what users expect on a "Set" field.
                  -->
                  <button type="button" class="pw-toggle"
                          data-pw-toggle-for="<?= e($inputId) ?>"
                          <?php if ($isSet): ?>data-pw-reveal-key="<?= e($key) ?>" data-pw-reveal-csrf="<?= e(csrf_token()) ?>"<?php endif; ?>
                          aria-label="Show password" aria-pressed="false">
                    <svg class="pw-eye pw-eye--open" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="pw-eye pw-eye--closed" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a19.77 19.77 0 0 1 5.06-5.94"/><path d="M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a19.77 19.77 0 0 1-3.17 4.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                  </button>
                </span>
                <span class="badge badge-<?= e($statusCls) ?>" style="margin-left:0.25rem;"><?= e($statusText) ?></span>
              <?php elseif ($options !== null):
                  // #442: string settings with a fixed option set render as a
                  // validated <select>. The registry owns the option list; the
                  // POST handler rejects any value not in it.
                  //
                  // If the currently stored value is outside the option set
                  // (e.g. from a direct SQL write or a dropped registry entry),
                  // render it as an extra "⚠ invalid" option at the top of the
                  // list and mark it selected so the admin can see the bad
                  // state instead of the browser silently picking the first
                  // valid option. The POST handler rejects a re-save of the
                  // same invalid value, forcing the admin to pick a valid fix.
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

      <div class="row" style="margin-top:1rem;">
        <button type="submit" class="button-primary">Save <?= e($groupLabel) ?></button>
      </div>
    </form>
    <?php if ($groupKey === 'smtp'): ?>
    <div class="row" style="margin-top:0.75rem;gap:0.5rem;align-items:center;">
      <button type="button" id="smtp-test-btn" class="button-secondary">Send test email</button>
      <span id="smtp-test-result" class="muted"></span>
    </div>
    <script>
    (function(){
      var btn = document.getElementById('smtp-test-btn');
      var out = document.getElementById('smtp-test-result');
      if (!btn) return;
      btn.addEventListener('click', function(){
        btn.disabled = true;
        out.textContent = 'Sending…';
        out.className = 'muted';
        var fd = new FormData();
        fd.append('csrf', <?= json_encode(csrf_token()) ?>);
        fetch('smtp_test.php', {method:'POST', body:fd})
          .then(function(r){ return r.json(); })
          .then(function(d){
            out.textContent = d.message || (d.ok ? 'Sent.' : 'Failed.');
            out.className = d.ok ? 'success' : 'danger';
          })
          .catch(function(e){ out.textContent = 'Request failed: ' + e; out.className = 'danger'; })
          .finally(function(){ btn.disabled = false; });
      });
    })();
    </script>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php page_footer(); ?>
