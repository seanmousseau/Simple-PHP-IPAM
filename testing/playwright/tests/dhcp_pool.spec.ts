/**
 * DHCP Pool — subnet picker, pool reservation, clear-reservation, and
 * write-role access control on dhcp_pool.php.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchPost, appUrl,
  ADMIN_USER, ADMIN_PASS,
  TEST_DHCP_CIDR,
  RO_USER, RO_PASS,
  newAuthContext, subnetIdFor, ensureRoUser,
} from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;
let testSubnetId = 0;

// IPs within 10.55.0.0/24 for DHCP pool tests
const POOL_START = '10.55.0.10';
const POOL_END   = '10.55.0.20';
const POOL_NOTE  = 'pw-dhcp-test';

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Ensure the readonly test user exists (db-tools import can wipe it)
  await ensureRoUser(page);

  // Ensure the test subnet exists (clean up and recreate if necessary)
  await page.goto('subnets.php');
  const existingId = await subnetIdFor(page, TEST_DHCP_CIDR);
  if (existingId) {
    // Clean up any stale reserved addresses from a previous run
    await fetchPost(page, appUrl('dhcp_pool.php'), {
      action:    'clear_pool',
      subnet_id: String(existingId),
      start_ip:  POOL_START,
      end_ip:    POOL_END,
    });
    testSubnetId = existingId;
  } else {
    await fetchPost(page, appUrl('subnets.php'), {
      action:          'create',
      cidr:            TEST_DHCP_CIDR,
      description:     'dhcp pool spec test subnet',
      confirm_overlap: '1',
    });
    await page.goto('subnets.php');
    testSubnetId = await subnetIdFor(page, TEST_DHCP_CIDR) ?? 0;
  }
});

test.afterAll(async () => {
  if (testSubnetId > 0) {
    await page.goto('subnets.php');
    await fetchPost(page, appUrl('subnets.php'), {
      action: 'delete',
      id:     String(testSubnetId),
    });
  }
  await ctx?.close();
});

// ── Page smoke tests ───────────────────────────────────────────────────────────

test('dhcp_pool page: loads with correct title', async () => {
  await page.goto('dhcp_pool.php');
  await expect(page).toHaveTitle(/DHCP/i);
  await expect(page.locator('h1')).toContainText('DHCP');
});

test('dhcp_pool page: subnet picker is present', async () => {
  await page.goto('dhcp_pool.php');
  await expect(page.locator('select[name=subnet_id]')).toBeVisible();
});

test('dhcp_pool page: test subnet appears in picker', async () => {
  await page.goto('dhcp_pool.php');
  const select = page.locator('select[name=subnet_id]');
  const options = await select.locator('option').allInnerTexts();
  expect(options.some(t => t.includes(TEST_DHCP_CIDR))).toBe(true);
});

// ── Pool reservation ───────────────────────────────────────────────────────────

test('dhcp_pool: navigate to subnet shows reserve form', async () => {
  expect(testSubnetId, 'Test subnet must have been created').toBeGreaterThan(0);
  await page.goto(`dhcp_pool.php?subnet_id=${testSubnetId}`);
  const reserveCard = page.locator('.card').filter({ has: page.locator('h2', { hasText: 'Reserve a range' }) });
  await expect(reserveCard.locator('h2').filter({ hasText: 'Reserve a range' })).toBeVisible();
  await expect(reserveCard.locator('input[name=start_ip]')).toBeVisible();
  await expect(reserveCard.locator('input[name=end_ip]')).toBeVisible();
});

test('dhcp_pool: reserve a range creates reserved addresses', async () => {
  expect(testSubnetId).toBeGreaterThan(0);
  await page.goto(`dhcp_pool.php?subnet_id=${testSubnetId}`);

  // Fill and submit the reserve form
  const reserveForm = page.locator('form').filter({ has: page.locator('[value=reserve_pool]') });
  await reserveForm.locator('input[name=start_ip]').fill(POOL_START);
  await reserveForm.locator('input[name=end_ip]').fill(POOL_END);
  await reserveForm.locator('input[name=note]').fill(POOL_NOTE);
  await reserveForm.locator('button[type=submit]').click();

  // Success message should appear
  await expect(page.locator('.success')).toBeVisible();
  const successText = await page.locator('.success').innerText();
  expect(successText).toMatch(/\d+ reserved/i);
});

test('dhcp_pool: reserved addresses appear in the table', async () => {
  expect(testSubnetId).toBeGreaterThan(0);
  await page.goto(`dhcp_pool.php?subnet_id=${testSubnetId}`);

  // Reserved address table should show the pool IPs
  await expect(page.locator('table')).toBeVisible();
  const tableText = await page.locator('table').innerText();
  expect(tableText).toContain(POOL_START);
});

test('dhcp_pool: reserved count matches expected range size', async () => {
  expect(testSubnetId).toBeGreaterThan(0);
  await page.goto(`dhcp_pool.php?subnet_id=${testSubnetId}`);

  // Range 10.55.0.10 – 10.55.0.20 = 11 IPs
  const header = page.locator('h2').filter({ hasText: /Reserved addresses/ });
  await expect(header).toBeVisible();
  const headerText = await header.innerText();
  const match = headerText.match(/\((\d+)\)/);
  expect(match).not.toBeNull();
  const count = parseInt(match![1], 10);
  expect(count).toBeGreaterThanOrEqual(11);
});

// ── Clear reservation ──────────────────────────────────────────────────────────

test('dhcp_pool: clear a range removes reserved addresses', async () => {
  expect(testSubnetId).toBeGreaterThan(0);
  await page.goto(`dhcp_pool.php?subnet_id=${testSubnetId}`);

  const clearForm = page.locator('form').filter({ has: page.locator('[value=clear_pool]') });
  await clearForm.locator('input[name=start_ip]').fill(POOL_START);
  await clearForm.locator('input[name=end_ip]').fill(POOL_END);
  page.once('dialog', d => d.accept());
  await clearForm.locator('button[type=submit]').click();

  await expect(page.locator('.success')).toBeVisible();
  const successText = await page.locator('.success').innerText();
  expect(successText).toMatch(/\d+.*removed/i);
});

test('dhcp_pool: pool is empty after clear', async () => {
  expect(testSubnetId).toBeGreaterThan(0);
  await page.goto(`dhcp_pool.php?subnet_id=${testSubnetId}`);

  // Should now show the empty state
  await expect(page.locator('.empty-state')).toBeVisible();
});

// ── Write-role access control ──────────────────────────────────────────────────

test('dhcp_pool: readonly user can view page but not reserve', async () => {
  expect(testSubnetId).toBeGreaterThan(0);
  const roCtx = await newAuthContext(ctx.browser()!);
  const roPage = await roCtx.newPage();
  try {
    await login(roPage, RO_USER, RO_PASS);
    await roPage.goto(`dhcp_pool.php?subnet_id=${testSubnetId}`);
    // Page should load (login required, but no admin-only block)
    await expect(roPage.locator('h1')).toContainText('DHCP');
    // Reserve and clear forms should NOT be present for readonly users
    const reserveForm = roPage.locator('form').filter({ has: roPage.locator('[value=reserve_pool]') });
    expect(await reserveForm.count()).toBe(0);
  } finally {
    await roCtx.close();
  }
});

// ── Validation ─────────────────────────────────────────────────────────────────

test('dhcp_pool: start IP outside subnet returns error', async () => {
  expect(testSubnetId).toBeGreaterThan(0);
  const res = await fetchPost(page, appUrl(`dhcp_pool.php`), {
    action:    'reserve_pool',
    subnet_id: String(testSubnetId),
    start_ip:  '192.168.1.10',
    end_ip:    '192.168.1.20',
    note:      'should fail',
  });
  // The response body should contain an error about the subnet
  expect(res.body).toMatch(/not within|invalid/i);
});
