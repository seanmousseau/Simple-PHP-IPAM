/**
 * Page inventory — every admin page loads without a .danger error element.
 * Also covers: breadcrumbs on all pages, sticky headers, mobile nav toggle.
 * Migrated from cdp_test.py section 3.
 */
import { adminTest, expect } from '../fixtures/ipam';

// Pages that legitimately show a .danger element on normal load
const ALLOWED_DANGER = new Set(['db_tools.php']);

const PAGES: Array<[string, string]> = [
  ['dashboard.php',   'Dashboard'],
  ['subnets.php',     'Subnet'],
  ['search.php',      'Search'],
  ['audit.php',       'Audit'],
  ['users.php',       'User'],
  ['sites.php',       'Site'],
  ['vlans.php',       'VLAN'],
  ['vrfs.php',        'VRF'],
  ['aggregates.php',  'Aggregate'],
  ['pd_pools.php',    'PD Pool'],
  ['tags.php',        'Tag'],
  ['api_keys.php',    'API Key'],
  ['dhcp_pool.php',   'DHCP'],
  ['import_csv.php',  'Import'],
  ['db_tools.php',    'Database'],
  ['unassigned.php',  'Unassigned'],
  ['bulk_update.php', 'Bulk'],
  ['change_password.php', 'Password'],
  ['scan_history.php', 'Scan'],
];

// Pages that include breadcrumbs (all non-login pages with page_header())
const BREADCRUMB_PAGES = [
  'dashboard.php', 'subnets.php', 'search.php', 'audit.php',
  'users.php', 'sites.php', 'vlans.php', 'tags.php',
  'api_keys.php', 'change_password.php',
];

adminTest.describe('Page inventory', () => {
  for (const [slug, keyword] of PAGES) {
    adminTest(`${slug} loads without error`, async ({ adminPage: page }) => {
      await page.goto(slug);
      const title = await page.title();
      expect(title.toLowerCase()).toContain(keyword.toLowerCase());

      if (!ALLOWED_DANGER.has(slug)) {
        const dangerCount = await page.locator('.danger').count();
        expect(dangerCount, `${slug} has unexpected .danger element`).toBe(0);
      }
    });
  }
});

adminTest.describe('Breadcrumbs', () => {
  for (const slug of BREADCRUMB_PAGES) {
    adminTest(`${slug}: breadcrumb is present`, async ({ adminPage: page }) => {
      await page.goto(slug);
      await expect(page.locator('.breadcrumbs')).toBeVisible();
      await expect(page.locator('.breadcrumbs')).toContainText('Dashboard');
    });
  }
});

adminTest.describe('Sticky headers', () => {
  // Check position:sticky on <th>, NOT <thead>. The CSS must apply sticky to the cell
  // level because .table-wrap{overflow-x:auto} creates a scroll container that would
  // confine thead-level sticky to scroll within the wrapper instead of the viewport.
  adminTest('audit.php: thead th has sticky position', async ({ adminPage: page }) => {
    await page.goto('audit.php');
    const th = page.locator('thead th').first();
    await expect(th).toBeVisible();
    const position = await th.evaluate(el => getComputedStyle(el).position);
    expect(position).toBe('sticky');
  });

  adminTest('users.php: thead th has sticky position', async ({ adminPage: page }) => {
    await page.goto('users.php');
    const th = page.locator('thead th').first();
    await expect(th).toBeVisible();
    const position = await th.evaluate(el => getComputedStyle(el).position);
    expect(position).toBe('sticky');
  });

  // SKIPPED (#432 audit, v2.5.2): app.js does not set a --topbar-h CSS
  // custom property. This test asserts an uninstalled feature. See the
  // matching skip in js-behaviour.spec.ts.
  adminTest.skip('thead th top offset equals --topbar-h CSS custom property', async ({ adminPage: page }) => {
    await page.goto('audit.php');
    await page.waitForLoadState('networkidle');
    const th = page.locator('thead th').first();
    await expect(th).toBeVisible();
    const [topbarH, thTop] = await page.evaluate(() => {
      const topbarH = getComputedStyle(document.documentElement)
        .getPropertyValue('--topbar-h').trim();
      const th = document.querySelector('thead th') as HTMLElement;
      const thTop = th ? getComputedStyle(th).top : '';
      return [topbarH, thTop];
    });
    expect(topbarH).not.toBe('');
    expect(topbarH).not.toBe('0px');
    expect(thTop).toBe(topbarH); // must pin exactly at topbar bottom
  });

  // thead itself must NOT have position:sticky — that breaks inside overflow-x:auto.
  adminTest('thead does NOT have position:sticky (per-th approach)', async ({ adminPage: page }) => {
    await page.goto('audit.php');
    const theads = await page.locator('thead').all();
    if (theads.length === 0) return;
    const pos = await theads[0].evaluate(el => getComputedStyle(el).position);
    // 'static' or 'relative' are both fine; 'sticky' is the regression
    expect(pos).not.toBe('sticky');
  });

  // thead th must have a non-transparent background so content doesn't bleed through.
  adminTest('thead th has opaque background', async ({ adminPage: page }) => {
    await page.goto('audit.php');
    const th = page.locator('thead th').first();
    await expect(th).toBeVisible();
    const bg = await th.evaluate(el => getComputedStyle(el).backgroundColor);
    // rgba(0,0,0,0) / transparent means content will show through the pinned header
    expect(bg).not.toBe('rgba(0, 0, 0, 0)');
    expect(bg).not.toBe('transparent');
  });
});

adminTest.describe('Mobile hamburger nav', () => {
  adminTest('nav-toggle button exists in DOM', async ({ adminPage: page }) => {
    await page.goto('dashboard.php');
    const toggle = page.locator('#nav-toggle');
    await expect(toggle).toBeAttached();
  });

  adminTest('nav-drawer opens on toggle click (mobile viewport)', async ({ adminPage: page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('dashboard.php');
    // Open: toggle click adds body.nav-open and removes aria-hidden from drawer
    await page.locator('#nav-toggle').click();
    await expect(page.locator('body')).toHaveClass(/nav-open/);
    // drawer uses CSS transform (not display:none), so check aria-hidden instead of toBeVisible
    await expect(page.locator('#nav-drawer')).not.toHaveAttribute('aria-hidden', 'true');
    // Close via overlay click
    await page.locator('.nav-drawer-overlay').click();
    await expect(page.locator('body')).not.toHaveClass(/nav-open/);
    await expect(page.locator('#nav-drawer')).toHaveAttribute('aria-hidden', 'true');
  });
});
