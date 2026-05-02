/**
 * backup_restore.spec.ts — unified Backup & Restore admin surface (#1040, v3.21.0)
 *
 * Covers the gaps in coverage that the legacy split specs (backups.spec.ts,
 * restore.spec.ts, backup-integration.spec.ts) cannot exercise because they
 * predate the unified surface introduced in Wave 4 of v3.21.0:
 *
 *   - 5-tab navigation on backup_admin.php?tab=… (deep-link, refresh,
 *     active-tab styling, sidebar entry)
 *   - Notifications tab read-only summary (zero prior coverage)
 *   - Sidebar consolidation: a single "Backup & Restore" entry (#797, #798)
 *
 * Drawer focus-trap, restore-wizard E2E, manual upload, and run-now inline
 * progress are covered in subsequent v3.21.0 commits.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { login, appUrl, newAuthContext, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';

const TABS = ['backup', 'restore', 'destinations', 'notifications', 'history'] as const;
type Tab = typeof TABS[number];

const TAB_LABEL: Record<Tab, string> = {
    backup:        'Backup',
    restore:       'Restore',
    destinations:  'Destinations',
    notifications: 'Notifications',
    history:       'History',
};

/** URL for a tab on the unified surface. */
function tabUrl(tab: Tab): string {
    return appUrl(`backup_admin.php?tab=${tab}`);
}

let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
    ctx  = await newAuthContext(browser);
    page = await ctx.newPage();
    await login(page, ADMIN_USER, ADMIN_PASS);
});

test.afterAll(async () => {
    await ctx.close();
});

// ── Tab bar structure ───────────────────────────────────────────────────────────

test.describe('Backup & Restore unified surface — tab bar', () => {

    test('default URL (no ?tab=) lands on Backup tab', async () => {
        await page.goto(appUrl('backup_admin.php'));
        await expect(page).toHaveTitle(/Backup & Restore/i, { timeout: 10_000 });
        // h1 is constant, h2 reflects active tab.
        await expect(page.locator('h1')).toHaveText('Backup & Restore');
        await expect(page.locator('#backup-admin-tab-title')).toHaveText('Backup');
        await expect(page.locator('.tab-bar__link.is-active')).toHaveText('Backup');
    });

    test('all 5 tabs are present in the tab bar in fixed order', async () => {
        await page.goto(appUrl('backup_admin.php'));
        const links = page.locator('.tab-bar__link');
        await expect(links).toHaveCount(5);
        const labels = await links.allTextContents();
        expect(labels.map(s => s.trim())).toEqual([
            'Backup', 'Restore', 'Destinations', 'Notifications', 'History',
        ]);
    });

    test('unknown ?tab= value falls back to Backup', async () => {
        await page.goto(appUrl('backup_admin.php?tab=does-not-exist'));
        await expect(page.locator('#backup-admin-tab-title')).toHaveText('Backup');
        await expect(page.locator('.tab-bar__link.is-active')).toHaveText('Backup');
    });

    for (const tab of TABS) {
        test(`?tab=${tab} deep-links to the ${TAB_LABEL[tab]} tab`, async () => {
            await page.goto(tabUrl(tab));
            await expect(page.locator('#backup-admin-tab-title')).toHaveText(TAB_LABEL[tab]);
            await expect(page.locator('.tab-bar__link.is-active')).toHaveText(TAB_LABEL[tab]);
            // aria-current="page" is set on the active tab only.
            await expect(page.locator('.tab-bar__link[aria-current="page"]')).toHaveCount(1);
        });
    }

    test('reload preserves the active tab (history → reload → history)', async () => {
        await page.goto(tabUrl('history'));
        await expect(page.locator('#backup-admin-tab-title')).toHaveText('History');
        await page.reload();
        await expect(page.locator('#backup-admin-tab-title')).toHaveText('History');
        await expect(page.locator('.tab-bar__link.is-active')).toHaveText('History');
    });

    test('clicking a tab link navigates and updates active state without losing the surface', async () => {
        await page.goto(tabUrl('backup'));
        await page.locator('.tab-bar__link', { hasText: 'Destinations' }).click();
        await page.waitForURL(/tab=destinations/, { timeout: 10_000 });
        await expect(page.locator('#backup-admin-tab-title')).toHaveText('Destinations');
        await expect(page.locator('.tab-bar__link.is-active')).toHaveText('Destinations');
        // Tab bar still rendered with all 5 entries.
        await expect(page.locator('.tab-bar__link')).toHaveCount(5);
    });
});

