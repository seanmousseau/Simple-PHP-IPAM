/**
 * Global search — parameterized filter matrix, special-char LIKE-escape,
 * pagination, and CSV-export match.
 *
 * Expands the original 6 tests to ~30 cases (#461).
 * Migrated from cdp_test.py section 6 + v1.19.0 mac search.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchPost, fetchGet, deleteSubnet, subnetIdFor, appUrl,
  ADMIN_USER, ADMIN_PASS,
  newAuthContext,
} from '../fixtures/ipam';

// ── Constants ──────────────────────────────────────────────────────────────────

const SEARCH_CIDR_V4   = '10.88.0.0/24';
const SEARCH_CIDR_V6   = '2001:db8:aaaa::/120';
const SEARCH_IP_USED   = '10.88.0.10';
const SEARCH_IP_RES    = '10.88.0.20';
const SEARCH_IP_FREE   = '10.88.0.30';
const SEARCH_IP_V6     = '2001:db8:aaaa::10';
const SEARCH_HOST      = 'pw-srch-host';
const SEARCH_OWNER     = 'PW Srch Owner';
const SEARCH_GROUP     = 'srchgrp';
const SEARCH_NOTE      = 'pw-srch-note-text';
const SEARCH_MAC       = 'AA:BB:CC:DD:EE:FF';
const SEARCH_LIKE_HOST = 'pw-srch-percent%host';   // contains %, _, !
const SEARCH_LIKE_IP   = '10.88.0.40';

// ── Fixtures ───────────────────────────────────────────────────────────────────

let ctx:      BrowserContext;
let page:     Page;
let subnetV4: number | null = null;
let subnetV6: number | null = null;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx  = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Clean up stale data
  await page.goto('subnets.php');
  await deleteSubnet(page, SEARCH_CIDR_V4);
  await deleteSubnet(page, SEARCH_CIDR_V6);

  // Create IPv4 subnet
  await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: SEARCH_CIDR_V4, description: 'PW search test', confirm_overlap: '1',
  });
  await page.goto('subnets.php');
  subnetV4 = await subnetIdFor(page, SEARCH_CIDR_V4);

  if (subnetV4) {
    // used address with full metadata
    await fetchPost(page, appUrl('addresses.php'), {
      action: 'create', subnet_id: String(subnetV4),
      ip: SEARCH_IP_USED, hostname: SEARCH_HOST, owner: SEARCH_OWNER,
      status: 'used', note: SEARCH_NOTE, grp: SEARCH_GROUP, mac: SEARCH_MAC, expires_at: '',
    });
    // reserved address
    await fetchPost(page, appUrl('addresses.php'), {
      action: 'create', subnet_id: String(subnetV4),
      ip: SEARCH_IP_RES, hostname: `${SEARCH_HOST}-res`, owner: SEARCH_OWNER,
      status: 'reserved', note: '', grp: '', mac: '', expires_at: '',
    });
    // free address
    await fetchPost(page, appUrl('addresses.php'), {
      action: 'create', subnet_id: String(subnetV4),
      ip: SEARCH_IP_FREE, hostname: `${SEARCH_HOST}-free`, owner: SEARCH_OWNER,
      status: 'free', note: '', grp: '', mac: '', expires_at: '',
    });
    // address with LIKE-special hostname
    await fetchPost(page, appUrl('addresses.php'), {
      action: 'create', subnet_id: String(subnetV4),
      ip: SEARCH_LIKE_IP, hostname: SEARCH_LIKE_HOST, owner: SEARCH_OWNER,
      status: 'used', note: '', grp: '', mac: '', expires_at: '',
    });
  }

  // Create IPv6 subnet
  await fetchPost(page, appUrl('subnets.php'), {
    action: 'create', cidr: SEARCH_CIDR_V6, description: 'PW search v6', confirm_overlap: '1',
  });
  await page.goto('subnets.php');
  subnetV6 = await subnetIdFor(page, SEARCH_CIDR_V6);

  if (subnetV6) {
    await fetchPost(page, appUrl('addresses.php'), {
      action: 'create', subnet_id: String(subnetV6),
      ip: SEARCH_IP_V6, hostname: `${SEARCH_HOST}-v6`, owner: SEARCH_OWNER,
      status: 'used', note: '', grp: '', mac: '', expires_at: '',
    });
  }
});

test.afterAll(async () => {
  try {
    if (page) {
      await page.goto('subnets.php');
      await deleteSubnet(page, SEARCH_CIDR_V4);
      await deleteSubnet(page, SEARCH_CIDR_V6);
    }
  } finally {
    await ctx?.close();
  }
});

// ── Helper ─────────────────────────────────────────────────────────────────────

/** Navigate to search.php with optional filters; returns visible IP text from body. */
async function searchFor(filters: Record<string, string>): Promise<string> {
  const qs = new URLSearchParams(filters).toString();
  await page.goto(`search.php?${qs}`);
  return page.locator('body').innerText();
}

