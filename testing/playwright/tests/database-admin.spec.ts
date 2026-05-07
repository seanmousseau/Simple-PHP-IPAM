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
  // CR feedback PR #1054: assert the response actually loaded, not just
  // the URL — toHaveURL alone passes even on a 5xx.
  const response = await page.goto('db_tools.php');
  expect(response, 'page.goto must return a response').not.toBeNull();
  expect(response?.ok()).toBe(true);
  await expect(page).toHaveURL(/db_tools\.php/);
});

test('nav has no separate "Backups" link', async () => {
  await page.goto('db_tools.php');
  const backupsLink = page.locator('.sidebar-link', { hasText: /^Backups$/ });
  await expect(backupsLink).toHaveCount(0);
});

// v3.26.0 (#1059): the in-page Backup History section was removed from
// db_tools.php and moved to backup_admin.php?tab=history. db_tools.php
// now only carries the SQL export/import surface plus a redirect notice
// pointing operators at the unified backup admin.
test('db_tools.php links to the unified Backups admin', async () => {
  await page.goto('db_tools.php');
  // The page header sidebar nav, breadcrumb, and the inline notice all
  // link to backup_admin.php; .first() is enough to confirm at least one
  // visible link is present (the notice is what the operator-facing text
  // points at).
  const link = page.locator('a[href="backup_admin.php"]').first();
  await expect(link).toBeVisible();
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
