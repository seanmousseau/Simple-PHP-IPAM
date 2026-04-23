/**
 * Data tables — v3.8.0 sticky thead + .data-table class (#515).
 * Tests: sticky header position on addresses page.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { login, newAuthContext, ADMIN_USER, ADMIN_PASS, subnetIdFor, TEST_CIDR1 } from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;
let subnetId: number | null = null;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
  subnetId = await subnetIdFor(page, TEST_CIDR1);
});

test.afterAll(async () => {
  await ctx.close();
});

test('addresses table has sticky header', async () => {
  if (!subnetId) {
    test.skip(true, 'TEST_CIDR1 subnet not found in demo data');
    return;
  }
  await page.goto(`addresses.php?subnet_id=${subnetId}`);
  const position = await page.locator('.data-table thead th').first().evaluate(
    function(el: HTMLElement) { return getComputedStyle(el).position; }
  );
  expect(position).toBe('sticky');
});

test('addresses table has .data-table class', async () => {
  if (!subnetId) {
    test.skip(true, 'TEST_CIDR1 subnet not found in demo data');
    return;
  }
  await page.goto(`addresses.php?subnet_id=${subnetId}`);
  await expect(page.locator('.data-table')).toBeVisible();
});
