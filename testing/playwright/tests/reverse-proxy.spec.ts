import { test, expect } from '@playwright/test';

// Skeleton spec for reverse-proxy + password-manager harness (#459).
// Auto-skips unless IPAM_PROXY_MODE=1 is set in the environment.

test.describe('Reverse proxy harness', () => {
  test.beforeEach(() => {
    if (process.env.IPAM_PROXY_MODE !== '1') {
      test.skip();
    }
  });

  test('login page loads correctly behind reverse proxy', async ({ page }) => {
    await page.goto('login.php');
    await expect(page.locator('form[action="login.php"]')).toBeVisible();
  });

  test('HTTPS is enforced behind proxy', async ({ page }) => {
    // Must start from an explicit http:// URL so the redirect is actually exercised.
    const baseUrl = process.env.IPAM_BASE_URL ?? '';
    const httpUrl = baseUrl.replace(/^https:/, 'http:') + '/login.php';
    await page.goto(httpUrl);
    expect(page.url()).toMatch(/^https:/);
  });

  test('password manager autocomplete attributes present', async ({ page }) => {
    await page.goto('login.php');
    const usernameInput = page.locator('input[name="username"]');
    const passwordInput = page.locator('input[type="password"]');
    await expect(usernameInput).toBeVisible();
    await expect(passwordInput).toBeVisible();
    await expect(usernameInput).toHaveAttribute('autocomplete', 'username');
    await expect(passwordInput).toHaveAttribute('autocomplete', 'current-password');
  });
});
