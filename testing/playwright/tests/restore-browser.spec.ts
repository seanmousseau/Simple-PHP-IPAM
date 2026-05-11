/**
 * restore-browser.spec.ts — destination-driven backup browser on the
 * Restore tab (#1077, v3.23.0).
 *
 * Pre-#1077 the Restore tab Step 1 was a free-text filename input — operators
 * had to know the remote filename to start a restore. This spec exercises the
 * new browser that calls `BackupClientInterface::listObjects()` on the picked
 * destination and renders a per-row table.
 *
 * Coverage:
 *   1. Destination picker → ?dest=N reload (page-state survives refresh).
 *   2. Browse table renders columns (filename, size, encryption, type, checksum, actions).
 *   3. Empty-state message points operator at the Backup tab.
 *   4. Restore button on a row stages the backup (lands in Step 2 of the wizard).
 *   5. v3.27.6 #1136: removed Advanced-fallback test — the legacy free-text
 *      "stage by filename" UI was deleted; the per-row Restore button in
 *      the enumerated backup table is now the sole stage path.
 *
 * Required fixture state:
 *   - 'ci-local' destination seeded with at least one backup. The backup
 *     integration spec (backup-integration.spec.ts) runs first in this gate
 *     and uploads one via run_backup_now, so by the time we get here the
 *     ci-local destination has a non-empty listObjects(). On rare orderings
 *     where it doesn't, this spec asserts the empty-state message instead.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { login, appUrl, newAuthContext, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';

const DEST_LOCAL = 'ci-local';

let ctx: BrowserContext;
let page: Page;

async function gotoRestore(p: Page): Promise<void> {
    await p.goto(appUrl('backup_admin.php?tab=restore'));
}

async function selectDestinationByName(p: Page, name: string): Promise<number> {
    // Read the option value matching the seeded destination's display name.
    const opt = p.locator(`select[name="dest"] option`, { hasText: name }).first();
    const val = await opt.getAttribute('value');
    expect(val, `option for ${name} must carry a numeric destination_id`).toBeTruthy();
    const id = parseInt(val ?? '0', 10);
    expect(id).toBeGreaterThan(0);
    // The picker uses <select onchange=this.form.submit()> — that's reliable
    // in browsers but Playwright's selectOption fires the change event AFTER
    // its return resolves, so a naive `selectOption(); await waitForURL(...)`
    // races. Pair the change with an explicit GET to the same URL the form
    // would post; this avoids the race entirely and is functionally identical
    // for a method=get form.
    await p.goto(appUrl(`backup_admin.php?tab=restore&dest=${id}`));
    return id;
}

test.describe('Restore tab — destination-driven backup browser (#1077)', () => {
    test.beforeAll(async ({ browser }: { browser: Browser }) => {
        ctx  = await newAuthContext(browser);
        page = await ctx.newPage();
        await login(page, ADMIN_USER, ADMIN_PASS);
    });

    test.afterAll(async () => {
        await ctx.close();
    });

    test('default landing renders only the destination picker (no table)', async () => {
        await gotoRestore(page);
        await expect(page.locator('select[name="dest"]')).toBeVisible();
        // No browse table when no destination is selected.
        await expect(page.locator('table.data-table')).toHaveCount(0);
        // v3.27.6 #1136: the upload-from-workstation card is now a peer to
        // the destination picker (no <details> wrapper, no Advanced
        // disclosure). Confirm the upload affordance is visible at landing.
        await expect(page.locator('input[type="file"][name="restore_upload"]')).toBeVisible();
    });

    test('destination picker form has method=get + data-submit-on-change auto-submit', async () => {
        // Affordance contract: selecting an option reloads the page with
        // ?dest=N. v3.27.7: the inline `onchange="this.form.submit()"`
        // pattern was replaced with a `data-submit-on-change` attribute +
        // delegated handler in assets/app.js because the strict CSP
        // (`script-src 'self'` with no script-src-attr) blocks inline
        // event handlers under CSP3 enforcement. Asserted at the markup
        // level rather than via live click because Playwright's
        // selectOption races with auto-submit handlers (the actual
        // <select>; the integration tests below use direct goto to a
        // ?dest=N URL for determinism).
        await gotoRestore(page);
        const sel = page.locator('select[name="dest"]');
        await expect(sel).toHaveAttribute('data-submit-on-change', '');
        const form = sel.locator('xpath=ancestor::form');
        await expect(form).toHaveAttribute('method', /get/i);
        await expect(form).toHaveAttribute('action', /backup_admin\.php/);
    });

    test('selecting ci-local reloads with ?dest=N and either lists rows or shows empty-state', async () => {
        await gotoRestore(page);
        const id = await selectDestinationByName(page, DEST_LOCAL);
        expect(id).toBeGreaterThan(0);

        // The page now either renders the browse table OR the empty-state
        // message. Both are valid contracts; the spec asserts the contract
        // matches the actual destination contents.
        const table = page.locator('table.data-table');
        const tableVisible = await table.count();
        if (tableVisible > 0) {
            // Header columns we promise in the view.
            const headers = await table.locator('thead th').allTextContents();
            const norm = headers.map(h => h.trim().toLowerCase());
            for (const expected of ['filename', 'size', 'date', 'encryption', 'type', 'checksum', 'actions']) {
                expect(norm, `expected header "${expected}"`).toContain(expected);
            }
            // At least one row must include a Restore button (or the
            // disabled-degraded variant on non-sqlite installs missing CLI).
            const rowsWithRestore = await table.locator('tbody tr', {
                has: page.locator('button', { hasText: /Restore/i }),
            }).count();
            expect(rowsWithRestore).toBeGreaterThan(0);
        } else {
            await expect(page.locator('p', { hasText: /No backups in this destination yet/i })).toBeVisible();
        }
    });

    test('Restore button on first row stages the backup and lands on Step 2', async () => {
        await gotoRestore(page);
        await selectDestinationByName(page, DEST_LOCAL);

        const tableVisible = await page.locator('table.data-table').count();
        test.skip(tableVisible === 0, 'destination has no backups yet (run-now ordering); covered by integration spec');

        // First row's Restore form. The view renders the Restore submit
        // button as the LAST submit in the row's actions cell, but we
        // scope by [type=submit] inside a form whose hidden step input
        // is "stage" to disambiguate from the Download form.
        const restoreForm = page.locator(
            'table.data-table tbody tr form:has(input[name="step"][value="stage"])'
        ).first();
        await expect(restoreForm).toBeVisible();
        await restoreForm.locator('button[type="submit"]').click();

        // Stage success → controller emits Step 2 ("dry-run preview") with
        // a Run dry-run button. URL stays on backup_admin.php?tab=restore.
        await expect(page.locator('h2', { hasText: /Step 2: dry-run preview/i }))
            .toBeVisible({ timeout: 15_000 });
    });

    // v3.27.6 #1136 removed the "Advanced — stage by filename" <details>
    // entirely (it was a workaround for the missing enumeration UI before
    // #1077 landed and is redundant now). The matching legacy-form test
    // that lived here was deleted with the UI; the per-row Restore button
    // in the destination-driven backup browser is now the sole stage path,
    // and `tests/restore-browser.spec.ts:click-row-Restore` above already
    // covers it end-to-end.

    // v3.27.8 Bug A: Verify / Delete control on the Restore tab must open
    // the same per-run drawer the History tab uses, not navigate to a
    // partial-HTML page rendered without page_header(). Asserted both at
    // the markup level (button + data-drawer-url) and via live click so
    // a future regression to <a href=…> can't slip past.
    test('Bug A: Verify/Delete uses drawer-pattern button, not anchor navigation', async () => {
        await gotoRestore(page);
        await selectDestinationByName(page, DEST_LOCAL);

        const verifyBtn = page.locator(
            'table.data-table tbody tr button[data-drawer-url^="backup_run_detail.php"]'
        ).first();
        const hasBtn = await verifyBtn.count();
        test.skip(hasBtn === 0, 'no backup row with run_id > 0 in fixture; covered by integration spec');

        // Markup contract: must be a <button>, must carry data-drawer-url,
        // must NOT be a navigation anchor.
        const tagName = await verifyBtn.evaluate(el => el.tagName.toLowerCase());
        expect(tagName).toBe('button');
        const drawerUrl = await verifyBtn.getAttribute('data-drawer-url');
        expect(drawerUrl, 'data-drawer-url must point at backup_run_detail.php').toMatch(
            /^backup_run_detail\.php\?id=\d+$/
        );

        // No same-row legacy anchor regression.
        const anchorRegression = page.locator(
            'table.data-table tbody tr a[href^="backup_run_detail.php"]'
        );
        expect(await anchorRegression.count(), 'no <a href=backup_run_detail.php> anchors should remain').toBe(0);

        // Live behaviour: click opens the global drawer with the partial body.
        // The URL must NOT change (full-page navigation would update location).
        const urlBefore = page.url();
        await verifyBtn.click();
        await expect(page.locator('#global-drawer')).toBeVisible();
        await expect(page.locator('#global-drawer-body')).not.toBeEmpty();
        expect(page.url(), 'drawer open must not navigate the page').toBe(urlBefore);
    });
});