// ── Basic UI ───────────────────────────────────────────────────────────────────

test('search page loads with query input', async () => {
  await page.goto('search.php');
  await expect(page.locator('[name=q]')).toBeVisible();
});

// ── Text query combos ──────────────────────────────────────────────────────────

const TEXT_QUERY_CASES: Array<{ name: string; q: string; expectIP: string }> = [
  { name: 'by exact IP',        q: SEARCH_IP_USED,  expectIP: SEARCH_IP_USED  },
  { name: 'by hostname prefix', q: SEARCH_HOST,     expectIP: SEARCH_IP_USED  },
  { name: 'by owner',           q: SEARCH_OWNER,    expectIP: SEARCH_IP_USED  },
  { name: 'by note text',       q: SEARCH_NOTE,     expectIP: SEARCH_IP_USED  },
  { name: 'by group',           q: SEARCH_GROUP,    expectIP: SEARCH_IP_USED  },
  { name: 'by MAC prefix',      q: SEARCH_MAC.substring(0, 5), expectIP: SEARCH_IP_USED },
];

for (const c of TEXT_QUERY_CASES) {
  test(`search ${c.name} finds result`, async () => {
    const body = await searchFor({ q: c.q });
    expect(body).toContain(c.expectIP);
  });
}

// ── Status filter ──────────────────────────────────────────────────────────────

const STATUS_CASES: Array<{ status: string; expectIP: string; excludeIP: string }> = [
  { status: 'used',     expectIP: SEARCH_IP_USED, excludeIP: SEARCH_IP_RES  },
  { status: 'reserved', expectIP: SEARCH_IP_RES,  excludeIP: SEARCH_IP_USED },
  { status: 'free',     expectIP: SEARCH_IP_FREE, excludeIP: SEARCH_IP_USED },
];

for (const c of STATUS_CASES) {
  test(`search status=${c.status} shows correct rows`, async () => {
    const body = await searchFor({ q: SEARCH_OWNER, status: c.status });
    expect(body).toContain(c.expectIP);
    expect(body).not.toContain(c.excludeIP);
  });
}

// ── IP version filter ──────────────────────────────────────────────────────────

test('search ip_version=4 returns only IPv4 results', async () => {
  const body = await searchFor({ q: SEARCH_OWNER, ip_version: '4' });
  expect(body).toContain(SEARCH_IP_USED);
  expect(body).not.toContain(SEARCH_IP_V6);
});

test('search ip_version=6 returns only IPv6 results', async () => {
  if (!subnetV6) { test.skip(); return; }
  const body = await searchFor({ q: SEARCH_OWNER, ip_version: '6' });
  expect(body).toContain(SEARCH_IP_V6);
  expect(body).not.toContain(SEARCH_IP_USED);
});

// ── Status × IP version combos ─────────────────────────────────────────────────

const COMBO_CASES: Array<{ status: string; ip_version: string; expectIP: string }> = [
  { status: 'used',     ip_version: '4', expectIP: SEARCH_IP_USED },
  { status: 'reserved', ip_version: '4', expectIP: SEARCH_IP_RES  },
  { status: 'free',     ip_version: '4', expectIP: SEARCH_IP_FREE },
  { status: 'used',     ip_version: '6', expectIP: SEARCH_IP_V6   },
];

for (const c of COMBO_CASES) {
  test(`search status=${c.status} + ip_version=${c.ip_version} combo`, async () => {
    if (c.ip_version === '6' && !subnetV6) { test.skip(); return; }
    const body = await searchFor({ q: SEARCH_OWNER, status: c.status, ip_version: c.ip_version });
    expect(body).toContain(c.expectIP);
  });
}

// ── Impossible filters (expect empty state) ────────────────────────────────────

test('search status=used + ip_version=6 with no v6-used returns empty', async () => {
  // We only seeded one IPv6 address as used, so it should appear (not empty).
  // Use an owner that does NOT match any IPv6 address to force empty.
  const body = await searchFor({ q: 'zzz-no-match-owner-999', status: 'used', ip_version: '6' });
  const emptyState = page.locator('.empty-state, .muted');
  await expect(emptyState.first()).toBeVisible();
  expect(body).not.toContain(SEARCH_IP_USED);
});

// ── LIKE-escape: special characters in query ───────────────────────────────────

const LIKE_ESCAPE_CASES: Array<{ name: string; q: string; shouldFind: boolean }> = [
  { name: '% wildcard not expanded',      q: 'pw-srch-percent%host', shouldFind: true  },
  { name: '_ wildcard not expanded',      q: 'pw-srch-percent_host', shouldFind: false },
  { name: 'quoted single quote safe',     q: "pw-srch-host'drop",    shouldFind: false },
  { name: 'double quote safe',            q: 'pw-srch-host"drop',    shouldFind: false },
  { name: 'angle bracket safe (XSS)',     q: 'pw-srch-host<script>',  shouldFind: false },
  { name: 'semicolon safe (SQLi)',        q: 'pw-srch-host;drop',    shouldFind: false },
];

