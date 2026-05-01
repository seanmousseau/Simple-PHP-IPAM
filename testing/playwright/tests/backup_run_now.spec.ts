/**
 * backup_run_now.spec.ts — Run-now inline progress on backup_admin.php?tab=backup
 * (#1040, #801 v3.21.0).
 *
 * The Backup tab exposes a destination <select> + "Run backup now" button
 * (#run-now-button) that POSTs to run_backup_now.php and renders the JSON
 * response into #run-now-result inline — no redirect, no flash, no page
 * reload. The handler also disables the button + select while the request
 * is in flight and changes the button label to "Running…".
 *
 * Tests in this file pin that contract so the inline behavior cannot
 * regress to the legacy redirect-and-flash pattern.
 *
 * Pre-conditions: bootstrap-app.sh seeds 'ci-local' (filesystem destination)
 * and 'ci-minio' (S3 destination). We exercise 'ci-local' because it does
 * not depend on the MinIO sidecar being responsive.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { login, appUrl, newAuthContext, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';

const DEST_LOCAL = 'ci-local';

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

test.describe('Run-now inline progress (#1040, #801)', () => {

    test.beforeEach(async () => {
        await page.goto(appUrl('backup_admin.php?tab=backup'));
        await expect(page.locator('#run-now-button')).toBeVisible();
        // Select the local destination so the run is fast and offline-safe.
        await page.locator('#run-now-destination').selectOption({ label: DEST_LOCAL });
    });

    test('button + select + result span are wired with the expected attributes', async () => {
        const btn = page.locator('#run-now-button');
        await expect(btn).toHaveAttribute('data-run-now-target', 'run-now-destination');
        await expect(btn).toHaveAttribute('data-run-now-result', 'run-now-result');
        // aria-live polite ensures screen readers announce the result without
        // stealing focus — required for the no-redirect UX.
        await expect(page.locator('#run-now-result')).toHaveAttribute('aria-live', 'polite');
    });

    test('clicking Run-now keeps the URL on ?tab=backup (no redirect-and-flash)', async () => {
        page.once('dialog', d => d.accept());
        const before = page.url();
        await page.locator('#run-now-button').click();
        // Wait for the result span to be populated, then confirm the URL
        // never navigated. The confirm dialog auto-accepted; the fetch
        // path is XHR so no top-level navigation should ever fire.
        await expect(page.locator('#run-now-result')).not.toBeEmpty({ timeout: 30_000 });
        expect(page.url()).toBe(before);
    });

    test('Run-now disables the button + select while the request is in flight', async () => {
        page.once('dialog', d => d.accept());
        // CR feedback PR #1054: assert the in-flight disabled state, not
        // just the post-completion enabled state. The local-filesystem
        // backup path resolves in low-double-digit milliseconds against
        // SQLite, so we route-intercept run_backup_now.php and inject a
        // small delay to guarantee a visible window in which to assert
        // disabled. A regression that stops disabling the controls during
        // the request would now fail this test rather than silently pass.
        await page.route('**/run_backup_now.php', async (route) => {
            await new Promise((res) => setTimeout(res, 750));
            await route.continue();
        });
        try {
            const button      = page.locator('#run-now-button');
            const destination = page.locator('#run-now-destination');
            await Promise.all([
                button.click(),
                expect(button).toBeDisabled({ timeout: 5_000 }),
                expect(destination).toBeDisabled({ timeout: 5_000 }),
            ]);
            // Wait for the request to complete, then assert restored state.
            await expect(page.locator('#run-now-result')).not.toBeEmpty({ timeout: 30_000 });
            await expect(button).toBeEnabled();
            await expect(destination).toBeEnabled();
            await expect(button).toHaveText('Run backup now');
        } finally {
            await page.unroute('**/run_backup_now.php');
        }
    });

    test('successful Run-now writes a ✓ checkmark and applies .success styling', async () => {
        page.once('dialog', d => d.accept());
        await page.locator('#run-now-button').click();
        const result = page.locator('#run-now-result');
        // ci-local writes to the disk-backed destination which always
        // succeeds in the bootstrapped sidecar environment. Match the
        // ✓ + filename-with-bytes format emitted by app.js.
        await expect(result).toHaveText(/✓\s+\S+.*bytes/, { timeout: 30_000 });
        await expect(result).toHaveClass(/\bsuccess\b/);
    });

    test('Run-now is idempotent — a second click works the same way', async () => {
        // Pin that the button isn't a one-shot; users can run again. This
        // guards against accidentally leaving the disabled state set.
        for (let i = 0; i < 2; i++) {
            page.once('dialog', d => d.accept());
            await page.locator('#run-now-button').click();
            await expect(page.locator('#run-now-result')).toHaveText(/✓\s+\S+.*bytes/, { timeout: 30_000 });
            await expect(page.locator('#run-now-button')).toBeEnabled();
        }
    });

    test('Run-now declines when the user cancels the confirm dialog', async () => {
        // Dismissing the confirm should be a no-op: result span untouched,
        // button + select remain enabled, no fetch fired.
        page.once('dialog', d => d.dismiss());
        const result = page.locator('#run-now-result');
        // Pre-clear the span by reloading; some prior tests may have left
        // a value in it (the script-side text only clears when a fetch
        // starts).
        await page.reload();
        await page.locator('#run-now-destination').selectOption({ label: DEST_LOCAL });
        await page.locator('#run-now-button').click();
        // CR feedback PR #1054: avoid hard wait. After cancelling the confirm,
        // app.js never starts a fetch; the button/select stay enabled and the
        // result span stays empty. Assert the button-enabled state (which the
        // confirm-cancel branch leaves untouched) as the deterministic signal.
        await expect(page.locator('#run-now-button')).toBeEnabled({ timeout: 2_000 });
        await expect(result).toBeEmpty();
    });
});
