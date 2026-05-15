/**
 * passkeys.spec.ts — WebAuthn/Passkey 2FA tests (#688, #689, #718)
 *
 * Uses Playwright's built-in virtual authenticator (Chromium only) to simulate
 * hardware security key interactions without real hardware.
 *
 * Prerequisites:
 *   - SEED_PASSKEY_TEST_USER=1 set by bootstrap-app.sh
 *   - mfa.passkeys_enabled=true toggled per-test via settings.php
 *   - Chromium only — Firefox/WebKit do not expose addVirtualAuthenticator
 */

import { test, expect, Page } from '@playwright/test';
import { login, logout, ADMIN_USER, ADMIN_PASS, fetchPost, passStepUpIfPresent } from '../fixtures/ipam';

// Chrome rejects IP addresses as WebAuthn RP IDs. Route passkey tests through
// localhost so the browser origin matches the server's 'localhost' rpId.
const PASSKEY_BASE = (process.env.IPAM_BASE_URL || 'https://localhost:8443')
    .replace('//127.0.0.1', '//localhost');

function pkUrl(path: string): string {
    return `${PASSKEY_BASE}/${path.replace(/^\//, '')}`;
}

const PASSKEY_USER = 'passkey_test_user';
const PASSKEY_PASS = 'Password1!';

function isPasskeySeeded(): boolean {
    return process.env.SEED_PASSKEY_TEST_USER === '1';
}

async function addVirtualAuth(page: Page): Promise<string> {
    const client = await page.context().newCDPSession(page);
    await client.send('WebAuthn.enable', { enableUI: true });
    const { authenticatorId } = await client.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: false,
            hasUserVerification: true,
            isUserVerified: true,
            automaticPresenceSimulation: true,
        },
    });
    return authenticatorId as string;
}

async function removeVirtualAuth(page: Page, authenticatorId: string) {
    const client = await page.context().newCDPSession(page);
    await client.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
    await client.send('WebAuthn.disable');
}

async function enablePasskeys(page: import('@playwright/test').Page) {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(pkUrl('settings.php'));
    // v3.27.2 (#1121): per-key save path was removed. Use the group form
    // and explicitly include every bool field in the group — programmatic
    // POSTs that omit a bool would be treated as unchecked = 0, silently
    // flipping it. Pass passkeys=1 while preserving the v3.x defaults of
    // the sibling MFA bools so tests running after this spec aren't broken.
    await fetchPost(page, pkUrl('settings.php'), {
        group: 'mfa',
        k_mfa__totp_enabled:      '1',
        k_mfa__email_otp_enabled: '0',
        k_mfa__passkeys_enabled:  '1',
        k_mfa__require:           '0',
    });
    await logout(page);
}

async function disablePasskeys(page: import('@playwright/test').Page) {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(pkUrl('settings.php'));
    // v3.27.2 (#1121): group form, all bools explicit. Restore the v3.x
    // defaults (totp on, email_otp off, passkeys off, require off).
    await fetchPost(page, pkUrl('settings.php'), {
        group: 'mfa',
        k_mfa__totp_enabled:      '1',
        k_mfa__email_otp_enabled: '0',
        k_mfa__passkeys_enabled:  '0',
        k_mfa__require:           '0',
    });
    await logout(page);
}

async function deleteAllPasskeys(page: import('@playwright/test').Page) {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(pkUrl('users.php'));
    const userRow = page.locator('tr', { hasText: PASSKEY_USER });
    // Actions are inside a <details> — expand it so buttons become visible.
    await userRow.locator('details').click();
    const resetBtn = userRow.locator('button', { hasText: 'Reset Passkeys' });
    if (await resetBtn.isVisible()) {
        page.once('dialog', d => d.accept());
        await resetBtn.click();
        await page.waitForLoadState('load', { timeout: 15_000 });
    }
    await logout(page);
}

