/**
 * Playwright E2E tests for Email OTP 2FA (#684, #715).
 * Tests enrollment on the Account page and mid-login challenge flow.
 *
 * Self-skips when SEED_EMAIL_OTP_TEST_USER is not '1'.
 * Requires: SMTP configured (or a test user without Email OTP for enrollment tests).
 *
 * Uses injectTestOtp() to bypass actual email delivery in the containerized harness.
 */

import { test, expect } from '@playwright/test';
import { login, logout, ADMIN_USER, ADMIN_PASS, appUrl, fetchPost, injectTestOtp, resetEmailOtpEnrollment, ensureEmailOtpEnrolled, setSmtpMailhog } from '../fixtures/ipam';

const EMAIL_OTP_USER = 'email_otp_test_user';
const EMAIL_OTP_PASS = 'Password1!';

function isEmailOtpSeeded(): boolean {
    return process.env.SEED_EMAIL_OTP_TEST_USER === '1';
}

function isMailhogEnabled(): boolean {
    return process.env.IPAM_TEST_MAILHOG === '1';
}

// ── Enrollment flow ───────────────────────────────────────────────────────────

test.describe('Email OTP enrollment', () => {
    test.skip(!isEmailOtpSeeded(), 'SEED_EMAIL_OTP_TEST_USER not set');

    test.beforeEach(async ({ page }) => {
        // Reset test user to unenrolled state before each enrollment test so
        // tests are independent regardless of seed or prior test run order.
        await resetEmailOtpEnrollment(EMAIL_OTP_USER);
        // Restore SMTP to MailHog before each test so enrollment tests have
        // working email delivery regardless of test-file execution order.
        // (alerts-smtp.spec.ts afterAll wipes SMTP settings before this spec runs.)
        if (isMailhogEnabled()) {
            await setSmtpMailhog();
        }
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('settings.php'));
        // Per-key save (#756): enable Email OTP and DISABLE every competing
        // MFA global. EMAIL_OTP_USER may have stale TOTP / passkey enrollments
        // from prior specs; without disabling those globals, login routes to
        // totp_verify.php or passkey_verify.php instead of dashboard, and the
        // goto change_password.php that follows bounces back to login.
        // Pre-#756 the legacy `group=mfa` POST cascade-wiped these sibling
        // bools as a side effect; per-key POST is explicit instead.
        await fetchPost(page, appUrl('settings.php'), { key: 'mfa.email_otp_enabled', value: '1' });
        await fetchPost(page, appUrl('settings.php'), { key: 'mfa.totp_enabled',      value: '0' });
        await fetchPost(page, appUrl('settings.php'), { key: 'mfa.passkeys_enabled',  value: '0' });
        await logout(page);
    });

    test.afterEach(async ({ page }) => {
        // Ensure the test user's session is cleared before logging in as admin.
        await logout(page).catch(() => undefined);
        // Restore: disable email_otp; re-enable totp (the v3.x default and
        // #747's gate so totp.spec.ts running after this file finds TOTP
        // dispatch enabled). Don't touch passkeys — leave at whatever value
        // earlier specs set.
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('settings.php'));
        await fetchPost(page, appUrl('settings.php'), { key: 'mfa.email_otp_enabled', value: '0' });
        await fetchPost(page, appUrl('settings.php'), { key: 'mfa.totp_enabled',      value: '1' });
        await logout(page);
    });

    test('Email OTP section appears on Account page when globally enabled', async ({ page }) => {
        await login(page, EMAIL_OTP_USER, EMAIL_OTP_PASS);
        await page.goto(appUrl('change_password.php'));
        await expect(page.locator('#email-otp')).toBeVisible();
        await logout(page);
    });

    test('enable button triggers enrollment flow', async ({ page }) => {
        test.skip(!isMailhogEnabled(), 'requires IPAM_TEST_MAILHOG=1 (SMTP delivery)');
        await login(page, EMAIL_OTP_USER, EMAIL_OTP_PASS);
        await page.goto(appUrl('change_password.php'));

        await page.locator('#email-otp button[type=submit]').first().click();
        await page.waitForURL(/change_password\.php/);

        // Inject a known code since we can't receive the email
        const code = await injectTestOtp(EMAIL_OTP_USER, '654321');

        // Should now show the verification form
        await expect(page.locator('#email-otp input[name=otp_code]')).toBeVisible();

        await page.locator('#email-otp input[name=otp_code]').fill(code);
        await page.locator('#email-otp button[type=submit]').first().click();

        await expect(page.locator('.success').first()).toBeVisible();
        // v3.16.0 (#745): MFA card uses a status pill instead of literal "active" text.
        await expect(page.locator('#email-otp .mfa-method-pill--enabled')).toBeVisible();
        await logout(page);
    });

    test('wrong code shows error and stays on enrollment', async ({ page }) => {
        test.skip(!isMailhogEnabled(), 'requires IPAM_TEST_MAILHOG=1 (SMTP delivery)');
        await login(page, EMAIL_OTP_USER, EMAIL_OTP_PASS);
        await page.goto(appUrl('change_password.php'));
        await page.locator('#email-otp button[type=submit]').first().click();
        await injectTestOtp(EMAIL_OTP_USER, '654321');

        await page.locator('#email-otp input[name=otp_code]').fill('000000');
        await page.locator('#email-otp button[type=submit]').first().click();

        await expect(page.locator('.danger')).toBeVisible();
        await expect(page.locator('#email-otp input[name=otp_code]')).toBeVisible();
        await logout(page);
    });

    test('disable button removes Email OTP enrollment', async ({ page }) => {
        test.skip(!isMailhogEnabled(), 'requires IPAM_TEST_MAILHOG=1 (SMTP delivery)');
        // First enroll
        await login(page, EMAIL_OTP_USER, EMAIL_OTP_PASS);
        await page.goto(appUrl('change_password.php'));
        await page.locator('#email-otp button[type=submit]').first().click();
        const code = await injectTestOtp(EMAIL_OTP_USER, '111222');
        await page.locator('#email-otp input[name=otp_code]').fill(code);
        await page.locator('#email-otp button[type=submit]').first().click();
        // Now disable — fill required password field, accept confirm dialog, then submit
        await page.locator('#email-otp input[name=current_password]').fill(EMAIL_OTP_PASS);
        page.once('dialog', d => d.accept());
        await page.locator('#email-otp button.button-danger').click();
        await expect(page.locator('#email-otp')).not.toContainText(/active/i);
        await logout(page);
    });
});

