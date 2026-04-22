import { test, expect } from '@playwright/test';
import { login, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';

test.describe('TOTP enrollment page', () => {
  test('totp_enroll.php redirects to login when unauthenticated', async ({ page }) => {
    await page.goto('totp_enroll.php');
    await expect(page).toHaveURL(/login\.php/);
  });
});

test.describe('TOTP verify page', () => {
  test('totp_verify.php redirects to login when no pending session', async ({ page }) => {
    await page.goto('totp_verify.php');
    await expect(page).toHaveURL(/login\.php/);
  });
});

test.describe('Users admin page', () => {
  test('users.php shows 2FA column header', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto('users.php');
    await expect(page.locator('table th', { hasText: '2FA' })).toBeVisible();
  });
});

test.describe('API rate limit', () => {
  test('API returns 401 on unauthenticated request (not 429)', async ({ request }) => {
    const resp = await request.get('api.php?resource=subnets');
    // Without a valid API key we must always get 401, never 429.
    expect(resp.status()).toBe(401);
  });
});
