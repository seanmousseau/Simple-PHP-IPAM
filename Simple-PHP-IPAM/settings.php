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
        'groups'      => ['security', 'password_policy', 'mfa', 'oidc', 'login_protection', 'recaptcha_enterprise'],
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

    $postedGroup = to_str($_POST['group'] ?? '');
    if ($postedGroup === '' || !isset($groups[$postedGroup])) {
        flash_set('Unknown settings group.', 'danger');
        header('Location: settings.php');
        exit;
    }

    $user   = current_user();
    $userId = to_int($user['id'] ?? 0) ?: null;

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
            $formOverrides[$key] = isset($_POST[$fieldName]) ? '1' : '0';
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
            $newValue = isset($_POST[$fieldName]);
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
  <nav class="settings-rail" aria-label="Settings sections" data-settings-rail>
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
        'fieldErrors'   => $fieldErrors,
        'formOverrides' => $formOverrides,
    ]);
    ?>
  </section>
</div>

<?php page_footer(); ?>
