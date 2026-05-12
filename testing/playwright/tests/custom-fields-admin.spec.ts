/**
 * Custom Fields Admin — CRUD for custom_fields.php, admin role guard, JS behaviour,
 * type filter, refuse-if-in-use delete guard, and options-editor visibility.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchGet, fetchPost, appUrl,
  ADMIN_USER, ADMIN_PASS,
  RO_USER, RO_PASS,
  newAuthContext, ensureRoUser, warmSudoGrant,
} from '../fixtures/ipam';

const CF_KEY_TEXT     = 'pw_cf_text';
const CF_KEY_NUMBER   = 'pw_cf_number';
const CF_KEY_DATE     = 'pw_cf_date';
const CF_KEY_BOOL     = 'pw_cf_bool';
const CF_KEY_SELECT   = 'pw_cf_select';
const CF_LABEL_TEXT   = 'PW CF Text';
const CF_LABEL_SELECT = 'PW CF Select';

let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  await ensureRoUser(page);

  // v3.28.0 (#1158): custom field def create/update/delete is sudo-gated.
  // Warm a sudo grant once for the whole suite (shared page/context, default
  // TTL=300s) so the form-driven CRUD tests and the fetchPost cleanup helpers
  // below don't each land on the step-up prompt.
  await warmSudoGrant(page);

  // Clean up stale test custom field defs from previous failed runs
  await page.goto('custom_fields.php');
  const staleIds = await page.evaluate((keys: string[]) => {
    const ids: string[] = [];
    for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
      const act = f.querySelector<HTMLInputElement>('[name=action]');
      const id  = f.querySelector<HTMLInputElement>('[name=id]');
      if (act?.value === 'delete' && id) {
        const row = f.closest('tr');
        if (row && keys.some(k => row.innerText.includes(k))) ids.push(id.value);
      }
    }
    return ids;
  }, [CF_KEY_TEXT, CF_KEY_NUMBER, CF_KEY_DATE, CF_KEY_BOOL, CF_KEY_SELECT]);
  for (const id of staleIds) {
    await fetchPost(page, appUrl('custom_fields.php'), { action: 'delete', id });
  }
});

test.afterAll(async () => {
  await ctx?.close();
});

// ── Page smoke ─────────────────────────────────────────────────────────────────

test('custom-fields page: loads with correct title', async () => {
  await page.goto('custom_fields.php');
  await expect(page).toHaveTitle(/Custom Fields/i);
  await expect(page.locator('h1')).toContainText('Custom Fields');
});

test('custom-fields page: breadcrumb present', async () => {
  await page.goto('custom_fields.php');
  await expect(page.locator('.breadcrumbs')).toBeVisible();
  await expect(page.locator('.breadcrumbs')).toContainText('Dashboard');
});

test('custom-fields page: nav sidebar link present', async () => {
  await page.goto('dashboard.php');
  await expect(page.locator(".sidebar-link[href='custom_fields.php']")).toBeVisible();
});

// ── Role guard ─────────────────────────────────────────────────────────────────

test('custom-fields: readonly user gets 403', async () => {
  const roCtx = await newAuthContext(ctx.browser()!);
  const roPage = await roCtx.newPage();
  try {
    await login(roPage, RO_USER, RO_PASS);
    const res = await fetchGet(roPage, appUrl('custom_fields.php'));
    expect(res.status).toBe(403);
  } finally {
    await roCtx.close();
  }
});

// ── Create — all five field types ──────────────────────────────────────────────

test('custom-fields: create text field (subnet)', async () => {
  await page.goto('custom_fields.php');

  const form = page.locator('#add-field');
  await form.locator('select[name=entity_type]').selectOption('subnet');
  await form.locator('input[name=key]').fill(CF_KEY_TEXT);
  await form.locator('input[name=label]').fill(CF_LABEL_TEXT);
  await form.locator('select[name=type]').selectOption('text');
  await form.locator('button[type=submit]').click();

  await page.waitForURL(/custom_fields\.php/);
  await expect(page.locator('table')).toContainText(CF_KEY_TEXT);
  await expect(page.locator('table')).toContainText(CF_LABEL_TEXT);
});

test('custom-fields: create number field (subnet)', async () => {
  await page.goto('custom_fields.php');

  const form = page.locator('#add-field');
  await form.locator('select[name=entity_type]').selectOption('subnet');
  await form.locator('input[name=key]').fill(CF_KEY_NUMBER);
  await form.locator('input[name=label]').fill('PW CF Number');
  await form.locator('select[name=type]').selectOption('number');
  await form.locator('button[type=submit]').click();

  await page.waitForURL(/custom_fields\.php/);
  await expect(page.locator('table')).toContainText(CF_KEY_NUMBER);
});

test('custom-fields: create date field (address)', async () => {
  await page.goto('custom_fields.php');

  const form = page.locator('#add-field');
  await form.locator('select[name=entity_type]').selectOption('address');
  await form.locator('input[name=key]').fill(CF_KEY_DATE);
  await form.locator('input[name=label]').fill('PW CF Date');
  await form.locator('select[name=type]').selectOption('date');
  await form.locator('button[type=submit]').click();

  await page.waitForURL(/custom_fields\.php/);
  await expect(page.locator('table')).toContainText(CF_KEY_DATE);
});

test('custom-fields: create boolean field (address)', async () => {
  await page.goto('custom_fields.php');

  const form = page.locator('#add-field');
  await form.locator('select[name=entity_type]').selectOption('address');
  await form.locator('input[name=key]').fill(CF_KEY_BOOL);
  await form.locator('input[name=label]').fill('PW CF Boolean');
  await form.locator('select[name=type]').selectOption('boolean');
  await form.locator('button[type=submit]').click();

  await page.waitForURL(/custom_fields\.php/);
  await expect(page.locator('table')).toContainText(CF_KEY_BOOL);
});

test('custom-fields: create select field with options', async () => {
  await page.goto('custom_fields.php');

  const form = page.locator('#add-field');
  await form.locator('select[name=entity_type]').selectOption('subnet');
  await form.locator('input[name=key]').fill(CF_KEY_SELECT);
  await form.locator('input[name=label]').fill(CF_LABEL_SELECT);
  await form.locator('select[name=type]').selectOption('select');

  // Options textarea should now be visible
  const optionsTa = form.locator('textarea[name=options]');
  await expect(optionsTa).toBeVisible();
  await optionsTa.fill('low\nmedium\nhigh');
  await form.locator('button[type=submit]').click();

  await page.waitForURL(/custom_fields\.php/);
  await expect(page.locator('table')).toContainText(CF_KEY_SELECT);
  await expect(page.locator('table')).toContainText(CF_LABEL_SELECT);
});

// ── Options editor JS toggle ───────────────────────────────────────────────────

test('custom-fields: options textarea hidden for non-select types', async () => {
  await page.goto('custom_fields.php');
  const form    = page.locator('#add-field');
  const typeSelect = form.locator('select[name=type]');
  const optionsTa  = form.locator('textarea[name=options]');

  for (const t of ['text', 'number', 'date', 'boolean']) {
    await typeSelect.selectOption(t);
    await expect(optionsTa).toBeHidden();
  }

  await typeSelect.selectOption('select');
  await expect(optionsTa).toBeVisible();
});

// ── Metrics card ───────────────────────────────────────────────────────────────

test('custom-fields: metrics show non-zero counts after creates', async () => {
  await page.goto('custom_fields.php');
  const metricValues = await page.locator('.metric .value').allInnerTexts();
  const total = parseInt(metricValues[0] ?? '0', 10);
  expect(total).toBeGreaterThanOrEqual(1);
});

// ── Type filter tabs ───────────────────────────────────────────────────────────

test('custom-fields: subnet filter shows only subnet fields', async () => {
  await page.goto('custom_fields.php?type=subnet');
  const table = page.locator('table');
  await expect(table).toContainText(CF_KEY_TEXT);
  // address fields should not appear
  await expect(table).not.toContainText(CF_KEY_DATE);
  await expect(table).not.toContainText(CF_KEY_BOOL);
});

test('custom-fields: address filter shows only address fields', async () => {
  await page.goto('custom_fields.php?type=address');
  const table = page.locator('table');
  await expect(table).toContainText(CF_KEY_DATE);
  await expect(table).toContainText(CF_KEY_BOOL);
  // subnet fields should not appear
  await expect(table).not.toContainText(CF_KEY_TEXT);
});

test('custom-fields: all filter shows all fields', async () => {
  await page.goto('custom_fields.php');
  const table = page.locator('table');
  await expect(table).toContainText(CF_KEY_TEXT);
  await expect(table).toContainText(CF_KEY_DATE);
});

// ── Duplicate key rejection ────────────────────────────────────────────────────

test('custom-fields: duplicate key rejected with error', async () => {
  await page.goto('custom_fields.php');
  const form = page.locator('#add-field');
  await form.locator('select[name=entity_type]').selectOption('subnet');
  await form.locator('input[name=key]').fill(CF_KEY_TEXT); // already exists for subnet
  await form.locator('input[name=label]').fill('Duplicate Label');
  await form.locator('select[name=type]').selectOption('text');
  await form.locator('button[type=submit]').click();

  await page.waitForURL(/custom_fields\.php/);
  // Should show a page-level error message, not create a second row
  await expect(page.locator('p.danger')).toBeVisible();
  // Verify only one row with this key (code.monospace inside the table cell)
  const keyCount = await page.locator('table tbody tr')
    .filter({ hasText: CF_KEY_TEXT })
    .count();
  expect(keyCount).toBe(1);
});

// ── Edit field ─────────────────────────────────────────────────────────────────

test('custom-fields: edit label of text field', async () => {
  await page.goto('custom_fields.php');
  const row = page.locator('table tbody tr').filter({ hasText: CF_KEY_TEXT }).first();
  await expect(row).toBeVisible();

  const details = row.locator('details');
  await details.evaluate((el: HTMLDetailsElement) => { el.open = true; });
  const labelInput = details.locator('input[name=label]');
  await labelInput.fill(CF_LABEL_TEXT + '-v2');
  await details.locator('button[type=submit]').first().click();
  await page.waitForURL(/custom_fields\.php/);
  await expect(page.locator('table')).toContainText(CF_LABEL_TEXT + '-v2');
});

test('custom-fields: edit options of select field', async () => {
  await page.goto('custom_fields.php');
  const row = page.locator('table tbody tr').filter({ hasText: CF_KEY_SELECT }).first();
  await expect(row).toBeVisible();

  const details = row.locator('details');
  await details.evaluate((el: HTMLDetailsElement) => { el.open = true; });
  const optionsTa = details.locator('textarea[name=options]');
  await optionsTa.fill('critical\nhigh\nlow');
  await details.locator('button[type=submit]').first().click();
  await page.waitForURL(/custom_fields\.php/);
  // Options count should update to 3
  const updatedRow = page.locator('table tbody tr').filter({ hasText: CF_KEY_SELECT }).first();
  await expect(updatedRow).toContainText('3 options');
});

// ── In-use delete guard ────────────────────────────────────────────────────────

test('custom-fields: delete guard blocks when field has values', async () => {
  // This test requires Phase 3 (form rendering on subnet/address edit) to set
  // a custom field value via the UI. It is deferred to custom-fields-forms.spec.ts
  // which runs after Phase 3 is complete.
  test.skip(true, 'Requires Phase 3 form integration to assign a custom field value');
});

// ── Delete ─────────────────────────────────────────────────────────────────────

test('custom-fields: delete text field', async () => {
  await page.goto('custom_fields.php');
  const row = page.locator('table tbody tr').filter({ hasText: CF_KEY_TEXT }).first();
  await expect(row).toBeVisible();

  const details = row.locator('details');
  await details.evaluate((el: HTMLDetailsElement) => { el.open = true; });
  page.once('dialog', d => d.accept());
  await details.locator('button.button-danger').click();
  await page.waitForURL(/custom_fields\.php/);
  await expect(page.locator('body')).not.toContainText(CF_KEY_TEXT);
});

test('custom-fields: delete remaining test fields', async () => {
  await page.goto('custom_fields.php');
  const staleIds = await page.evaluate((keys: string[]) => {
    const ids: string[] = [];
    for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
      const act = f.querySelector<HTMLInputElement>('[name=action]');
      const id  = f.querySelector<HTMLInputElement>('[name=id]');
      if (act?.value === 'delete' && id) {
        const row = f.closest('tr');
        if (row && keys.some(k => row.innerText.includes(k))) ids.push(id.value);
      }
    }
    return ids;
  }, [CF_KEY_NUMBER, CF_KEY_DATE, CF_KEY_BOOL, CF_KEY_SELECT]);
  for (const id of staleIds) {
    await fetchPost(page, appUrl('custom_fields.php'), { action: 'delete', id });
  }
  await page.reload();
  await expect(page.locator('body')).not.toContainText(CF_KEY_NUMBER);
  await expect(page.locator('body')).not.toContainText(CF_KEY_SELECT);
});
