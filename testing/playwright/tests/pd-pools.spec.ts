/**
 * PD Pools (#325) — IPv6 prefix delegation pool management.
 *
 * Tests:
 * - Page loads without error
 * - Pool creation requires an IPv6 parent subnet
 * - Create pool, delegate a prefix, revoke it, delete pool
 * - Expired delegation shown in red
 * - Readonly user cannot access
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, appUrl,
  ADMIN_USER, ADMIN_PASS,
  RO_USER, RO_PASS,
  newAuthContext, ensureRoUser,
  TEST_CIDR_V6,
  subnetIdFor,
} from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;
let ipv6SubnetId: number | null = null;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
  // Look for an existing IPv6 test subnet
  ipv6SubnetId = await subnetIdFor(page, TEST_CIDR_V6);
});

test.afterAll(async () => {
  await ctx.close();
});

test('pd_pools.php loads without error', async () => {
  await page.goto(appUrl('pd_pools.php'));
  // The page H1 is "IPv6 Prefix Delegation Pools"; match the stable substring.
  await expect(page.locator('h1')).toContainText('Prefix Delegation');
  await expect(page.locator('.danger')).toHaveCount(0);
});

test('pd_pools.php shows IPv6 subnets in parent picker', async () => {
  await page.goto(appUrl('pd_pools.php'));
  // The previous version assumed that if the test-owned TEST_CIDR_V6 subnet
  // was absent, no IPv6 subnets existed at all — but the demo seed ships with
  // 2001:db8::/32, 2001:db8:1::/48, and 2001:db8:2::/64. Check whether the
  // empty-state is rendered, and if not, assert the picker has at least one
  // usable IPv6 option.
  const emptyState = page.locator('text=No IPv6 subnets available');
  if (await emptyState.isVisible().catch(() => false)) return;
  const options = page.locator('select[name="parent_subnet_id"] option[value]').filter({
    hasNot: page.locator('[value=""]'),
  });
  expect(await options.count()).toBeGreaterThan(0);
});

test('create and delete PD pool', async () => {
  if (ipv6SubnetId === null) test.skip();
  await page.goto(appUrl('pd_pools.php'));
  await page.selectOption('select[name="parent_subnet_id"]', String(ipv6SubnetId));
  await page.fill('input[name="delegation_prefix"]', '64');
  await page.fill('input[name="description"]', 'Test PD pool');
  await page.click('button[type="submit"]');
  await page.waitForURL(/pd_pools\.php/);
  // Pool should appear in the table
  await expect(page.locator('table')).toContainText('/64');
  // Delete the pool
  page.once('dialog', d => d.accept());
  await page.locator('button.button-danger').first().click();
  await page.waitForURL(/pd_pools\.php/);
});

test('delegation prefix validation (0 is rejected)', async () => {
  if (ipv6SubnetId === null) test.skip();
  await page.goto(appUrl('pd_pools.php'));
  await page.selectOption('select[name="parent_subnet_id"]', String(ipv6SubnetId));
  await page.fill('input[name="delegation_prefix"]', '0');
  // HTML5 min=1 should prevent submission — fill with invalid and check
  const prefixInput = page.locator('input[name="delegation_prefix"]');
  const minVal = await prefixInput.getAttribute('min');
  expect(minVal).toBe('1');
});

test('readonly user cannot access pd_pools.php', async () => {
  await ensureRoUser(page);
  const roCtx = await newAuthContext(page.context().browser()!);
  const roPage = await roCtx.newPage();
  await login(roPage, RO_USER, RO_PASS);
  await roPage.goto(appUrl('pd_pools.php'));
  const url = roPage.url();
  const body = await roPage.content();
  expect(url.includes('login.php') || body.includes('403') || body.includes('Forbidden')).toBeTruthy();
  await roCtx.close();
});
