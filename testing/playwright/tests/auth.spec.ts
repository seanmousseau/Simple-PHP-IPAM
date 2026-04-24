/**
 * Authentication tests — login/logout, bad credentials, session protection.
 * Migrated from cdp_test.py section 1.
 */
import { test, expect } from '@playwright/test';
import { login, logout, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';

test.describe('Authentication', () => {
  test('login page renders required fields', async ({ page }) => {
    await page.goto('login.php');
    await expect(page.locator('[name=username]')).toBeVisible();
    await expect(page.locator('[name=password]')).toBeVisible();
    await expect(page.locator('button[type=submit]')).toBeVisible();
  });

  test('bad credentials: stays on login, shows error', async ({ page }) => {
    await page.goto('login.php');
    await page.locator('[name=username]').fill('admin');
    await page.locator('[name=password]').fill('wrongpass!');
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page).toHaveURL(/login\.php/);
    await expect(page.locator('.danger')).toBeVisible();
  });

  test('unauthenticated visit to protected page redirects to login', async ({ page }) => {
    // Ensure no active session first
    await page.goto('logout.php').catch(() => undefined);
    await page.goto('subnets.php');
    await expect(page).toHaveURL(/login\.php/);
  });

  test('correct credentials: redirects to dashboard', async ({ page }) => {
    const path = await (async () => {
      await login(page, ADMIN_USER, ADMIN_PASS);
      return page.url();
    })();
    expect(path).not.toMatch(/login\.php/);
    await logout(page);
  });

  test('logout destroys session', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await logout(page);
    await page.goto('dashboard.php');
    await expect(page).toHaveURL(/login\.php/);
  });

  test('reCAPTCHA v3: data-recaptcha-action attribute present on hidden input', async ({ page }) => {
    await page.goto('login.php');
    // The hidden reCAPTCHA v3 input renders data-recaptcha-action when reCAPTCHA is configured.
    // When reCAPTCHA is NOT configured, the input is absent — skip gracefully.
    const rv3Input = page.locator('[data-recaptcha-action]');
    const count = await rv3Input.count();
    if (count === 0) {
      test.skip(true, 'reCAPTCHA v3 not configured on this instance — skipping action attribute check');
      return;
    }
    const action = await rv3Input.getAttribute('data-recaptcha-action');
    expect(typeof action).toBe('string');
    expect(action!.length).toBeGreaterThan(0);
  });

  test('login page has no sidebar', async ({ page }) => {
    await page.goto('login.php');
    await expect(page.locator('#sidebar')).not.toBeAttached();
    // Login form should still be visible
    await expect(page.locator('[name=username]')).toBeVisible();
  });

  test('forgot_password page has no sidebar', async ({ page }) => {
    await page.goto('forgot_password.php');
    await expect(page.locator('#sidebar')).not.toBeAttached();
  });

  test('reset_password page has no sidebar', async ({ page }) => {
    await page.goto('reset_password.php');
    await expect(page.locator('#sidebar')).not.toBeAttached();
  });
});
