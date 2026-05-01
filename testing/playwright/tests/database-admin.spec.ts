/**
 * database-admin.spec.ts
 *
 * Originally introduced for #615 (consolidated Database admin page that
 * absorbed backup history). v3.21.0 Wave 4 #798 retires the sidebar
 * "Database" entry: db_tools.php still works via direct URL, but the
 * sidebar link is gone in favour of the unified Backup & Restore surface.
 * These assertions now lock in the retirement instead of the presence.
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
  await ctx?.close();
});

test('sidebar no longer carries a "Database" link (#798 retirement)', async () => {
  await page.goto('dashboard.php');
  const dbLinks = page.locator('a.sidebar-link[href="db_tools.php"]');
  await expect(dbLinks).toHaveCount(0);
});

test('db_tools.php is still reachable via direct URL', async () => {
  await page.goto('db_tools.php');
  // Page must still load (admins may have bookmarks or external refs); only
  // the sidebar entry retires. 200 + recognisable content is enough.
  await expect(page).toHaveURL(/db_tools\.php/);
});

test('nav has no separate "Backups" link', async () => {
  await page.goto('db_tools.php');
  const backupsLink = page.locator('.sidebar-link', { hasText: /^Backups$/ });
  await expect(backupsLink).toHaveCount(0);
});

test('#backup-history section is present on db_tools.php', async () => {
  await page.goto('db_tools.php');
  const section = page.locator('#backup-history');
  await expect(section).toBeVisible();
});

test('#backup-history section contains "Backup History" heading text', async () => {
  await page.goto('db_tools.php');
  await expect(page.locator('#backup-history')).toContainText('Backup History');
});

test('backups.php redirects to db_tools.php', async ({ browser }) => {
  // Use a separate context so we don't interfere with the shared page
  const redirectCtx = await newAuthContext(browser);
  const redirectPage = await redirectCtx.newPage();
  try {
    await login(redirectPage, ADMIN_USER, ADMIN_PASS);
    await redirectPage.goto('backups.php');
    // After the 301 redirect, the final URL should be db_tools.php
    await expect(redirectPage).toHaveURL(/db_tools\.php/);
  } finally {
    await redirectCtx.close();
  }
});

test('db_tools.php page title contains "Database"', async () => {
  await page.goto('db_tools.php');
  const title = await page.title();
  expect(title.toLowerCase()).toContain('database');
});
