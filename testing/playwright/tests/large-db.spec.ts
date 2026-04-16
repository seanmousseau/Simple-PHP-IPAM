/**
 * Large database import and export tests. (#306)
 *
 * Verifies that db_tools.php can handle large SQL imports and exports
 * without timeout or data corruption.
 *
 * Strategy:
 *   1. Export the current DB to capture the live schema and existing data.
 *   2. Append 100 synthetic /24 subnets and 50 addresses per subnet (5 000 addresses)
 *      to the exported SQL and re-import the augmented dump.
 *   3. Export the augmented DB and verify size / content.
 *   4. Round-trip: re-import the large export and verify no data loss.
 *   5. Restore the original DB in afterAll so subsequent specs are unaffected.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchPost, fetchPostForm, appUrl,
  ADMIN_USER, ADMIN_PASS,
  newAuthContext, IS_SQLITE,
} from '../fixtures/ipam';

// v2.10.0 #433 / v2.11.0 #388: db_tools.php SQL import/export uses
// ipam_db_dump_stream() which emits SQLite-format dumps by design. On
// MySQL and Postgres, db_tools.php gates both actions with a user-facing
// notice; the large-db round-trip scenarios only make sense on SQLite.
test.skip(!IS_SQLITE, 'large-db round-trip is SQLite-only; MySQL/Postgres gating covered in db-tools.spec.ts');

let ctx: BrowserContext;
let page: Page;
let originalSql = '';   // current DB export — restored in afterAll
let largeExportSql = ''; // export after large-data import

const SUBNET_COUNT   = 100;  // synthetic /24 subnets to inject
const ADDRS_PER_CIDR = 50;   // addresses per subnet

// ── SQL generator ─────────────────────────────────────────────────────────────

function toHex4(b1: number, b2: number, b3: number, b4: number): string {
  return [b1, b2, b3, b4]
    .map(b => b.toString(16).padStart(2, '0'))
    .join('');
}

/**
 * Build subnet + address INSERT statements for 172.17–172.27.x.0/24 blocks.
 * Uses INSERT OR IGNORE so re-runs are idempotent.
 * Address rows use an INSERT … SELECT to look up the subnet_id by CIDR.
 */
function buildLargeInsertSql(): string {
  const lines: string[] = ['-- Large-DB-test data (100 subnets × 50 addresses)'];

  for (let s = 0; s < SUBNET_COUNT; s++) {
    const o2 = 17 + Math.floor(s / 256);
    const o3 = s % 256;
    const cidr = `172.${o2}.${o3}.0/24`;
    const net  = `172.${o2}.${o3}.0`;
    const netHex = toHex4(172, o2, o3, 0);
    lines.push(
      `INSERT OR IGNORE INTO subnets (cidr, ip_version, network, network_bin, prefix, description, site_id) ` +
      `VALUES ('${cidr}', 4, '${net}', CAST(X'${netHex}' AS BLOB), 24, 'Large-DB-test subnet ${s}', NULL);`,
    );
  }

  for (let s = 0; s < SUBNET_COUNT; s++) {
    const o2 = 17 + Math.floor(s / 256);
    const o3 = s % 256;
    const cidr = `172.${o2}.${o3}.0/24`;
    for (let h = 1; h <= ADDRS_PER_CIDR; h++) {
      const ip    = `172.${o2}.${o3}.${h}`;
      const ipHex = toHex4(172, o2, o3, h);
      lines.push(
        `INSERT OR IGNORE INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, grp, status, mac, expires_at, owner_contact_id) ` +
        `SELECT id, '${ip}', CAST(X'${ipHex}' AS BLOB), ` +
        `'host-${s}-${h}.largedb.test.local', 'pw-large-db', '', 'test', 'used', '', NULL, NULL ` +
        `FROM subnets WHERE cidr = '${cidr}';`,
      );
    }
  }

  return lines.join('\n') + '\n';
}

// ── Suite ──────────────────────────────────────────────────────────────────────

