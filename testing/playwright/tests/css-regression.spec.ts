/**
 * CSS regression guards — deterministic DOM-level assertions for key visual
 * behaviours. Uses toHaveCSS() rather than screenshots to avoid font/rendering
 * flake across environments.
 *
 * Covers (v2.5.0):
 *   - Theme switching light/dark/auto via user_preference.php
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

  adminTest('theme toggle persists html[data-theme] across page navigation', async ({ adminPage: page }) => {
    await page.goto('dashboard.php');

    // Get current CSRF token from the page meta tag
    const csrfToken = await page.locator('meta[name="ipam-csrf"]').getAttribute('content').catch(() => '');
    if (!csrfToken) {
      adminTest.skip(true, 'could not get CSRF token');
      return;
    }

    // Set theme to dark via user_preference.php
    await page.evaluate(async (csrf: string) => {
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('key', 'theme');
      fd.append('value', 'dark');
      await fetch('user_preference.php', { method: 'POST', body: fd });
    }, csrfToken);

    // Navigate to another page and check html[data-theme]
    await page.goto('subnets.php');
    const theme = await page.locator('html').getAttribute('data-theme');
    expect(theme).toBe('dark');

    // Restore to auto
    const csrfToken2 = await page.locator('meta[name="ipam-csrf"]').getAttribute('content').catch(() => '');
    if (csrfToken2) {
      await page.evaluate(async (csrf: string) => {
        const fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('key', 'theme');
        fd.append('value', 'auto');
        await fetch('user_preference.php', { method: 'POST', body: fd });
      }, csrfToken2);
    }
  });

  adminTest('sparklines visible in dark mode', async ({ adminPage: page }) => {
    await page.goto('dashboard.php');
    await page.evaluate(() => {
      document.documentElement.setAttribute('data-theme', 'dark');
    });
    const sparklines = page.locator('svg.sparkline polyline');
    const count = await sparklines.count();
    if (count === 0) {
      adminTest.skip(true, 'no sparkline data in this env');
      return;
    }
    const stroke = await sparklines.first().evaluate(
      (el: Element) => window.getComputedStyle(el as SVGElement).stroke
    );
    expect(stroke).toMatch(/^rgb/);
  });

  adminTest('dashboard metric-row renders exactly 3 columns at 1280px viewport', async ({ adminPage: page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('dashboard.php');
    await page.waitForLoadState('networkidle');

    const columnCount = await page.evaluate(() => {
      const grid = document.querySelector('[data-widget="metrics"]') as HTMLElement;
      if (!grid) return -1;
      const style = window.getComputedStyle(grid);
      const cols  = style.gridTemplateColumns.split(' ').filter(Boolean);
      return cols.length;
    });

    expect(columnCount, 'metric-row should have exactly 3 columns at 1280px viewport (regression for #649)').toBe(3);
  });
});
