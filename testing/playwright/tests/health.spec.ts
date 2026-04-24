/**
 * health.php regression guards.
 *
 * Covers:
 *   - Page loads without any .danger elements (general health assertion)
 *   - IPAM version cell shows a semver string, not "?" (regression for #646, #662)
 *   - DB version cell is non-empty
 */
import { adminTest, expect } from '../fixtures/ipam';

adminTest.describe('health dashboard', () => {
  adminTest('loads without .danger element', async ({ adminPage: page }) => {
    await page.goto('health.php?nocache=1', { waitUntil: 'networkidle' });
    // Exclude tool-availability warnings (e.g. mysqldump not in $PATH on test containers)
    // which are expected infrastructure gaps, not application errors.
    const dangerCount = await page.locator('.danger')
      .filter({ hasNotText: /not found in \$PATH/i })
      .count();
    expect(dangerCount, 'expected no application .danger elements on health page').toBe(0);
  });

  adminTest('IPAM version cell shows semver pattern, not "?"', async ({ adminPage: page }) => {
    await page.goto('health.php?nocache=1', { waitUntil: 'networkidle' });

    // Find the health-row whose label contains "IPAM version", then read its value cell
    const row = page.locator('.health-row', { has: page.locator('.health-label', { hasText: 'IPAM version' }) });
    await expect(row).toBeVisible();

    const valText = await row.locator('.health-val').innerText();
    expect(valText.trim(), `IPAM version should be semver, got "${valText.trim()}"`).toMatch(/^\d+\.\d+\.\d+$/);
  });

  adminTest('DB version cell is non-empty', async ({ adminPage: page }) => {
    await page.goto('health.php?nocache=1', { waitUntil: 'networkidle' });

    // Find the first health-row whose label contains "Version" (DB version row)
    const row = page.locator('.health-row', { has: page.locator('.health-label', { hasText: 'Version' }) }).first();
    await expect(row).toBeVisible();

    const valText = await row.locator('.health-val').innerText();
    expect(valText.trim(), 'DB version cell should not be empty').not.toBe('');
  });

  adminTest('health page has breadcrumb', async ({ adminPage: page }) => {
    await page.goto('health.php');
    const bc = page.locator('.breadcrumbs');
    await expect(bc).toBeVisible();
    await expect(bc.locator('a[href="dashboard.php"]')).toContainText('Dashboard');
    await expect(bc.locator('span').last()).toContainText('Health');
  });

  adminTest('scanning card shows warn alerts row with green dot for zero', async ({ adminPage: page }) => {
    await page.goto('health.php?nocache=1', { waitUntil: 'networkidle' });

    const warnRow = page.locator('.health-row', {
      has: page.locator('.health-label', { hasText: 'Warn alerts' })
    });
    await expect(warnRow, 'Warn alerts row must exist in health page').toBeVisible();

    const dot = warnRow.locator('.health-dot');
    await expect(dot, 'Warn alerts dot must be .ok (green) when count is 0').toHaveClass(/\bok\b/);
  });

  adminTest('scanning card shows crit alerts row with green dot for zero', async ({ adminPage: page }) => {
    await page.goto('health.php?nocache=1', { waitUntil: 'networkidle' });

    const critRow = page.locator('.health-row', {
      has: page.locator('.health-label', { hasText: 'Crit alerts' })
    });
    await expect(critRow, 'Crit alerts row must exist in health page').toBeVisible();

    const dot = critRow.locator('.health-dot');
    await expect(dot, 'Crit alerts dot must be .ok (green) when count is 0').toHaveClass(/\bok\b/);
  });
});
