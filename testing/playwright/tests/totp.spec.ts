/**
 * TOTP 2FA tests — login with TOTP code, backup code toggle visibility (#625),
 * backup code login, backup code single-use invalidation, admin 2FA reset (#624).
 *
 * Prerequisites:
 *   - SEED_2FA_TEST_USER=1 must have been passed to the seed step so that
 *     the 2fa_test_user account exists with a known TOTP secret and backup codes.
 *   - app_secret must be set in config.php (test-config.php ships it as
 *     'playwright-test-app-secret-for-totp').
 *
 * The spec self-skips when SEED_2FA_TEST_USER is not '1' so that existing CI
 * runs that do not opt in (e.g. ad-hoc runs against a seeded DB without 2FA
 * data) remain clean rather than fail.
 *
 * Known TOTP secret: JBSWY3DPEHPK3PXP (standard RFC 6238 test vector, base32)
 * Known backup codes (seeded in order, first two used by tests in this file):
 *   AAAA1111-BBBB2222  (used by backup-code-login test)
 *   CCCC3333-DDDD4444  (used by single-use-invalidation test)
 *   EEEE5555-FFFF6666 … OOOO5555-PPPP6666  (unused)
 */

import { test, expect } from '@playwright/test';
import { TOTP } from 'otpauth';
import { login, logout, ADMIN_USER, ADMIN_PASS, appUrl, fetchPost } from '../fixtures/ipam';

// ── Constants ────────────────────────────────────────────────────────────────

const TFA_USER   = '2fa_test_user';
const TFA_PASS   = 'Password1!';
const TFA_SECRET = 'JBSWY3DPEHPK3PXP';

// Backup codes seeded by demo_seed.php (in insertion order)
const BACKUP_CODE_1 = 'AAAA1111-BBBB2222';
const BACKUP_CODE_2 = 'CCCC3333-DDDD4444';

// ── Self-skip guard ──────────────────────────────────────────────────────────

/**
 * Returns true when the 2FA test user was seeded into this run's database.
 * Bootstrap sets SEED_2FA_TEST_USER=1 in the environment before running the
 * seeder, and that env var is also available to Playwright at process level.
 */
function is2FaSeeded(): boolean {
    return process.env.SEED_2FA_TEST_USER === '1';
}

// ── TOTP code generator ───────────────────────────────────────────────────────

/**
 * Generate a current TOTP code for the given base32 secret.
 * Uses the same algorithm as robthree/twofactorauth (SHA1, 30-second window, 6 digits).
 */
