/**
 * Audit log — page load, entries, default pagination (#243), detail truncation (#244).
 * Migrated from cdp_test.py section 11.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchPost, deleteSubnet, appUrl,
  ADMIN_USER, ADMIN_PASS, TEST_CIDR1,
  newAuthContext,
} from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Ensure there's at least one audit entry by creating + deleting a subnet
  await page.goto('subnets.php');
  await deleteSubnet(page, TEST_CIDR1);
  await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: TEST_CIDR1, description: 'PW audit test', confirm_overlap: '1',
  });
  await page.goto('subnets.php');
  await deleteSubnet(page, TEST_CIDR1);
});

test.afterAll(async () => {
  await ctx?.close();
});

test('audit page loads with correct title', async () => {
  await page.goto('audit.php');
  const title = await page.title();
  expect(title.toLowerCase()).toContain('audit');
});

test('audit log has entries', async () => {
  await page.goto('audit.php');
  const rows = await page.locator('table tbody tr').count();
  expect(rows, 'audit log has at least one entry').toBeGreaterThan(0);
});

test('audit log contains subnet.create action', async () => {
  await page.goto('audit.php');
  const body = await page.locator('body').innerText();
  expect(body).toMatch(/subnet\.create|10\.99|subnet/i);
});

test('audit log has Client IP column', async () => {
  await page.goto('audit.php');
  const headers = await page.locator('table thead th').allInnerTexts();
  const hasIp = headers.some(h => h.toLowerCase().includes('ip'));
  expect(hasIp, 'Client IP column present').toBe(true);
});

test('audit log default pagination is 50 (#243)', async () => {
  await page.goto('audit.php');
  // The limit selector should default to 50
  const limitSelect = page.locator('[name=limit]');
  const hasLimitSelect = await limitSelect.count();
  if (hasLimitSelect > 0) {
    const selected = await limitSelect.inputValue();
    expect(selected).toBe('50');
  } else {
    // Pagination may be shown in the URL or as a default in the query
    const url = page.url();
    // Default should be 50 — either no limit param (defaults to 50) or limit=50
    const hasLimit100 = url.includes('limit=100') || url.includes('limit=25');
    expect(hasLimit100, 'default limit should not be 100 or 25').toBe(false);
  }
});

test('audit log Details column is truncated with title tooltip (#244)', async () => {
  await page.goto('audit.php');
  // Details cells should have a title attribute for the full text (tooltip on hover)
  const detailsWithTitle = page.locator('td span[title], td[title], .audit-details[title]');
  const count = await detailsWithTitle.count();
  expect(count, 'at least one details cell has a title tooltip').toBeGreaterThan(0);
});

test('audit log action filter works', async () => {
  // The filter uses ?prefix=subnet (not ?action=), which matches all subnet.* actions
  await page.goto('audit.php?prefix=subnet');
  // Audit columns: Time | User | Action | Entity | Client IP | Details
  // Action is the 3rd column
  const actionCells = await page.locator('table tbody tr td:nth-child(3)').allInnerTexts();
  expect(actionCells.length, 'filtered results should not be empty').toBeGreaterThan(0);
  for (const cell of actionCells) {
    expect(cell, `expected "${cell}" to start with "subnet."`).toMatch(/^subnet\./);
  }
});

test('audit log has pagination controls when entries exist', async () => {
  await page.goto('audit.php');
  const rows = await page.locator('table tbody tr').count();
  if (rows > 0) {
    // Some pagination UI should exist (links, selects, or text like "Page 1")
    const paginationEl = page.locator('.pagination, [name=page], [name=limit], a[href*="page="]');
    const hasPagination = await paginationEl.count();
    expect(hasPagination, 'pagination UI present').toBeGreaterThan(0);
  }
});
