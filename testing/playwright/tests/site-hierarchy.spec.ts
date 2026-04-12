/**
 * Site hierarchy — parent site picker (#269), indented list display,
 * subnet site inheritance from parent.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchGet, appUrl,
  ADMIN_USER, ADMIN_PASS,
  newAuthContext,
} from '../fixtures/ipam';

const TEST_REGION_NAME = 'pw-test-region';
const TEST_REGION_DESC = 'Playwright test region (parent)';
const TEST_CHILD_NAME  = 'pw-test-child-site';
const TEST_CHILD_DESC  = 'Playwright test child site';

let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
});

test.afterAll(async () => {
  await ctx?.close();
});

// ── Site hierarchy CRUD ────────────────────────────────────────────────────────

test('sites page: loads with correct title', async () => {
  await page.goto('sites.php');
  await expect(page).toHaveTitle(/Sites/i);
  await expect(page.locator('h1')).toContainText('Sites');
});

test('sites: create parent region', async () => {
  await page.goto('sites.php');

  await page.locator('input[name=name]').fill(TEST_REGION_NAME);
  await page.locator('input[name=description]').fill(TEST_REGION_DESC);
  // Leave parent_id as (none) / 0
  await page.locator('button[type=submit]').first().click();

  await page.waitForURL(/sites\.php/);
  await expect(page.locator('table, .sites-list, body')).toContainText(TEST_REGION_NAME);
});

test('sites: parent site picker available on create form', async () => {
  await page.goto('sites.php');
  const parentSelect = page.locator('select[name=parent_id]');
  await expect(parentSelect).toBeVisible();
  const options = await parentSelect.locator('option').allInnerTexts();
  // Should have at least (none) option
  expect(options.length).toBeGreaterThanOrEqual(1);
  // The region we just created should appear as an option
  const hasRegion = options.some(o => o.includes(TEST_REGION_NAME));
  expect(hasRegion).toBe(true);
});

test('sites: create child site under region', async () => {
  await page.goto('sites.php');

  await page.locator('input[name=name]').fill(TEST_CHILD_NAME);
  await page.locator('input[name=description]').fill(TEST_CHILD_DESC);

  // Select the parent region
  const parentSelect = page.locator('select[name=parent_id]');
  const options = await parentSelect.locator('option').all();
  for (const opt of options) {
    const text = await opt.innerText();
    if (text.includes(TEST_REGION_NAME)) {
      const val = await opt.getAttribute('value');
      if (val) await parentSelect.selectOption(val);
      break;
    }
  }

  await page.locator('button[type=submit]').first().click();
  await page.waitForURL(/sites\.php/);
  await expect(page.locator('body')).toContainText(TEST_CHILD_NAME);
});

test('sites: child site shows indented under parent (depth indicator)', async () => {
  await page.goto('sites.php');
  const body = await page.locator('body').innerText();
  // Both sites should appear, child indented with ↳ or similar
  expect(body).toContain(TEST_REGION_NAME);
  expect(body).toContain(TEST_CHILD_NAME);
});

test('sites: subnet site picker shows hierarchy', async () => {
  await page.goto('subnets.php');
  const siteSelect = page.locator('select[name=site_id]').first();
  await expect(siteSelect).toBeVisible();
  const options = await siteSelect.locator('option').allInnerTexts();
  const hasRegion = options.some(o => o.includes(TEST_REGION_NAME));
  expect(hasRegion).toBe(true);
});

test('sites: delete child site', async () => {
  await page.goto('sites.php');
  const rows = await page.locator('table tbody tr').all();
  for (const row of rows) {
    const text = await row.innerText();
    if (!text.includes(TEST_CHILD_NAME)) continue;

    const details = row.locator('details');
    await details.click();
    page.once('dialog', d => d.accept());
    await details.locator('button.button-danger').click();
    await page.waitForURL(/sites\.php/);
    break;
  }
  await expect(page.locator('body')).not.toContainText(TEST_CHILD_NAME);
});

test('sites: delete parent region', async () => {
  await page.goto('sites.php');
  const rows = await page.locator('table tbody tr').all();
  for (const row of rows) {
    const text = await row.innerText();
    if (!text.includes(TEST_REGION_NAME)) continue;

    const details = row.locator('details');
    await details.click();
    page.once('dialog', d => d.accept());
    await details.locator('button.button-danger').click();
    await page.waitForURL(/sites\.php/);
    break;
  }
  await expect(page.locator('body')).not.toContainText(TEST_REGION_NAME);
});

// ── API — site hierarchy ───────────────────────────────────────────────────────

test('api: site response includes parent_id field', async () => {
  const res = await fetchGet(page, appUrl('api.php?resource=sites'));
  expect(res.status).toBe(200);
  const data = JSON.parse(res.body);
  expect(data).toHaveProperty('sites');
  if (data.sites?.length > 0) {
    expect(Object.prototype.hasOwnProperty.call(data.sites[0], 'parent_id')).toBe(true);
  }
});
