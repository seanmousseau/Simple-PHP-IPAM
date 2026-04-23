/**
 * Sidebar navigation — v3.8.0 Enterprise Gateway pattern (#512).
 * Tests: visibility at desktop/mobile, hamburger open/close, active link.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { login, newAuthContext, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
});

test.afterAll(async () => {
  await ctx.close();
});

test('sidebar is visible at 1280px viewport', async () => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto('dashboard.php');
  await expect(page.locator('#sidebar')).toBeVisible();
  await expect(page.locator('.topbar--mobile')).toBeHidden();
});

test('sidebar is hidden and hamburger visible at 768px', async () => {
  await page.setViewportSize({ width: 768, height: 800 });
  await page.goto('dashboard.php');
  // Sidebar hides via CSS transform (not display:none), so check it has no is-open class
  await expect(page.locator('#sidebar')).not.toHaveClass(/is-open/);
  await expect(page.locator('#sidebar-open')).toBeVisible();
});

test('hamburger opens sidebar', async () => {
  await page.setViewportSize({ width: 768, height: 800 });
  await page.goto('dashboard.php');
  await page.click('#sidebar-open');
  await expect(page.locator('#sidebar')).toHaveClass(/is-open/);
});

test('Escape closes sidebar', async () => {
  await page.setViewportSize({ width: 768, height: 800 });
  await page.goto('dashboard.php');
  await page.click('#sidebar-open');
  await page.keyboard.press('Escape');
  await expect(page.locator('#sidebar')).not.toHaveClass(/is-open/);
});

test('sidebar active link matches current page', async () => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto('subnets.php');
  const activeLink = page.locator('.sidebar-link.is-active');
  await expect(activeLink).toContainText('Subnets');
});
