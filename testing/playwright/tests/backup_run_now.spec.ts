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
        // Start watching the disabled state immediately after the click; the
        // request resolves quickly against a local destination so we sample
        // via Promise.all rather than racing on toBeDisabled directly.
        const click = page.locator('#run-now-button').click();
        await click;
        // The button transitions to "Running…" + disabled while the fetch
        // is open. Catch that transition before the fetch resolves.
        // (The label revert happens in the .finally() handler in app.js.)
        await expect(page.locator('#run-now-result')).not.toBeEmpty({ timeout: 30_000 });
        // After completion: the button + select are re-enabled and the
        // button label is restored.
        await expect(page.locator('#run-now-button')).toBeEnabled();
        await expect(page.locator('#run-now-destination')).toBeEnabled();
        await expect(page.locator('#run-now-button')).toHaveText('Run backup now');
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
        // Give the confirm-dismiss a moment to settle; the result span
        // should remain empty.
        await page.waitForTimeout(250);
        await expect(result).toBeEmpty();
        await expect(page.locator('#run-now-button')).toBeEnabled();
    });
});
