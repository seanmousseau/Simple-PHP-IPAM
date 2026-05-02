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

// ── Notifications tab — editable preferences (v3.22.0 §2.4) ───────────────────

test.describe('Notifications tab — editable preferences', () => {

    // 8 event toggles (boolean) — keys match ipam_setting_definitions() event_*.
    // Default-ON keys per the registry; all others default OFF.
    const EVENT_KEYS = [
        'success_scheduled',
        'success_manual',
        'failure_scheduled',
        'failure_manual',
        'destination_conn_failure',
        'schedule_overdue',
        'retention_prune',
        'encryption_change',
    ] as const;
    const DEFAULT_ON = new Set([
        'failure_scheduled',
        'failure_manual',
        'destination_conn_failure',
        'schedule_overdue',
        'encryption_change',
    ]);

    test.beforeEach(async () => {
        await page.goto(tabUrl('notifications'));
    });

    test('renders an editable form scoped to backup_admin.php?tab=notifications', async () => {
        const form = page.locator('.backup-admin-tab form[method="post"]');
        await expect(form).toHaveCount(1);
        await expect(form).toHaveAttribute('action', /backup_admin\.php\?tab=notifications/);
        // Hidden action discriminator the controller dispatches on.
        await expect(form.locator('input[type="hidden"][name="action"][value="save_notifications"]')).toHaveCount(1);
    });

    test('renders all 8 event toggles as checkboxes with stable name= attrs', async () => {
        for (const key of EVENT_KEYS) {
            const cb = page.locator(`.backup-admin-tab form input[type="checkbox"][name="event_${key}"]`);
            await expect(cb, `event toggle for ${key}`).toHaveCount(1);
        }
    });

    test('checkbox states match the setting registry defaults after explicit reset', async () => {
        // Tests share a single SQLite DB (workers=1). An earlier test that
        // toggles any backup.notify_* setting would otherwise make this
        // assertion flaky. Submit the form with the registry-default values
        // first so the assertion reflects the contract, not stale shared
        // state. (#1074 CR comment on backup_restore.spec.ts:199.)
        const csrf = await page.locator('.backup-admin-tab form input[type="hidden"][name="csrf"]').first().getAttribute('value');
        if (!csrf) throw new Error('csrf token missing on notifications form');
        const formAction = await page.locator('.backup-admin-tab form[method="post"]').first().getAttribute('action');
        if (!formAction) throw new Error('notifications form action missing');
        const targetUrl = new URL(formAction, page.url()).toString();
        const body = new URLSearchParams();
        body.append('csrf', csrf);
        body.append('action', 'save_notifications');
        body.append('overdue_grace_minutes', '60');
        for (const key of EVENT_KEYS) {
            if (DEFAULT_ON.has(key)) body.append(`event_${key}`, '1');
            // unchecked checkboxes are simply absent from form data
        }
        const resetResp = await page.request.post(targetUrl, {
            form: Object.fromEntries(body),
            failOnStatusCode: false,
        });
        if (!resetResp.ok() && resetResp.status() !== 302) {
            throw new Error(`reset POST returned ${resetResp.status()}`);
        }
        await page.goto(tabUrl('notifications'));

        for (const key of EVENT_KEYS) {
            const cb = page.locator(`.backup-admin-tab form input[type="checkbox"][name="event_${key}"]`);
            if (DEFAULT_ON.has(key)) {
                await expect(cb, `${key} should be ON after reset`).toBeChecked();
            } else {
                await expect(cb, `${key} should be OFF after reset`).not.toBeChecked();
            }
        }
    });

    test('renders the schedule-overdue grace-minutes integer input (default 60)', async () => {
        const grace = page.locator('.backup-admin-tab form input[type="number"][name="overdue_grace_minutes"]');
        await expect(grace).toHaveCount(1);
        await expect(grace).toHaveValue('60');
        await expect(grace).toHaveAttribute('min', '5');
        await expect(grace).toHaveAttribute('max', '1440');
    });

    test('CSRF hidden input is present in the editable form', async () => {
        const csrf = page.locator('.backup-admin-tab form input[type="hidden"][name="csrf"]');
        await expect(csrf).toHaveCount(1);
        const value = await csrf.getAttribute('value');
        expect(value, 'csrf token should be a non-empty string').toBeTruthy();
        expect((value ?? '').length).toBeGreaterThan(8);
    });

    test('submit button is present', async () => {
        await expect(
            page.locator('.backup-admin-tab form button[type="submit"]', { hasText: /Save preferences/i }),
        ).toHaveCount(1);
    });

    test('recipients summary card renders SMTP status + alert recipients', async () => {
        // Second card on the tab — recipients summary still surfaces SMTP
        // delivery state and the existing alert.email / alert.recipient_user_ids
        // configuration, with deep-links into settings.php for editing.
        const recipients = page.locator('.backup-admin-tab section.card').filter({
            has: page.locator('h3', { hasText: /^\s*Recipients\s*$/ }),
        });
        await expect(recipients).toHaveCount(1);
        await expect(recipients.locator('tbody tr', { hasText: /SMTP delivery/i })).toHaveCount(1);
        await expect(recipients.locator('tbody tr', { hasText: /Selected alert users/i })).toHaveCount(1);
        // Deep-link into the alerts group of settings.php still exists.
        await expect(
            recipients.locator('a[href*="settings.php?tab=general"]'),
        ).not.toHaveCount(0);
    });
});
