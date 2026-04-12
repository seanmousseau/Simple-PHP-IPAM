/**
 * Scan History page (scan_history.php) — v2.3.0 (#321)
 *
 * Tests: page loads for any logged-in user, subnet selector is present,
 * empty-state message shown when no scan results exist, and the page is
 * reachable via the "Scan History" action pill on subnets.php.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchPost, appUrl,
  ADMIN_USER, ADMIN_PASS,
  TEST_SCAN_CIDR,
  RO_USER, RO_PASS,
  newAuthContext, subnetIdFor, ensureRoUser,
} from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;
let testSubnetId = 0;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
  await ensureRoUser(page);

  // Ensure test subnet exists
  await page.goto('subnets.php');
  let existingId = await subnetIdFor(page, TEST_SCAN_CIDR);
  if (!existingId) {
    await fetchPost(page, appUrl('subnets.php'), {
      action:          'create',
      cidr:            TEST_SCAN_CIDR,
      description:     'scan history spec test subnet',
      confirm_overlap: '1',
    });
    await page.goto('subnets.php');
    existingId = await subnetIdFor(page, TEST_SCAN_CIDR);
  }
  testSubnetId = existingId ?? 0;
});

test.afterAll(async () => {
  if (testSubnetId > 0) {
    await fetchPost(page, appUrl('subnets.php'), {
      action: 'delete',
      id:     String(testSubnetId),
    });
  }
  await ctx?.close();
});

test('scan_history.php loads for admin user', async () => {
  await page.goto('scan_history.php');
  await expect(page).toHaveTitle(/Scan History/i);
  await expect(page.locator('h1')).toContainText('Scan History');
});

test('scan history page shows subnet selector', async () => {
  await page.goto('scan_history.php');
  await expect(page.locator('select[name="subnet_id"]')).toBeVisible();
});

test('scan history shows empty-state when no scans have run', async () => {
  if (testSubnetId <= 0) test.skip();
  await page.goto(`scan_history.php?subnet_id=${testSubnetId}`);
  await expect(page.locator('text=/No scan results yet/')).toBeVisible();
});

test('scan history page is accessible to read-only users', async ({ browser }: { browser: Browser }) => {
  const roCtx = await newAuthContext(browser);
  const roPage = await roCtx.newPage();
  await login(roPage, RO_USER, RO_PASS);
  await roPage.goto('scan_history.php');
  await expect(roPage).toHaveTitle(/Scan History/i);
  await roCtx.close();
});

test('subnets.php shows Scan History action pill', async () => {
  await page.goto('subnets.php');
  // At least one Scan History pill should be present
  const pills = page.locator('a.action-pill', { hasText: 'Scan History' });
  await expect(pills.first()).toBeVisible();
});

test('Scan History pill links to scan_history.php with subnet_id', async () => {
  if (testSubnetId <= 0) test.skip();
  await page.goto('subnets.php');
  const pill = page.locator(`a.action-pill[href*="scan_history.php?subnet_id=${testSubnetId}"]`);
  await expect(pill.first()).toBeVisible();
});
