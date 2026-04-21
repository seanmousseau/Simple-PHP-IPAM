import { test, expect } from '@playwright/test';

// Helper: login as admin
async function loginAsAdmin(page: any, baseURL: string, adminUser: string, adminPass: string) {
    await page.goto(`${baseURL}/login.php`);
    const csrf = await page.locator('input[name="csrf"]').inputValue();
    await page.fill('input[name="username"]', adminUser);
    await page.fill('input[name="password"]', adminPass);
    await page.click('button[type="submit"]');
    await page.waitForURL(/dashboard\.php/);
}

test.describe('TOTP enrollment page', () => {
    test('totp_enroll.php redirects to login when unauthenticated', async ({ page, baseURL }) => {
        await page.goto(`${baseURL}/totp_enroll.php`);
        await expect(page).toHaveURL(/login\.php/);
    });
});

test.describe('TOTP verify page', () => {
    test('totp_verify.php redirects to login when no pending session', async ({ page, baseURL }) => {
        await page.goto(`${baseURL}/totp_verify.php`);
        await expect(page).toHaveURL(/login\.php/);
    });
});

test.describe('Users admin page', () => {
    test('users.php shows 2FA column header', async ({ page, baseURL }) => {
        const adminUser = process.env.IPAM_ADMIN_USER || 'demo';
        const adminPass = process.env.IPAM_ADMIN_PASS || 'demo';
        await loginAsAdmin(page, baseURL as string, adminUser, adminPass);
        await page.goto(`${baseURL}/users.php`);
        await expect(page.locator('table th', { hasText: '2FA' })).toBeVisible();
    });
});

test.describe('API rate limit', () => {
    test('API returns 200 or 401 on normal single request (not 429)', async ({ request, baseURL }) => {
        const resp = await request.get(`${baseURL}/api.php?resource=subnets`);
        // Without a valid API key we expect 401, not 429
        expect([200, 401]).toContain(resp.status());
    });
});