function totpCode(secret: string): string {
    const totp = new TOTP({ secret, algorithm: 'SHA1', digits: 6, period: 30 });
    return totp.generate();
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Submit the login form with username+password only (no TOTP step yet).
 * Returns the page at totp_verify.php after the password step.
 */
async function loginPasswordStep(
    page: import('@playwright/test').Page,
    username: string,
    password: string,
): Promise<void> {
    await page.goto('login.php');
    await page.waitForSelector('[name=username]', { timeout: 30_000 });
    await page.locator('[name=username]').fill(username);
    await page.locator('[name=password]').fill(password);
    await page.locator('button[type=submit]').click();
    // Wait for navigation away from login.php, then assert we reached the TOTP challenge.
    // If login goes straight to dashboard, TOTP is disabled (DB was left dirty by a prior
    // admin-reset test run — re-run bootstrap-app.sh to restore).
    await page.waitForURL(url => !url.pathname.endsWith('login.php'), { timeout: 30_000 });
    const landed = page.url();
    if (!landed.includes('totp_verify.php')) {
        throw new Error(
            `loginPasswordStep: expected totp_verify.php after ${username} login, got ${landed}. ` +
            'TOTP is likely disabled (totp_enabled=0). Re-run bootstrap-app.sh to restore state: ' +
            'bash testing/playwright/bootstrap-app.sh <driver>',
        );
    }
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test.describe('TOTP 2FA', () => {
    test.beforeEach(({}, testInfo) => {
        if (!is2FaSeeded()) {
            testInfo.skip(true, 'SEED_2FA_TEST_USER is not set — 2FA test user not seeded; skipping');
        }
    });

    // ── 1. Successful TOTP login ─────────────────────────────────────────────

    test('TOTP login: correct code redirects to dashboard', async ({ page }) => {
        await loginPasswordStep(page, TFA_USER, TFA_PASS);

        // Generate a fresh code
        const code = totpCode(TFA_SECRET);
        await page.locator('#totp-code-input').fill(code);
        await page.locator('button[type=submit]').click();

        // Should now be on dashboard (or any page that is not totp_verify / login)
        await page.waitForURL(
            url => !url.pathname.endsWith('totp_verify.php') && !url.pathname.endsWith('login.php'),
            { timeout: 15_000 },
        );
        await expect(page).not.toHaveURL(/totp_verify\.php/);
        await expect(page).not.toHaveURL(/login\.php/);

        await logout(page);
    });

    // ── 2. Wrong TOTP code stays on totp_verify with error ───────────────────

    test('TOTP login: wrong code shows error and stays on verify page', async ({ page }) => {
        await loginPasswordStep(page, TFA_USER, TFA_PASS);

        await page.locator('#totp-code-input').fill('000000');
        await page.locator('button[type=submit]').click();
        await page.waitForLoadState('domcontentloaded');

        await expect(page).toHaveURL(/totp_verify\.php/);
        await expect(page.locator('.danger')).toBeVisible();
    });

    // ── 3. Backup code toggle visibility (#625 regression) ──────────────────

    test('#625 backup code toggle: shows backup input and hides TOTP input', async ({ page }) => {
        await loginPasswordStep(page, TFA_USER, TFA_PASS);

        // Initially: TOTP code row visible, backup code row hidden
        await expect(page.locator('#totp-code-row')).toBeVisible();
        await expect(page.locator('#backup-code-row')).toBeHidden();

        // Backup code input is disabled initially
        await expect(page.locator('#backup-code-input')).toBeDisabled();

        // Click the toggle link
        await page.locator('#toggle-backup').click();

        // After toggle: TOTP row hidden, backup row visible
        await expect(page.locator('#totp-code-row')).toBeHidden();
        await expect(page.locator('#backup-code-row')).toBeVisible();

        // Backup code input must now be enabled (regression: #625 — input stayed disabled)
        await expect(page.locator('#backup-code-input')).toBeEnabled();

        // use_backup hidden field should be set to '1'
        const useBackupVal = await page.locator('#use-backup-hidden').inputValue();
        expect(useBackupVal).toBe('1');

        // Toggle back: TOTP row should return to visible, backup row hidden
        await page.locator('#toggle-backup').click();
        await expect(page.locator('#totp-code-row')).toBeVisible();
        await expect(page.locator('#backup-code-row')).toBeHidden();
        await expect(page.locator('#backup-code-input')).toBeDisabled();

        const useBackupValAfter = await page.locator('#use-backup-hidden').inputValue();
        expect(useBackupValAfter).toBe('0');
    });

    // ── 4. Backup code login ─────────────────────────────────────────────────

    test('backup code login: valid code redirects to dashboard', async ({ page }) => {
        await loginPasswordStep(page, TFA_USER, TFA_PASS);

        // Toggle to backup code mode
        await page.locator('#toggle-backup').click();
        await expect(page.locator('#backup-code-input')).toBeEnabled();

        await page.locator('#backup-code-input').fill(BACKUP_CODE_1);
        await page.locator('button[type=submit]').click();

        await page.waitForURL(
            url => !url.pathname.endsWith('totp_verify.php') && !url.pathname.endsWith('login.php'),
            { timeout: 15_000 },
        );
        await expect(page).not.toHaveURL(/totp_verify\.php/);
        await expect(page).not.toHaveURL(/login\.php/);

        await logout(page);
    });

    // ── 5. Backup code single-use invalidation ───────────────────────────────

    test('backup code: once used, same code is rejected on second attempt', async ({ page }) => {
        // First use: should succeed
        await loginPasswordStep(page, TFA_USER, TFA_PASS);
        await page.locator('#toggle-backup').click();
        await page.locator('#backup-code-input').fill(BACKUP_CODE_2);
        await page.locator('button[type=submit]').click();

        await page.waitForURL(
            url => !url.pathname.endsWith('totp_verify.php') && !url.pathname.endsWith('login.php'),
            { timeout: 15_000 },
        );
        await logout(page);

        // Second use of the same code: should fail
        await loginPasswordStep(page, TFA_USER, TFA_PASS);
        await page.locator('#toggle-backup').click();
        await page.locator('#backup-code-input').fill(BACKUP_CODE_2);
        await page.locator('button[type=submit]').click();
        await page.waitForLoadState('domcontentloaded');

        await expect(page).toHaveURL(/totp_verify\.php/);
        await expect(page.locator('.danger')).toBeVisible();
    });

    // ── 6. Admin 2FA reset (#624 regression verify) ──────────────────────────

    test('#624 admin 2FA reset: clears 2FA for another user and allows password-only login', async ({ page }) => {
        // Log in as admin
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto('users.php');

        // Find the row for 2fa_test_user and open the actions menu
        const rows = await page.locator('table tbody tr').all();
        let targetRow: import('@playwright/test').Locator | null = null;
        for (const row of rows) {
            const text = await row.innerText();
            if (text.includes(TFA_USER)) {
                targetRow = row;
                break;
            }
        }
        expect(targetRow).not.toBeNull();

        // Open the <details> actions menu and click "Reset 2FA"
        const details = targetRow!.locator('details');
        await details.click();

        // The reset form has action=reset_totp; click its submit button
        const resetForm = targetRow!.locator('form').filter({ hasText: 'Reset 2FA' });
        // Accept any confirm() dialog that may appear
        page.once('dialog', d => d.accept());
        await resetForm.locator('button[type=submit]').click();
        await page.waitForURL(/users\.php/, { timeout: 30_000 });

        await logout(page);

        // Now try to log in as 2fa_test_user — should go straight to dashboard (no TOTP step)
        await page.goto('login.php');
        await page.waitForSelector('[name=username]', { timeout: 30_000 });
        await page.locator('[name=username]').fill(TFA_USER);
        await page.locator('[name=password]').fill(TFA_PASS);
        await page.locator('button[type=submit]').click();

        // Should NOT redirect to totp_verify.php
        await page.waitForURL(
            url => !url.pathname.endsWith('login.php'),
            { timeout: 30_000 },
        );
        await expect(page).not.toHaveURL(/totp_verify\.php/);
        await expect(page).not.toHaveURL(/login\.php/);

        await logout(page);
    });
});
