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
});
