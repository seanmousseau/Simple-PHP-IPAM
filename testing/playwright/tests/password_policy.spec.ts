/**
 * Playwright tests for password policy UI enforcement (#717).
 * Tests change_password.php and the Settings admin page for password policy.
 *
 * Prerequisite: the containerized IPAM instance must be bootstrapped with
 * demo_seed.php (demo/demo credentials).
 */

import { test, expect } from '@playwright/test';
import { login, logout, ADMIN_USER, ADMIN_PASS, appUrl, fetchPost } from '../fixtures/ipam';

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
        const minLengthInput = page.locator('input[name="password_policy.min_length"]');
        await minLengthInput.fill('14');
        await page.locator('#group-password_policy button[type=submit]').click();
        await page.waitForURL(/settings\.php/);
        await page.goto(appUrl('settings.php#group-password_policy'));
        await expect(page.locator('input[name="password_policy.min_length"]')).toHaveValue('14');
        // Restore to default
        await page.locator('input[name="password_policy.min_length"]').fill('12');
        await page.locator('#group-password_policy button[type=submit]').click();
    });

    test('admin can toggle require_uppercase', async ({ page }) => {
        await page.goto(appUrl('settings.php#group-password_policy'));
        const checkbox = page.locator('input[name="password_policy.require_uppercase"]');
        const wasChecked = await checkbox.isChecked();
        if (!wasChecked) { await checkbox.check(); }
        await page.locator('#group-password_policy button[type=submit]').click();
        await page.goto(appUrl('settings.php#group-password_policy'));
        await expect(page.locator('input[name="password_policy.require_uppercase"]')).toBeChecked();
        // Restore
        await page.locator('input[name="password_policy.require_uppercase"]').uncheck();
        await page.locator('#group-password_policy button[type=submit]').click();
    });
});

// ── Change password — policy enforcement ──────────────────────────────────────

test.describe('change_password.php — policy enforcement', () => {
    test.beforeEach(async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        // Ensure require_uppercase is on for enforcement tests
        await fetchPost(page, appUrl('settings.php'), {
            group: 'password_policy',
            'password_policy.min_length': '12',
            'password_policy.require_uppercase': '1',
        });
    });

    test.afterEach(async ({ page }) => {
        // Restore defaults
        await fetchPost(page, appUrl('settings.php'), {
            group: 'password_policy',
            'password_policy.min_length': '12',
            'password_policy.require_uppercase': '0',
            'password_policy.require_lowercase': '0',
            'password_policy.require_number': '0',
            'password_policy.require_symbol': '0',
        });
        await logout(page);
    });

    test('short password shows inline error', async ({ page }) => {
        await page.goto(appUrl('change_password.php'));
        await page.locator('input[name=current_password]').fill(ADMIN_PASS);
        await page.locator('input[name=new_password]').fill('short');
        await page.locator('input[name=new_password2]').fill('short');
        await page.locator('form button[type=submit]').first().click();
        await expect(page.locator('.danger, .error')).toBeVisible();
        await expect(page.locator('.danger, .error').first()).toContainText(/length|characters|minimum/i);
    });

    test('missing uppercase shows inline error', async ({ page }) => {
        await page.goto(appUrl('change_password.php'));
        await page.locator('input[name=current_password]').fill(ADMIN_PASS);
        await page.locator('input[name=new_password]').fill('alllowercase1!');
        await page.locator('input[name=new_password2]').fill('alllowercase1!');
        await page.locator('form button[type=submit]').first().click();
        await expect(page.locator('.danger, .error').first()).toContainText(/uppercase/i);
    });

    test('policy-compliant password is accepted', async ({ page }) => {
        await page.goto(appUrl('change_password.php'));
        await page.locator('input[name=current_password]').fill(ADMIN_PASS);
        const newPass = 'ValidPassword1!';
        await page.locator('input[name=new_password]').fill(newPass);
        await page.locator('input[name=new_password2]').fill(newPass);
        await page.locator('form button[type=submit]').first().click();
        await expect(page.locator('.success')).toBeVisible();
        // Restore password
        await fetchPost(page, appUrl('change_password.php'), {
            current_password: newPass,
            new_password: ADMIN_PASS,
            new_password2: ADMIN_PASS,
        });
    });
});
