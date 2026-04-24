/**
 * reports.php regression guards — utilization history page (#668).
 *
 * Covers:
 *   - Page loads with "Utilization History" heading
 *   - Subnet filter select is present
 *   - Days filter select is present with expected options
 *   - CSV export link is visible and points to export_utilization_history.php
 *   - CSV export endpoint returns 200 with text content-type
 *   - Empty-state message shown when no snapshot data exists
 *   - Breadcrumb renders correctly
 *   - Readonly user is blocked (403)
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, logout, fetchGet, appUrl, newAuthContext, ensureRoUser,
  ADMIN_USER, ADMIN_PASS, RO_USER, RO_PASS, adminTest,
} from '../fixtures/ipam';

// ── Shared authenticated session for non-adminTest tests ─────────────────────
let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
});

test.afterAll(async () => {
  await ctx?.close().catch(() => undefined);
});

// ── Page structure ────────────────────────────────────────────────────────────

test('reports page loads with Utilization History heading', async () => {
  await page.goto(appUrl('reports.php'));
  await expect(page.locator('h1')).toContainText('Utilization History');
});

test('reports page has subnet filter select', async () => {
  await page.goto(appUrl('reports.php'));
  const select = page.locator('select#rep-subnet');
  await expect(select).toBeVisible();
  // The "(all subnets)" option is always present
  await expect(select.locator('option[value=""]')).toContainText('all subnets');
});

test('reports page has days filter select with expected options', async () => {
  await page.goto(appUrl('reports.php'));
  const select = page.locator('select#rep-days');
  await expect(select).toBeVisible();
  // Verify a representative set of day-range options are rendered
  const values = await select.locator('option').evaluateAll(
    (opts: HTMLOptionElement[]) => opts.map(o => o.value),
  );
  expect(values).toContain('7');
  expect(values).toContain('30');
  expect(values).toContain('90');
  expect(values).toContain('365');
});

test('reports page has visible CSV export link', async () => {
  await page.goto(appUrl('reports.php'));
  const exportLink = page.locator('a.action-pill[href*="export_utilization_history"]');
  await expect(exportLink).toBeVisible();
  await expect(exportLink).toContainText('Export CSV');
});

// ── CSV export endpoint ───────────────────────────────────────────────────────

test('export_utilization_history returns 200 with text content-type', async () => {
  await page.goto(appUrl('reports.php'));
  const r = await fetchGet(page, appUrl('export_utilization_history.php'));
  expect(r.status).toBe(200);
  expect(r.contentType.toLowerCase()).toContain('text');
  // Header row must always be present even when there is no data
  expect(r.body.toLowerCase()).toContain('subnet');
});

// ── Empty state ───────────────────────────────────────────────────────────────

test('reports page shows empty-state or data table (no crash on fresh install)', async () => {
  await page.goto(appUrl('reports.php'));
  // Either the empty-state div OR a data table must be present — never neither
  const hasEmptyState = await page.locator('.empty-state').count();
  const hasTable      = await page.locator('table').count();
  expect(hasEmptyState + hasTable, 'expected empty-state or data table to be present').toBeGreaterThan(0);
});

// ── Breadcrumb ────────────────────────────────────────────────────────────────

adminTest.describe('reports breadcrumb', () => {
  adminTest('breadcrumb renders Dashboard > Reports', async ({ adminPage: p }) => {
    await p.goto('reports.php');
    const bc = p.locator('.breadcrumbs');
    await expect(bc).toBeVisible();
    await expect(bc.locator('a[href="dashboard.php"]')).toContainText('Dashboard');
    await expect(bc.locator('span').last()).toContainText('Reports');
  });
});

// ── Access control ────────────────────────────────────────────────────────────

test('readonly user is blocked from reports.php (403)', async ({ browser }: { browser: Browser }) => {
  // Ensure the readonly user exists (admin session is available via the shared `page`)
  await ensureRoUser(page);

  const roCtx  = await newAuthContext(browser);
  const roPage = await roCtx.newPage();
  try {
    await login(roPage, RO_USER, RO_PASS);
    const response = await roPage.goto(appUrl('reports.php'));
    // Primary guard: assert HTTP 403 status
    expect(response?.status()).toBe(403);
    // Secondary guard: page body confirms forbidden
    const bodyText = await roPage.locator('body').innerText();
    expect(bodyText).toMatch(/forbidden|403/i);
  } finally {
    await logout(roPage).catch(() => undefined);
    await roCtx.close().catch(() => undefined);
  }
});