// ── Mid-login challenge ───────────────────────────────────────────────────────

test.describe('Email OTP login challenge', () => {
    test.skip(!isEmailOtpSeeded(), 'SEED_EMAIL_OTP_TEST_USER not set');

    test.beforeEach(async ({ page }) => {
        // Ensure test user is enrolled so the login challenge fires.
        await ensureEmailOtpEnrolled(EMAIL_OTP_USER);
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('settings.php'));
        // Enable Email OTP and disable sibling MFA globals so EMAIL_OTP_USER
        // routes specifically to email_otp_verify.php (not totp_verify.php
        // or passkey_verify.php). See enrollment beforeEach for the full
        // explanation of why per-key POST needs explicit sibling disable.
        await fetchPost(page, appUrl('settings.php'), { key: 'mfa.email_otp_enabled', value: '1' });
        await fetchPost(page, appUrl('settings.php'), { key: 'mfa.totp_enabled',      value: '0' });
        await fetchPost(page, appUrl('settings.php'), { key: 'mfa.passkeys_enabled',  value: '0' });
        await logout(page);
    });

    test.afterEach(async ({ page }) => {
        await logout(page).catch(() => undefined);
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('settings.php'));
        // Restore: disable email_otp; re-enable totp (the v3.x default and
        // #747's gate). Don't touch passkeys.
        await fetchPost(page, appUrl('settings.php'), { key: 'mfa.email_otp_enabled', value: '0' });
        await fetchPost(page, appUrl('settings.php'), { key: 'mfa.totp_enabled',      value: '1' });
        await logout(page);
    });

    test('user with Email OTP enrolled is challenged on login', async ({ page }) => {
        test.skip(!isMailhogEnabled(), 'requires IPAM_TEST_MAILHOG=1 (SMTP delivery for login OTP)');
        await page.goto(appUrl('login.php'));
        await page.locator('[name=username]').fill(EMAIL_OTP_USER);
        await page.locator('[name=password]').fill(EMAIL_OTP_PASS);
        await page.locator('button[type=submit]').click();
        await page.waitForURL(url => !url.pathname.endsWith('login.php'), { timeout: 30_000 });
        await expect(page).toHaveURL(/email_otp_verify\.php/);
    });

    test('correct OTP code completes login', async ({ page }) => {
        test.skip(!isMailhogEnabled(), 'requires IPAM_TEST_MAILHOG=1 (SMTP delivery for login OTP)');
        await page.goto(appUrl('login.php'));
        await page.locator('[name=username]').fill(EMAIL_OTP_USER);
        await page.locator('[name=password]').fill(EMAIL_OTP_PASS);
        await page.locator('button[type=submit]').click();
        await page.waitForURL(/email_otp_verify\.php/, { timeout: 30_000 });

        const code = await injectTestOtp(EMAIL_OTP_USER, '333444');
        await page.locator('[name=otp_code]').fill(code);
        await page.locator('button[type=submit]').click();
        await expect(page).toHaveURL(/dashboard\.php/);
        await logout(page);
    });

    test('wrong OTP code stays on challenge page', async ({ page }) => {
        test.skip(!isMailhogEnabled(), 'requires IPAM_TEST_MAILHOG=1 (SMTP delivery for login OTP)');
        await page.goto(appUrl('login.php'));
        await page.locator('[name=username]').fill(EMAIL_OTP_USER);
        await page.locator('[name=password]').fill(EMAIL_OTP_PASS);
        await page.locator('button[type=submit]').click();
        await page.waitForURL(/email_otp_verify\.php/, { timeout: 30_000 });

        await injectTestOtp(EMAIL_OTP_USER, '555666');
        await page.locator('[name=otp_code]').fill('000000');
        await page.locator('button[type=submit]').click();
        await expect(page).toHaveURL(/email_otp_verify\.php/);
        await expect(page.locator('.danger')).toBeVisible();
    });

    test('email_otp_verify.php without session redirects to login', async ({ page }) => {
        await page.goto(appUrl('email_otp_verify.php'));
        await expect(page).toHaveURL(/login\.php/);
    });
});

