/**
 * Right-side drawer UX — v3.8.0 (#517).
 * Tests: Add Subnet drawer open/close, title content.
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

test('Add Subnet button opens drawer', async () => {
  await page.goto('subnets.php');
  await page.click('[data-drawer-title="Add Subnet"]');
  await expect(page.locator('#global-drawer')).toHaveClass(/is-open/, { timeout: 3000 });
  await expect(page.locator('#global-drawer .drawer-title')).toContainText('Add Subnet');
});

test('Escape closes drawer', async () => {
  await page.goto('subnets.php');
  await page.click('[data-drawer-title="Add Subnet"]');
  await expect(page.locator('#global-drawer')).toHaveClass(/is-open/, { timeout: 3000 });
  await page.keyboard.press('Escape');
  await expect(page.locator('#global-drawer')).not.toHaveClass(/is-open/);
});
