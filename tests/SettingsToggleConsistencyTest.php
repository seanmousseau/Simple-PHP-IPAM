<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Bug V (Pass A 2026-05-08, v3.27.2 #1121) — settings page UX consistency.
 *
 * Pre-fix: the v3.16.0 #756 design gave bool checkboxes a hidden per-key
 * shadow form (`<form id="toggle-...">`) that app.js auto-submits on change.
 * Outcome: clicking a checkbox triggered a server save + 302 redirect +
 * page reload — discarding any unsaved values the operator had typed
 * into sibling text/int/json fields in the same group.
 *
 * Sean reproduced this end-to-end on 2026-05-09 (PR #1119 follow-up):
 * "checked auto-provision under OIDC, my entire config I entered was wiped."
 *
 * Locked direction (2026-05-09): page-wide UX consistency. No field
 * type auto-saves. Every change — bool, string, int, json — stages
 * locally; "Save" commits the whole group atomically. This test locks
 * the structural pieces of that direction:
 *
 *   1. The shadow per-toggle form is gone from views/settings_group_form.php.
 *   2. The `data-setting-toggle-target` attribute is gone from the checkbox.
 *   3. Every checkbox is preceded by a hidden `value="0"` shim so the
 *      group form posts an explicit value (not presence) for every bool —
 *      which closes the original #756 bug (silent sibling cascade) the
 *      shadow form was added to fix.
 *   4. assets/app.js no longer binds to setting-toggle inputs.
 *   5. settings.php no longer carries the per-key save handler block.
 *   6. settings.php's bool save reads value (`'1'`) — not presence — so the
 *      shim's `value="0"` correctly maps to "unchecked".
 *
 * Behavioural Pass A on the test instance covers the operator-facing UX;
 * this test guards the wiring against regressions.
 */
final class SettingsToggleConsistencyTest extends TestCase
{
    private string $groupForm;
    private string $appJs;
    private string $settingsPhp;

    protected function setUp(): void
    {
        $this->groupForm   = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/views/settings_group_form.php');
        // v3.34.0 #939 Phase 2b/3/4: assets/app.js was split into per-concern
        // modules. The password show/hide toggle complex (formerly the C05
        // forms-core concern) now lives in `assets/modules/30-forms-core.js`.
        // The #1121 assertions in this test all check strings in that file.
        $this->appJs       = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/assets/modules/30-forms-core.js');
        $this->settingsPhp = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/settings.php');
        $this->assertNotEmpty($this->groupForm,   'settings_group_form.php must be readable');
        $this->assertNotEmpty($this->appJs,       'assets/modules/30-forms-core.js must be readable');
        $this->assertNotEmpty($this->settingsPhp, 'settings.php must be readable');
    }

    public function testShadowToggleFormIsRemoved(): void
    {
        $this->assertStringNotContainsString(
            'class="setting-toggle-form"',
            $this->groupForm,
            "#1121: shadow toggle form must be removed; UX consistency requires bool changes go through the group form"
        );
        $this->assertStringNotContainsString(
            'id="toggle-',
            $this->groupForm,
            "#1121: per-toggle <form id='toggle-...'> must be removed"
        );
    }

    public function testToggleTargetAttributeIsRemoved(): void
    {
        $this->assertStringNotContainsString(
            'data-setting-toggle-target',
            $this->groupForm,
            "#1121: data-setting-toggle-target must be removed from checkboxes"
        );
        $this->assertStringNotContainsString(
            'data-setting-toggle-target',
            $this->appJs,
            "#1121: app.js must no longer bind to data-setting-toggle-target"
        );
    }

    public function testHiddenShimPrecedesEveryBoolCheckbox(): void
    {
        // Pattern: when rendering type === 'bool', a hidden value="0" input
        // with the same name as the checkbox must precede the checkbox.
        // Match the structural template: hidden input, then checkbox, both
        // with name=$fieldName. The exact PHP variable interpolation lives
        // in the template; this regex matches the rendered structural form.
        $this->assertMatchesRegularExpression(
            '/<input\s+type="hidden"\s+name="<\?=\s*e\(\$fieldName\)\s*\?>"\s+value="0">\s*<input\s+type="checkbox"/s',
            $this->groupForm,
            "#1121: every bool checkbox must be preceded by a hidden value=0 shim with the same field name (closes #756's silent sibling cascade without requiring per-key auto-save)"
        );
    }

    public function testAppJsToggleHandlerIsRemoved(): void
    {
        // The handler binds to checkboxes by attribute selector. Removing
        // the attribute (above) makes the handler dead code; removing the
        // handler too keeps the bundle smaller and prevents accidental
        // re-binding via a future selector tweak.
        $this->assertStringNotContainsString(
            'Settings per-toggle auto-submit',
            $this->appJs,
            "#1121: per-toggle auto-submit block in app.js must be removed"
        );
        $this->assertStringNotContainsString(
            'shadow.submit()',
            $this->appJs,
            "#1121: shadow-form submit() call must be removed from app.js"
        );
    }

    public function testPerKeyHandlerIsGone(): void
    {
        // v3.29.0 (#1126): the server-side per-key save handler in
        // settings.php that survived v3.27.2 as a Playwright-test
        // stopgap has been removed now that every spec under
        // testing/playwright/tests/ POSTs via the group form. This
        // test pins the deletion so a future refactor can't quietly
        // resurrect the per-key path.
        $this->assertStringNotContainsString(
            "\$postedKey = to_str(\$_POST['key'] ?? '');",
            $this->settingsPhp,
            "#1126: per-key save handler (\$postedKey detection) must remain removed from settings.php"
        );
        $this->assertStringNotContainsString(
            'Per-key save currently only supports boolean toggles.',
            $this->settingsPhp,
            "#1126: per-key save error path must remain removed from settings.php"
        );
        // UI-side wirings stay gone.
        $this->assertStringNotContainsString(
            'class="setting-toggle-form"',
            $this->groupForm,
            "#1121: UI shadow toggle form must stay removed (testShadowToggleFormIsRemoved enforces this — assert here too as a near-by sanity check)"
        );
    }

    public function testBoolDetectionUsesValueNotPresence(): void
    {
        // With a shim that always emits the field, `isset($_POST[$fieldName])`
        // evaluates to true for every bool — making "unchecked" indistinguishable
        // from "checked". The detection must read the value: '1' = checked,
        // '0' = unchecked. Any pre-fix `isset($_POST[...])` for bool reading
        // becomes a bug under the shim.
        //
        // Look for the canonical post-fix pattern. Matches both the
        // formOverrides assignment and the newValue assignment.
        $this->assertMatchesRegularExpression(
            "/\\\$_POST\\[\\\$fieldName\\][^=]*===\\s*['\"]1['\"]/",
            $this->settingsPhp,
            "#1121: bool detection must read value (=== '1'), not presence (isset). Otherwise the shim breaks unchecked semantics."
        );
    }
}
