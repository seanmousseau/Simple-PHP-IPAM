/**
 * Playwright tests for the vault-key step-up flow (issues #1110 + #1111,
 * regressions captured by issue #1098).
 *
 * Covers:
 *
 *   1. The "Reveal vault key" button is a primary, always-visible control —
 *      it MUST NOT be buried inside a <details> disclosure (the v3.26.0 bug
 *      that motivated #1111).
 *
 *   2. End-to-end reveal flow under the default policy:
 *      - admin lands on backup_admin.php?tab=destinations
 *      - if no vault key exists, sets one via vault_set + step-up + password
 *        proof (and the raw generated key flashes exactly once)
 *      - clicks Reveal → step-up prompt → password proof → raw key flashes
 *        exactly once again (a different request, fresh one-shot reveal)
 *
 * The spec is robust to the SQLite test container's starting state: if a
 * vault key is already present from a prior run, vault_set is skipped and we
 * exercise reveal directly. Either way the assertions about the Reveal
 * button's DOM placement and the one-shot reveal contract hold.
 */

import { test, expect } from '@playwright/test';
import {
    login,
    logout,
    ADMIN_USER,
    ADMIN_PASS,
    appUrl,
    purgeEncryptedBackupRuns,
} from '../fixtures/ipam';

const VAULT_PAGE = 'backup_admin.php?tab=destinations';

// Pass through the step-up prompt by submitting the password proof. The demo
// admin's only available method under default policy is `password` (provider
// re-auth), so the method dropdown is rendered as a hidden input — no
// selection required, just fill the password and submit.
async function passStepUpWithPassword(page: import('@playwright/test').Page) {
    await expect(page.locator('[data-step-up-prompt]')).toBeVisible();
    const methodSel = page.locator('select[name="_sudo_method"]');
    if (await methodSel.count()) {
        await methodSel.selectOption('password');
    }
    await page.locator('input[name="_sudo_password"]').fill(ADMIN_PASS);
    await page.locator('[data-step-up-section="password"] button[type=submit]').click();
}

test.describe('Vault key — step-up flow (#1110, #1111)', () => {
    test.beforeEach(async ({ page }) => {
        // Earlier specs (backup-integration etc.) leave encrypted backup_runs
        // behind, which would block the vault_set + generate path here with
        // the CR #1100 "encrypted backups exist" guard. Purge them so this
        // spec's preconditions match what it was written against.
        await purgeEncryptedBackupRuns();
        // Cycle the session so any warm sudo grant from another spec is gone.
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('logout.php'));
        await login(page, ADMIN_USER, ADMIN_PASS);
    });

    test.afterEach(async ({ page }) => {
        await logout(page).catch(() => undefined);
    });

    test('Reveal vault key button is NOT buried inside <details>', async ({ page }) => {
        await page.goto(appUrl(VAULT_PAGE));

        // The Reveal button only renders when a vault key is present. If the
        // container has no key yet, mint one first so we can assert button
        // placement in the rendered DOM.
        const fp = page.locator('[data-test="vault-fingerprint"]');
        if (await fp.count() === 0) {
            await page.locator('[data-test="vault-set-submit"]').click();
            await passStepUpWithPassword(page);
            // After vault_set the page redirects back with the raw key shown.
            // Reload to drop the one-shot raw-key flash and reach steady state.
            await page.goto(appUrl(VAULT_PAGE));
            await expect(fp).toBeVisible();
        }

        // The button must be visible AND not nested inside any <details>.
        const reveal = page.locator('[data-test="vault-reveal-submit"]');
        await expect(reveal).toBeVisible();
        await expect(page.locator('details [data-test="vault-reveal-submit"]')).toHaveCount(0);
    });

    test('Reveal flow flashes the raw vault key exactly once via step-up', async ({ page }) => {
        await page.goto(appUrl(VAULT_PAGE));

        // Ensure a vault key exists. If we set it here, capture the generated
        // key from the one-shot flash so we can compare against the reveal
        // result below; if a prior run already set one, we just verify that
        // reveal flashes a 44-char base64 key (32 raw bytes encoded).
        let keyFromSet: string | null = null;
        if (await page.locator('[data-test="vault-fingerprint"]').count() === 0) {
            await page.locator('[data-test="vault-set-submit"]').click();
            await passStepUpWithPassword(page);
            const flashed = page.locator('[data-test="vault-revealed-key"]');
            await expect(flashed).toBeVisible();
            keyFromSet = (await flashed.textContent() ?? '').trim();
            expect(keyFromSet.length).toBeGreaterThanOrEqual(43); // base64(32 bytes) ≈ 44 chars
        }

        // Reload to clear the one-shot flash; raw key must NOT persist across
        // the GET that follows the POST.
        await page.goto(appUrl(VAULT_PAGE));
        await expect(page.locator('[data-test="vault-revealed-key"]')).toHaveCount(0);

        // Click Reveal. Even though we passed step-up moments ago for vault_set,
        // the spec re-prompts here to exercise the gate cleanly: we've
        // re-logged in via beforeEach AFTER vault_set, so any warm grant is
        // gone. The reveal is a fresh sudo action.
        await page.locator('[data-test="vault-reveal-submit"]').click();
        await passStepUpWithPassword(page);

        const revealed = page.locator('[data-test="vault-revealed-key"]');
        await expect(revealed).toBeVisible();
        const keyFromReveal = (await revealed.textContent() ?? '').trim();
        expect(keyFromReveal.length).toBeGreaterThanOrEqual(43);

        if (keyFromSet !== null) {
            expect(keyFromReveal).toBe(keyFromSet);
        }

        // One-shot contract: after a reload, the raw key block is gone.
        await page.goto(appUrl(VAULT_PAGE));
        await expect(page.locator('[data-test="vault-revealed-key"]')).toHaveCount(0);
        await expect(page.locator('[data-test="vault-fingerprint"]')).toBeVisible();
    });
});
