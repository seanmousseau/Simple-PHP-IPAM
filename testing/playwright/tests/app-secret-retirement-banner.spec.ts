/**
 * v3.28.0 #1164 — the persistent retirement notice for `app_secret`-based
 * backup encryption must render on both the Backups → Destinations and
 * Backups → Restore tabs (it's the operator's heads-up about the v4.0.0 cold
 * break). Marked with `data-test="app-secret-retirement-banner"`.
 */
import { test, expect } from '@playwright/test';
import { login, appUrl, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';

test.describe('app_secret backup-encryption retirement banner (#1164)', () => {
    test.beforeEach(async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
    });

    test('renders on the Destinations tab', async ({ page }) => {
        await page.goto(appUrl('backup_admin.php?tab=destinations'));
        const banner = page.locator('[data-test="app-secret-retirement-banner"]');
        await expect(banner).toBeVisible();
        await expect(banner).toContainText('app_secret');
        await expect(banner).toContainText('v4.0.0');
        await expect(banner).toContainText('decrypt-backup.php');
    });

    test('renders on the Restore tab', async ({ page }) => {
        await page.goto(appUrl('backup_admin.php?tab=restore'));
        const banner = page.locator('[data-test="app-secret-retirement-banner"]');
        await expect(banner).toBeVisible();
        await expect(banner).toContainText('app_secret');
    });
});
