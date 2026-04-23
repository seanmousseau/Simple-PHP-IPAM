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
    const dangerCount = await page.locator('.danger').count();
    expect(dangerCount, 'expected no .danger elements on health page').toBe(0);
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
});
