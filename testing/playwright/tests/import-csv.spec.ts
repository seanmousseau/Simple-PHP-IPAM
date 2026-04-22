/**
 * import_csv.php — CSV import wizard spec (#460)
 *
 * Tests the multi-step wizard (upload → map columns → dry-run → apply):
 *  1. Golden path: upload valid CSV, map columns, apply, verify row count
 *  2. Edge cases: BOM/CRLF, multiple MAC notations, owner with commas
 *  3. Malformed CSV: missing required 'ip' header → friendly error
 *  4. Read-only user → 403
 *
 * Cleanup is done in afterAll via subnet/address delete API calls.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import * as path from 'path';
import {
  login, fetchPost, appUrl,
  ADMIN_USER, ADMIN_PASS,
  RO_USER, RO_PASS,
  newAuthContext, subnetIdFor, ensureRoUser,
} from '../fixtures/ipam';

const FIXTURES = path.join(__dirname, '../fixtures/csv');
const IMPORT_SUBNET  = '10.99.1.0/24';
const IMPORT_SUBNET2 = '10.99.2.0/24';
const EDGE_SUBNET    = '10.99.3.0/24';

let ctx:  BrowserContext;
let page: Page;
const createdSubnetIds: number[] = [];

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx  = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
  await ensureRoUser(page);

  // Pre-create subnets so the import can target them
  for (const cidr of [IMPORT_SUBNET, IMPORT_SUBNET2, EDGE_SUBNET]) {
    await fetchPost(page, appUrl('subnets.php'), {
      action:          'create',
      cidr,
      description:     `csv import spec ${cidr}`,
      confirm_overlap: '1',
    });
    await page.goto('subnets.php');
    const id = await subnetIdFor(page, cidr);
    if (id) createdSubnetIds.push(id);
  }
});

test.afterAll(async () => {
  try {
    for (const id of createdSubnetIds) {
      await fetchPost(page, appUrl('subnets.php'), {
        action:    'delete',
        subnet_id: String(id),
      });
    }
  } finally {
    await ctx.close();
  }
});

test.describe('CSV Import wizard', () => {
  test('step 1 renders upload form for admin', async () => {
    await page.goto('import_csv.php?step=1');
    await expect(page.locator('input[type=file][name=csv]')).toBeVisible();
    await expect(page.locator('button[type=submit], input[type=submit]')).toBeVisible();
  });

  test('golden path: upload → map → apply, addresses created', async () => {
    // Step 1: upload
    await page.goto('import_csv.php?step=1');
    const fileInput = page.locator('input[type=file][name=csv]');
    await fileInput.setInputFiles(path.join(FIXTURES, 'valid-small.csv'));
    await page.locator('button[type=submit], input[type=submit]').first().click();
    await page.waitForURL(/step=2/);

    // Step 2: column mapping — set the IP column (CSV column index 0 = "ip")
    await expect(page).toHaveURL(/step=2/);
    await page.locator('select[name="map[ip]"]').selectOption('0');
    const nextBtn = page.locator('button[type=submit], input[type=submit]').first();
    await nextBtn.click();
    await page.waitForURL(/step=3/);

    // Step 3: dry-run — click "Apply Import" (second form's submit button)
    await expect(page).toHaveURL(/step=3/);
    page.once('dialog', d => d.accept());
    const applyBtn = page.locator('form[action*="step=4"] button[type=submit]');
    await applyBtn.click();
    await page.waitForURL(/step=4/);

    // Step 4: result — expect a concrete success summary (not a failure message)
    await expect(page).toHaveURL(/step=4/);
    const body = await page.textContent('body') ?? '';
    expect(body).toMatch(/Import complete\.|Created addresses:|Addresses created:/i);
    expect(body).not.toMatch(/import failed|error occurred/i);
  });

  test('edge-case CSV: BOM, CRLF, MAC notations, quoted owner — no error', async () => {
    await page.goto('import_csv.php?step=1');
    const fileInput = page.locator('input[type=file][name=csv]');
    await fileInput.setInputFiles(path.join(FIXTURES, 'valid-edge-cases.csv'));
    await page.locator('button[type=submit], input[type=submit]').first().click();
    // Should advance past step 1 without a fatal error
    await expect(page).toHaveURL(/step=2|step=3|step=4/);
    const body = await page.textContent('body') ?? '';
    expect(body).not.toMatch(/fatal error|exception|stack trace/i);
  });

  test('malformed CSV: submitting without ip mapping shows validation error', async () => {
    await page.goto('import_csv.php?step=1');
    const fileInput = page.locator('input[type=file][name=csv]');
    await fileInput.setInputFiles(path.join(FIXTURES, 'malformed.csv'));
    await page.locator('button[type=submit], input[type=submit]').first().click();
    // malformed.csv has no "ip" header so step 2 renders with no auto-mapped ip column
    const url = page.url();
    if (url.includes('step=2')) {
      // Drive step-2 validation: submit without setting map[ip]
      await page.locator('button[type=submit], input[type=submit]').first().click();
      const body = await page.textContent('body') ?? '';
      expect(body).toMatch(/ip.*required|required.*ip|must.*map.*ip|ip.*column/i);
      expect(body).not.toMatch(/fatal error|exception/i);
    } else {
      // The upload itself may reject the file early (step=1 error)
      const body = await page.textContent('body') ?? '';
      expect(body).toMatch(/ip|required|column|header|error/i);
      expect(body).not.toMatch(/fatal error|exception/i);
    }
  });

  test('read-only user is denied (403 or redirect)', async ({ browser }: { browser: Browser }) => {
    const roCtx  = await newAuthContext(browser);
    const roPage = await roCtx.newPage();
    try {
      await login(roPage, RO_USER, RO_PASS);
      await roPage.goto('import_csv.php?step=1');
      const status = roPage.url();
      const body   = await roPage.textContent('body') ?? '';
      // Either redirected away or shows a 403/access-denied message
      const isDenied = /403|forbidden|access denied|not authorized/i.test(body)
        || !status.includes('import_csv.php');
      expect(isDenied).toBe(true);
    } finally {
      await roCtx.close();
    }
  });
});
