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
 *   5. Advanced filename fallback `<details>` is preserved.
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
    await p.locator('select[name="dest"]').selectOption(String(id));
    // The picker auto-submits via onchange=submit — wait for the GET reload.
    // Function predicate avoids dynamic-RegExp construction.
    await p.waitForURL((url: URL) => {
        return url.searchParams.get('tab') === 'restore'
            && url.searchParams.get('dest') === String(id);
    }, { timeout: 10_000 });
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
        // Advanced disclosure (free-text fallback) still present.
        await expect(page.locator('details summary', { hasText: /Advanced/i })).toBeVisible();
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

    test('Advanced fallback exposes the legacy free-text filename form', async () => {
        await gotoRestore(page);
        // The disclosure is closed by default; the input is in the DOM but
        // not visible until expanded.
        const summary = page.locator('details summary', { hasText: /Advanced/i });
        await summary.click();
        const advForm = page.locator('details form:has(input[name="step"][value="stage"])');
        await expect(advForm.locator('input[name="name"]')).toBeVisible();
        await expect(advForm.locator('select[name="destination_id"]')).toBeVisible();
        await expect(advForm.locator('button[type="submit"]')).toBeVisible();
    });
});
