/**
 * Command palette (⌘K / Ctrl+K) — v3.8.0 (#516).
 * Tests: open/close, keyboard nav, search filtering.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { login, newAuthContext, ensureRoUser, ADMIN_USER, ADMIN_PASS, RO_USER, RO_PASS } from '../fixtures/ipam';

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
  await page.keyboard.press('Escape');
  await expect(page.locator('#cmd-palette-bg')).not.toHaveClass(/is-open/);
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

test('Enter on a highlighted result navigates to that page', async () => {
  await page.goto('dashboard.php');
  await page.keyboard.press('Control+k');
  await expect(page.locator('#cmd-palette-bg')).toHaveClass(/is-open/, { timeout: 3000 });
  await page.locator('#cmd-input').fill('subnet');
  // Arrow down to highlight the first .cmd-item result
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Enter');
  await page.waitForURL(/subnets\.php/, { timeout: 5000 });
});

test('"New Subnet" action is visible to admin in palette', async () => {
  await page.goto('dashboard.php');
  await page.keyboard.press('Control+k');
  await expect(page.locator('#cmd-palette-bg')).toHaveClass(/is-open/, { timeout: 3000 });
  await page.locator('#cmd-input').fill('subnet');
  const newSubnetResult = page.locator('.cmd-item').filter({ hasText: /new subnet/i }).first();
  await expect(newSubnetResult).toBeVisible({ timeout: 2000 });
  await page.keyboard.press('Escape');
});

test('readonly user sees navigation commands in palette', async () => {
  // The IPAM_COMMANDS array in app.js is static — no server-side role filtering.
  // Write-action enforcement happens at the page/handler level, not in the palette.
  // This test confirms a readonly user can open the palette and sees navigation commands.

  // Ensure the readonly test user exists (uses admin page context to create if missing)
  await page.goto('dashboard.php');
  await ensureRoUser(page);

  const roCtx = await newAuthContext(page.context().browser()!);
  const roPage = await roCtx.newPage();
  try {
    await login(roPage, RO_USER, RO_PASS);
    await roPage.goto('dashboard.php');
    await roPage.keyboard.press('Control+k');
    await expect(roPage.locator('#cmd-palette-bg')).toHaveClass(/is-open/, { timeout: 3000 });
    await roPage.locator('#cmd-input').fill('subnet');
    // Navigation command "Subnets" is present for readonly users
    await expect(roPage.locator('.cmd-item').filter({ hasText: /subnets/i }).first()).toBeVisible({ timeout: 2000 });
    await roPage.keyboard.press('Escape');
  } finally {
    await roCtx.close();
  }
});
