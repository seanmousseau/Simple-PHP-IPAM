/**
 * Settings (v2.6.0) — admin page at settings.php backed by the new `settings`
 * table and ipam_setting*() helpers. Covers the happy path: an admin can
 * navigate to the page, edit a branding field, save it, see the source badge
 * flip from "config.php" or "Default" to "Database", and confirm the change
 * shows up in the audit log as a `setting.update` entry.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, appUrl, ADMIN_USER, ADMIN_PASS,
  newAuthContext,
} from '../fixtures/ipam';

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

test.describe('Settings page', () => {
  test('loads under Admin dropdown for admin users', async () => {
    await page.goto(appUrl('settings.php'));
    await expect(page.locator('h1')).toContainText('Settings');
    // Every group card should render.
    for (const label of ['Branding', 'Security', 'Alerting', 'Update checker', 'OIDC']) {
      await expect(page.locator('.card h2', { hasText: label }).first()).toBeVisible();
    }
  });

  test('admin can save a branding change and the source badge flips to Database', async () => {
    await page.goto(appUrl('settings.php'));

    const field = page.locator('input[name="k_branding__site_name"]');
    await expect(field).toBeVisible();

    const newValue = `IPAM Test ${Date.now()}`;
    await field.fill(newValue);

    // Submit the Branding group form.
    const brandingCard = page.locator('.card', { has: page.locator('h2', { hasText: 'Branding' }) });
    await brandingCard.locator('button[type="submit"]').click();

    // After redirect, the field value should reflect the new save...
    await expect(page.locator('input[name="k_branding__site_name"]')).toHaveValue(newValue);
    // ...and the row's source badge should now read "Database".
    const row = page.locator('label', { hasText: 'Application name' }).first();
    await expect(row).toContainText('Database');
  });

  test('saving a setting produces a setting.update audit entry', async () => {
    await page.goto(appUrl('audit.php'));
    // Most recent entries appear near the top; a setting.update entry must exist.
    await expect(page.locator('body')).toContainText('setting.update');
  });
});
