/**
 * Playwright fan-out spec for the v3.27.0 step-up authentication gate
 * (issue #1114).
 *
 * The point of this spec is *uniformity*: every sensitive admin action that
 * was migrated to ipam_sudo_require() in #1112/#1113 must refuse to proceed
 * without a fresh grant, and a single grant must then satisfy them all
 * within the install TTL. One spec, one user, one session.
 *
 * Endpoints exercised (without a grant):
 *
 *   1. settings_reveal.php  POST  → HTTP 401, JSON {error: 'step_up_required'}
 *   2. api_keys.php         POST action=create     → step-up prompt rendered
 *   3. db_tools.php         POST action=import     → step-up prompt rendered
 *   4. change_password.php  POST action=disable_totp → step-up prompt rendered
 *
 * Then we mint a grant by completing the api_keys round-trip (password proof,
 * default policy → demo admin's only available method). With that grant warm,
 * settings_reveal.php is replayed and now returns HTTP 200 with the stored
 * value (or empty string for an unset key — the contract is the response
 * *shape*, not the secret itself).
 *
 * The destructive endpoints (db_tools import, disable_totp) are NOT replayed
 * with a grant because completing them would mutate state that is awkward to
 * restore between test runs. The negative path (prompt renders without grant)
 * is enough to prove they are wired to the same gate; SudoVerifyTest already
 * covers the positive path at the unit-test layer.
 */

import { test, expect } from '@playwright/test';
import {
    login,
    logout,
    ADMIN_USER,
    ADMIN_PASS,
    appUrl,
    fetchPost,
} from '../fixtures/ipam';

// Any registry key with sensitive=true works here — settings_reveal returns
// {value: ...} (possibly empty string) when granted and 401 when ungated.
const SENSITIVE_KEY = 'smtp.auth_pass';

test.describe('Step-up gate fan-out (#1114)', () => {
    test.beforeEach(async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        // Make sure no warm sudo grant carries over from another spec.
        await page.goto(appUrl('logout.php'));
        await login(page, ADMIN_USER, ADMIN_PASS);
    });

    test.afterEach(async ({ page }) => {
        await logout(page).catch(() => undefined);
    });

    test('settings_reveal returns 401 step_up_required without a grant', async ({ page }) => {
        await page.goto(appUrl('settings.php'));
        const res = await fetchPost(page, appUrl('settings_reveal.php'), {
            key: SENSITIVE_KEY,
        });
        expect(res.status).toBe(401);
        const body = JSON.parse(res.body);
        expect(body.error).toBe('step_up_required');
    });

    test('api_keys create renders the step-up prompt without a grant', async ({ page }) => {
        await page.goto(appUrl('api_keys.php'));
        const res = await fetchPost(page, appUrl('api_keys.php'), {
            action: 'create',
            name: 'pw-fanout-prompt-only',
            description: 'should not actually create — gate must intervene',
        });
        expect(res.status).toBe(200);
        expect(res.body).toContain('data-step-up-prompt');
    });

    test('db_tools import renders the step-up prompt without a grant', async ({ page }) => {
        await page.goto(appUrl('db_tools.php'));
        // db_tools needs a file field for the real handler, but the sudo gate
        // runs before file validation so a bare action=import POST is enough
        // to reach the gate.
        const res = await fetchPost(page, appUrl('db_tools.php'), {
            action: 'import',
        });
        expect(res.status).toBe(200);
        expect(res.body).toContain('data-step-up-prompt');
    });

    test('change_password disable_totp renders the step-up prompt without a grant', async ({ page }) => {
        await page.goto(appUrl('change_password.php'));
        const res = await fetchPost(page, appUrl('change_password.php'), {
            action: 'disable_totp',
        });
        expect(res.status).toBe(200);
        expect(res.body).toContain('data-step-up-prompt');
    });

    test('one grant satisfies the gate fan-out within TTL', async ({ page }) => {
        // Mint a grant by completing the api_keys round-trip end-to-end.
        await page.goto(appUrl('api_keys.php'));
        const keyName = `pw-fanout-grant-${Date.now()}`;
        await page.locator('input[name="name"]').fill(keyName);
        await page.locator('button[name="action"][value="create"], button:has-text("Generate key")').first().click();

        // The first submit should land on the step-up prompt (single available
        // method → method is carried as a hidden input).
        await expect(page.locator('[data-step-up-prompt]')).toBeVisible();
        const methodSel = page.locator('select[name="_sudo_method"]');
        if (await methodSel.count()) {
            await methodSel.selectOption('password');
        }
        await page.locator('input[name="_sudo_password"]').fill(ADMIN_PASS);
        await page.locator('#step-up-form button[type=submit]').click();

        // After the grant lands, the api_keys handler runs the create branch
        // and shows the raw token once. Confirm we're past the prompt.
        await expect(page.locator('[data-step-up-prompt]')).toHaveCount(0);

        // With the grant warm, settings_reveal must now return 200 + a JSON
        // body of the {value: ...} shape — proving the same grant satisfies
        // a second, unrelated sudo-gated endpoint within the install TTL.
        const res = await fetchPost(page, appUrl('settings_reveal.php'), {
            key: SENSITIVE_KEY,
        });
        expect(res.status).toBe(200);
        const body = JSON.parse(res.body);
        expect(body).toHaveProperty('value');
        expect(body).not.toHaveProperty('error');

        // Cleanup: deactivate then delete the API key we created so the test
        // is idempotent across reruns. The grant is still warm so neither
        // action re-prompts (they aren't gated anyway — only `create` is).
        await page.goto(appUrl('api_keys.php'));
        const row = page.locator('tr', { hasText: keyName });
        const deactivate = row.locator('button[name="action"][value="deactivate"]');
        if (await deactivate.count()) {
            await deactivate.first().click();
            await page.waitForLoadState('networkidle');
        }
        const deleteBtn = page.locator('tr', { hasText: keyName })
            .locator('button[name="action"][value="delete"]');
        if (await deleteBtn.count()) {
            page.once('dialog', (d) => d.accept());
            await deleteBtn.first().click();
            await page.waitForLoadState('networkidle');
        }
    });
});
