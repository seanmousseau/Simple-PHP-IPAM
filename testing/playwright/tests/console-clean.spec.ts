/**
 * Fleet-wide JS cleanliness guard — every authenticated page must load with no
 * console.error output and no uncaught promise rejections. Catches silent JS
 * regressions that page-level tests miss.
 */
import { adminTest, expect } from '../fixtures/ipam';

const PAGES = [
  'dashboard.php',
  'subnets.php',
  'search.php',
  'audit.php',
  'users.php',
  'sites.php',
  'vlans.php',
  'vrfs.php',
  'aggregates.php',
  'pd_pools.php',
  'tags.php',
  'contacts.php',
  'api_keys.php',
  'dhcp_pool.php',
  'unassigned.php',
  'bulk_update.php',
  'change_password.php',
  'scan_history.php',
  'settings.php',
];

// Benign warnings we deliberately tolerate — empty for now. Add with caution.
const IGNORED_SUBSTRINGS: string[] = [];

adminTest.describe('Console cleanliness', () => {
  for (const slug of PAGES) {
    adminTest(`${slug} has no console.error or unhandled rejections`, async ({ adminPage: page }) => {
      const errors: string[] = [];
      page.on('console', (msg) => {
        if (msg.type() !== 'error') return;
        const text = msg.text();
        if (IGNORED_SUBSTRINGS.some((s) => text.includes(s))) return;
        errors.push(text);
      });
      page.on('pageerror', (err) => {
        const text = err.message;
        if (IGNORED_SUBSTRINGS.some((s) => text.includes(s))) return;
        errors.push(`pageerror: ${text}`);
      });

      await page.goto(slug, { waitUntil: 'networkidle' });

      expect(
        errors,
        `${slug} emitted ${errors.length} console error(s):\n  - ${errors.join('\n  - ')}`
      ).toEqual([]);
    });
  }
});
