/**
 * ARP Import page (import_arp.php) — v2.3.0 (#320)
 *
 * Tests: page loads for write-role users, read-only users are denied,
 * paste + preview flow works, and applying updates MAC addresses.
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
let createdSubnet = false; // track whether this spec created the subnet
let testIp = '10.44.0.1';

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
  await ensureRoUser(page);

  // Ensure subnet + one address exist
  await page.goto('subnets.php');
  let existingId = await subnetIdFor(page, TEST_SCAN_CIDR);
  if (!existingId) {
    await fetchPost(page, appUrl('subnets.php'), {
      action:          'create',
      cidr:            TEST_SCAN_CIDR,
      description:     'arp import spec test subnet',
      confirm_overlap: '1',
    });
    await page.goto('subnets.php');
    existingId = await subnetIdFor(page, TEST_SCAN_CIDR);
    createdSubnet = true;
  }
  testSubnetId = existingId ?? 0;

  if (testSubnetId > 0) {
    // Create a test address
    await fetchPost(page, appUrl('addresses.php'), {
      action:    'create',
      subnet_id: String(testSubnetId),
      ip:        testIp,
      hostname:  'arp-import-test-host',
      status:    'used',
    });
  }
});

test.afterAll(async () => {
  try {
    // Only delete the subnet if this spec created it
    if (createdSubnet && testSubnetId > 0) {
      await fetchPost(page, appUrl('subnets.php'), {
        action: 'delete',
        id:     String(testSubnetId),
      });
    }
  } catch { /* best-effort cleanup */ }
  await ctx?.close();
});

test('import_arp.php loads for admin user', async () => {
  await page.goto('import_arp.php');
  await expect(page).toHaveTitle(/ARP Import/i);
  await expect(page.locator('h1')).toContainText('ARP');
});

test('import_arp.php shows subnet selector', async () => {
  await page.goto('import_arp.php');
  await expect(page.locator('select[name="subnet_id"]')).toBeVisible();
  await expect(page.locator('textarea[name="raw"]')).toBeVisible();
});

test('import_arp.php is in admin nav dropdown', async () => {
  await page.goto('subnets.php');
  // In v3.8.0 sidebar nav, ARP Import is a direct sidebar link
  await expect(page.locator(".sidebar-link[href='import_arp.php']")).toBeVisible();
});

test('ARP import preview shows parsed entries', async () => {
  if (testSubnetId <= 0) test.skip();
  await page.goto('import_arp.php');

  await page.locator('select[name="subnet_id"]').selectOption(String(testSubnetId));
  await page.locator('textarea[name="raw"]').fill(`${testIp} aa:bb:cc:dd:ee:ff`);
  await page.locator('button[value="preview"]').click();

  // Should show preview table with the parsed IP (match table cell specifically)
  await expect(page.locator('table td').filter({ hasText: testIp }).first()).toBeVisible();
  await expect(page.locator('table td').filter({ hasText: 'aa:bb:cc:dd:ee:ff' }).first()).toBeVisible();
});

test('ARP import preview distinguishes in-subnet vs out-of-subnet IPs', async () => {
  if (testSubnetId <= 0) test.skip();
  await page.goto('import_arp.php');

  await page.locator('select[name="subnet_id"]').selectOption(String(testSubnetId));
  await page.locator('textarea[name="raw"]').fill(
    `${testIp} aa:bb:cc:dd:ee:ff\n1.2.3.4 11:22:33:44:55:66`
  );
  await page.locator('button[value="preview"]').click();

  await expect(page.locator('text=Yes')).toBeVisible();
  await expect(page.locator('text=No — skipped')).toBeVisible();
});

test('ARP import apply updates MAC and flashes success', async () => {
  if (testSubnetId <= 0) test.skip();
  await page.goto('import_arp.php');

  await page.locator('select[name="subnet_id"]').selectOption(String(testSubnetId));
  await page.locator('textarea[name="raw"]').fill(`${testIp} cc:dd:ee:ff:00:11`);
  await page.locator('button[value="preview"]').click();

  // Click Apply after preview
  await page.locator('button[value="apply"]').click();

  // Should redirect back and show success flash message
  await expect(page.locator('body')).toContainText(/MAC address|import complete/i);
});

test('import_arp.php is blocked for read-only users', async ({ browser }: { browser: Browser }) => {
  const roCtx = await newAuthContext(browser);
  const roPage = await roCtx.newPage();
  await login(roPage, RO_USER, RO_PASS);
  await roPage.goto('import_arp.php');
  // Should show 403 or redirect to login
  const statusOrTitle = await roPage.title();
  const bodyText = await roPage.locator('body').textContent() ?? '';
  const isBlocked = statusOrTitle.includes('403') || bodyText.includes('403')
    || bodyText.toLowerCase().includes('forbidden') || bodyText.toLowerCase().includes('read-only');
  expect(isBlocked).toBe(true);
  await roCtx.close();
});
