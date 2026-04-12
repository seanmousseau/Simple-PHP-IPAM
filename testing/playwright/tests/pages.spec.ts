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
  ['tags.php',        'Tag'],
  ['api_keys.php',    'API Key'],
  ['dhcp_pool.php',   'DHCP'],
  ['import_csv.php',  'Import'],
  ['db_tools.php',    'Database'],
  ['unassigned.php',  'Unassigned'],
  ['bulk_update.php', 'Bulk'],
  ['change_password.php', 'Password'],
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
