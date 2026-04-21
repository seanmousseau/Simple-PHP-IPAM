/**
 * Phase 5: custom_fields round-trip through export_addresses.php and import_csv.php.
 * Tests that the custom_fields JSON column:
 *   - appears in the per-subnet CSV export header
 *   - can be imported via the CSV wizard (mapping + apply) and persists
 *   - rejects invalid JSON in the mapped column
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchGet, fetchPost, fetchPostForm, deleteSubnet, subnetIdFor, appUrl,
  ADMIN_USER, ADMIN_PASS,
  newAuthContext,
} from '../fixtures/ipam';

const CF_CSV_CIDR    = '10.93.0.0/24';
const CF_CSV_IP      = '10.93.0.5';
const CF_CSV_IMPORT  = '10.93.0.6';
const CF_CSV_INVALID = '10.93.0.7';
const CF_KEY         = 'cf_csv_spec_txt';
const CF_LABEL       = 'CSV Spec Test';
const CF_VALUE       = 'round-trip-ok';

let ctx:      BrowserContext;
let page:     Page;
let subnetId: number | null = null;
let cfDefId:  number | null = null;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx  = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Clean up any leftover state
  await page.goto('subnets.php');
  await deleteSubnet(page, CF_CSV_CIDR);

  // Create test subnet
  await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: CF_CSV_CIDR, description: 'CF CSV spec', confirm_overlap: '1',
  });
  await page.goto('subnets.php');
  subnetId = await subnetIdFor(page, CF_CSV_CIDR);

  // Create an address CF definition via the admin page
  await fetchPost(page, appUrl('custom_fields.php'), {
    action: 'create', entity_type: 'address', key: CF_KEY,
    label: CF_LABEL, type: 'text', options: '[]', sort_order: '95', is_required: '0',
  });

  // Navigate to the admin page and find the definition ID from the delete form
  await page.goto('custom_fields.php');
  cfDefId = await page.evaluate((key: string) => {
    // Look for a form or link with data-id or a delete input that matches the key
    const rows = document.querySelectorAll('tr');
    for (const row of rows) {
      if (row.textContent?.includes(key)) {
        // Try button or input with name="id"
        const inp = row.querySelector<HTMLInputElement>('input[name="id"]');
        if (inp) return parseInt(inp.value, 10);
        // Try data-id attribute on a button
        const btn = row.querySelector<HTMLElement>('[data-id]');
        if (btn) return parseInt(btn.getAttribute('data-id') ?? '0', 10);
        // Try form action with id= in query string
        const form = row.querySelector<HTMLFormElement>('form[action*="id="]');
        if (form) {
          const m = form.action.match(/id=(\d+)/);
          if (m) return parseInt(m[1], 10);
        }
      }
    }
    return null;
  }, CF_KEY);

  // Create a seed address for export tests
  if (subnetId !== null) {
    await fetchPost(page, appUrl('addresses.php'), {
      action: 'create', subnet_id: String(subnetId),
      ip: CF_CSV_IP, hostname: 'cf-export-host', status: 'used',
    });
  }
});

test.afterAll(async () => {
  try {
    // Delete CF definition
    if (cfDefId !== null) {
      await fetchPost(page, appUrl('custom_fields.php'), {
        action: 'delete', id: String(cfDefId),
      });
    }
    // Delete test subnet (cascades addresses)
    await page.goto('subnets.php');
    await deleteSubnet(page, CF_CSV_CIDR);
    // Reset wizard state
    await fetchGet(page, appUrl('import_csv.php?reset=1'));
  } finally {
    await ctx?.close();
  }
});

// ── Export ────────────────────────────────────────────────────────────────────

test('custom_fields column present in per-subnet CSV export', async () => {
  if (!subnetId) { test.skip(); return; }
  const r = await fetchGet(page, appUrl(`export_addresses.php?subnet_id=${subnetId}`));
  expect(r.status).toBe(200);
  const header = r.body.split('\n')[0] ?? '';
  expect(header).toContain('custom_fields');
  expect(r.body).toContain(CF_CSV_IP);
});

test('custom_fields column present in cross-subnet CSV export', async () => {
  const r = await fetchGet(page, appUrl('export_addresses.php'));
  expect(r.status).toBe(200);
  const header = r.body.split('\n')[0] ?? '';
  expect(header).toContain('custom_fields');
});

// ── Import ─────────────────────────────────────────────────────────────────────

test('import CSV: address created with custom_fields value round-trips in export', async () => {
  if (!subnetId || cfDefId === null) { test.skip(); return; }

  await fetchGet(page, appUrl('import_csv.php?reset=1'));

  // CSV with custom_fields JSON cell (double-quoted JSON inside CSV requires escaped quotes)
  const cfJson = JSON.stringify({ [CF_KEY]: CF_VALUE });
  const csvContent = [
    'ip,hostname,status,cidr,custom_fields',
    `${CF_CSV_IMPORT},cf-import-host,used,${CF_CSV_CIDR},"${cfJson.replace(/"/g, '""')}"`,
  ].join('\n');

  // Step 1 — upload
  await fetchPostForm(page, appUrl('import_csv.php?step=1'), { action: 'upload' }, {
    name: 'csv', content: csvContent, filename: 'cf-test.csv', type: 'text/csv',
  });

  // Step 2 — set mapping (0=ip, 1=hostname, 2=status, 3=cidr, 4=custom_fields)
  await fetchPost(page, appUrl('import_csv.php?step=2'), {
    action: 'set_mapping', delimiter: ',', has_header: 'yes', dup_mode: 'overwrite',
    'map[ip]': '0', 'map[hostname]': '1', 'map[status]': '2',
    'map[cidr]': '3', 'map[custom_fields]': '4',
  });

  // Step 3 — navigate to dry-run page
  await page.goto('import_csv.php?step=3');
  const dryRunBody = await page.content();
  // Row should be classified as 'create', not 'report-invalid'
  expect(dryRunBody).toContain(CF_CSV_IMPORT);
  expect(dryRunBody).toContain('report-create');
  expect(dryRunBody).not.toContain('report-invalid');

  // Step 4 — apply import
  await fetchPost(page, appUrl('import_csv.php?step=4'), { action: 'apply' });

  // Verify round-trip: the exported CSV should contain the CF value
  const exportR = await fetchGet(page, appUrl(`export_addresses.php?subnet_id=${subnetId}`));
  expect(exportR.status).toBe(200);
  expect(exportR.body).toContain(CF_CSV_IMPORT);
  expect(exportR.body).toContain(CF_VALUE);
});

test('import CSV: invalid JSON in custom_fields column marks row invalid', async () => {
  if (!subnetId || cfDefId === null) { test.skip(); return; }

  await fetchGet(page, appUrl('import_csv.php?reset=1'));

  const csvContent = [
    'ip,custom_fields',
    `${CF_CSV_INVALID},not-valid-json`,
  ].join('\n');

  await fetchPostForm(page, appUrl('import_csv.php?step=1'), { action: 'upload' }, {
    name: 'csv', content: csvContent, filename: 'cf-bad.csv', type: 'text/csv',
  });

  await fetchPost(page, appUrl('import_csv.php?step=2'), {
    action: 'set_mapping', delimiter: ',', has_header: 'yes', dup_mode: 'skip',
    'map[ip]': '0', 'map[custom_fields]': '1',
  });

  await page.goto('import_csv.php?step=3');
  const dryRunBody = await page.content();
  expect(dryRunBody).toContain('report-invalid');
});
