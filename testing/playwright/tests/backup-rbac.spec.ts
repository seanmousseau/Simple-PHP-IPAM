/**
 * RBAC — Backup & Restore unified admin surface (#811, T10).
 *
 * Enumerates every page and POST handler introduced by F1 / F3 / F11 / F12
 * and asserts:
 *   - readonly user GET → 403
 *   - readonly user POST → 403 (or login redirect for the API path)
 *   - logged-out user → redirect to login
 *
 * Pairs with tests/BackupAdminRbacTest.php which lints that
 * require_role('admin') is present at the top of every entry point.
 *
 * Adding a new tab to backup_admin.php?tab=… or a new POST handler
 * under the backup surface must add an entry to TABS / POST_HANDLERS
 * below — otherwise the role guard for the new surface is untested.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, logout, fetchPost, appUrl,
  ADMIN_USER, ADMIN_PASS, RO_USER, RO_PASS,
  newAuthContext,
} from '../fixtures/ipam';

// Every tab whitelisted by backup_admin.php's $tabs array. Keep in sync
// with the array — BackupAdminRbacTest::testBackupAdminTabsAreEnumerated
// fails the build if a tab is added there without being added here.
const TABS = ['backup', 'restore', 'destinations', 'notifications', 'history'] as const;

// POST handlers introduced by the unified surface. backup_admin.php
// itself accepts POSTs for each tab (csrf-required, admin-required);
// run_backup_now.php is the AJAX endpoint behind the Run-now button.
const POST_HANDLERS: Array<{ path: string; form: Record<string, string> }> = [
  { path: 'backup_admin.php?tab=destinations', form: { action: 'create', name: 'rbac-test', kind: 'local' } },
  { path: 'backup_admin.php?tab=notifications', form: { action: 'save', notify_on_failure: '1' } },
  { path: 'run_backup_now.php', form: { destination_id: '1' } },
];

let ctx: BrowserContext;
let page: Page;
let roUserId: number | null = null;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Clean up any leftover readonly user from prior runs.
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

  await page.goto('users.php');
  await fetchPost(page, appUrl('users.php'), {
    action: 'create', username: RO_USER, password: RO_PASS,
    name: 'PW Readonly RBAC', email: '', role: 'readonly',
  });

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

  await logout(page);
  await login(page, RO_USER, RO_PASS);
});

test.afterAll(async () => {
  try {
    if (page && roUserId) {
      await logout(page).catch(() => undefined);
      await login(page, ADMIN_USER, ADMIN_PASS);
      await page.goto('users.php');
      await fetchPost(page, appUrl('users.php'), { action: 'delete', id: String(roUserId) });
    }
  } finally {
    await ctx?.close();
  }
});

for (const tab of TABS) {
  test(`readonly is blocked from backup_admin.php?tab=${tab} (403)`, async () => {
    const resp = await page.request.get(appUrl(`backup_admin.php?tab=${tab}`), { maxRedirects: 0 });
    expect(resp.status()).toBe(403);
  });
}

test('readonly is blocked from run_backup_now.php (403)', async () => {
  const resp = await page.request.post(appUrl('run_backup_now.php'), {
    form: { destination_id: '1' },
    maxRedirects: 0,
  });
  expect(resp.status()).toBe(403);
});

for (const handler of POST_HANDLERS) {
  test(`readonly POST to ${handler.path} is blocked (403)`, async () => {
    const resp = await page.request.post(appUrl(handler.path), {
      form: handler.form,
      maxRedirects: 0,
    });
    expect(resp.status()).toBe(403);
  });
}

test('readonly is blocked from legacy backup_history.php (403)', async () => {
  const resp = await page.request.get(appUrl('backup_history.php'), { maxRedirects: 0 });
  expect(resp.status()).toBe(403);
});

test('readonly is blocked from legacy restore_web.php (403)', async () => {
  const resp = await page.request.get(appUrl('restore_web.php'), { maxRedirects: 0 });
  expect(resp.status()).toBe(403);
});

// CR feedback PR #1054: cover the new drawer-partial endpoints introduced
// in v3.21.0. Structural lint in tests/BackupAdminRbacTest.php asserts the
// require_role('admin') guard is present in source; the HTTP-level checks
// below confirm the guard actually rejects readonly callers in practice.
const DRAWER_ENDPOINTS = [
  'backup_run_detail.php?id=1',
  'destination_edit_drawer.php?id=1&form=destination',
  'destination_edit_drawer.php?id=1&form=schedule',
] as const;

for (const path of DRAWER_ENDPOINTS) {
  test(`readonly is blocked from ${path} (403)`, async () => {
    const resp = await page.request.get(appUrl(path), { maxRedirects: 0 });
    expect(resp.status()).toBe(403);
  });
}

test('logged-out user is redirected from backup_admin.php to login', async () => {
  // Use a fresh context with no session so we exercise the unauthenticated path.
  const anonCtx = await page.context().browser()!.newContext({ ignoreHTTPSErrors: true });
  try {
    const anonPage = await anonCtx.newPage();
    const resp = await anonPage.goto(appUrl('backup_admin.php'));
    expect(anonPage.url()).toMatch(/login\.php/);
    expect(resp?.status()).toBeLessThan(400);
  } finally {
    await anonCtx.close();
  }
});
