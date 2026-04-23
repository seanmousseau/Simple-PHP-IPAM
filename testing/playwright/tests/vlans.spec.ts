/**
 * VLANs — CRUD for the vlans admin page (#268), VLAN picker on subnet create,
 * VLAN badge in subnet list, and API /api.php?resource=vlans coverage.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchGet, fetchPost, appUrl,
  ADMIN_USER, ADMIN_PASS,
  TEST_VLAN_ID, TEST_VLAN_NAME, TEST_VLAN_DESC, TEST_VLAN_CIDR,
  newAuthContext,
} from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Clean up stale test VLANs from previous failed runs
  await page.goto('vlans.php');
  const staleIds = await page.evaluate(() => {
    const ids: string[] = [];
    for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
      const act = f.querySelector<HTMLInputElement>('[name=action]');
      const id  = f.querySelector<HTMLInputElement>('[name=id]');
      if (act?.value === 'delete' && id) {
        const row = f.closest('tr');
        if (row?.innerText.includes('pw-test-vlan')) ids.push(id.value);
      }
    }
    return ids;
  });
  for (const id of staleIds) {
    await fetchPost(page, appUrl('vlans.php'), { action: 'delete', id });
  }
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

  // Scope to the create card to avoid strict-mode violations from inline edit forms
  const createCard = page.locator('#add-vlan');
  await createCard.locator('input[name=vlan_id]').fill(String(TEST_VLAN_ID));
  await createCard.locator('input[name=name]').fill(TEST_VLAN_NAME);
  await createCard.locator('input[name=description]').fill(TEST_VLAN_DESC);
  await createCard.locator('button[type=submit]').click();

  // After redirect, the VLAN should appear in the list
  await page.waitForURL(/vlans\.php/);
  await expect(page.locator('table')).toContainText(TEST_VLAN_NAME);
  await expect(page.locator('table')).toContainText(String(TEST_VLAN_ID));
});

test('vlans: VLAN appears in subnet create picker', async () => {
  await page.goto('subnets.php');
  await page.locator('[data-drawer-title="Add Subnet"]').first().click();
  await expect(page.locator('#global-drawer')).toBeVisible();
  const vlanSelect = page.locator('#global-drawer-body select[name=vlan_fk]');
  await expect(vlanSelect).toBeVisible();
  const optionText = await vlanSelect.locator('option').allInnerTexts();
  await page.keyboard.press('Escape');
  const hasVlan = optionText.some(t => t.includes(TEST_VLAN_NAME) || t.includes(String(TEST_VLAN_ID)));
  expect(hasVlan).toBe(true);
});

test('vlans: create subnet with VLAN and verify badge', async () => {
  await page.goto('subnets.php');
  await page.locator('[data-drawer-title="Add Subnet"]').first().click();
  await expect(page.locator('#global-drawer')).toBeVisible();

  // Read the VLAN option value from the drawer picker
  const drawer = page.locator('#global-drawer-body');
  const vlanSelect = drawer.locator('select[name=vlan_fk]');
  const options = await vlanSelect.locator('option').all();
  let vlanFkValue = '';
  for (const opt of options) {
    const text = await opt.innerText();
    if (text.includes(TEST_VLAN_NAME)) {
      vlanFkValue = await opt.getAttribute('value') ?? '';
      break;
    }
  }
  expect(vlanFkValue, 'Test VLAN must appear in the subnet VLAN picker').toBeTruthy();
  await page.keyboard.press('Escape');

  // Use fetchPost with confirm_overlap to bypass the overlap confirmation step
  // (TEST_VLAN_CIDR is within the demo DB's 10.0.0.0/8 subnet)
  await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: TEST_VLAN_CIDR, description: 'vlan badge test',
    vlan_fk: vlanFkValue, confirm_overlap: '1',
  });

  await page.goto('subnets.php');
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

    // Open details via evaluate to avoid sticky-header pointer-event interception
    const details = row.locator('details');
    await details.evaluate((el: HTMLDetailsElement) => { el.open = true; });
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
    // Open details via evaluate to avoid sticky-header pointer-event interception
    const details = subnetNode.locator('details').first();
    await details.evaluate((el: HTMLDetailsElement) => { el.open = true; });
    const deleteForm = subnetNode.locator('form').filter({ has: page.locator('[value=delete]') });
    page.once('dialog', d => d.accept());
    // Use evaluate to bypass sticky-header pointer-event interception
    await deleteForm.locator('button.button-danger').evaluate((el: HTMLElement) => el.click());
    await page.waitForURL(/subnets\.php/);
  }

  // Now delete the VLAN
  await page.goto('vlans.php');
  const rows = await page.locator('table tbody tr').all();
  for (const row of rows) {
    const text = await row.innerText();
    if (!text.includes(TEST_VLAN_NAME) && !text.includes(TEST_VLAN_NAME + '-edited')) continue;

    // Open details via evaluate to avoid sticky-header pointer-event interception
    const details = row.locator('details');
    await details.evaluate((el: HTMLDetailsElement) => { el.open = true; });
    page.once('dialog', d => d.accept());
    await details.locator('button.button-danger').click();
    await page.waitForURL(/vlans\.php/);
    break;
  }

  await expect(page.locator('body')).not.toContainText(TEST_VLAN_NAME);
});

// ── API — VLANs resource ───────────────────────────────────────────────────────

test('api: GET vlans returns 200 with vlans array', async () => {
  if (!process.env.IPAM_API_KEY) {
    test.skip(true, 'IPAM_API_KEY not set — skipping API endpoint test');
    return;
  }
  const res = await fetchGet(page, appUrl('api.php?resource=vlans'));
  expect(res.status).toBe(200);
  const data = JSON.parse(res.body);
  expect(data).toHaveProperty('vlans');
  expect(Array.isArray(data.vlans)).toBe(true);
});

test('api: subnet response includes vlan_name field', async () => {
  if (!process.env.IPAM_API_KEY) {
    test.skip(true, 'IPAM_API_KEY not set — skipping API endpoint test');
    return;
  }
  const res = await fetchGet(page, appUrl('api.php?resource=subnets'));
  expect(res.status).toBe(200);
  const data = JSON.parse(res.body);
  expect(data).toHaveProperty('subnets');
  // Each subnet should have vlan_name (may be null if no VLAN assigned)
  if (data.subnets.length > 0) {
    expect(Object.prototype.hasOwnProperty.call(data.subnets[0], 'vlan_name')).toBe(true);
  }
});
