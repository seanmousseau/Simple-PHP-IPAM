/**
 * Unassigned IPs — IPv4 and IPv6 (#263).
 * Migrated from cdp_test.py section 8.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchPost, deleteSubnet, subnetIdFor, appUrl,
  ADMIN_USER, ADMIN_PASS, TEST_CIDR2, TEST_IP, TEST_CIDR_V6,
  newAuthContext,
} from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;
let ipv4SubnetId: number | null = null;
let ipv6SubnetId: number | null = null;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Stale cleanup
  await page.goto('subnets.php');
  await deleteSubnet(page, TEST_CIDR2);
  await deleteSubnet(page, TEST_CIDR_V6);

  // Create IPv4 test subnet and one address (to verify it's excluded from unassigned list)
  await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: TEST_CIDR2, description: 'PW unassigned test', confirm_overlap: '1',
  });
  await page.goto('subnets.php');
  ipv4SubnetId = await subnetIdFor(page, TEST_CIDR2);

  if (ipv4SubnetId) {
    await page.goto(`addresses.php?subnet_id=${ipv4SubnetId}`);
    await fetchPost(page, appUrl('addresses.php'), {
      action: 'create', subnet_id: String(ipv4SubnetId),
      ip: TEST_IP, hostname: 'pw-assigned', owner: '',
      status: 'used', note: '', grp: '', mac: '', expires_at: '',
    });
  }

  // Create IPv6 test subnet
  await page.goto('subnets.php');
  await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: TEST_CIDR_V6, description: 'PW IPv6 unassigned test', confirm_overlap: '1',
  });
  await page.goto('subnets.php');
  ipv6SubnetId = await subnetIdFor(page, TEST_CIDR_V6);
});

test.afterAll(async () => {
  try {
    if (page) {
      await page.goto('subnets.php');
      await deleteSubnet(page, TEST_CIDR2);
      await deleteSubnet(page, TEST_CIDR_V6);
    }
  } finally {
    await ctx?.close();
  }
});

test('unassigned page has subnet selector', async () => {
  await page.goto('unassigned.php');
  await expect(page.locator('[name=subnet_id]')).toBeVisible();
});

test('unassigned IPv4: shows available IPs', async () => {
  if (!ipv4SubnetId) { test.skip(); return; }
  await page.goto(`unassigned.php?subnet_id=${ipv4SubnetId}`);
  const body = await page.locator('body').innerText();
  expect(body).toContain('10.88.0.');
});

test('unassigned IPv4: assigned IP is excluded from table', async () => {
  if (!ipv4SubnetId) { test.skip(); return; }
  await page.goto(`unassigned.php?subnet_id=${ipv4SubnetId}`);
  // The assigned IP should not appear as a table entry in the unassigned list
  const ipInTable = await page.evaluate((ip) => {
    return Array.from(document.querySelectorAll('table tbody tr td b'))
      .some((b) => (b as HTMLElement).innerText.trim() === ip);
  }, TEST_IP);
  expect(ipInTable, `${TEST_IP} should not be in unassigned table`).toBe(false);
});

test('unassigned IPv4: count is non-zero', async () => {
  if (!ipv4SubnetId) { test.skip(); return; }
  await page.goto(`unassigned.php?subnet_id=${ipv4SubnetId}`);
  const countEl = page.locator('.muted b, .summary b').first();
  const count = await countEl.innerText().catch(() => '0');
  expect(parseInt(count, 10), 'unassigned count > 0').toBeGreaterThan(0);
});

test('unassigned IPv6: shows unassigned addresses (#263)', async () => {
  if (!ipv6SubnetId) { test.skip(); return; }
  await page.goto(`unassigned.php?subnet_id=${ipv6SubnetId}`);
  const body = await page.locator('body').innerText();
  // Should list 2001:db8: addresses
  expect(body).toContain('2001:db8:');
});

test('unassigned IPv6: shows (IPv6) label in subnet dropdown', async () => {
  await page.goto('unassigned.php');
  const options = await page.locator('[name=subnet_id] option').allInnerTexts();
  const hasIpv6Label = options.some(o => o.includes('IPv6'));
  expect(hasIpv6Label, 'IPv6 subnets labeled in dropdown').toBe(true);
});
