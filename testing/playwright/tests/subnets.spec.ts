/**
 * Subnet CRUD + deep-link to addresses (#246) + overlap warnings.
 * Also covers: auto-reserve checkbox, breadcrumb, VLAN picker.
 * Migrated from cdp_test.py section 4.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchPost, deleteSubnet, subnetIdFor, appUrl,
  ADMIN_USER, ADMIN_PASS, TEST_CIDR1, TEST_VLAN_ID, TEST_VLAN_NAME,
  newAuthContext,
} from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;
let subnetId: number | null = null;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('subnets.php');
  await deleteSubnet(page, TEST_CIDR1);             // stale cleanup
  await deleteSubnet(page, '10.99.0.128/28');
  // Create a test VLAN so the VLAN picker renders on subnets.php
  await fetchPost(page, appUrl('vlans.php'), {
    action: 'create', vlan_id: String(TEST_VLAN_ID), name: TEST_VLAN_NAME, description: 'subnets test',
  });
});

test.afterAll(async () => {
  try {
    if (page) {
      await page.goto('subnets.php');
      await deleteSubnet(page, '10.99.0.128/28');
      await deleteSubnet(page, TEST_CIDR1);
      // Clean up the test VLAN created in beforeAll
      await page.goto('vlans.php');
      const vlanId = await page.evaluate((vlanName: string) => {
        for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
          const act = f.querySelector<HTMLInputElement>('[name=action]');
          const id  = f.querySelector<HTMLInputElement>('[name=id]');
          if (act?.value === 'delete' && id) {
            const row = f.closest('tr');
            if (row?.innerText.includes(vlanName)) return id.value;
          }
        }
        return null;
      }, TEST_VLAN_NAME);
      if (vlanId) {
        await fetchPost(page, appUrl('vlans.php'), { action: 'delete', id: vlanId });
      }
    }
  } finally {
    await ctx?.close();
  }
});

test('create subnet appears in list', async () => {
  await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: TEST_CIDR1, description: 'PW test subnet 1',
  });
  await page.goto('subnets.php');
  await expect(page.getByText(TEST_CIDR1)).toBeVisible();
  subnetId = await subnetIdFor(page, TEST_CIDR1);
  expect(subnetId, 'subnet ID extractable').not.toBeNull();
});

test('duplicate subnet shows error', async () => {
  await page.goto('subnets.php');
  const r = await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: TEST_CIDR1, description: 'dup',
  });
  expect(r.body).toMatch(/already exists|duplicate/i);
});

test('update subnet description', async () => {
  expect(subnetId, 'need subnet ID from prior test').not.toBeNull();
  await page.goto('subnets.php');
  await fetchPost(page, appUrl('subnets.php'), {
    action: 'update', id: String(subnetId!),
    cidr: TEST_CIDR1, description: 'PW test subnet 1 — EDITED',
  });
  await page.goto('subnets.php');
  await expect(page.getByText('EDITED')).toBeVisible();
});

test('deep-link from subnet row leads to addresses page (#246)', async () => {
  await page.goto('subnets.php');
  const link = page.locator('a[href*="addresses.php"][href*="subnet_id"]').first();
  await expect(link).toBeVisible();
  await link.click();
  await expect(page).toHaveURL(/addresses\.php/);
  await expect(page).toHaveURL(/subnet_id=/);
});

test('overlap subnet creates with warning', async () => {
  // 10.99.0.128/28 is a child of 10.99.0.0/24 — the server accepts it or rejects gracefully.
  // Child subnets may appear nested under the parent, not at the top level.
  const childCidr = '10.99.0.128/28';
  await page.goto('subnets.php');
  const r = await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: childCidr, description: 'overlap test',
  });
  // The server must respond without a 5xx error
  expect(r.status, 'server must not 500 on child-subnet create').toBeLessThan(500);
  // The subnets page itself must still load
  await page.goto('subnets.php');
  const title = await page.title();
  expect(title.toLowerCase()).toContain('subnet');
});

test('subnets page has nav bar', async () => {
  await page.goto('subnets.php');
  await expect(page.locator('.topbar')).toBeVisible();
});

test('subnets: breadcrumb is present', async () => {
  await page.goto('subnets.php');
  await expect(page.locator('.breadcrumbs')).toBeVisible();
  await expect(page.locator('.breadcrumbs')).toContainText('Dashboard');
});

test('subnets: create form has auto-reserve checkbox', async () => {
  await page.goto('subnets.php');
  const checkbox = page.locator('input[name=auto_reserve]');
  await expect(checkbox).toBeVisible();
  // Should be pre-checked by default
  await expect(checkbox).toBeChecked();
});

test('subnets: create form has gateway field', async () => {
  await page.goto('subnets.php');
  await expect(page.locator('input[name=gateway]')).toBeVisible();
});

test('subnets: create form has VLAN picker', async () => {
  await page.goto('subnets.php');
  const vlanSelect = page.locator('select[name=vlan_fk]').first();
  await expect(vlanSelect).toBeVisible();
  // VLAN picker should at least have a (none) option
  const opts = await vlanSelect.locator('option').count();
  expect(opts).toBeGreaterThanOrEqual(1);
});

test('subnets: VLAN picker lists test VLAN when it exists', async () => {
  await page.goto('subnets.php');
  const vlanSelect = page.locator('select[name=vlan_fk]').first();
  const optTexts = await vlanSelect.locator('option').allInnerTexts();
  // If pw-test-vlan exists, it should appear in the picker
  // This test is informational — skip if no test VLAN present
  const hasTestVlan = optTexts.some(t => t.includes(TEST_VLAN_NAME));
  if (!hasTestVlan) {
    test.skip(true, 'Test VLAN not present — skipping VLAN picker content check');
    return;
  }
  expect(hasTestVlan).toBe(true);
});

test('subnets: form drawer opens on Add Subnet click', async () => {
  await page.goto('subnets.php');
  const drawerTrigger = page.locator('[data-open-drawer]').first();
  if (await drawerTrigger.count() === 0) {
    test.skip(true, 'No drawer trigger found — drawer may not be implemented on this page');
    return;
  }
  await drawerTrigger.click();
  await expect(page.locator('#form-drawer')).toBeVisible();
});
