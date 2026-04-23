/**
 * Command palette (⌘K / Ctrl+K) — v3.8.0 (#516).
 * Tests: open/close, keyboard nav, search filtering.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { login, newAuthContext, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('dashboard.php');
});

test.afterAll(async () => {
  await ctx.close();
});

test('opens on Ctrl+K', async () => {
  await page.keyboard.press('Control+k');
  await expect(page.locator('#cmd-palette-bg')).toHaveClass(/is-open/, { timeout: 3000 });
  // clean up
  await page.keyboard.press('Escape');
});

test('closes on Escape', async () => {
  await page.keyboard.press('Control+k');
  await expect(page.locator('#cmd-palette-bg')).toHaveClass(/is-open/, { timeout: 3000 });
  await page.keyboard.press('Escape');
  await expect(page.locator('#cmd-palette-bg')).not.toHaveClass(/is-open/);
});

test('typing "dash" shows Dashboard command', async () => {
  await page.keyboard.press('Control+k');
  await expect(page.locator('#cmd-palette-bg')).toHaveClass(/is-open/, { timeout: 3000 });
  await page.locator('.cmd-input').fill('dash');
  await expect(page.locator('.cmd-item').filter({ hasText: 'Dashboard' })).toBeVisible();
  await page.keyboard.press('Escape');
});
