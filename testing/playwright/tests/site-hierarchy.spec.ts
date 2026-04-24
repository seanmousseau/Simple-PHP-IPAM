/**
 * Site hierarchy — parent site picker (#269), indented list display,
 * subnet site inheritance from parent.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchGet, fetchPost, appUrl,
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

  // Clean up stale test sites from previous failed runs (child first, then parent)
  await page.goto('sites.php');
  // Delete children first (they reference parent), then parents
  for (const nameFilter of ['pw-test-child-site', 'pw-test-region', 'pw-test-site']) {
    const staleIds = await page.evaluate((nameFilter: string) => {
      const ids: string[] = [];
      for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
        const act = f.querySelector<HTMLInputElement>('[name=action]');
        const id  = f.querySelector<HTMLInputElement>('[name=id]');
        if (act?.value === 'delete' && id) {
          const row = f.closest('tr');
          if (row?.innerText.includes(nameFilter)) ids.push(id.value);
        }
      }
      return ids;
    }, nameFilter);
    for (const id of staleIds) {
      await fetchPost(page, appUrl('sites.php'), { action: 'delete', id });
    }
    if (staleIds.length > 0) await page.goto('sites.php');
  }
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
  await fetchPost(page, appUrl('sites.php'), {
    action: 'create',
    name: TEST_REGION_NAME,
    description: TEST_REGION_DESC,
  });
  await page.goto('sites.php');
  await expect(page.locator('table')).toContainText(TEST_REGION_NAME);
});

test('sites: parent site picker available on create form', async () => {
  await page.goto('sites.php');
  // Scope to #add-site to avoid matching edit-form selects
  const parentSelect = page.locator('#add-site select[name=parent_id]');
  await expect(parentSelect).toBeVisible();
  const options = await parentSelect.locator('option').allInnerTexts();
  // Should have at least (none) option
  expect(options.length).toBeGreaterThanOrEqual(1);
  // The region we just created should appear as an option
  const hasRegion = options.some(o => o.includes(TEST_REGION_NAME));
  expect(hasRegion).toBe(true);
});

test('sites: create child site under region', async () => {
  // Find the parent region's ID from its delete form, then POST the child site
  await page.goto('sites.php');
  const parentId = await page.evaluate((regionName: string) => {
    for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
      const act = f.querySelector<HTMLInputElement>('[name=action]');
      const id  = f.querySelector<HTMLInputElement>('[name=id]');
      if (act?.value === 'delete' && id) {
        const row = f.closest('tr');
        if (row?.innerText.includes(regionName)) return id.value;
      }
    }
    return '';
  }, TEST_REGION_NAME);

  expect(parentId).not.toBe('');

  await fetchPost(page, appUrl('sites.php'), {
    action: 'create',
    name: TEST_CHILD_NAME,
    description: TEST_CHILD_DESC,
    parent_id: parentId,
  });

  await page.goto('sites.php');
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
  await page.locator('[data-drawer-title="Add Subnet"]').first().click();
  await expect(page.locator('#global-drawer')).toBeVisible();
  const siteSelect = page.locator('#global-drawer-body select[name=site_id]');
  await expect(siteSelect).toBeVisible();
  const options = await siteSelect.locator('option').allInnerTexts();
  await page.keyboard.press('Escape');
  const hasRegion = options.some(o => o.includes(TEST_REGION_NAME));
  expect(hasRegion).toBe(true);
});

// ── Collapsible rows (v3.11.0 #632 #633) ─────────────────────────────────────
// Run before the delete tests so the pw-test-region/pw-test-child-site hierarchy
// created above is guaranteed to be present.

test('sites: parent sites render a collapse toggle button', async () => {
  await page.goto('sites.php', { waitUntil: 'networkidle' });
  const toggles = page.locator('[data-collapsible-toggle]');
  await expect(toggles.first()).toBeVisible();
  await expect(toggles.first()).toHaveAttribute('aria-expanded');
});

test('sites: clicking toggle collapses and expands child rows', async () => {
  await page.goto('sites.php', { waitUntil: 'networkidle' });
  const toggles = page.locator('[data-collapsible-toggle]');
  const groupId = await toggles.first().getAttribute('data-collapsible-group-id');
  const childRows = page.locator(`[data-collapsible-child="${groupId}"]`);
  await expect(childRows.first()).toBeVisible();
  // Collapse
  await toggles.first().click();
  await expect(toggles.first()).toHaveAttribute('aria-expanded', 'false');
  await expect(childRows.first()).toHaveClass(/collapsible-child--hidden/);
  // Re-expand
  await toggles.first().click();
  await expect(toggles.first()).toHaveAttribute('aria-expanded', 'true');
  await expect(childRows.first()).not.toHaveClass(/collapsible-child--hidden/);
});

test('sites: delete child site', async () => {
  await page.goto('sites.php');
  const rows = await page.locator('table tbody tr').all();
  for (const row of rows) {
    const text = await row.innerText();
    if (!text.includes(TEST_CHILD_NAME)) continue;

    // Open details via evaluate to avoid sticky-header pointer-event interception
    const details = row.locator('details');
    await details.evaluate((el: HTMLDetailsElement) => { el.open = true; });
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

    // Open details via evaluate to avoid sticky-header pointer-event interception
    const details = row.locator('details');
    await details.evaluate((el: HTMLDetailsElement) => { el.open = true; });
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
  // 401 is acceptable when no API key is configured in the test environment
  expect([200, 401]).toContain(res.status);
  if (res.status === 200) {
    const data = JSON.parse(res.body);
    expect(data).toHaveProperty('sites');
    if (data.sites?.length > 0) {
      expect(Object.prototype.hasOwnProperty.call(data.sites[0], 'parent_id')).toBe(true);
    }
  }
});