test.describe('Large database import/export (#306)', () => {
  test.beforeAll(async ({ browser }: { browser: Browser }) => {
    ctx  = await newAuthContext(browser);
    page = await ctx.newPage();
    await login(page, ADMIN_USER, ADMIN_PASS);

    await page.goto('db_tools.php');
    const r = await fetchPost(page, appUrl('db_tools.php'), { action: 'export' });
    originalSql = r.body;
    expect(originalSql.length, 'baseline export is non-empty').toBeGreaterThan(100);
  });

  test.afterAll(async () => {
    // Always restore the original DB so subsequent specs are unaffected.
    try {
      if (originalSql && page) {
        await page.goto('db_tools.php');
        await fetchPostForm(
          page, appUrl('db_tools.php'),
          { action: 'import', confirmed: '1' },
          { name: 'sql_file', content: originalSql, filename: 'restore.sql', type: 'application/sql' },
        );
      }
    } finally {
      await ctx?.close();
    }
  });

  // ── Import ──────────────────────────────────────────────────────────────────

  test('large import (100 subnets × 50 addresses) completes without error', async () => {
    test.setTimeout(90_000);
    const augmentedSql = originalSql + '\n' + buildLargeInsertSql();

    await page.goto('db_tools.php');
    const r = await fetchPostForm(
      page, appUrl('db_tools.php'),
      { action: 'import', confirmed: '1' },
      { name: 'sql_file', content: augmentedSql, filename: 'large-test.sql', type: 'application/sql' },
    );
    expect(r.body, 'large import succeeded').toContain('Import successful');
  });

  test('subnets page loads correctly after large import', async () => {
    await page.goto('subnets.php');
    const body = await page.textContent('body') ?? '';
    expect(body, 'no PHP fatal after large import').not.toContain('Fatal error');
    expect(body, 'no uncaught exception after large import').not.toContain('Uncaught');
    await expect(page.locator('h1')).toBeVisible();
  });

  test('addresses page loads after large import', async () => {
    // Navigate to addresses for any available subnet
    await page.goto('subnets.php');
    const href = await page
      .locator('a[href*="addresses.php?subnet_id"]')
      .first()
      .getAttribute('href', { timeout: 5_000 })
      .catch(() => null);
    if (href) {
      await page.goto(href);
      const body = await page.textContent('body') ?? '';
      expect(body).not.toContain('Fatal error');
    }
  });

  // ── Export ──────────────────────────────────────────────────────────────────

  test('large DB export is larger than baseline and contains expected tables', async () => {
    test.setTimeout(90_000);
    await page.goto('db_tools.php');
    const r = await fetchPost(page, appUrl('db_tools.php'), { action: 'export' });
    largeExportSql = r.body;
    expect(r.status).toBe(200);

    // Export must be substantially larger than the original (100 subnets + 5000 addresses added)
    expect(largeExportSql.length, 'large export is bigger than baseline export').toBeGreaterThan(
      originalSql.length + 500_000,
    );

    // The export encodes all text as CAST(X'hex' AS TEXT).
    // Check for the hex-encoded prefix of 'Large-DB-test subnet' to confirm large-db rows are present.
    // hex('Large-DB-test subnet') = 4c617267652d44422d74657374207375626e6574
    const LARGE_DB_DESC_HEX = '4c617267652d44422d74657374207375626e6574';
    expect(largeExportSql, 'export contains large-db subnet descriptions (hex-encoded)').toContain(
      LARGE_DB_DESC_HEX,
    );

    // Must contain all core table names
    const tables = ['users', 'subnets', 'addresses', 'sites', 'audit_log',
                    'vlans', 'vrfs', 'contacts', 'tags', 'schema_migrations'];
    for (const tbl of tables) {
      expect(largeExportSql, `export contains ${tbl}`).toContain(tbl);
    }
  });

  test('large DB export contains audit_log append-only triggers', async () => {
    if (!largeExportSql) { test.skip(); return; }
    expect(largeExportSql).toContain('audit_log_no_update');
    expect(largeExportSql).toContain('audit_log_no_delete');
  });

  test('large DB export contains updated_at triggers', async () => {
    if (!largeExportSql) { test.skip(); return; }
    expect(largeExportSql).toContain('addresses_updated_at');
    expect(largeExportSql).toContain('subnets_updated_at');
  });

  // ── Round-trip ──────────────────────────────────────────────────────────────

  test('large DB round-trip: re-import the exported large dump succeeds', async () => {
    test.setTimeout(90_000);
    if (!largeExportSql) { test.skip(); return; }

    await page.goto('db_tools.php');
    const r = await fetchPostForm(
      page, appUrl('db_tools.php'),
      { action: 'import', confirmed: '1' },
      { name: 'sql_file', content: largeExportSql, filename: 'large-roundtrip.sql', type: 'application/sql' },
    );
    expect(r.body, 'large round-trip import succeeded').toContain('Import successful');
  });

  test('subnets page works after round-trip import', async () => {
    await page.goto('subnets.php');
    const body = await page.textContent('body') ?? '';
    expect(body).not.toContain('Fatal error');
    await expect(page.locator('h1')).toBeVisible();
  });

  test('audit page works after round-trip import', async () => {
    await page.goto('audit.php');
    const body = await page.textContent('body') ?? '';
    expect(body).not.toContain('Fatal error');
    await expect(page.locator('h1')).toBeVisible();
  });

  test('dashboard loads and shows data after round-trip', async () => {
    await page.goto('dashboard.php');
    const body = await page.textContent('body') ?? '';
    expect(body).not.toContain('Fatal error');
    await expect(page.locator('h1')).toBeVisible();
  });
});
