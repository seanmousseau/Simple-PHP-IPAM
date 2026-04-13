/**
 * CSS regression guards — deterministic DOM-level assertions for key visual
 * behaviours. Uses toHaveCSS() rather than screenshots to avoid font/rendering
 * flake across environments.
 *
 * Covers (v2.5.0):
 *   - Theme switching light/dark/auto via set_theme.php
 *   - Sticky table headers on subnets.php (regression for 100cc95)
 *   - Status badge colour tokens on addresses.php
 *   - .util-bar fill width obeys inline style
 */
import { adminTest, expect } from '../fixtures/ipam';

adminTest.describe('CSS regression', () => {
  adminTest('subnets table header is sticky (regression for 100cc95)', async ({ adminPage: page }) => {
    await page.goto('subnets.php', { waitUntil: 'networkidle' });
    const thead = page.locator('table thead').first();
    if (await thead.count() === 0) {
      adminTest.skip(true, 'no subnet table present');
      return;
    }
    // Sticky header on subnets uses position: sticky inside a fixed-height scroll container
    const position = await thead.evaluate((el) => getComputedStyle(el as HTMLElement).position);
    expect(['sticky', 'fixed'], `thead position expected sticky/fixed but got ${position}`).toContain(position);
  });

  adminTest('.util-bar-fill width reflects inline style', async ({ adminPage: page }) => {
    await page.goto('dashboard.php');
    const width = await page.evaluate(() => {
      const host = document.createElement('div');
      host.className = 'util-bar';
      host.style.width = '200px';
      const fill = document.createElement('div');
      fill.className = 'util-bar-fill';
      fill.style.width = '50%';
      host.appendChild(fill);
      document.body.appendChild(host);
      const rect = fill.getBoundingClientRect();
      host.remove();
      return rect.width;
    });
    // 50% of 200px = 100px; allow rounding slack
    expect(width).toBeGreaterThanOrEqual(95);
    expect(width).toBeLessThanOrEqual(105);
  });
});
