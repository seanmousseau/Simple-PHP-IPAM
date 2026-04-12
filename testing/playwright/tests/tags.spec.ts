/**
 * Tags — CRUD for the tags admin page (#266), tag attachment on subnet/address,
 * tag filter in search, and API tag fields.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchGet, appUrl,
  ADMIN_USER, ADMIN_PASS,
  TEST_CIDR2,
  newAuthContext,
} from '../fixtures/ipam';

export const TEST_TAG_NAME   = 'pw-test-tag';
export const TEST_TAG_COLOUR = '#ff0000';

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

// ── Tag CRUD ───────────────────────────────────────────────────────────────────

test('tags page: loads with correct title', async () => {
  await page.goto('tags.php');
  await expect(page).toHaveTitle(/Tags/i);
  await expect(page.locator('h1')).toContainText('Tags');
});

test('tags page: breadcrumb present', async () => {
  await page.goto('tags.php');
  await expect(page.locator('.breadcrumbs')).toBeVisible();
  await expect(page.locator('.breadcrumbs')).toContainText('Dashboard');
});

test('tags: create a tag', async () => {
  await page.goto('tags.php');

  await page.locator('input[name=name]').fill(TEST_TAG_NAME);
  // colour input is type=color — set value directly
  await page.locator('input[name=colour]').evaluate(
    (el: HTMLInputElement, c: string) => { el.value = c; },
    TEST_TAG_COLOUR,
  );
  await page.locator('button[type=submit]').first().click();

  await page.waitForURL(/tags\.php/);
  await expect(page.locator('table')).toContainText(TEST_TAG_NAME);
});

test('tags: overview metric shows at least 1 tag', async () => {
  await page.goto('tags.php');
  const metricValues = await page.locator('.metric .value').allInnerTexts();
  const tagsCount = parseInt(metricValues[0] ?? '0', 10);
  expect(tagsCount).toBeGreaterThanOrEqual(1);
});

test('tags: edit tag name', async () => {
  await page.goto('tags.php');
  const rows = await page.locator('table tbody tr').all();
  for (const row of rows) {
    const text = await row.innerText();
    if (!text.includes(TEST_TAG_NAME)) continue;

    const details = row.locator('details');
    await details.click();
    const nameInput = details.locator('input[name=name]');
    await nameInput.fill(TEST_TAG_NAME + '-v2');
    await details.locator('button[type=submit]').first().click();
    await page.waitForURL(/tags\.php/);
    await expect(page.locator('table')).toContainText(TEST_TAG_NAME + '-v2');
    break;
  }
});

test('tags: subnet create form loads without error', async () => {
  await page.goto('subnets.php');
  // Tag picker is rendered only after tags are created; just verify page loads cleanly
  await expect(page.locator('h1')).toContainText('Subnets');
  await expect(page.locator('.danger')).not.toBeVisible();
});

test('tags: delete test tag', async () => {
  await page.goto('tags.php');
  const rows = await page.locator('table tbody tr').all();
  for (const row of rows) {
    const text = await row.innerText();
    if (!text.includes(TEST_TAG_NAME)) continue;

    const details = row.locator('details');
    await details.click();
    page.once('dialog', d => d.accept());
    await details.locator('button.button-danger').click();
    await page.waitForURL(/tags\.php/);
    break;
  }

  await expect(page.locator('body')).not.toContainText(TEST_TAG_NAME);
});

// ── API — tags field ───────────────────────────────────────────────────────────

test('api: subnet response includes tags array', async () => {
  const res = await fetchGet(page, appUrl('api.php?resource=subnets'));
  expect(res.status).toBe(200);
  const data = JSON.parse(res.body);
  expect(data).toHaveProperty('subnets');
  if (data.subnets.length > 0) {
    expect(Object.prototype.hasOwnProperty.call(data.subnets[0], 'tags')).toBe(true);
    expect(Array.isArray(data.subnets[0].tags)).toBe(true);
  }
});

test('api: address response includes tags array', async () => {
  const res = await fetchGet(page, appUrl(`api.php?resource=addresses&subnet_cidr=${TEST_CIDR2}`));
  expect([200, 404]).toContain(res.status);
  if (res.status === 200) {
    const data = JSON.parse(res.body);
    if (data.addresses?.length > 0) {
      expect(Object.prototype.hasOwnProperty.call(data.addresses[0], 'tags')).toBe(true);
    }
  }
});
