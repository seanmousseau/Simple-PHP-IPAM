/**
 * Playwright tests for the v3.27.0 step-up authentication policy admin card
 * (issue #1109; settings tab "authentication", group "step_up").
 *
 * Coverage:
 *   1. Card renders with all four allow_* checkboxes plus the discrete TTL
 *      dropdown (six exact values from the registry).
 *   2. Lock-out guard refuses a policy save that would strand every active
 *      admin (all four allow_* flags off → no available step-up method).
 *   3. Valid policy save flows through the step-up prompt, persists the new
 *      TTL, and writes an `auth.step_up_policy.updated` audit row.
 *
 * The lock-out test deliberately uses the "everything off" policy so we don't
 * need an OIDC-only admin SQL fixture in this spec — that fixture is exercised
 * by step-up-oidc-only.spec.ts. With every method flag false, even the demo
 * admin (who can otherwise satisfy the gate via password / provider re-auth)
 * gets stranded, so the guard fires for any seeded environment.
 *
 * Settings field-name encoding: 'k_' + key.replace('.', '__'),
 * e.g. auth.step_up.allow_totp → k_auth__step_up__allow_totp.
 */

import { test, expect } from '@playwright/test';
import { login, logout, ADMIN_USER, ADMIN_PASS, appUrl } from '../fixtures/ipam';

const ALLOW_FLAGS = [
    'k_auth__step_up__allow_totp',
    'k_auth__step_up__allow_email_otp',
    'k_auth__step_up__allow_webauthn',
    'k_auth__step_up__allow_provider_reauth',
];

const TTL_FIELD = 'k_auth__step_up__ttl_seconds';

// Six discrete TTL values shipped by the registry (lib.php:2183).
const EXPECTED_TTL_VALUES = ['0', '60', '300', '900', '1800', '3600'];

test.describe('Settings — step-up authentication policy (#1109)', () => {
    test.beforeEach(async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
    });

    test.afterEach(async ({ page }) => {
        await logout(page).catch(() => undefined);
    });

    test('card renders all four allow_* controls + six TTL dropdown options', async ({ page }) => {
        await page.goto(appUrl('settings.php?tab=authentication#group-step_up'));
        await expect(page.locator('#group-step_up')).toBeVisible();

        for (const name of ALLOW_FLAGS) {
            await expect(page.locator(`input[name="${name}"]`)).toBeVisible();
        }

        const ttl = page.locator(`select[name="${TTL_FIELD}"]`);
        await expect(ttl).toBeVisible();
        await expect(ttl.locator('option')).toHaveCount(EXPECTED_TTL_VALUES.length);

        const values = await ttl.locator('option').evaluateAll(
            (opts) => opts.map((o) => (o as HTMLOptionElement).value),
        );
        expect(values.sort()).toEqual([...EXPECTED_TTL_VALUES].sort());
    });

    test('lock-out guard refuses a policy that strands every admin', async ({ page }) => {
        await page.goto(appUrl('settings.php?tab=authentication#group-step_up'));

        // Each allow_* checkbox carries data-setting-toggle-target which app.js
        // wires up to auto-submit a per-key toggle form on change. Bypass that
        // and just flip the rendered DOM state — the group save below picks up
        // the unchecked state from the form serialisation.
        for (const name of ALLOW_FLAGS) {
            await page.locator(`input[name="${name}"]`).evaluate(
                (el) => { (el as HTMLInputElement).checked = false; },
            );
        }

        await page.locator('#group-step_up button[type=submit]').click();

        // Server should redisplay the form with the field-level _group error
        // and MUST NOT advance to the step-up prompt — lock-out is checked
        // before the sudo gate (settings.php:281).
        await expect(page.locator('text=/Cannot save/i').first()).toBeVisible();
        await expect(page.locator('[data-step-up-prompt]')).toHaveCount(0);
    });

    test('valid policy save flows through step-up prompt and persists + audits', async ({ page }) => {
        await page.goto(appUrl('settings.php?tab=authentication#group-step_up'));

        // Re-arm any flags the previous test left unchecked (test order is
        // sequential and we share the SQLite DB; no other test commits a save
        // for this group before this one, but be defensive).
        for (const name of ALLOW_FLAGS) {
            const cb = page.locator(`input[name="${name}"]`);
            if (!(await cb.isChecked())) {
                await cb.check();
            }
        }

        // Move TTL away from its current value so the save is observably
        // distinguishable from a no-op.
        const ttl = page.locator(`select[name="${TTL_FIELD}"]`);
        const before = await ttl.inputValue();
        const next = before === '900' ? '1800' : '900';
        await ttl.selectOption(next);

        await page.locator('#group-step_up button[type=submit]').click();

        // Saving the step-up policy is itself a sudo action: we expect the
        // shared step-up prompt to render before the change is committed.
        await expect(page.locator('[data-step-up-prompt]')).toBeVisible();

        // Default policy → demo admin's only available method is `password`
        // (provider re-auth). The method dropdown only renders when there are
        // ≥ 2 available methods; otherwise a hidden input carries the value.
        const methodSel = page.locator('select[name="_sudo_method"]');
        if (await methodSel.count()) {
            await methodSel.selectOption('password');
        }
        await page.locator('input[name="_sudo_password"]').fill(ADMIN_PASS);
        await page.locator('[data-step-up-section="password"] button[type=submit]').click();

        // Reload and confirm the new TTL persisted.
        await page.goto(appUrl('settings.php?tab=authentication#group-step_up'));
        await expect(page.locator(`select[name="${TTL_FIELD}"]`)).toHaveValue(next);

        // Confirm the audit row exists. audit.php accepts ?action= for an
        // exact match on the action column.
        await page.goto(appUrl('audit.php?action=auth.step_up_policy.updated'));
        await expect(page.locator('table tbody tr').first()).toBeVisible();

        // Restore the original TTL to leave the DB in its starting state.
        // The sudo grant from the first save is still warm (TTL is the new
        // value, ≥ 900s), so this second save short-circuits the prompt.
        await page.goto(appUrl('settings.php?tab=authentication#group-step_up'));
        await page.locator(`select[name="${TTL_FIELD}"]`).selectOption(before);
        await page.locator('#group-step_up button[type=submit]').click();
    });
});
