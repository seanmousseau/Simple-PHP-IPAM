/**
 * Playwright tests for password policy UI enforcement (#717).
 * Tests change_password.php and the Settings admin page for password policy.
 *
 * Prerequisite: the containerized IPAM instance must be bootstrapped with
 * demo_seed.php (demo/demo credentials).
 *
 * Note: settings.php encodes field names as 'k_' + key.replace('.', '__')
 * e.g. password_policy.min_length → k_password_policy__min_length
 */

import { test, expect } from '@playwright/test';
import { login, logout, ADMIN_USER, ADMIN_PASS, appUrl, fetchPost, resetTestPassword } from '../fixtures/ipam';

// ── Settings — password policy admin controls ─────────────────────────────────

test.describe('Settings — password policy', () => {
    test.beforeEach(async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
    });

    test.afterEach(async ({ page }) => {
        await logout(page);
    });

    test('password_policy group renders in Settings', async ({ page }) => {
        await page.goto(appUrl('settings.php'));
        await expect(page.locator('#group-password_policy')).toBeVisible();
    });

    test('admin can set minimum password length', async ({ page }) => {
        await page.goto(appUrl('settings.php#group-password_policy'));
        const minLengthInput = page.locator('input[name="k_password_policy__min_length"]');
        await minLengthInput.fill('14');
        await page.locator('#group-password_policy button[type=submit]').click();
        await page.waitForURL(/settings\.php/);
        await page.goto(appUrl('settings.php#group-password_policy'));
        await expect(page.locator('input[name="k_password_policy__min_length"]')).toHaveValue('14');
        // Restore to default
        await page.locator('input[name="k_password_policy__min_length"]').fill('12');
        await page.locator('#group-password_policy button[type=submit]').click();
    });

    test('admin can toggle require_uppercase', async ({ page }) => {
        await page.goto(appUrl('settings.php#group-password_policy'));
        const checkbox = page.locator('input[name="k_password_policy__require_uppercase"]');
        const wasChecked = await checkbox.isChecked();
        if (!wasChecked) { await checkbox.check(); }
        await page.locator('#group-password_policy button[type=submit]').click();
        await page.goto(appUrl('settings.php#group-password_policy'));
        await expect(page.locator('input[name="k_password_policy__require_uppercase"]')).toBeChecked();
        // Restore
        await page.locator('input[name="k_password_policy__require_uppercase"]').uncheck();
        await page.locator('#group-password_policy button[type=submit]').click();
    });
});

// ── Change password — policy enforcement ──────────────────────────────────────

test.describe('change_password.php — policy enforcement', () => {
    test.beforeEach(async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        // Navigate to settings.php first so getCsrf() finds a valid CSRF token
        await page.goto(appUrl('settings.php'));
        await fetchPost(page, appUrl('settings.php'), {
            group: 'password_policy',
            k_password_policy__min_length: '12',
            k_password_policy__require_uppercase: '1',
        });
    });

    // Known test password used by the compliant-password test.
    // afterEach always tries to restore from this value so cleanup works
    // even if the test body fails mid-way.
    const TEST_PASS = 'ValidPassword1!';

    test.afterEach(async ({ page }) => {
        // 1. Reset password directly in DB (bypasses policy; ADMIN_PASS='demo' is 4
        //    chars and cannot be set via the form while min_length≥8 is enforced).
        await resetTestPassword(ADMIN_USER, ADMIN_PASS);
        // 2. Restore policy defaults (goto ensures a valid CSRF token for fetchPost).
        await page.goto(appUrl('settings.php'));
        await fetchPost(page, appUrl('settings.php'), {
            group: 'password_policy',
            k_password_policy__min_length: '12',
        });
        await logout(page);
    });

    test('short password shows inline error', async ({ page }) => {
        await page.goto(appUrl('change_password.php'));
        await page.locator('input[name=old_password]').fill(ADMIN_PASS);
        await page.locator('input[name=new_password]').fill('short');
        await page.locator('input[name=new_password2]').fill('short');
        await page.locator('form button[type=submit]').first().click();
        await expect(page.locator('.danger, .error')).toBeVisible();
        await expect(page.locator('.danger, .error').first()).toContainText(/length|characters|minimum/i);
    });

    test('missing uppercase shows inline error', async ({ page }) => {
        await page.goto(appUrl('change_password.php'));
        await page.locator('input[name=old_password]').fill(ADMIN_PASS);
        await page.locator('input[name=new_password]').fill('alllowercase1!');
        await page.locator('input[name=new_password2]').fill('alllowercase1!');
        await page.locator('form button[type=submit]').first().click();
        await expect(page.locator('.danger, .error').first()).toContainText(/uppercase/i);
    });

    test('policy-compliant password is accepted', async ({ page }) => {
        await page.goto(appUrl('change_password.php'));
        await page.locator('input[name=old_password]').fill(ADMIN_PASS);
        await page.locator('input[name=new_password]').fill(TEST_PASS);
        await page.locator('input[name=new_password2]').fill(TEST_PASS);
        await page.locator('form button[type=submit]').first().click();
        await expect(page.locator('.success')).toBeVisible();
        // Restore is handled by afterEach after clearing the policy.
    });
});
