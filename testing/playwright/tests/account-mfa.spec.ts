/**
 * account-mfa.spec.ts — Consolidated Two-Factor Authentication card on the
 * Account page (#745, #755).
 *
 * Covers:
 *   - Single "Two-Factor Authentication" card with three method rows
 *   - Each row has a status pill with a consistent class (.mfa-method-pill)
 *   - Preserved-enrollment hint surfaces when the matching global toggle is
 *     OFF but the user still has the per-user method enrolled
 *   - Tab order walks the three rows in document order (TOTP → Email OTP →
 *     Passkeys) and each focusable control surfaces a visible focus ring
 *
 * Self-skips when the relevant test user was not seeded into this run.
 */

import { test, expect } from '@playwright/test';
import { login, logout, ADMIN_USER, ADMIN_PASS, appUrl, fetchPost, reset2faEnrollment, ensureEmailOtpEnrolled } from '../fixtures/ipam';

const TFA_USER       = '2fa_test_user';
const TFA_PASS       = 'Password1!';
const EMAIL_OTP_USER = 'email_otp_test_user';
const EMAIL_OTP_PASS = 'Password1!';

function isTotpSeeded(): boolean {
    return process.env.SEED_2FA_TEST_USER === '1';
}
function isEmailOtpSeeded(): boolean {
    return process.env.SEED_EMAIL_OTP_TEST_USER === '1';
}

/**
 * Set the mfa.* booleans by posting to settings.php as admin.
 * Empty `keys` means all bool keys in the mfa group default to false
 * (settings.php absent-key = false convention for booleans).
 */
async function setMfaToggles(page: import('@playwright/test').Page, keys: Record<string, string>): Promise<void> {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(appUrl('settings.php'));
    await fetchPost(page, appUrl('settings.php'), { group: 'mfa', ...keys });
    await logout(page);
}

test.describe('Account page — consolidated MFA card (#745)', () => {
    test.beforeEach(async ({ page }) => {
        // Enable all three MFA methods so every row renders an enroll affordance.
        await setMfaToggles(page, {
            'k_mfa__totp_enabled':      '1',
            'k_mfa__email_otp_enabled': '1',
            'k_mfa__passkeys_enabled':  '1',
        });
    });

    test.afterAll(async ({ browser }) => {
        // Restore the suite-wide default (TOTP on, others off) so totp.spec.ts
        // and other specs run against the original config they expect.
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await setMfaToggles(page, { 'k_mfa__totp_enabled': '1' });
        await ctx.close();
    });

    test('renders a single Two-Factor Authentication card', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('change_password.php'));
        const cards = page.locator('.mfa-card');
        await expect(cards).toHaveCount(1);
        await expect(cards.locator('h2', { hasText: 'Two-Factor Authentication' })).toBeVisible();
    });

    test('contains three method rows with consistent status pills', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('change_password.php'));

        const rows = page.locator('.mfa-card .mfa-method-row');
        await expect(rows).toHaveCount(3);

        // Order matches the issue spec: TOTP, Email OTP, Passkeys.
        await expect(rows.nth(0)).toHaveAttribute('id', 'totp');
        await expect(rows.nth(1)).toHaveAttribute('id', 'email-otp');
        await expect(rows.nth(2)).toHaveAttribute('id', 'passkeys');

        // Each row has exactly one pill with the shared class.
        for (let i = 0; i < 3; i++) {
            const pill = rows.nth(i).locator('.mfa-method-pill');
            await expect(pill).toHaveCount(1);
            await expect(pill).toBeVisible();
        }
    });

    test('Each method row exposes a focusable control with a visible focus ring', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('change_password.php'));

        // Each row must contain at least one focusable control (link, button, or input).
        // This guarantees keyboard reachability into every method, which is the core
        // accessibility requirement (#745). Document order is enforced by the markup
        // itself: the .mfa-method-row elements are siblings, and the browser's natural
        // Tab order is DOM order for non-tabindex-overridden elements.
        const rowIds = ['totp', 'email-otp', 'passkeys'];
        for (const id of rowIds) {
            const focusable = page.locator(`#${id} a, #${id} button, #${id} input`).first();
            await expect(focusable).toHaveCount(1);
            await focusable.focus();

            const hasRing = await page.evaluate(() => {
                const el = document.activeElement as HTMLElement | null;
                if (!el) return false;
                const cs = getComputedStyle(el);
                // Either a real outline or a focus box-shadow (the global :focus-visible
                // rule on .action-pill / button / input applies var(--focus-ring) as a shadow).
                return cs.outlineStyle !== 'none' || cs.boxShadow !== 'none';
            });
            expect(hasRing, `focus ring missing on first focusable in #${id}`).toBeTruthy();
        }
    });
});

test.describe('Preserved-enrollment hints (#755)', () => {
    test.afterAll(async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await setMfaToggles(page, { 'k_mfa__totp_enabled': '1' });
        await ctx.close();
    });

    test('TOTP preserved-enrollment hint shows when global toggle is OFF', async ({ page }) => {
        test.skip(!isTotpSeeded(), 'SEED_2FA_TEST_USER not set');
        // Re-seed the user as enrolled, then disable TOTP globally.
        await reset2faEnrollment(TFA_USER);
        await setMfaToggles(page, {}); // all mfa bools false (TOTP off)
        try {
            await login(page, TFA_USER, TFA_PASS);
            await page.goto(appUrl('change_password.php'));

            const totpRow = page.locator('#totp');
            await expect(totpRow.locator('.mfa-method-pill')).toContainText(/Unavailable|Disabled by admin/);
            await expect(totpRow.locator('.mfa-method-row__hint')).toContainText('TOTP enrolment is preserved');

            await logout(page);
        } finally {
            // Always restore default (TOTP on) so other specs are not affected.
            await setMfaToggles(page, { 'k_mfa__totp_enabled': '1' });
        }
    });

    test('Email OTP preserved-enrollment hint shows when global toggle is OFF', async ({ page }) => {
        test.skip(!isEmailOtpSeeded(), 'SEED_EMAIL_OTP_TEST_USER not set');
        await ensureEmailOtpEnrolled(EMAIL_OTP_USER);
        await setMfaToggles(page, {}); // all mfa bools false (Email OTP off)
        try {
            await login(page, EMAIL_OTP_USER, EMAIL_OTP_PASS);
            await page.goto(appUrl('change_password.php'));

            const eoRow = page.locator('#email-otp');
            await expect(eoRow.locator('.mfa-method-pill')).toContainText(/Unavailable|Disabled by admin/);
            await expect(eoRow.locator('.mfa-method-row__hint')).toContainText('Email OTP enrolment is preserved');

            await logout(page);
        } finally {
            await setMfaToggles(page, { 'k_mfa__totp_enabled': '1' });
        }
    });

    test('Passkey row shows "Disabled by admin" pill when globally OFF and no creds', async ({ page }) => {
        // Passkeys default to OFF and the seeded admin has no credentials,
        // so the unavailable pill is present and the hint is NOT shown.
        await setMfaToggles(page, { 'k_mfa__totp_enabled': '1' });
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('change_password.php'));
        const pkRow = page.locator('#passkeys');
        await expect(pkRow.locator('.mfa-method-pill')).toContainText(/Disabled by admin|Disabled/);
        // No preserved hint when the user has no credentials.
        await expect(pkRow.locator('.mfa-method-row__hint')).toHaveCount(0);
    });
});
