/**
 * CSP regression guard — every authenticated page must load without Content
 * Security Policy violations on the browser console.
 *
 * Ships as v2.5.0 regression gate for commit ca61512 ("eliminate all
 * inline-style CSP violations"). If any page regresses by re-introducing an
 * inline style or inline script, this spec will fail loudly.
 */
import { adminTest, expect } from '../fixtures/ipam';

const PAGES = [
  'dashboard.php',
  'subnets.php',
  'addresses.php',
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
  'import_csv.php',
  'db_tools.php',
  'unassigned.php',
  'bulk_update.php',
  'change_password.php',
  'scan_history.php',
];

// Substrings identifying a CSP violation in a console message. Blink/Chromium
// emits "Refused to apply inline style ... because it violates the following
// Content Security Policy directive ..." and similar for scripts.
const CSP_MARKERS = [
  'Refused to apply inline style',
  'Refused to execute inline script',
  'Refused to load the',
  'Content Security Policy',
];

adminTest.describe('CSP compliance', () => {
  for (const slug of PAGES) {
    adminTest(`${slug} loads with no CSP violations`, async ({ adminPage: page }) => {
      const violations: string[] = [];
      page.on('console', (msg) => {
        if (msg.type() === 'error') {
          const text = msg.text();
          if (CSP_MARKERS.some((m) => text.includes(m))) {
            violations.push(text);
          }
        }
      });
      page.on('pageerror', (err) => {
        const text = err.message;
        if (CSP_MARKERS.some((m) => text.includes(m))) {
          violations.push(text);
        }
      });

      // addresses.php requires a subnet_id; fall back to subnets.php for its nav
      const target = slug === 'addresses.php' ? 'addresses.php?subnet_id=1' : slug;
      const response = await page.goto(target, { waitUntil: 'networkidle' });
      expect(response, `${slug} returned no response`).not.toBeNull();

      // Assert the CSP header is actually being sent (regression guard against
      // accidentally dropping the header on a refactor).
      const headers = response!.headers();
      const csp = headers['content-security-policy'] || headers['content-security-policy-report-only'];
      expect(csp, `${slug} missing CSP header`).toBeTruthy();

      expect(
        violations,
        `${slug} emitted CSP violations:\n  - ${violations.join('\n  - ')}`
      ).toEqual([]);
    });
  }
});
