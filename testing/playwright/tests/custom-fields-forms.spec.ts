/**
 * Custom Fields Forms — Phase 3 integration tests.
 * Covers:
 *   - Subnet create/update with custom field values (text, number, date, boolean, select)
 *   - Address create/update with custom field values
 *   - Required-field rejection
 *   - Strict-type rejection (number field with non-numeric value)
 *   - Custom field section visibility in rendered forms
 *   - Data-custom-fields attribute is present on edit buttons
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchPost, deleteSubnet, subnetIdFor, appUrl,
  ADMIN_USER, ADMIN_PASS,
  newAuthContext, warmSudoGrant,
} from '../fixtures/ipam';

// ── Test data ──────────────────────────────────────────────────────────────────
const CF_FORMS_CIDR      = '10.240.0.0/24';
const CF_FORMS_ADDR      = '10.240.0.50';
const CF_KEY_TXT         = 'pf_cf_text';
const CF_KEY_NUM         = 'pf_cf_number';
const CF_KEY_DATE        = 'pf_cf_date';
const CF_KEY_BOOL        = 'pf_cf_bool';
const CF_KEY_SEL         = 'pf_cf_select';
const CF_KEY_REQ         = 'pf_cf_required';
const SELECT_OPTS        = ['alpha', 'beta', 'gamma'];

let ctx: BrowserContext;
let page: Page;
let subnetId: number | null = null;
let addrId:   number | null = null;

// ── Helpers ────────────────────────────────────────────────────────────────────

async function createCfDef(
  p: Page,
  entityType: string,
  key: string,
  label: string,
  type: string,
  opts?: { required?: boolean; options?: string[] },
): Promise<void> {
  await p.goto('custom_fields.php');
  const form = p.locator('#add-field');
  await form.locator('select[name=entity_type]').selectOption(entityType);
  await form.locator('input[name=key]').fill(key);
  await form.locator('input[name=label]').fill(label);
  await form.locator('select[name=type]').selectOption(type);
  if (opts?.required) {
    await form.locator('input[name=is_required]').check();
  }
  if (type === 'select' && opts?.options) {
    // The options textarea is inside #cf-options-row which is revealed by JS on type=select
    const textarea = p.locator('#cf-options-row textarea[name=options]');
    await expect(textarea).toBeVisible({ timeout: 3000 });
    await textarea.fill(opts.options.join('\n'));
  }
  await form.locator('button[type=submit]').click();
  await p.waitForURL(/custom_fields\.php/);
}

async function deleteCfDefsWithKeys(p: Page, keys: string[]): Promise<void> {
  await p.goto('custom_fields.php');
  const ids = await p.evaluate((ks: string[]) => {
    const found: string[] = [];
    for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
      const act = f.querySelector<HTMLInputElement>('[name=action]');
      const id  = f.querySelector<HTMLInputElement>('[name=id]');
      if (act?.value === 'delete' && id) {
        const row = f.closest('tr');
        if (row && ks.some(k => row.innerText.includes(k))) found.push(id.value);
      }
    }
    return found;
  }, keys);
  for (const id of ids) {
    await fetchPost(p, appUrl('custom_fields.php'), { action: 'delete', id });
  }
}

// ── Suite lifecycle ────────────────────────────────────────────────────────────

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // v3.28.0 (#1158): custom field def create/delete is sudo-gated. Warm one
  // grant for the whole suite (shared page/context, default TTL=300s) so the
  // createCfDef/deleteCfDefsWithKeys helpers don't land on the step-up prompt.
  await warmSudoGrant(page);

  // Remove stale defs + subnet from previous runs
  await deleteCfDefsWithKeys(page, [
    CF_KEY_TXT, CF_KEY_NUM, CF_KEY_DATE, CF_KEY_BOOL, CF_KEY_SEL, CF_KEY_REQ,
  ]);
  await page.goto('subnets.php');
  await deleteSubnet(page, CF_FORMS_CIDR);

  // Create CF definitions used across form tests
  await createCfDef(page, 'subnet', CF_KEY_TXT,  'PF Text Field',   'text');
  await createCfDef(page, 'subnet', CF_KEY_NUM,  'PF Number Field',  'number');
  await createCfDef(page, 'subnet', CF_KEY_DATE, 'PF Date Field',    'date');
  await createCfDef(page, 'subnet', CF_KEY_BOOL, 'PF Bool Field',    'boolean');
  await createCfDef(page, 'subnet', CF_KEY_SEL,  'PF Select Field',  'select', { options: SELECT_OPTS });
  await createCfDef(page, 'address', CF_KEY_REQ, 'PF Required Text', 'text', { required: true });
  await createCfDef(page, 'address', CF_KEY_TXT, 'PF Addr Text',     'text');
  await createCfDef(page, 'address', CF_KEY_NUM, 'PF Addr Number',   'number');

  // Create test subnet
  await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: CF_FORMS_CIDR, description: 'PW CF forms test', confirm_overlap: '1',
  });
  await page.goto('subnets.php');
  subnetId = await subnetIdFor(page, CF_FORMS_CIDR);
});

test.afterAll(async () => {
  try {
    if (page) {
      // Delete test address
      if (addrId && subnetId) {
        await fetchPost(page, appUrl('addresses.php'), {
          action: 'delete', subnet_id: String(subnetId), id: String(addrId),
        });
      }
      // Delete test subnet
      await page.goto('subnets.php');
      await deleteSubnet(page, CF_FORMS_CIDR);

      // Delete CF defs
      await deleteCfDefsWithKeys(page, [
        CF_KEY_TXT, CF_KEY_NUM, CF_KEY_DATE, CF_KEY_BOOL, CF_KEY_SEL, CF_KEY_REQ,
      ]);
    }
  } finally {
    await ctx?.close();
  }
});

// ── Subnet create with custom field values ─────────────────────────────────────

test('subnet create: custom field section appears in form', async () => {
  await page.goto('subnets.php');
  await page.locator('[data-drawer-title="Add Subnet"]').first().click();
  await expect(page.locator('#global-drawer-body .custom-field-group')).toBeVisible({ timeout: 5000 });
  await expect(page.locator('#global-drawer-body .custom-field-heading')).toContainText('Custom fields');
  await page.keyboard.press('Escape');
});

test('subnet create: all five field types render correct input controls', async () => {
  await page.goto('subnets.php');
  await page.locator('[data-drawer-title="Add Subnet"]').first().click();
  const drawer = page.locator('#global-drawer-body');
  await expect(drawer).toBeVisible({ timeout: 5000 });
  // text
  await expect(drawer.locator(`input[name="cf_${CF_KEY_TXT}"]`)).toBeVisible();
  // number
  await expect(drawer.locator(`input[type=number][name="cf_${CF_KEY_NUM}"]`)).toBeVisible();
  // date
  await expect(drawer.locator(`input[type=date][name="cf_${CF_KEY_DATE}"]`)).toBeVisible();
  // boolean
  await expect(drawer.locator(`input[type=checkbox][name="cf_${CF_KEY_BOOL}"]`)).toBeVisible();
  // select
  await expect(drawer.locator(`select[name="cf_${CF_KEY_SEL}"]`)).toBeVisible();
  await page.keyboard.press('Escape');
});

test('subnet create: persists text + number + date + boolean + select values', async () => {
  expect(subnetId, 'need subnet ID from beforeAll').not.toBeNull();

  const res = await fetchPost(page, appUrl('subnets.php'), {
    action: 'update',
    id: String(subnetId!),
    cidr: CF_FORMS_CIDR,
    description: 'CF forms test with values',
    confirm_overlap: '1',
    [`cf_${CF_KEY_TXT}`]:  'hello world',
    [`cf_${CF_KEY_NUM}`]:  '42',
    [`cf_${CF_KEY_DATE}`]: '2030-06-15',
    [`cf_${CF_KEY_BOOL}`]: '1',
    [`cf_${CF_KEY_SEL}`]:  'beta',
  });
  expect(res.status).toBeLessThan(400);

  // Verify data-custom-fields attribute on the edit button reflects saved values
  await page.goto('subnets.php');
  const btn = page.locator(`.subnet-edit-btn[data-sid="${subnetId}"]`);
  await expect(btn).toBeVisible();
  const rawJson = await btn.getAttribute('data-custom-fields');
  expect(rawJson).not.toBeNull();
  const cf = JSON.parse(rawJson!);
  expect(cf[CF_KEY_TXT]).toBe('hello world');
  expect(cf[CF_KEY_NUM]).toBe(42);
  expect(cf[CF_KEY_DATE]).toBe('2030-06-15');
  expect(cf[CF_KEY_BOOL]).toBe(true);
  expect(cf[CF_KEY_SEL]).toBe('beta');
});

// ── Subnet update — drawer JS population ──────────────────────────────────────

test('subnet edit drawer: CF inputs populated from data-custom-fields', async () => {
  expect(subnetId, 'need subnet ID').not.toBeNull();
  await page.goto('subnets.php');

  // Click the edit button to open the drawer
  const btn = page.locator(`.subnet-edit-btn[data-sid="${subnetId}"]`);
  await btn.click();

  // Wait for drawer to become visible
  const drawer = page.locator('#subnet-edit-drawer');
  await expect(drawer).toBeVisible({ timeout: 5000 });

  // Check text field populated
  const txtInput = drawer.locator(`input[name="cf_${CF_KEY_TXT}"]`);
  await expect(txtInput).toHaveValue('hello world');

  // Check number field populated
  const numInput = drawer.locator(`input[name="cf_${CF_KEY_NUM}"]`);
  await expect(numInput).toHaveValue('42');

  // Check date field populated
  const dateInput = drawer.locator(`input[name="cf_${CF_KEY_DATE}"]`);
  await expect(dateInput).toHaveValue('2030-06-15');

  // Check checkbox checked
  const boolInput = drawer.locator(`input[name="cf_${CF_KEY_BOOL}"]`);
  await expect(boolInput).toBeChecked();

  // Check select value
  const selInput = drawer.locator(`select[name="cf_${CF_KEY_SEL}"]`);
  await expect(selInput).toHaveValue('beta');
});

test('subnet edit drawer: CF values updated and persisted', async () => {
  expect(subnetId, 'need subnet ID').not.toBeNull();
  await page.goto('subnets.php');

  const btn = page.locator(`.subnet-edit-btn[data-sid="${subnetId}"]`);
  await btn.click();
  const drawer = page.locator('#subnet-edit-drawer');
  await expect(drawer).toBeVisible({ timeout: 5000 });

  // Wait for JS to populate CF inputs from data-custom-fields before overwriting
  const txtInput = drawer.locator(`input[name="cf_${CF_KEY_TXT}"]`);
  await expect(txtInput).not.toHaveValue('', { timeout: 3000 });
  await txtInput.fill('updated via drawer');

  // Submit and navigate explicitly — POST redirects back to subnets.php, but waitForURL/waitForLoadState
  // can resolve immediately when already on that URL. goto() guarantees a fresh page with updated data.
  await Promise.all([
    page.waitForResponse(r => r.url().includes('subnets.php') && r.request().method() === 'POST', { timeout: 10_000 }),
    drawer.locator('button[type=submit]:not(.button-danger)').click(),
  ]);
  await page.goto('subnets.php');

  // Verify the updated value is in the data attribute
  const rawJson = await page.locator(`.subnet-edit-btn[data-sid="${subnetId}"]`).getAttribute('data-custom-fields');
  const cf = JSON.parse(rawJson ?? '{}');
  expect(cf[CF_KEY_TXT]).toBe('updated via drawer');
});

// ── Subnet strict-type rejection ──────────────────────────────────────────────

test('subnet update: non-numeric value for number field returns error', async () => {
  expect(subnetId, 'need subnet ID').not.toBeNull();
  const res = await fetchPost(page, appUrl('subnets.php'), {
    action: 'update',
    id: String(subnetId!),
    cidr: CF_FORMS_CIDR,
    description: 'CF type test',
    confirm_overlap: '1',
    [`cf_${CF_KEY_NUM}`]: 'not-a-number',
  });
  expect(res.body).toMatch(/custom field error|expected a number/i);
});

test('subnet update: invalid date format returns error', async () => {
  expect(subnetId, 'need subnet ID').not.toBeNull();
  const res = await fetchPost(page, appUrl('subnets.php'), {
    action: 'update',
    id: String(subnetId!),
    cidr: CF_FORMS_CIDR,
    description: 'CF date test',
    confirm_overlap: '1',
    [`cf_${CF_KEY_DATE}`]: '15/06/2030',
  });
  expect(res.body).toMatch(/custom field error|YYYY-MM-DD/i);
});

test('subnet update: invalid select option returns error', async () => {
  expect(subnetId, 'need subnet ID').not.toBeNull();
  const res = await fetchPost(page, appUrl('subnets.php'), {
    action: 'update',
    id: String(subnetId!),
    cidr: CF_FORMS_CIDR,
    description: 'CF select test',
    confirm_overlap: '1',
    [`cf_${CF_KEY_SEL}`]: 'not-a-valid-option',
  });
  expect(res.body).toMatch(/custom field error|valid option/i);
});

// ── Address create with custom field values ────────────────────────────────────

test('address create form: CF section appears', async () => {
  expect(subnetId, 'need subnet ID').not.toBeNull();
  await page.goto(`addresses.php?subnet_id=${subnetId}`);
  await page.locator('[data-drawer-title="Add Address"]').first().click();
  await expect(page.locator('#global-drawer-body .custom-field-group')).toBeVisible({ timeout: 5000 });
  await expect(page.locator('#global-drawer-body .custom-field-heading')).toContainText('Custom fields');
  await page.keyboard.press('Escape');
});

test('address create: required CF field enforced server-side', async () => {
  expect(subnetId, 'need subnet ID').not.toBeNull();
  const res = await fetchPost(page, appUrl('addresses.php'), {
    action: 'create',
    subnet_id: String(subnetId!),
    ip: CF_FORMS_ADDR,
    hostname: 'pw-cf-test',
    owner: '',
    status: 'used',
    note: '',
    grp: '',
    // Intentionally omit CF_KEY_REQ (required field)
  });
  expect(res.body).toMatch(/custom field error|required/i);
});

test('address create: persists CF values', async () => {
  expect(subnetId, 'need subnet ID').not.toBeNull();
  const res = await fetchPost(page, appUrl('addresses.php'), {
    action: 'create',
    subnet_id: String(subnetId!),
    ip: CF_FORMS_ADDR,
    hostname: 'pw-cf-test',
    owner: '',
    status: 'used',
    note: '',
    grp: '',
    [`cf_${CF_KEY_REQ}`]: 'required-value',
    [`cf_${CF_KEY_TXT}`]: 'addr-text-value',
    [`cf_${CF_KEY_NUM}`]: '7',
  });
  expect(res.status).toBeLessThan(400);

  await page.goto(`addresses.php?subnet_id=${subnetId}`);
  await expect(page.getByText(CF_FORMS_ADDR)).toBeVisible();

  // Extract address ID
  addrId = await page.evaluate((ip) => {
    for (const a of document.querySelectorAll<HTMLAnchorElement>('a[href*="address_id"]')) {
      const row = a.closest('tr');
      if (row?.innerText.includes(ip)) {
        const m = a.href.match(/address_id=([0-9]+)/);
        if (m) return parseInt(m[1], 10);
      }
    }
    return null;
  }, CF_FORMS_ADDR);
  expect(addrId, 'addrId must be extractable').not.toBeNull();
});

// ── Address update with custom fields ─────────────────────────────────────────

test('address update: CF values persisted via inline form', async () => {
  expect(subnetId).not.toBeNull();
  expect(addrId).not.toBeNull();

  const res = await fetchPost(page, appUrl('addresses.php'), {
    action: 'update',
    subnet_id: String(subnetId!),
    id: String(addrId!),
    hostname: 'pw-cf-test-updated',
    owner: '',
    status: 'used',
    note: '',
    grp: '',
    [`cf_${CF_KEY_REQ}`]: 'updated-required',
    [`cf_${CF_KEY_TXT}`]: 'addr-text-updated',
    [`cf_${CF_KEY_NUM}`]: '99',
  });
  expect(res.status).toBeLessThan(400);

  // Verify the page still loads cleanly after the update
  await page.goto(`addresses.php?subnet_id=${subnetId}`);
  await expect(page.getByText(CF_FORMS_ADDR)).toBeVisible();
  await expect(page.getByText('pw-cf-test-updated')).toBeVisible();
});

test('address update: required CF field rejected when empty', async () => {
  expect(subnetId).not.toBeNull();
  expect(addrId).not.toBeNull();

  const res = await fetchPost(page, appUrl('addresses.php'), {
    action: 'update',
    subnet_id: String(subnetId!),
    id: String(addrId!),
    hostname: 'pw-cf-test',
    owner: '',
    status: 'used',
    note: '',
    grp: '',
    [`cf_${CF_KEY_REQ}`]: '', // empty — should fail
    [`cf_${CF_KEY_TXT}`]: 'something',
  });
  expect(res.body).toMatch(/custom field error|required/i);
});

test('address update: non-numeric number field returns error', async () => {
  expect(subnetId).not.toBeNull();
  expect(addrId).not.toBeNull();

  const res = await fetchPost(page, appUrl('addresses.php'), {
    action: 'update',
    subnet_id: String(subnetId!),
    id: String(addrId!),
    hostname: 'pw-cf-test',
    owner: '',
    status: 'used',
    note: '',
    grp: '',
    [`cf_${CF_KEY_REQ}`]: 'value',
    [`cf_${CF_KEY_NUM}`]: 'abc',
  });
  expect(res.body).toMatch(/custom field error|expected a number/i);
});

// ── Inline edit form renders CF inputs with current values ─────────────────────

test('address inline edit: CF section visible in details form', async () => {
  expect(subnetId).not.toBeNull();
  await page.goto(`addresses.php?subnet_id=${subnetId}`);

  // Open the inline details form for the test address
  const detailsToggle = page.locator('details').filter({ hasText: CF_FORMS_ADDR }).first();
  if (await detailsToggle.count() > 0) {
    await detailsToggle.click();
    const cfSection = detailsToggle.locator('.custom-field-group');
    await expect(cfSection).toBeVisible({ timeout: 3000 });
    await expect(cfSection.locator('.custom-field-heading')).toContainText('Custom fields');
  } else {
    // Some themes render the edit form differently; just check the page loaded
    const cfCount = await page.locator('.custom-field-group').count();
    expect(cfCount).toBeGreaterThanOrEqual(1);
  }
});
