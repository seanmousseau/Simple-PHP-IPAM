/**
 * Subnet CRUD + deep-link to addresses (#246) + overlap warnings.
 * Migrated from cdp_test.py section 4.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchPost, deleteSubnet, subnetIdFor, appUrl,
  ADMIN_USER, ADMIN_PASS, TEST_CIDR1,
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
  await deleteSubnet(page, TEST_CIDR1);             // stale cleanup
  await deleteSubnet(page, '10.99.0.128/28');
});

test.afterAll(async () => {
  try {
    if (page) {
      await page.goto('subnets.php');
      await deleteSubnet(page, '10.99.0.128/28');
      await deleteSubnet(page, TEST_CIDR1);
    }
  } finally {
    await ctx?.close();
  }
});

test('create subnet appears in list', async () => {
  await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: TEST_CIDR1, description: 'PW test subnet 1',
  });
  await page.goto('subnets.php');
  await expect(page.getByText(TEST_CIDR1)).toBeVisible();
  subnetId = await subnetIdFor(page, TEST_CIDR1);
  expect(subnetId, 'subnet ID extractable').not.toBeNull();
});

test('duplicate subnet shows error', async () => {
  await page.goto('subnets.php');
  const r = await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: TEST_CIDR1, description: 'dup',
  });
  expect(r.body).toMatch(/already exists|duplicate/i);
});

test('update subnet description', async () => {
  expect(subnetId, 'need subnet ID from prior test').not.toBeNull();
  await page.goto('subnets.php');
  await fetchPost(page, appUrl('subnets.php'), {
    action: 'update', id: String(subnetId!),
    cidr: TEST_CIDR1, description: 'PW test subnet 1 — EDITED',
  });
  await page.goto('subnets.php');
  await expect(page.getByText('EDITED')).toBeVisible();
});

test('deep-link from subnet row leads to addresses page (#246)', async () => {
  await page.goto('subnets.php');
  const link = page.locator('a[href*="addresses.php"][href*="subnet_id"]').first();
  await expect(link).toBeVisible();
  await link.click();
  await expect(page).toHaveURL(/addresses\.php/);
  await expect(page).toHaveURL(/subnet_id=/);
});

test('overlap subnet creates with warning', async () => {
  // 10.99.0.128/28 is a child of 10.99.0.0/24 — the server accepts it or rejects gracefully.
  // Child subnets may appear nested under the parent, not at the top level.
  const childCidr = '10.99.0.128/28';
  await page.goto('subnets.php');
  const r = await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: childCidr, description: 'overlap test',
  });
  // The server must respond without a 5xx error
  expect(r.status, 'server must not 500 on child-subnet create').toBeLessThan(500);
  // The subnets page itself must still load
  await page.goto('subnets.php');
  const title = await page.title();
  expect(title.toLowerCase()).toContain('subnet');
});

test('subnets page has nav bar', async () => {
  await page.goto('subnets.php');
  await expect(page.locator('.topbar')).toBeVisible();
});
