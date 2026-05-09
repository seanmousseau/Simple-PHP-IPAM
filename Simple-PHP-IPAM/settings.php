<?php
/**
 * Settings page (v3.16.0 #749) — vertical-tab layout.
 *
 * The 16 settings groups defined in ipam_setting_groups() are mapped to 5
 * top-level tabs:
 *
 *   General         → branding, display, update_check
 *   Authentication  → security, password_policy, mfa, oidc, login_protection, recaptcha_enterprise
 *   Notifications   → smtp, alert
 *   Data            → backup, housekeeping, limits
 *   Integrations    → api, webhooks
 *
 * URL state lives in ?tab=. The form POST flow is unchanged — the handler
 * still keys on $_POST['group'], validates the whole group atomically, and
 * after save redirects back to the OWNING tab + #group anchor so admins
 * stay on the same screen they just edited.
 */
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$definitions = ipam_setting_definitions();
$groups      = ipam_setting_groups();

/**
 * Tab definitions and the group → tab reverse map.
 *
 * The order here is the order the tabs render in the rail. Each tab's
 * `groups` list also defines render order within the tab, so the rail tab
 * order, the redirect after save, and the right-pane group order all flow
 * from this single structure.
 *
 * @var array<string, array{label:string, description:string, groups:list<string>}> $tabs
 */
$tabs = [
    'general' => [
        'label'       => 'General',
        'description' => 'Branding, display preferences, and update checker.',
        'groups'      => ['branding', 'display', 'update_check'],
    ],
    'authentication' => [
        'label'       => 'Authentication',
        'description' => 'Sessions, password policy, MFA, OIDC, and login protection.',
        'groups'      => ['security', 'step_up', 'password_policy', 'mfa', 'oidc', 'login_protection', 'recaptcha_enterprise'],
    ],
    'notifications' => [
        'label'       => 'Notifications',
        'description' => 'SMTP delivery and subnet utilization alerts.',
        'groups'      => ['smtp', 'alert'],
    ],
    'data' => [
        'label'       => 'Data & Maintenance',
        'description' => 'Database backup, housekeeping, and upload limits.',
        'groups'      => ['backup', 'housekeeping', 'limits'],
    ],
    'integrations' => [
        'label'       => 'Integrations',
        'description' => 'REST API and outbound webhooks.',
        'groups'      => ['api', 'webhooks'],
    ],
];

