/**
 * Settings (v2.6.0) — admin page at settings.php backed by the new `settings`
 * table and ipam_setting*() helpers. The shared SQLite Playwright runner means
 * every test must clean up after itself (try/finally restore) and every
 * assertion must be self-sufficient rather than depend on a prior test.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, appUrl, ADMIN_USER, ADMIN_PASS,
  newAuthContext,
} from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;

const SITE_NAME_FIELD = 'input[name="k_branding__site_name"]';
const BRANDING_CARD   = '.card:has(h2:has-text("Branding"))';

async function brandingSubmit(p: Page): Promise<void> {
  await p.locator(`${BRANDING_CARD} button[type="submit"]`).click();
  // Wait for the redirect back to settings.php to complete.
  await p.waitForURL(/settings\.php/);
}

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
});

test.afterAll(async () => {
  await ctx.close();
});

test.describe('Settings page', () => {
  test('loads under Admin dropdown for admin users', async () => {
    await page.goto(appUrl('settings.php'));
    await expect(page.locator('h1')).toContainText('Settings');
    for (const label of ['Branding', 'Security', 'Alerting', 'Update checker', 'OIDC']) {
      await expect(page.locator('.card h2', { hasText: label }).first()).toBeVisible();
    }
  });

  test('admin can save a branding change and the source badge flips to Database', async () => {
    await page.goto(appUrl('settings.php'));
    const field = page.locator(SITE_NAME_FIELD);
    await expect(field).toBeVisible();

    const original = (await field.inputValue()) ?? '';
    const newValue = `IPAM Test ${Date.now()}`;

    try {
      await field.fill(newValue);
      await brandingSubmit(page);

      await expect(page.locator(SITE_NAME_FIELD)).toHaveValue(newValue);
      const row = page.locator('label', { hasText: 'Application name' }).first();
      await expect(row).toContainText('Database');
    } finally {
      // Always put the field back so later specs see the seeded value.
      await page.goto(appUrl('settings.php'));
      await page.locator(SITE_NAME_FIELD).fill(original);
      await brandingSubmit(page);
    }
  });

  test('saving a setting produces a setting.update audit entry', async () => {
    // Self-sufficient: create a fresh setting.update here rather than relying
    // on the prior test's side effect.
    await page.goto(appUrl('settings.php'));
    const field = page.locator(SITE_NAME_FIELD);
    const original = (await field.inputValue()) ?? '';
    const marker = `audit-check-${Date.now()}`;

    try {
      await field.fill(marker);
      await brandingSubmit(page);

      await page.goto(appUrl('audit.php'));
      await expect(page.locator('body')).toContainText('setting.update');
    } finally {
      await page.goto(appUrl('settings.php'));
      await page.locator(SITE_NAME_FIELD).fill(original);
      await brandingSubmit(page);
    }
  });
});
