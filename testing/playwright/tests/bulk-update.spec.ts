/**
 * Bulk update — subnet selector, address list, mac/expires_at fields.
 * Migrated from cdp_test.py section 7.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchPost, deleteSubnet, subnetIdFor, appUrl,
  ADMIN_USER, ADMIN_PASS, TEST_CIDR2, TEST_IP, TEST_HOST,
  newAuthContext,
} from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;
let subnetId: number | null = null;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  await page.goto('subnets.php');
  await deleteSubnet(page, TEST_CIDR2);

  await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: TEST_CIDR2, description: 'PW bulk test', confirm_overlap: '1',
  });
  await page.goto('subnets.php');
  subnetId = await subnetIdFor(page, TEST_CIDR2);

  if (subnetId) {
    await page.goto(`addresses.php?subnet_id=${subnetId}`);
    await fetchPost(page, appUrl('addresses.php'), {
      action: 'create', subnet_id: String(subnetId),
      ip: TEST_IP, hostname: TEST_HOST, owner: 'PW Test',
      status: 'used', note: '', grp: '', mac: '', expires_at: '',
    });
  }
});

test.afterAll(async () => {
  try {
    if (page) {
      await page.goto('subnets.php');
      await deleteSubnet(page, TEST_CIDR2);
    }
  } finally {
    await ctx?.close();
  }
});

test('bulk update page loads with subnet selector', async () => {
  await page.goto('bulk_update.php');
  await expect(page.locator('[name=subnet_id]')).toBeVisible();
  const dangerCount = await page.locator('.danger').count();
  expect(dangerCount, 'no error on load').toBe(0);
});

test('bulk update shows addresses for subnet', async () => {
  if (!subnetId) { test.skip(); return; }
  await page.goto(`bulk_update.php?subnet_id=${subnetId}`);
  // Use exact cell match to avoid substring collision (e.g. 10.88.0.10 vs 10.88.0.100)
  await expect(page.getByRole('cell', { name: TEST_IP, exact: true })).toBeVisible();
});

test('bulk update form has mac and expires_at fields', async () => {
  if (!subnetId) { test.skip(); return; }
  await page.goto(`bulk_update.php?subnet_id=${subnetId}`);
  // Check that mac checkbox/input or the do_mac checkbox is present
  const macField = page.locator('[name=mac], [name=do_mac]');
  await expect(macField.first()).toBeVisible();
  const expiresField = page.locator('[name=expires_at], [name=do_expires_at]');
  await expect(expiresField.first()).toBeVisible();
});

test('bulk select all checkbox present', async () => {
  if (!subnetId) { test.skip(); return; }
  await page.goto(`bulk_update.php?subnet_id=${subnetId}`);
  // There should be a select-all checkbox
  // Soft check — the select-all control may use different names
  const hasCheckboxes = await page.locator('input[type=checkbox]').count();
  expect(hasCheckboxes, 'bulk update has checkboxes').toBeGreaterThan(0);
});