/** @var array<string, string> $groupToTab Reverse map: group key → tab slug. */
$groupToTab = [];
foreach ($tabs as $tabSlug => $tabMeta) {
    foreach ($tabMeta['groups'] as $g) {
        $groupToTab[$g] = $tabSlug;
    }
}

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

    $user   = current_user();
    $userId = to_int($user['id'] ?? 0) ?: null;

    // #1121 (v3.27.2): the per-key save path (#756) was removed in favour of
    // page-wide UX consistency — every field, including bool checkboxes,
    // stages locally and is committed by "Save Group". The original #756
    // bug (legacy group form's silent-sibling cascade when a checkbox is
    // unchecked) is now closed by a hidden value="0" shim rendered before
    // each checkbox in views/settings_group_form.php; the bool branch below
    // reads the explicit value rather than presence (`=== '1'`).
    $postedGroup = to_str($_POST['group'] ?? '');
    if ($postedGroup === '' || !isset($groups[$postedGroup])) {
        flash_set('Unknown settings group.', 'danger');
        header('Location: settings.php');
        exit;
    }

    /** @var array<string, mixed> $pending */
    $pending = [];

    foreach ($definitions as $key => $def) {
        if (($def['group'] ?? '') !== $postedGroup) continue;
        if (!empty($def['deprecated'])) continue;

        $fieldName = 'k_' . str_replace('.', '__', $key);
        $type      = is_string($def['type'] ?? null) ? $def['type'] : 'string';
        $sensitive = !empty($def['sensitive']);
        $current   = ipam_setting($key);

        if ($key === 'alert.recipient_user_ids') {
            $rawSel = $_POST[$fieldName . '__select'] ?? null;
            if (is_array($rawSel)) {
                $intIds = array_values(array_unique(array_map(fn($v) => (int)to_str($v), $rawSel)));
                $intIds = array_values(array_filter($intIds, fn($i) => $i > 0));
                $encoded = json_encode($intIds, JSON_UNESCAPED_SLASHES);
                $_POST[$fieldName] = is_string($encoded) ? $encoded : '[]';
            }
        }

        if ($type === 'bool') {
            // #1121: read value, not presence. The hidden shim emits '0' for
            // unchecked, the checkbox emits '1' for checked. With shim,
            // `isset()` would always be true — making "unchecked" indistinguishable
            // from "checked".
            $formOverrides[$key] = (to_str($_POST[$fieldName] ?? '0') === '1') ? '1' : '0';
        } elseif ($sensitive) {
            $formOverrides[$key] = '';
        } else {
            $formOverrides[$key] = to_str($_POST[$fieldName] ?? '');
        }

        if ($sensitive) {
            $raw = to_str($_POST[$fieldName] ?? '');
            if ($raw === '') continue;
            $pending[$key] = $raw;
            continue;
        }

        if ($type === 'bool') {
            // #1121: see formOverrides note above — value, not presence.
            $newValue = (to_str($_POST[$fieldName] ?? '0') === '1');
        } elseif ($type === 'int') {
            $raw = trim(to_str($_POST[$fieldName] ?? ''));
            if ($raw !== '' && !preg_match('/^-?\d+$/', $raw)) {
                $fieldErrors[$key] = 'Must be an integer.';
                continue;
            }
            $newValue = $raw === '' ? 0 : (int)$raw;

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

            $options = ipam_setting_options($def);
            if ($options !== null) {
                $currentStr     = is_scalar($current) ? (string)$current : '';
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

        if ($current === $newValue) continue;
        if ($type === 'int' && to_int($current) === to_int($newValue)) continue;
        if ($type === 'bool' && (bool)$current === (bool)$newValue) continue;

        $pending[$key] = $newValue;
    }

    // Step-up policy save: lock-out precondition + sudo gate. Plan §3.3 + §3.4.
    // The audit + invalidate side-effects fire after the commit below.
    $stepUpPolicySave = ($postedGroup === 'step_up' && !$fieldErrors && $pending !== []);
    if ($stepUpPolicySave) {
        $proposed = ipam_sudo_proposed_policy_from_overrides($pending);
        $offender = ipam_sudo_policy_lockout_check($db, $proposed);
        if ($offender !== '') {
            $fieldErrors['_group'] = "Cannot save: admin '{$offender}' would have no available step-up method under the proposed policy. Enable at least one method that admin can satisfy.";
        } elseif (!ipam_sudo_require($db, to_int($userId ?? 0))) {
            page_header('Confirm your identity');
            $stepUpUserId       = to_int($userId ?? 0);
            $stepUpFormAction   = 'settings.php';
            $stepUpHiddenFields = ['group' => 'step_up'];
            // Re-emit only the policy field POSTs so the form re-submits the
            // same edits with the step-up proof attached. #1121: with the
            // hidden value="0" shim, every bool field is always present;
            // re-emit the actual submitted value (0 or 1) rather than just
            // its presence.
            $boolFields = ['k_auth__step_up__allow_totp', 'k_auth__step_up__allow_email_otp', 'k_auth__step_up__allow_webauthn', 'k_auth__step_up__allow_provider_reauth'];
            foreach ($boolFields as $boolField) {
                $stepUpHiddenFields[$boolField] = (to_str($_POST[$boolField] ?? '0') === '1') ? '1' : '0';
            }
            if (isset($_POST['k_auth__step_up__ttl_seconds'])) {
                $stepUpHiddenFields['k_auth__step_up__ttl_seconds'] = to_str($_POST['k_auth__step_up__ttl_seconds']);
            }
            $stepUpDescription = 'Saving the step-up authentication policy is itself a sudo action under the current policy.';
            $stepUpReturnPath  = 'settings.php?tab=authentication#group-step_up';
            $stepUpError       = isset($_POST['_sudo_method']) ? 'Verification failed. Please try again.' : '';
            include __DIR__ . '/views/_step_up_prompt.php';
            page_footer();
            exit;
        }
        ipam_sudo_consume_once();  // Bug X (Pass A 2026-05-08, v3.27.1): consume sudo_once for TTL=0 policy.

    }

    $changed = 0;
    if (!$fieldErrors && $pending) {
        $db->beginTransaction();
        try {
            foreach ($pending as $key => $newValue) {
                ipam_setting_set($db, $key, $newValue, $userId);
                $changed++;
            }
            $db->commit();
            if ($stepUpPolicySave) {
                $detail = 'methods='
                    . implode(',', array_filter([
                        $proposed['allow_totp']            ? 'totp'            : null,
                        $proposed['allow_email_otp']       ? 'email_otp'       : null,
                        $proposed['allow_webauthn']        ? 'webauthn'        : null,
                        $proposed['allow_provider_reauth'] ? 'provider_reauth' : null,
                    ]))
                    . ' ttl=' . $proposed['ttl_seconds']
                    . ' by=' . to_str($user['username'] ?? '');
                audit($db, 'auth.step_up_policy.updated', 'auth', null, $detail);
                ipam_sudo_invalidate();
            }
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
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
        // Redirect back to the tab that owns this group so the admin stays
        // on the same screen they just edited. Fall back to ?tab=general
        // for any group not in $groupToTab (defensive — every registered
        // group is in the map by construction).
        $owningTab = $groupToTab[$postedGroup] ?? 'general';
        header('Location: settings.php?tab=' . rawurlencode($owningTab) . '#group-' . rawurlencode($postedGroup));
        exit;
    }
    // Fall through to re-render with errors.
}

// Determine active tab for GET (and for re-render on validation error).
// On a POSTed validation error, prefer the tab that owns the posted group
// so the admin sees the field they just failed to save.
$activeTab = to_str($_GET['tab'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($postedGroup) && isset($groupToTab[$postedGroup])) {
    $activeTab = $groupToTab[$postedGroup];
}
if (!isset($tabs[$activeTab])) {
    $activeTab = 'general';
}

page_header('Settings');
?>
<!-- Settings-specific skip-link (#758) — page_header()'s skip-link lands at #main-content
     above the rail; this jumps past the rail straight to the form area on this page. -->
<a class="skip-link" href="#settings-content">Skip to settings content</a>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="#">Admin</a><span class="sep">›</span>
  <span>Settings</span>
</div>

<div class="toolbar">
  <div>
    <h1>Settings</h1>
    <div class="muted">
      Configure the application from the database. <code>config.php</code> still holds
      the bootstrap server-level values (<code>app_secret</code>, DB credentials); everything
      else is editable here.
    </div>
  </div>
</div>

<?php $flash = flash_get(); if ($flash): ?>
  <p class="<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></p>
<?php endif; unset($flash); ?>
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

<div class="settings-shell">
  <!-- Mobile: <select> dropdown shown <768px in place of the rail. -->
  <form class="settings-mobile-nav" method="get" action="settings.php" aria-label="Settings tab">
    <label for="settings-mobile-tab" class="settings-mobile-nav__label">Settings section</label>
    <select id="settings-mobile-tab" name="tab" class="w-full" data-settings-mobile-nav>
      <?php foreach ($tabs as $tabSlug => $tabMeta): ?>
        <option value="<?= e($tabSlug) ?>"<?= $tabSlug === $activeTab ? ' selected' : '' ?>><?= e($tabMeta['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <!-- Desktop: vertical rail. role=navigation so screen readers announce a
       landmark; aria-current="page" on the active link is the canonical
       way to expose "you are here" inside a navigation list. -->
  <?php $groupToTabJson = json_encode($groupToTab, JSON_UNESCAPED_SLASHES);
        if (!is_string($groupToTabJson)) $groupToTabJson = '{}'; ?>
  <nav class="settings-rail" aria-label="Settings sections" data-settings-rail
       data-group-tab-map='<?= e($groupToTabJson) ?>'>
    <ul class="settings-rail__list">
      <?php foreach ($tabs as $tabSlug => $tabMeta):
          $isActive = $tabSlug === $activeTab; ?>
        <li class="settings-rail__item">
          <a class="settings-rail__link<?= $isActive ? ' is-active' : '' ?>"
             href="settings.php?tab=<?= e($tabSlug) ?>"
             <?= $isActive ? 'aria-current="page"' : '' ?>>
            <?= e($tabMeta['label']) ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <section class="settings-content" id="settings-content" aria-labelledby="settings-content-title" tabindex="-1">
    <h2 class="settings-content__title" id="settings-content-title"><?= e($tabs[$activeTab]['label']) ?></h2>
    <?php if (!empty($tabs[$activeTab]['description'])): ?>
      <p class="muted settings-content__desc"><?= e($tabs[$activeTab]['description']) ?></p>
    <?php endif; ?>

    <?php
    ipam_render('settings_tab_' . $activeTab, [
        'db'            => $db,
        'definitions'   => $definitions,
        'groups'        => $groups,
        'tabGroups'     => $tabs[$activeTab]['groups'],
        'fieldErrors'   => $fieldErrors,
        'formOverrides' => $formOverrides,
    ]);
    ?>
  </section>
</div>

<?php page_footer(); ?>
