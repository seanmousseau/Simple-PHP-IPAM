/**
 * Page inventory — every admin page loads without a .danger error element.
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
  ['api_keys.php',    'API Key'],
  ['dhcp_pool.php',   'DHCP'],
  ['import_csv.php',  'Import'],
  ['db_tools.php',    'Database'],
  ['unassigned.php',  'Unassigned'],
  ['bulk_update.php', 'Bulk'],
  ['change_password.php', 'Password'],
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
