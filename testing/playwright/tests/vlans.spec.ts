/**
 * VLANs — CRUD for the vlans admin page (#268), VLAN picker on subnet create,
 * VLAN badge in subnet list, and API /api.php?resource=vlans coverage.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchGet, appUrl,
  ADMIN_USER, ADMIN_PASS,
  newAuthContext,
} from '../fixtures/ipam';

export const TEST_VLAN_ID   = 99;
export const TEST_VLAN_NAME = 'pw-test-vlan';
export const TEST_VLAN_DESC = 'Playwright test VLAN';
export const TEST_VLAN_CIDR = '10.77.99.0/24';

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

// ── VLAN CRUD ──────────────────────────────────────────────────────────────────

test('vlans page: loads with correct title', async () => {
  await page.goto('vlans.php');
  await expect(page).toHaveTitle(/VLANs/i);
  await expect(page.locator('h1')).toContainText('VLANs');
});

test('vlans page: breadcrumb present', async () => {
  await page.goto('vlans.php');
  await expect(page.locator('.breadcrumbs')).toBeVisible();
  await expect(page.locator('.breadcrumbs')).toContainText('Dashboard');
});

test('vlans: create a VLAN', async () => {
  await page.goto('vlans.php');

  // Fill the create form
  await page.locator('input[name=vlan_id]').fill(String(TEST_VLAN_ID));
  await page.locator('input[name=name]').fill(TEST_VLAN_NAME);
  await page.locator('input[name=description]').fill(TEST_VLAN_DESC);
  await page.locator('button[type=submit]').first().click();

  // After redirect, the VLAN should appear in the list
  await page.waitForURL(/vlans\.php/);
  await expect(page.locator('table')).toContainText(TEST_VLAN_NAME);
  await expect(page.locator('table')).toContainText(String(TEST_VLAN_ID));
});

test('vlans: VLAN appears in subnet create picker', async () => {
  await page.goto('subnets.php');
  const vlanSelect = page.locator('select[name=vlan_fk]').first();
  await expect(vlanSelect).toBeVisible();
  const optionText = await vlanSelect.locator('option').allInnerTexts();
  const hasVlan = optionText.some(t => t.includes(TEST_VLAN_NAME) || t.includes(String(TEST_VLAN_ID)));
  expect(hasVlan).toBe(true);
});

test('vlans: create subnet with VLAN and verify badge', async () => {
  await page.goto('subnets.php');

  // Find the VLAN option value by name
  const vlanSelect = page.locator('select[name=vlan_fk]').first();
  const options = await vlanSelect.locator('option').all();
  let vlanFkValue = '';
  for (const opt of options) {
    const text = await opt.innerText();
    if (text.includes(TEST_VLAN_NAME)) {
      vlanFkValue = await opt.getAttribute('value') ?? '';
      break;
    }
  }
  if (!vlanFkValue) {
    test.skip(true, 'Test VLAN not found in picker — skipping subnet test');
    return;
  }

  await page.locator('input[name=cidr]').fill(TEST_VLAN_CIDR);
  await page.locator('input[name=description]').fill('vlan badge test');
  await vlanSelect.selectOption(vlanFkValue);
  await page.locator('button[type=submit]').first().click();

  await page.waitForURL(/subnets\.php/);
  // VLAN badge should appear somewhere in the subnet list
  const body = await page.locator('body').innerText();
  expect(body).toContain(TEST_VLAN_NAME);
});

test('vlans: edit VLAN name', async () => {
  await page.goto('vlans.php');
  // Find the edit form for our test VLAN
  const rows = await page.locator('table tbody tr').all();
  for (const row of rows) {
    const text = await row.innerText();
    if (!text.includes(TEST_VLAN_NAME)) continue;

    const details = row.locator('details');
    await details.click();
    const nameInput = details.locator('input[name=name]');
    await nameInput.fill(TEST_VLAN_NAME + '-edited');
    await details.locator('button[type=submit]').first().click();
    await page.waitForURL(/vlans\.php/);
    await expect(page.locator('table')).toContainText(TEST_VLAN_NAME + '-edited');
    break;
  }
});

test('vlans: delete test VLAN subnet then VLAN', async () => {
  // First delete the test subnet we created
  await page.goto('subnets.php');
  const subnetNode = page.locator('.subnet-node').filter({ hasText: TEST_VLAN_CIDR });
  if (await subnetNode.count() > 0) {
    const details = subnetNode.locator('details').first();
    await details.click();
    const deleteForm = subnetNode.locator('form').filter({ has: page.locator('[value=delete]') });
    page.once('dialog', d => d.accept());
    await deleteForm.locator('button.button-danger').click();
    await page.waitForURL(/subnets\.php/);
  }

  // Now delete the VLAN
  await page.goto('vlans.php');
  const rows = await page.locator('table tbody tr').all();
  for (const row of rows) {
    const text = await row.innerText();
    if (!text.includes(TEST_VLAN_NAME) && !text.includes(TEST_VLAN_NAME + '-edited')) continue;

    const details = row.locator('details');
    await details.click();
    page.once('dialog', d => d.accept());
    await details.locator('button.button-danger').click();
    await page.waitForURL(/vlans\.php/);
    break;
  }

  await expect(page.locator('body')).not.toContainText(TEST_VLAN_NAME);
});

// ── API — VLANs resource ───────────────────────────────────────────────────────

test('api: GET vlans returns 200 with vlans array', async () => {
  const res = await fetchGet(page, appUrl('api.php?resource=vlans'));
  expect(res.status).toBe(200);
  const data = JSON.parse(res.body);
  expect(data).toHaveProperty('vlans');
  expect(Array.isArray(data.vlans)).toBe(true);
});

test('api: subnet response includes vlan_name field', async () => {
  const res = await fetchGet(page, appUrl('api.php?resource=subnets'));
  expect(res.status).toBe(200);
  const data = JSON.parse(res.body);
  expect(data).toHaveProperty('subnets');
  // Each subnet should have vlan_name (may be null if no VLAN assigned)
  if (data.subnets.length > 0) {
    expect(Object.prototype.hasOwnProperty.call(data.subnets[0], 'vlan_name')).toBe(true);
  }
});
