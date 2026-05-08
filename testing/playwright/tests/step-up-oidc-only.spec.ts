/**
 * Playwright regression test for the originating bug behind v3.27.0
 * (issue #1098): an OIDC-only admin (oidc_sub set, password_hash='!disabled')
 * was unable to reveal a vault key because the gate required a local
 * password they don't have. The fix decouples step-up from the login
 * provider and validates each non-password method against its own enrollment.
 *
 * Two end-to-end scenarios:
 *
 *   1. OIDC-only admin + TOTP enrolled, default policy
 *      → vault reveal succeeds via TOTP step-up proof.
 *
 *   2. OIDC-only admin + no MFA, allow_provider_reauth=false
 *      → step-up prompt renders the actionable "no method available"
 *        error end-to-end. (The lock-out guard would normally block this
 *        policy in the UI; we tighten via direct ipam_setting_set() to
 *        prove the prompt partial degrades gracefully if an install
 *        somehow ends up in this state.)
 *
 * Fixtures used:
 *   seed_oidc_only_admin.php   creates the user
 *   mint_test_session.php      writes a session file Apache can read
 *   set_test_setting.php       force-sets the install-wide policy
 */

import { test, expect } from '@playwright/test';
import { TOTP } from 'otpauth';
import { APP_BASE } from '../playwright.config';
import {
    HTTP_CREDENTIALS,
    seedOidcOnlyAdmin,
    mintTestSession,
    setTestSetting,
    appUrl,
    purgeEncryptedBackupRuns,
} from '../fixtures/ipam';

const OIDC_TOTP_USER  = 'pw-oidc-only-totp';
const OIDC_NO_MFA_USER = 'pw-oidc-only-no-mfa';
const TFA_SECRET       = 'JBSWY3DPEHPK3PXP'; // RFC 6238 test vector

function totpCode(secret: string): string {
    return new TOTP({ secret, algorithm: 'SHA1', digits: 6, period: 30 }).generate();
}

function cookieDomain(): string {
    // Strip scheme and any path; use just the host so the cookie is sent
    // back on every request to the test server.
    return new URL(APP_BASE).hostname;
}