// ── Sidebar consolidation (#797 / #798) ────────────────────────────────────────

test.describe('Sidebar — Backup & Restore entry (#797, #798)', () => {

    test('sidebar exposes a single "Backup & Restore" entry pointing at backup_admin.php', async () => {
        await page.goto(appUrl('dashboard.php'));
        // Anchor matcher is loose because the link's accessible text includes a
        // leading icon SVG; we only care that the label is present and the
        // href points at the unified surface.
        const link = page.locator('a.sidebar-link[href^="backup_admin.php"]', {
            hasText: /Backup\s*&\s*Restore/,
        });
        await expect(link).toHaveCount(1);
        await expect(link).toHaveAttribute('href', /backup_admin\.php(?!\?)/);
    });

    test('legacy sidebar entries (Destinations / Backup History / Remote Backups / Restore) are retired', async () => {
        await page.goto(appUrl('dashboard.php'));
        // No sidebar link should resolve to any of the retired legacy URLs.
        // (Matching by href is more robust than matching by visible label —
        // "Destinations" might appear elsewhere in nav for the unified surface.)
        for (const legacyHref of ['destinations.php', 'backup_history.php', 'remote_backups.php', 'restore_web.php']) {
            await expect(
                page.locator(`a.sidebar-link[href$="${legacyHref}"]`),
                `legacy sidebar link to ${legacyHref} should be retired`,
            ).toHaveCount(0);
        }
    });

    test('sidebar Backup & Restore link is marked active on every legacy URL too', async () => {
        // The thin wrappers still work as direct URLs; the sidebar should
        // still highlight the unified entry so users know where they are.
        for (const legacy of ['destinations.php', 'backup_history.php', 'restore_web.php']) {
            await page.goto(appUrl(legacy));
            const link = page.locator('a.sidebar-link.is-active[href^="backup_admin.php"]', {
                hasText: /Backup\s*&\s*Restore/,
            });
            await expect(link, `is-active expected on ${legacy}`).toHaveCount(1);
        }
    });
});

// ── Notifications tab — read-only summary ──────────────────────────────────────

test.describe('Notifications tab — read-only summary', () => {

    test.beforeEach(async () => {
        await page.goto(tabUrl('notifications'));
    });

    test('renders the four notification preference rows', async () => {
        const rows = page.locator('.backup-admin-tab .data-table tbody tr');
        await expect(rows).toHaveCount(4);
        await expect(rows.nth(0)).toContainText('Notify on backup failure');
        await expect(rows.nth(1)).toContainText('Notify on backup success');
        await expect(rows.nth(2)).toContainText('Recipient (alert_email)');
        await expect(rows.nth(3)).toContainText('SMTP delivery');
    });

    test('every row has an "Edit in Settings" deep-link (no inline editing)', async () => {
        const rows = page.locator('.backup-admin-tab .data-table tbody tr');
        for (let i = 0; i < 4; i++) {
            const link = rows.nth(i).locator('a', { hasText: 'Edit in Settings' });
            await expect(link).toHaveCount(1);
            await expect(link).toHaveAttribute('href', /^settings\.php\?tab=/);
        }
    });

    test('on/off badges render for boolean settings (not free-form text)', async () => {
        const failureRow = page.locator('.backup-admin-tab .data-table tbody tr', { hasText: 'Notify on backup failure' });
        const smtpRow    = page.locator('.backup-admin-tab .data-table tbody tr', { hasText: 'SMTP delivery' });
        await expect(failureRow.locator('.badge')).toHaveCount(1);
        await expect(smtpRow.locator('.badge')).toHaveCount(1);
    });

    test('no <form> elements — confirms the tab is read-only', async () => {
        // The Notifications tab body must not render any form. All editing
        // routes through the deep-links into settings.php.
        await expect(page.locator('.backup-admin-tab form')).toHaveCount(0);
    });
});
