/**
 * RBAC — readonly user access, admin-only page blocking.
 * Migrated from cdp_test.py section 12.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, logout, fetchPost, appUrl,
  ADMIN_USER, ADMIN_PASS, RO_USER, RO_PASS,
  newAuthContext,
} from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;
let roUserId: number | null = null;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Clean up any leftover readonly user from prior runs
  await page.goto('users.php');
  const existingRoId = await page.evaluate((username) => {
    for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
      const act = f.querySelector<HTMLInputElement>('[name=action]');
      const id  = f.querySelector<HTMLInputElement>('[name=id]');
      if (act?.value === 'delete' && id) {
        const row = f.closest('tr');
        if (row?.innerText.includes(username)) return parseInt(id.value, 10);
      }
    }
    return null;
  }, RO_USER);
  if (existingRoId) {
    await fetchPost(page, appUrl('users.php'), { action: 'delete', id: String(existingRoId) });
  }

  // Create readonly user
  await page.goto('users.php');
  await fetchPost(page, appUrl('users.php'), {
    action: 'create', username: RO_USER, password: RO_PASS,
    name: 'PW Readonly', email: '', role: 'readonly',
  });

  // Capture the readonly user's ID for cleanup
  await page.goto('users.php');
  roUserId = await page.evaluate((username) => {
    for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
      const act = f.querySelector<HTMLInputElement>('[name=action]');
      const id  = f.querySelector<HTMLInputElement>('[name=id]');
      if (act?.value === 'delete' && id) {
        const row = f.closest('tr');
        if (row?.innerText.includes(username)) return parseInt(id.value, 10);
      }
    }
    return null;
  }, RO_USER);
});

test.afterAll(async () => {
  try {
    if (page && roUserId) {
      // Ensure we're logged in as admin (may be in any session state after tests)
      await logout(page).catch(() => undefined);
      await login(page, ADMIN_USER, ADMIN_PASS);
      await page.goto('users.php');
      await fetchPost(page, appUrl('users.php'), { action: 'delete', id: String(roUserId) });
    }
  } finally {
    await ctx?.close();
  }
});

test('readonly user created successfully', async () => {
  await page.goto('users.php');
  await expect(page.getByText(RO_USER)).toBeVisible();
});

test('readonly user can log in', async () => {
  // Must logout from admin session before switching to readonly user
  await logout(page);
  await login(page, RO_USER, RO_PASS);
  expect(page.url()).not.toMatch(/login\.php/);
});

test('readonly can view subnets', async () => {
  await page.goto('subnets.php');
  const title = await page.title();
  expect(title.toLowerCase()).toContain('subnet');
  expect(page.url()).not.toMatch(/login\.php/);
});

test('readonly is blocked from users.php (403)', async () => {
  await page.goto('users.php');
  const body = await page.locator('body').innerText();
  expect(body).toMatch(/forbidden|403/i);
});

test('readonly is blocked from db_tools.php (403)', async () => {
  await page.goto('db_tools.php');
  const body = await page.locator('body').innerText();
  expect(body).toMatch(/forbidden|403/i);
});

test('readonly is blocked from import_csv.php (403)', async () => {
  await page.goto('import_csv.php');
  const body = await page.locator('body').innerText();
  expect(body).toMatch(/forbidden|403/i);
});

test('admin can re-login after readonly session', async () => {
  await logout(page);
  await login(page, ADMIN_USER, ADMIN_PASS);
  expect(page.url()).not.toMatch(/login\.php/);
});
