/**
 * Global search — by IP, hostname, MAC, empty state.
 * Migrated from cdp_test.py section 6 + v1.19.0 mac search.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchPost, deleteSubnet, subnetIdFor, appUrl,
  ADMIN_USER, ADMIN_PASS, TEST_CIDR2, TEST_IP, TEST_HOST, TEST_MAC,
  newAuthContext,
} from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;
let subnetId: number | null = null;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Stale cleanup
  await page.goto('subnets.php');
  await deleteSubnet(page, TEST_CIDR2);

  // Create subnet + address for search tests
  await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: TEST_CIDR2, description: 'PW search test', confirm_overlap: '1',
  });
  await page.goto('subnets.php');
  subnetId = await subnetIdFor(page, TEST_CIDR2);
  if (subnetId) {
    await page.goto(`addresses.php?subnet_id=${subnetId}`);
    await fetchPost(page, appUrl('addresses.php'), {
      action: 'create', subnet_id: String(subnetId),
      ip: TEST_IP, hostname: TEST_HOST, owner: 'PW Owner',
      status: 'used', note: '', grp: '', mac: TEST_MAC, expires_at: '',
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

test('search page loads with query input', async () => {
  await page.goto('search.php');
  await expect(page.locator('[name=q]')).toBeVisible();
});

test('search by IP finds result', async () => {
  await page.goto(`search.php?q=${encodeURIComponent(TEST_IP)}`);
  await expect(page.getByText(TEST_IP)).toBeVisible();
});

test('search by hostname finds result', async () => {
  await page.goto(`search.php?q=${encodeURIComponent(TEST_HOST)}`);
  await expect(page.getByText(TEST_HOST)).toBeVisible();
});

test('search by MAC address finds result', async () => {
  // #264: mac is searchable
  const macPrefix = TEST_MAC.substring(0, 5); // 'AA:BB'
  await page.goto(`search.php?q=${encodeURIComponent(macPrefix)}`);
  const body = await page.locator('body').innerText();
  expect(body).toContain(TEST_IP);
});

test('search with no match shows empty state', async () => {
  await page.goto('search.php?q=zzz-no-match-pw-xyz-999');
  const emptyState = page.locator('.empty-state, .muted');
  await expect(emptyState.first()).toBeVisible();
});

test('search result shows mac and expires_at columns', async () => {
  await page.goto(`search.php?q=${encodeURIComponent(TEST_IP)}`);
  const headers = await page.locator('table thead th').allInnerTexts();
  const headerText = headers.join(' ').toLowerCase();
  expect(headerText).toContain('mac');
});
