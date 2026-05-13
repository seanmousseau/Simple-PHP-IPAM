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
  newAuthContext, warmSudoGrant,
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

/**
 * Look up the id of an existing custom-field definition by key by scraping the
 * admin page. Returns null if no definition with that key is currently rendered.
 *
 * Stable selectors (see custom_fields.php ~L348–402):
 *   <tr>
 *     <td><code class="monospace">{key}</code></td>          ← exact-text match
 *     ...
 *     <form …><input name="action" value="delete">
 *             <input name="id"     value="{id}"></form>
 *   </tr>
 *
 * Matching the <code class="monospace"> child by exact text avoids false hits
 * on labels/descriptions that happen to contain the key as a substring, and
 * pulling the id from the row's delete-action form (rather than the first
 * input[name=id], shared with the update form) is unambiguous.
 */
async function findCfDefIdByKey(p: Page, key: string): Promise<number | null> {
  await p.goto('custom_fields.php');
  return await p.evaluate((k: string) => {
    const codes = document.querySelectorAll<HTMLElement>('tr td code.monospace');
    for (const code of codes) {
      if ((code.textContent ?? '').trim() !== k) continue;
      const row = code.closest('tr');
      if (!row) continue;
      // Prefer the delete form's hidden id (unambiguous)
      const forms = row.querySelectorAll<HTMLFormElement>('form');
      for (const f of forms) {
        const action = f.querySelector<HTMLInputElement>('input[name="action"]');
        if (action?.value === 'delete') {
          const idInp = f.querySelector<HTMLInputElement>('input[name="id"]');
          if (idInp) {
            const id = Number.parseInt(idInp.value, 10);
            if (Number.isFinite(id)) return id;
          }
        }
      }
      // Fallback: any input[name=id] in the row
      const idInp = row.querySelector<HTMLInputElement>('input[name="id"]');
      if (idInp) {
        const id = Number.parseInt(idInp.value, 10);
        if (Number.isFinite(id)) return id;
      }
      return null;
    }
    return null;
  }, key);
}

/**
 * Idempotent delete-by-key. Looks up the def (if any) and POSTs delete.
 * Safe to call when no def with that key exists — used for both pre-create
 * cleanup (self-healing against leaks from prior failed runs) and as a
 * fallback if id-based teardown fails or never captured an id.
 */
async function deleteCfDefByKey(p: Page, key: string): Promise<void> {
  const id = await findCfDefIdByKey(p, key);
  if (id !== null) {
    await fetchPost(p, appUrl('custom_fields.php'), {
      action: 'delete', id: String(id),
    });
  }
}

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx  = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // v3.28.0 (#1158): custom field def create/delete is sudo-gated. Warm one
  // grant for the whole suite (shared page/context, default TTL=300s) so the
  // fetchPost create/delete calls against custom_fields.php aren't bounced to
  // the step-up prompt.
  await warmSudoGrant(page);

  // Clean up any leftover state. Pre-create delete-by-KEY makes the test
  // self-healing against leaks from prior failed runs (e.g. scrape miss
  // or aborted teardown that never captured an id).
  await page.goto('subnets.php');
  await deleteSubnet(page, CF_CSV_CIDR);
  await deleteCfDefByKey(page, CF_KEY);

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

  // Capture the id for fast id-based teardown. Uses the same exact-text
  // <code class="monospace"> selector as the cleanup helper. If this scrape
  // somehow misses, the afterAll fallback (delete-by-key) still cleans up.
  cfDefId = await findCfDefIdByKey(page, CF_KEY);

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
    // Delete CF definition. Try id-based delete first (fast path); fall
    // through to a delete-by-key lookup if the id was never captured or
    // the id-based delete fails for any reason. This guarantees we never
    // leak `cf_csv_spec_txt` across runs (which collides with test_api.sh
    // and causes 422 errors on later CF tests).
    let idDeleteOk = false;
    if (cfDefId !== null) {
      try {
        await fetchPost(page, appUrl('custom_fields.php'), {
          action: 'delete', id: String(cfDefId),
        });
        idDeleteOk = true;
      } catch {
        idDeleteOk = false;
      }
    }
    if (!idDeleteOk) {
      try { await deleteCfDefByKey(page, CF_KEY); } catch { /* best-effort */ }
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
