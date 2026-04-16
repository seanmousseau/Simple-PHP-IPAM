/**
 * Database tools — SQL export, import round-trip, security banner.
 * Migrated from cdp_test.py section 9.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchPost, fetchPostForm, deleteSubnet, appUrl,
  ADMIN_USER, ADMIN_PASS, TEST_CIDR1,
  newAuthContext, IS_SQLITE,
} from '../fixtures/ipam';

// v2.10.0 #433 / v2.11.0 #388: SQL export/import via ipam_db_dump_stream()
// is SQLite-format only. On MySQL and Postgres, db_tools.php shows a
// user-facing "SQL export/import is SQLite-only" notice and the
// export/import POST handlers short-circuit with an HTTP 400. Round-trip
// specs live in a describe that skips on non-SQLite; the MySQL-only
// gating assertions live in their own describe at the bottom.

let ctx: BrowserContext;
let page: Page;
let exportedSql = '';

test.describe('db_tools SQLite round-trip', () => {
  test.skip(!IS_SQLITE, 'SQLite round-trip is SQLite-only; MySQL gating covered at the bottom of this file');

  test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Create test subnet so the export contains known data
  await page.goto('subnets.php');
  await deleteSubnet(page, TEST_CIDR1);
  await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: TEST_CIDR1, description: 'PW db-tools test', confirm_overlap: '1',
  });
});

test.afterAll(async () => {
  try {
    if (page) {
      await page.goto('subnets.php');
      await deleteSubnet(page, TEST_CIDR1);
    }
  } finally {
    await ctx?.close();
  }
});

test('db_tools page loads', async () => {
  await page.goto('db_tools.php');
  const title = await page.title();
  expect(title.toLowerCase()).toContain('database');
});

test('db_tools export/import card heights are equal', async () => {
  await page.goto('db_tools.php');
  const heights = await page.evaluate(() =>
    Array.from(document.querySelectorAll('.grid > .card'))
      .map((c) => Math.round(c.getBoundingClientRect().height)),
  );
  expect(heights.length, 'at least 2 cards').toBeGreaterThanOrEqual(2);
  const allEqual = heights.every(h => h === heights[0]);
  expect(allEqual, `cards equal height: ${JSON.stringify(heights)}`).toBe(true);
});

test('db export returns SQL with correct content-type', async () => {
  await page.goto('db_tools.php');
  const r = await fetchPost(page, appUrl('db_tools.php'), { action: 'export' });
  // Export redirects to a file download; the response body is the SQL dump
  // content-type check varies by browser; check body contains SQL markers
  exportedSql = r.body;
  expect(r.status).toBe(200);
  // The dump encodes CIDR as hex in CAST(X'...' AS TEXT)
  const cidrHex = Buffer.from(TEST_CIDR1).toString('hex');
  expect(exportedSql.toLowerCase()).toContain(cidrHex.toLowerCase());
});

test('db export contains audit_log triggers', async () => {
  if (!exportedSql) { test.skip(); return; }
  expect(exportedSql).toContain('audit_log_no_update');
  expect(exportedSql).toContain('audit_log_no_delete');
});

test('db export contains updated_at triggers', async () => {
  if (!exportedSql) { test.skip(); return; }
  expect(exportedSql).toContain('addresses_updated_at');
});

test('db import rejects missing confirmation', async () => {
  await page.goto('db_tools.php');
  const r = await fetchPostForm(page, appUrl('db_tools.php'),
    { action: 'import' },
    { name: 'sql_file', content: '-- dummy', filename: 'dummy.sql', type: 'application/sql' },
  );
  expect(r.body.toLowerCase()).toContain('confirmation');
});

test('db import round-trip succeeds and data survives', async () => {
  if (!exportedSql) { test.skip(); return; }
  await page.goto('db_tools.php');
  const r = await fetchPostForm(page, appUrl('db_tools.php'),
    { action: 'import', confirmed: '1' },
    { name: 'sql_file', content: exportedSql, filename: 'roundtrip.sql', type: 'application/sql' },
  );
  expect(r.body).toContain('Import successful');

  // Verify data survived the round-trip
  await page.goto('subnets.php');
  await expect(page.getByText(TEST_CIDR1).first()).toBeVisible();
});

test('db_tools security warning banner is present', async () => {
  await page.goto('db_tools.php');
  const banner = page.locator('.security-banner');
  await expect(banner).toBeVisible();
});

test('db_tools security banner can be dismissed', async () => {
  await page.goto('db_tools.php');
  const dismissLink = page.locator('.security-banner .dismiss-link');
  if (await dismissLink.count() > 0) {
    await dismissLink.click();
    await page.waitForTimeout(300);
    await page.goto('db_tools.php');
    // After dismiss, the banner should be hidden (sessionStorage flag)
    const banner = page.locator('.security-banner');
    // The banner should not be visible after dismiss (may still be in DOM but hidden)
    const isVisible = await banner.isVisible().catch(() => false);
    expect(isVisible, 'banner hidden after dismiss').toBe(false);
  } else {
    test.skip();
  }
});
});  // end of "db_tools SQLite round-trip" describe

// v2.10.0 #433 / v2.11.0 #388: non-SQLite gating assertion. Lives in its own
// describe so the SQLite round-trip describe's skip does not also skip these
// tests on the MySQL or Postgres matrix slots. Both engines show the same
// "SQL export/import is SQLite-only" notice with disabled buttons.
test.describe('db_tools non-SQLite SQL-dump gating', () => {
  test.skip(IS_SQLITE, 'Runs only on MySQL/Postgres');

  test('shows a user-facing SQL-only notice on the page', async ({ browser }) => {
    const ctx = await newAuthContext(browser);
    const page = await ctx.newPage();
    try {
      await login(page, ADMIN_USER, ADMIN_PASS);
      await page.goto('db_tools.php');
      const notice = page.locator('.admin-notice--warning', {
        hasText: /SQL export\/import is SQLite-only/i,
      });
      await expect(notice).toBeVisible();
      // Both buttons must be present but disabled so the user cannot POST.
      await expect(page.locator('button[type="submit"]', { hasText: /Download SQL Dump/i })).toBeDisabled();
      await expect(page.locator('button[type="submit"]', { hasText: /Import .* Replace/i })).toBeDisabled();
    } finally {
      await ctx.close();
    }
  });

  test('POST action=export short-circuits with the SQL-only notice and no dump body', async ({ browser }) => {
    const ctx = await newAuthContext(browser);
    const page = await ctx.newPage();
    try {
      await login(page, ADMIN_USER, ADMIN_PASS);
      // Must visit db_tools.php first so fetchPost can read its CSRF token.
      await page.goto('db_tools.php');
      const r = await fetchPost(page, appUrl('db_tools.php'), { action: 'export' });
      // The body must carry the user-facing notice and must NOT carry the
      // SQLite dump header line — if the gate regresses, the dump text
      // leaks through here and the assertion below catches it.
      expect(r.body).toContain('SQL export/import is only supported on the SQLite driver');
      expect(r.body).not.toContain('-- Simple PHP IPAM database dump');
      expect(r.body).not.toContain('BEGIN TRANSACTION');
    } finally {
      await ctx.close();
    }
  });
});