test.describe('Passkeys', () => {
    test.skip(!isPasskeySeeded(), 'SEED_PASSKEY_TEST_USER not set — skipping passkeys suite');
    // Route all navigations through localhost so Chrome accepts the WebAuthn RP ID.
    test.use({ baseURL: PASSKEY_BASE + '/' });

    test.beforeEach(async ({ page }) => {
        await enablePasskeys(page);
        await deleteAllPasskeys(page);
    });

    test.afterEach(async ({ page }) => {
        await deleteAllPasskeys(page);
        await disablePasskeys(page);
    });

    test('Account page shows disabled notice when passkeys are off', async ({ page }) => {
        await disablePasskeys(page);
        await login(page, PASSKEY_USER, PASSKEY_PASS);
        await page.goto(pkUrl('change_password.php'));
        // Consolidated MFA card (#745): the row keeps id="passkeys" and renders a status
        // pill with "Disabled by admin" (or "Unavailable" if the user still has creds)
        // when the global toggle is off, instead of the old standalone-card paragraph.
        await expect(page.locator('#passkeys .mfa-method-pill')).toContainText(/Disabled by admin|Unavailable/);
        await expect(page.locator('#passkeys #btn-add-passkey')).toHaveCount(0);
        await logout(page);
    });

    test('Account page shows Add Passkey button when passkeys enabled', async ({ page }) => {
        await login(page, PASSKEY_USER, PASSKEY_PASS);
        await page.goto(pkUrl('change_password.php'));
        await expect(page.locator('#btn-add-passkey')).toBeVisible();
        await logout(page);
    });

    test('register a passkey via virtual authenticator', async ({ page }) => {
        const authId = await addVirtualAuth(page);

        await login(page, PASSKEY_USER, PASSKEY_PASS);
        await page.goto(pkUrl('change_password.php#passkeys'));
        await expect(page.locator('#btn-add-passkey')).toBeVisible();

        // v3.15.2 removed the JS prompt — name comes from data-default-name.
        // Override the attribute so each test can register a uniquely-named credential.
        await page.locator('#btn-add-passkey').evaluate((el) => el.setAttribute('data-default-name', 'My Test Passkey'));
        await page.locator('#btn-add-passkey').click();

        await expect(page.locator('#passkeys')).toContainText('My Test Passkey', { timeout: 30_000 });
        await logout(page);

        await removeVirtualAuth(page, authId);
    });

    test('login with passkey after registration', async ({ page }) => {
        const authId = await addVirtualAuth(page);

        await login(page, PASSKEY_USER, PASSKEY_PASS);
        await page.goto(pkUrl('change_password.php#passkeys'));
        await page.locator('#btn-add-passkey').evaluate((el) => el.setAttribute('data-default-name', 'Login Test Passkey'));
        await page.locator('#btn-add-passkey').click();
        await expect(page.locator('#passkeys')).toContainText('Login Test Passkey', { timeout: 30_000 });
        await logout(page);

        await page.goto(pkUrl('login.php'));
        await page.locator('[name=username]').fill(PASSKEY_USER);
        await page.locator('[name=password]').fill(PASSKEY_PASS);
        await page.locator('button[type=submit]').click();

        // Virtual authenticator responds automatically
        await page.waitForURL(/dashboard\.php/, { timeout: 30_000 });
        await expect(page).toHaveURL(/dashboard\.php/);
        await logout(page);

        await removeVirtualAuth(page, authId);
    });

    test('invalid passkey assertion is rejected', async ({ page }) => {
        const authId = await addVirtualAuth(page);

        await login(page, PASSKEY_USER, PASSKEY_PASS);
        await page.goto(pkUrl('change_password.php#passkeys'));
        await page.locator('#btn-add-passkey').evaluate((el) => el.setAttribute('data-default-name', 'Reject Test Passkey'));
        await page.locator('#btn-add-passkey').click();
        await expect(page.locator('#passkeys')).toContainText('Reject Test Passkey', { timeout: 30_000 });
        await logout(page);
        await removeVirtualAuth(page, authId);

        // Trigger the passkey challenge, then send garbage data directly
        await page.goto(pkUrl('login.php'));
        await page.locator('[name=username]').fill(PASSKEY_USER);
        await page.locator('[name=password]').fill(PASSKEY_PASS);
        await page.locator('button[type=submit]').click();
        await page.waitForURL(/passkey_verify\.php/, { timeout: 15_000 });

        const csrf = await page.locator('[name=csrf]').inputValue();
        await page.evaluate(async ({ csrf }: { csrf: string }) => {
            await fetch('passkey_verify.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    csrf,
                    clientDataJSON:    btoa('garbage'),
                    authenticatorData: btoa('garbage'),
                    signature:         btoa('garbage'),
                    credentialId:      btoa('garbage'),
                }).toString(),
            });
        }, { csrf });

        await page.reload();
        await expect(page).toHaveURL(/login\.php|passkey_verify\.php/);
    });

    test('admin can view passkey count on users.php', async ({ page }) => {
        const authId = await addVirtualAuth(page);

        await login(page, PASSKEY_USER, PASSKEY_PASS);
        await page.goto(pkUrl('change_password.php#passkeys'));
        await page.locator('#btn-add-passkey').evaluate((el) => el.setAttribute('data-default-name', 'Count Test Passkey'));
        await page.locator('#btn-add-passkey').click();
        await expect(page.locator('#passkeys')).toContainText('Count Test Passkey', { timeout: 30_000 });
        await logout(page);

        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(pkUrl('users.php'));
        const userRow = page.locator('tr', { hasText: PASSKEY_USER });
        await expect(userRow.locator('.badge--success').first()).toBeVisible();
        await logout(page);

        await removeVirtualAuth(page, authId);
    });

    test('user can delete a passkey from Account page', async ({ page }) => {
        const authId = await addVirtualAuth(page);

        await login(page, PASSKEY_USER, PASSKEY_PASS);
        await page.goto(pkUrl('change_password.php#passkeys'));
        await page.locator('#btn-add-passkey').evaluate((el) => el.setAttribute('data-default-name', 'Deletable Passkey'));
        await page.locator('#btn-add-passkey').click();
        await expect(page.locator('#passkeys')).toContainText('Deletable Passkey', { timeout: 30_000 });

        // v3.27.0 (#1107): passkey_delete is gated behind ipam_sudo_verify().
        // The previous inline `current_password` field was removed — clicking
        // Remove now lands on the shared step-up prompt; submit password
        // proof to complete the delete.
        page.once('dialog', d => d.accept());
        await page.locator('#passkeys button[type=submit]').last().click();
        await passStepUpIfPresent(page, PASSKEY_PASS);
        await expect(page.locator('#passkeys')).not.toContainText('Deletable Passkey', { timeout: 15_000 });
        await logout(page);

        await removeVirtualAuth(page, authId);
    });

    test('admin can reset all passkeys for a user', async ({ page }) => {
        const authId = await addVirtualAuth(page);

        await login(page, PASSKEY_USER, PASSKEY_PASS);
        await page.goto(pkUrl('change_password.php#passkeys'));
        await page.locator('#btn-add-passkey').evaluate((el) => el.setAttribute('data-default-name', 'Admin Reset Passkey'));
        await page.locator('#btn-add-passkey').click();
        await expect(page.locator('#passkeys')).toContainText('Admin Reset Passkey', { timeout: 30_000 });
        await logout(page);

        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(pkUrl('users.php'));
        const userRow = page.locator('tr', { hasText: PASSKEY_USER });
        await userRow.locator('details').click();
        const resetBtn = userRow.locator('button', { hasText: 'Reset Passkeys' });
        await expect(resetBtn).toBeVisible();
        page.once('dialog', d => d.accept());
        await resetBtn.click();
        await page.waitForLoadState('load', { timeout: 15_000 });

        await page.goto(pkUrl('users.php'));
        const updatedRow = page.locator('tr', { hasText: PASSKEY_USER });
        await expect(updatedRow.locator('.badge--success')).not.toBeVisible();
        await logout(page);

        await removeVirtualAuth(page, authId);
    });
});