// ── Admin controls ────────────────────────────────────────────────────────────

test.describe('Email OTP admin controls', () => {
    test.skip(!isEmailOtpSeeded(), 'SEED_EMAIL_OTP_TEST_USER not set');

    test.beforeEach(async ({ page }) => {
        // Ensure test user is enrolled so the admin Reset action is visible.
        await ensureEmailOtpEnrolled(EMAIL_OTP_USER);
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('settings.php'));
        // Admin tests don't log in as EMAIL_OTP_USER so the sibling-disable
        // dance isn't strictly required, but apply the same pattern for
        // consistency with the other beforeEach blocks in this file.
        await fetchPost(page, appUrl('settings.php'), { key: 'mfa.email_otp_enabled', value: '1' });
        await logout(page);
    });

    test.afterEach(async ({ page }) => {
        await logout(page).catch(() => undefined);
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('settings.php'));
        // Per-key save (#756): disable Email OTP without cascading to siblings.
        await fetchPost(page, appUrl('settings.php'), {
            key: 'mfa.email_otp_enabled',
            value: '0',
        });
        await logout(page);
    });

    test('admin sees Reset Email OTP action for enrolled users in users.php', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('users.php'));
        // Reset Email OTP is in a collapsed <details> actions panel — expand the row first
        const userRow = page.locator('table tr').filter({ hasText: EMAIL_OTP_USER });
        await userRow.locator('details').click();
        await expect(page.locator('button', { hasText: 'Reset Email OTP' }).first()).toBeVisible();
        await logout(page);
    });
});
