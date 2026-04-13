/**
 * CSV exports — subnets, addresses, audit, utilization, history, search result.
 * Verifies new mac/expires_at columns are present (#264, #262).
 * Migrated from cdp_test.py section 13.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchPost, fetchGet, deleteSubnet, subnetIdFor, appUrl,
  ADMIN_USER, ADMIN_PASS, TEST_CIDR2, TEST_IP, TEST_HOST, TEST_MAC,
  newAuthContext,
} from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;
let subnetId: number | null = null;
let addrId:   number | null = null;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  await page.goto('subnets.php');
  await deleteSubnet(page, TEST_CIDR2);

  await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: TEST_CIDR2, description: 'PW export test', confirm_overlap: '1',
  });
  await page.goto('subnets.php');
  subnetId = await subnetIdFor(page, TEST_CIDR2);

  if (subnetId) {
    await page.goto(`addresses.php?subnet_id=${subnetId}`);
    await fetchPost(page, appUrl('addresses.php'), {
      action: 'create', subnet_id: String(subnetId),
      ip: TEST_IP, hostname: TEST_HOST, owner: 'PW Test',
      status: 'used', note: '', grp: '', mac: TEST_MAC, expires_at: '2099-12-31',
    });
    addrId = await page.evaluate((ip) => {
      for (const a of document.querySelectorAll<HTMLAnchorElement>('a[href*="address_id"]')) {
        const row = a.closest('tr');
        if (row?.innerText.includes(ip)) {
          const m = a.href.match(/address_id=([0-9]+)/);
          if (m) return parseInt(m[1], 10);
        }
      }
      return null;
    }, TEST_IP);
  }
});

test.afterAll(async () => {
  try {
    if (page) {
      await page.goto('subnets.php');
      await deleteSubnet(page, TEST_CIDR2);
    }
  } finally {
    await ctx?.close();
  }
});

test('export subnets: 200 response, CSV content', async () => {
  await page.goto('subnets.php');
  const r = await fetchGet(page, appUrl('export_subnets.php'));
  expect(r.status).toBe(200);
  expect(r.contentType.toLowerCase()).toContain('text');
  expect(r.body).toContain(TEST_CIDR2);
});

test('export addresses: contains test IP + mac + expires_at columns', async () => {
  if (!subnetId) { test.skip(); return; }
  await page.goto(`addresses.php?subnet_id=${subnetId}`);
  const r = await fetchGet(page, appUrl(`export_addresses.php?subnet_id=${subnetId}`));
  expect(r.status).toBe(200);
  expect(r.body).toContain(TEST_IP);
  expect(r.body).toContain(TEST_MAC);
  // Column headers
  expect(r.body.toLowerCase()).toContain('mac');
  expect(r.body.toLowerCase()).toContain('expires_at');
});

test('export audit: 200 response, CSV content', async () => {
  await page.goto('audit.php');
  const r = await fetchGet(page, appUrl('export_audit.php'));
  expect(r.status).toBe(200);
  expect(r.contentType.toLowerCase()).toContain('text');
});

test('export subnet utilization: has utilization_pct column', async () => {
  await page.goto('subnets.php');
  const r = await fetchGet(page, appUrl('export_subnet_utilization.php'));
  expect(r.status).toBe(200);
  expect(r.body.toLowerCase()).toContain('utilization_pct');
});

test('export address history: contains action column', async () => {
  if (!addrId) { test.skip(); return; }
  await page.goto(`address_history.php?address_id=${addrId}`);
  const r = await fetchGet(page, appUrl(`export_address_history.php?address_id=${addrId}`));
  expect(r.status).toBe(200);
  expect(r.contentType.toLowerCase()).toContain('text');
  expect(r.body.toLowerCase()).toContain('action');
});

test('export search: contains mac/expires_at columns', async () => {
  await page.goto(`search.php?q=${encodeURIComponent(TEST_IP)}`);
  const r = await fetchGet(page, appUrl(`export_search.php?q=${encodeURIComponent(TEST_IP)}`));
  expect(r.status).toBe(200);
  expect(r.body).toContain(TEST_IP);
  expect(r.body.toLowerCase()).toContain('mac');
  expect(r.body.toLowerCase()).toContain('expires_at');
});

test('export DNS forward zone: returns text with A records', async () => {
  if (!subnetId) { test.skip(); return; }
  await page.goto(`addresses.php?subnet_id=${subnetId}`);
  const r = await fetchGet(page, appUrl(`export_dns.php?subnet_id=${subnetId}&type=forward`));
  expect(r.status).toBe(200);
  expect(r.contentType.toLowerCase()).toContain('text');
  // Should contain BIND directives
  expect(r.body).toContain('$TTL');
  // Should have at least a comment line with the CIDR
  expect(r.body).toContain(TEST_CIDR2.split('/')[0]);
});

test('export DNS reverse zone: returns text with PTR $ORIGIN', async () => {
  if (!subnetId) { test.skip(); return; }
  const r = await fetchGet(page, appUrl(`export_dns.php?subnet_id=${subnetId}&type=reverse`));
  expect(r.status).toBe(200);
  expect(r.contentType.toLowerCase()).toContain('text');
  expect(r.body).toContain('in-addr.arpa');
});

test('export DNS both: contains forward and reverse sections', async () => {
  if (!subnetId) { test.skip(); return; }
  const r = await fetchGet(page, appUrl(`export_dns.php?subnet_id=${subnetId}&type=both`));
  expect(r.status).toBe(200);
  expect(r.body).toContain('$TTL');
  expect(r.body).toContain('in-addr.arpa');
});

test('export DNS missing subnet_id returns 400', async () => {
  const r = await fetchGet(page, appUrl('export_dns.php'));
  expect(r.status).toBe(400);
});