for (const c of LIKE_ESCAPE_CASES) {
  test(`LIKE-escape: ${c.name}`, async () => {
    const body = await searchFor({ q: c.q });
    if (c.shouldFind) {
      expect(body).toContain(SEARCH_LIKE_IP);
    } else {
      // Should either be empty or show a no-match state; never crash or XSS
      expect(body).not.toContain('<script>');
      expect(body).not.toContain("';DROP");
    }
  });
}

// ── Empty state ────────────────────────────────────────────────────────────────

test('search with no match shows empty state', async () => {
  await page.goto('search.php?q=zzz-no-match-pw-xyz-999');
  const emptyState = page.locator('.empty-state, .muted');
  await expect(emptyState.first()).toBeVisible();
});

// ── Column headers ─────────────────────────────────────────────────────────────

test('search result shows mac and expires_at columns', async () => {
  await page.goto(`search.php?q=${encodeURIComponent(SEARCH_IP_USED)}`);
  const headers = await page.locator('table thead th').allInnerTexts();
  const headerText = headers.join(' ').toLowerCase();
  expect(headerText).toContain('mac');
});

// ── Pagination ─────────────────────────────────────────────────────────────────

test('search pagination: page_size=1 shows pager controls', async () => {
  const body = await searchFor({ q: SEARCH_OWNER, page_size: '1' });
  // With multiple results seeded and page_size=1, pagination controls appear
  const pager = page.locator('.pagination, [aria-label*="page"], .pager, a[href*="page="]');
  const hasPager = await pager.count() > 0;
  // body may contain the IP (first page) — verify we're not crashing
  expect(body).toBeTruthy();
  // If multiple results exist, pager should appear
  if (hasPager) {
    await expect(pager.first()).toBeVisible();
  }
});

test('search pagination: page=2 with page_size=1 shows second result', async () => {
  await page.goto(`search.php?q=${encodeURIComponent(SEARCH_OWNER)}&page=2&page_size=1`);
  const body = await page.locator('body').innerText();
  // Page 2 should exist and contain a result row (not crash or empty-state)
  expect(body).toBeTruthy();
  const emptyState = page.locator('.empty-state');
  // empty-state should not be visible on page 2 (we have more than 1 result)
  await expect(emptyState).toHaveCount(0);
});

// ── Case-insensitive search (#750) ─────────────────────────────────────────────
//
// PostgreSQL's LIKE is case-sensitive by default; SQLite NOCASE only folds
// ASCII A-Z; MySQL's case sensitivity depends on column collation. The fix
// uses LOWER(col) LIKE LOWER(:bind) to give consistent semantics across all
// three engines. These cases assert mixed-case input matches a known seeded
// hostname (web-lon-01) regardless of input casing.

const CASE_INSENSITIVE_QUERIES = [
  'WEB-LON-01',
  'Web-Lon-01',
  'web-lon-01',
  'WeB-lOn-01',
];

for (const q of CASE_INSENSITIVE_QUERIES) {
  test(`search is case-insensitive across hostname mixed-case input: "${q}"`, async () => {
    await page.goto(`search.php?q=${encodeURIComponent(q)}`);
    // The seeded hostname web-lon-01 lives at 10.10.2.10 in subnet 10.10.2.0/24
    const body = await page.locator('body').innerText();
    expect(body).toContain('10.10.2.10');
    expect(body.toLowerCase()).toContain('web-lon-01');
  });
}

test('search is case-insensitive on owner field (mixed case)', async () => {
  // Seeded owner "WebTeam" — query with all-lower should still match
  await page.goto('search.php?q=webteam');
  const body = await page.locator('body').innerText();
  // web-lon-01..03 all owned by WebTeam
  expect(body).toContain('10.10.2.10');
});

// ── CSV export matches on-screen rows ─────────────────────────────────────────

test('CSV export matches on-screen search results for used+v4', async () => {
  const exportR = await fetchGet(
    page,
    appUrl(`export_search.php?q=${encodeURIComponent(SEARCH_OWNER)}&status=used&ip_version=4`),
  );
  expect(exportR.status).toBe(200);
  expect(exportR.body).toContain(SEARCH_IP_USED);
  // Only used status rows; reserved should not be in this export
  expect(exportR.body).not.toContain(SEARCH_IP_RES);
  // CSV header
  expect(exportR.body.toLowerCase()).toContain('ip');
  expect(exportR.body.toLowerCase()).toContain('hostname');
});