test.describe('Step-up — OIDC-only admin (#1098 regression)', () => {
    test('OIDC-only admin with TOTP can reveal vault via TOTP step-up', async ({ browser }) => {
        // Earlier specs (backup-integration etc.) leave encrypted backup_runs
        // behind, which would block vault_set + generate with the CR #1100
        // "encrypted backups exist" guard before this spec ever reaches the
        // step-up gate it is meant to exercise.
        await purgeEncryptedBackupRuns();
        await seedOidcOnlyAdmin(OIDC_TOTP_USER, 'with-totp');

        const ctx = await browser.newContext({
            httpCredentials: HTTP_CREDENTIALS,
            ignoreHTTPSErrors: true,
        });

        async function attachSession(): Promise<void> {
            const s = await mintTestSession(OIDC_TOTP_USER);
            await ctx.addCookies([{
                name: s.cookieName,
                value: s.sid,
                domain: cookieDomain(),
                path: '/',
                httpOnly: true,
                secure: true,
                sameSite: 'Strict',
            }]);
        }

        await attachSession();
        const page = await ctx.newPage();

        // Confirm the minted session is accepted: dashboard should render
        // without a 302 to login.php.
        await page.goto(appUrl('dashboard.php'));
        await expect(page).not.toHaveURL(/login\.php/);

        // Ensure a vault key exists. If we have to set one, the vault_set
        // POST mints a sudo grant that would short-circuit the reveal prompt
        // we want to exercise next, so re-mint the session to drop any warm
        // grant before the regression-target reveal step.
        await page.goto(appUrl('backup_admin.php?tab=destinations'));
        if (await page.locator('[data-test="vault-fingerprint"]').count() === 0) {
            await page.locator('[data-test="vault-set-submit"]').click();
            await expect(page.locator('[data-step-up-prompt]')).toBeVisible();
            const m1 = page.locator('select[name="_sudo_method"]');
            if (await m1.count()) await m1.selectOption('totp');
            await page.locator('input[name="_sudo_code"]').fill(totpCode(TFA_SECRET));
            await page.locator('[data-step-up-section="totp"] button[type=submit]').click();
            // CR round-3 #1116: explicitly assert the vault was set before
            // moving on. If the TOTP proof was silently rejected (clock
            // skew, rate-limit, etc.) the prompt re-renders with an error,
            // the vault key isn't created, and the next click for
            // `vault-reveal-submit` times out with a misleading error.
            // Fail here with a clear message instead.
            await expect(
                page.locator('[data-test="vault-revealed-key"], [data-test="vault-fingerprint"]').first(),
                'vault_set + TOTP step-up did not produce a vault key',
            ).toBeVisible({ timeout: 15_000 });
            // Re-mint to drop the warm grant.
            await attachSession();
        }

        // Now exercise reveal under default policy via TOTP proof. This is
        // the explicit regression scenario: a user with no local password
        // (password_hash='!disabled') successfully passes the gate.
        await page.goto(appUrl('backup_admin.php?tab=destinations'));
        await page.locator('[data-test="vault-reveal-submit"]').click();

        await expect(page.locator('[data-step-up-prompt]')).toBeVisible();
        const methodSel = page.locator('select[name="_sudo_method"]');
        if (await methodSel.count()) await methodSel.selectOption('totp');
        await page.locator('input[name="_sudo_code"]').fill(totpCode(TFA_SECRET));
        await page.locator('[data-step-up-section="totp"] button[type=submit]').click();

        // The raw vault key flashes exactly once.
        await expect(page.locator('[data-test="vault-revealed-key"]')).toBeVisible();

        await ctx.close();
    });

    test('OIDC-only admin with no MFA + provider_reauth=false sees no-methods error', async ({ browser }) => {
        await seedOidcOnlyAdmin(OIDC_NO_MFA_USER, 'no-mfa');

        // Force the policy past the lock-out guard by writing the setting
        // directly. Save current value first so we can restore it — a stuck
        // false would strand every admin and break later specs.
        await setTestSetting('auth.step_up.allow_provider_reauth', 'false');

        // The OIDC-only-no-MFA admin we just seeded would itself strand the
        // lockout check on the next spec that saves the step-up policy
        // (default policy can't satisfy them on installs without OIDC
        // configured — like the test container). Deactivate them in
        // `finally` so they no longer appear in the active-admins query.
        try {
            const session = await mintTestSession(OIDC_NO_MFA_USER);
            const ctx = await browser.newContext({
                httpCredentials: HTTP_CREDENTIALS,
                ignoreHTTPSErrors: true,
            });
            await ctx.addCookies([{
                name: session.cookieName,
                value: session.sid,
                domain: cookieDomain(),
                path: '/',
                httpOnly: true,
                secure: true,
                sameSite: 'Strict',
            }]);
            const page = await ctx.newPage();

            await page.goto(appUrl('backup_admin.php?tab=destinations'));
            await expect(page).not.toHaveURL(/login\.php/);

            // Trigger the gate. Even if no vault key exists yet, the vault_set
            // form is gated identically to vault_reveal — both paths route
            // through ipam_sudo_require() which then renders the prompt
            // partial. The "no methods available" branch is what we're
            // asserting here (prompt partial line 117–123).
            const target = await page.locator('[data-test="vault-reveal-submit"]').count() > 0
                ? page.locator('[data-test="vault-reveal-submit"]')
                : page.locator('[data-test="vault-set-submit"]');
            await target.first().click();

            await expect(page.locator('[data-step-up-prompt]')).toBeVisible();
            // The actionable error text from views/_step_up_prompt.php:117–123.
            await expect(
                page.locator('[data-step-up-prompt]'),
            ).toContainText(/No re-authentication method is available/i);

            // The form must NOT render any submittable proof input — the
            // "no methods" branch only renders a Cancel link.
            await expect(page.locator('#step-up-form')).toHaveCount(0);

            await ctx.close();
        } finally {
            // Always restore so subsequent specs/runs aren't stranded.
            await setTestSetting('auth.step_up.allow_provider_reauth', 'true');
            // Deactivate the seeded OIDC-only-no-MFA admin so the lockout
            // check on later policy saves doesn't see them as a stranded
            // active admin.
            await seedOidcOnlyAdmin(OIDC_NO_MFA_USER, 'deactivate').catch(() => undefined);
        }
    });
});
