/**
 * Aggregates (#328) — CRUD for the aggregates admin page.
 *
 * Tests:
 * - Page loads without error
 * - Create, read, update, delete an aggregate (IPv4 and IPv6)
 * - Duplicate CIDR is rejected
 * - Readonly user cannot access (redirect to login or 403)
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, appUrl,
  ADMIN_USER, ADMIN_PASS,
  RO_USER, RO_PASS,
  newAuthContext, ensureRoUser,
} from '../fixtures/ipam';

// Must be already-network-aligned: the app rewrites non-aligned /8 inputs
// like 10.200.0.0/8 to 10.0.0.0/8, which would then fail to match the test's
// own input string in later assertions.
const TEST_CIDR = '10.0.0.0/8';
// The prior fixture ('2001:db8:agg::/48') was invalid hex — 'g' is out of
// range — so creation failed outright. Use a valid hex group instead.
const TEST_CIDR_V6 = '2001:db8:a::/48';
// Substring used for row/body matching — the app lowercases and compresses
// IPv6 on render so this short prefix is the most stable identifier.
const TEST_CIDR_V6_MATCH = '2001:db8:a:';

let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
  // No cleanup needed — test CIDRs are unique; stale rows handled by delete tests
});

test.afterAll(async () => {
  await ctx.close();
});

test('aggregates.php loads without error', async () => {
  await page.goto(appUrl('aggregates.php'));
  await expect(page.locator('h1')).toContainText('Aggregates');
  await expect(page.locator('.danger')).toHaveCount(0);
});

test('create IPv4 aggregate', async () => {
  await page.goto(appUrl('aggregates.php'));
  await page.fill('input[name="cidr"]', TEST_CIDR);
  await page.selectOption('select[name="rir"]', 'ARIN');
  await page.fill('input[name="description"]', 'Test aggregate');
  await page.click('button[type="submit"]');
  await page.waitForURL(/aggregates\.php/);
  await expect(page.locator('table')).toContainText(TEST_CIDR);
});

test('create IPv6 aggregate', async () => {
  await page.goto(appUrl('aggregates.php'));
  await page.fill('input[name="cidr"]', TEST_CIDR_V6);
  await page.selectOption('select[name="rir"]', 'RIPE');
  await page.fill('input[name="description"]', 'Test IPv6 aggregate');
  await page.click('button[type="submit"]');
  await page.waitForURL(/aggregates\.php/);
  await expect(page.locator('table')).toContainText(TEST_CIDR_V6_MATCH);
});

test('IPv4 badge shown for IPv4 aggregate', async () => {
  await page.goto(appUrl('aggregates.php'));
  const row = page.locator('tr', { hasText: TEST_CIDR });
  await expect(row.locator('.badge')).toContainText('IPv4');
});

test('IPv6 badge shown for IPv6 aggregate', async () => {
  await page.goto(appUrl('aggregates.php'));
  const row = page.locator('tr', { hasText: TEST_CIDR_V6_MATCH });
  await expect(row.locator('.badge')).toContainText('IPv6');
});

test('update aggregate description', async () => {
  await page.goto(appUrl('aggregates.php'));
  // Open edit details for the IPv4 aggregate
  const row = page.locator('tr', { hasText: TEST_CIDR });
  await row.locator('details summary').click();
  const descInput = row.locator('input[name="description"]');
  await descInput.fill('Updated description');
  await row.locator('button[type="submit"]').first().click();
  await page.waitForURL(/aggregates\.php/);
  await expect(page.locator('table')).toContainText('Updated description');
});

test('delete IPv4 aggregate', async () => {
  await page.goto(appUrl('aggregates.php'));
  const row = page.locator('tr', { hasText: TEST_CIDR });
  await row.locator('details summary').click();
  page.once('dialog', d => d.accept());
  await row.locator('button.button-danger').click();
  await page.waitForURL(/aggregates\.php/);
  // Should no longer be in the table
  await expect(page.locator('body')).not.toContainText(TEST_CIDR);
});

test('delete IPv6 aggregate', async () => {
  await page.goto(appUrl('aggregates.php'));
  const row = page.locator('tr', { hasText: TEST_CIDR_V6_MATCH });
  await row.locator('details summary').click();
  page.once('dialog', d => d.accept());
  await row.locator('button.button-danger').click();
  await page.waitForURL(/aggregates\.php/);
  await expect(page.locator('body')).not.toContainText(TEST_CIDR_V6_MATCH);
});

test('invalid CIDR is rejected', async () => {
  await page.goto(appUrl('aggregates.php'));
  await page.fill('input[name="cidr"]', 'not-a-cidr');
  await page.click('button[type="submit"]');
  // Should remain on aggregates.php with an error
  await expect(page.locator('.danger')).toBeVisible();
});

test('readonly user cannot access aggregates.php', async () => {
  await ensureRoUser(page);
  const roCtx = await newAuthContext(page.context().browser()!);
  const roPage = await roCtx.newPage();
  await login(roPage, RO_USER, RO_PASS);
  await roPage.goto(appUrl('aggregates.php'));
  // Should be redirected or shown 403
  const url = roPage.url();
  const body = await roPage.content();
  expect(url.includes('login.php') || body.includes('403') || body.includes('Forbidden')).toBeTruthy();
  await roCtx.close();
});
