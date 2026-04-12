/**
 * VRFs — CRUD for the vrfs admin page, VRF-subnet integration (VRF picker and badge),
 * delete-guard when subnets are assigned, readonly access control, and
 * API /api.php?resource=vrfs coverage.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchGet, fetchPost, appUrl,
  ADMIN_USER, ADMIN_PASS,
  TEST_VRF_NAME, TEST_VRF_DESC, TEST_VRF_RD, TEST_VRF_CIDR,
  RO_USER, RO_PASS,
  newAuthContext, ensureRoUser,
} from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Ensure the readonly test user exists (db-tools import can wipe it)
  await ensureRoUser(page);

  // Clean up stale test VRFs (and their subnets) from previous failed runs
  await page.goto('vrfs.php');
  const staleIds = await page.evaluate((name) => {
    const ids: string[] = [];
    for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
      const act = f.querySelector<HTMLInputElement>('[name=action]');
      const id  = f.querySelector<HTMLInputElement>('[name=id]');
      if (act?.value === 'delete' && id) {
        const row = f.closest('tr');
        if (row?.innerText.includes(name)) ids.push(id.value);
      }
    }
    return ids;
  }, TEST_VRF_NAME);

  if (staleIds.length > 0) {
    // Clean up any stale subnets in those VRFs first
    await page.goto('subnets.php');
    for (const vId of staleIds) {
      await fetchPost(page, appUrl('subnets.php'), {
        action: 'delete_by_vrf', vrf_id: vId,
      }).catch(() => { /* best-effort; route may not exist */ });
    }
    // Now delete subnets containing the test CIDR
    await page.goto('subnets.php');
    const subnetForms = await page.evaluate((cidr) => {
      const ids: string[] = [];
      for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
        const act = f.querySelector<HTMLInputElement>('[name=action]');
        const id  = f.querySelector<HTMLInputElement>('[name=id]');
        if (act?.value === 'delete' && id) {
          const node = f.closest<HTMLElement>('.subnet-node');
          if (node?.innerText.includes(cidr)) ids.push(id.value);
        }
      }
      return ids;
    }, TEST_VRF_CIDR);
    for (const id of subnetForms) {
      await fetchPost(page, appUrl('subnets.php'), { action: 'delete', id });
    }
    // Delete stale VRFs
    await page.goto('vrfs.php');
    for (const id of staleIds) {
      await fetchPost(page, appUrl('vrfs.php'), { action: 'delete', id }).catch(() => {});
    }
  }
});

test.afterAll(async () => {
  await ctx?.close();
});

// ── Page smoke tests ───────────────────────────────────────────────────────────

test('vrfs page: loads with correct title', async () => {
  await page.goto('vrfs.php');
  await expect(page).toHaveTitle(/VRFs/i);
  await expect(page.locator('h1')).toContainText('VRFs');
});

test('vrfs page: breadcrumb present', async () => {
  await page.goto('vrfs.php');
  await expect(page.locator('.breadcrumbs')).toBeVisible();
  await expect(page.locator('.breadcrumbs')).toContainText('Dashboard');
});

// ── CRUD ───────────────────────────────────────────────────────────────────────

test('vrfs: create a VRF', async () => {
  await page.goto('vrfs.php');

  const createCard = page.locator('#add-vrf');
  await createCard.locator('input[name=name]').fill(TEST_VRF_NAME);
  await createCard.locator('input[name=rd]').fill(TEST_VRF_RD);
  await createCard.locator('input[name=description]').fill(TEST_VRF_DESC);
  await createCard.locator('button[type=submit]').click();

  await page.waitForURL(/vrfs\.php/);
  await expect(page.locator('table')).toContainText(TEST_VRF_NAME);
  await expect(page.locator('table')).toContainText(TEST_VRF_RD);
});

test('vrfs: VRF appears in subnet create picker', async () => {
  await page.goto('subnets.php');
  const vrfSelect = page.locator('select[name=vrf_id]').first();
  await expect(vrfSelect).toBeVisible();
  const optionTexts = await vrfSelect.locator('option').allInnerTexts();
  const hasVrf = optionTexts.some(t => t.includes(TEST_VRF_NAME));
  expect(hasVrf).toBe(true);
});

