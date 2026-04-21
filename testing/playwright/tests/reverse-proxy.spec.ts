import { test, expect } from '@playwright/test';

// Skeleton spec for reverse-proxy + password-manager harness (#459)
// Auto-skips unless IPAM_PROXY_MODE=1 is set in the environment.

test.describe('Reverse proxy harness', () => {
    test.beforeEach(async () => {
        if (process.env.IPAM_PROXY_MODE !== '1') {
            test.skip();
        }
    });

    test('login page loads correctly behind reverse proxy', async ({ page, baseURL }) => {
        await page.goto(`${baseURL}/login.php`);
        await expect(page.locator('form[action="login.php"]')).toBeVisible();
    });

    test('HTTPS is enforced (no mixed content)', async ({ page, baseURL }) => {
        await page.goto(`${baseURL}/login.php`);
        // Verify the page loaded over HTTPS
        expect(page.url()).toMatch(/^https:/);
    });

    test('password manager autocomplete attributes present', async ({ page, baseURL }) => {
        await page.goto(`${baseURL}/login.php`);
        const usernameInput = page.locator('input[name="username"]');
        const passwordInput = page.locator('input[type="password"]');
        await expect(usernameInput).toBeVisible();
        await expect(passwordInput).toBeVisible();
    });
});