test('vrfs: create subnet in VRF and verify badge', async () => {
  await page.goto('subnets.php');

  // Find the VRF option value via the picker (verifies the VRF appears in the UI)
  const vrfSelect = page.locator('select[name=vrf_id]').first();
  const options = await vrfSelect.locator('option').all();
  let vrfValue = '';
  for (const opt of options) {
    const text = await opt.innerText();
    if (text.includes(TEST_VRF_NAME)) {
      vrfValue = await opt.getAttribute('value') ?? '';
      break;
    }
  }
  expect(vrfValue, 'Test VRF must appear in the subnet VRF picker').toBeTruthy();

  // Use fetchPost so confirm_overlap can be included — the demo DB has 10.0.0.0/8
  // which would trigger an overlap-confirmation redirect on a browser form submit.
  await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: TEST_VRF_CIDR, description: 'vrf badge test',
    vrf_id: vrfValue, confirm_overlap: '1',
  });

  await page.goto('subnets.php');
  // VRF badge must appear in the subnet row, not just in the picker
  const vrfBadge = page.locator('.badge', { hasText: `VRF: ${TEST_VRF_NAME}` });
  await expect(vrfBadge).toBeVisible();
});

test('vrfs: edit VRF description', async () => {
  await page.goto('vrfs.php');
  const rows = await page.locator('table tbody tr').all();
  for (const row of rows) {
    const text = await row.innerText();
    if (!text.includes(TEST_VRF_NAME)) continue;

    const details = row.locator('details');
    await details.evaluate((el: HTMLDetailsElement) => { el.open = true; });
    const descInput = details.locator('input[name=description]');
    await descInput.fill(TEST_VRF_DESC + '-edited');
    await details.locator('button[type=submit]').first().click();
    await page.waitForURL(/vrfs\.php/);
    await expect(page.locator('table')).toContainText(TEST_VRF_DESC + '-edited');
    break;
  }
});

test('vrfs: cannot delete VRF with assigned subnets', async () => {
  await page.goto('vrfs.php');
  const rows = await page.locator('table tbody tr').all();
  for (const row of rows) {
    const text = await row.innerText();
    if (!text.includes(TEST_VRF_NAME)) continue;

    const details = row.locator('details');
    await details.evaluate((el: HTMLDetailsElement) => { el.open = true; });

    // The delete button should be disabled when subnet_count > 0
    const deleteBtn = details.locator('button.button-danger');
    const isDisabled = await deleteBtn.isDisabled();
    expect(isDisabled, 'Delete button must be disabled when subnets are assigned').toBe(true);
    break;
  }
});

test('vrfs: delete subnet then delete VRF', async () => {
  // Delete the test subnet
  await page.goto('subnets.php');
  const subnetForms = await page.evaluate((cidr) => {
    const ids: string[] = [];
    for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
      const act = f.querySelector<HTMLInputElement>('[name=action]');
      const id  = f.querySelector<HTMLInputElement>('[name=id]');
      if (act?.value === 'delete' && id) {
        const node = f.closest<HTMLElement>('.subnet-node');
        if (node?.innerText.includes(cidr)) ids.push(id.value);
      }
    }
    return ids;
  }, TEST_VRF_CIDR);

  for (const id of subnetForms) {
    // fetchPost uses JS fetch — no browser dialog fires; no page.once needed here
    await fetchPost(page, appUrl('subnets.php'), { action: 'delete', id });
  }

  // Now delete the VRF
  await page.goto('vrfs.php');
  const rows = await page.locator('table tbody tr').all();
  for (const row of rows) {
    const text = await row.innerText();
    if (!text.includes(TEST_VRF_NAME) && !text.includes(TEST_VRF_NAME + '-edited')) continue;

    const details = row.locator('details');
    await details.evaluate((el: HTMLDetailsElement) => { el.open = true; });
    page.once('dialog', d => d.accept());
    await details.locator('button.button-danger').click();
    await page.waitForURL(/vrfs\.php/);
    break;
  }

  await expect(page.locator('body')).not.toContainText(TEST_VRF_NAME);
});

// ── Readonly access control ────────────────────────────────────────────────────

test('vrfs: readonly user gets 403', async () => {
  const roCtx = await newAuthContext(ctx.browser()!);
  const roPage = await roCtx.newPage();
  try {
    await login(roPage, RO_USER, RO_PASS);
    const res = await fetchGet(roPage, appUrl('vrfs.php'));
    expect(res.status).toBe(403);
  } finally {
    await roCtx.close();
  }
});

// ── API ────────────────────────────────────────────────────────────────────────

test('api: GET vrfs returns 200 with vrfs array', async () => {
  if (!process.env.IPAM_API_KEY) {
    test.skip(true, 'IPAM_API_KEY not set — skipping API endpoint test');
    return;
  }
  const res = await fetchGet(page, appUrl('api.php?resource=vrfs'));
  expect(res.status).toBe(200);
  const data = JSON.parse(res.body);
  expect(data).toHaveProperty('vrfs');
  expect(Array.isArray(data.vrfs)).toBe(true);
});
